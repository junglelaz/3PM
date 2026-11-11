<?php
/**
 * Plugin Name: 3PM Homepage Rebuild
 * Description: Prepares an editable Elementor homepage redesign, preserving the original hero and media widgets. Includes preview, apply, and restore actions.
 * Version: 1.2.0
 * Author: 3PM
 */

if (!defined('ABSPATH')) {
    exit;
}

final class ThreePM_Homepage_Rebuild {
    const STATE = 'threepm_homepage_rebuild_state';
    const MARKER = '_threepm_homepage_rebuild';
    const SNAPSHOT = '_threepm_homepage_rebuild_snapshot';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_threepm_homepage_build', array(__CLASS__, 'handle_build'));
        add_action('admin_post_threepm_homepage_apply', array(__CLASS__, 'handle_apply'));
        add_action('admin_post_threepm_homepage_restore', array(__CLASS__, 'handle_restore'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'styles'), 99);
        add_filter('elementor/frontend/the_content', array(__CLASS__, 'wrap_content'), 99);
        add_filter('body_class', array(__CLASS__, 'body_class'));
        add_action('wp_footer', array(__CLASS__, 'interaction_script'), 30);
        add_filter('the_posts', array(__CLASS__, 'prioritize_roster'), 20, 2);
        add_filter('render_block_woocommerce/empty-cart-block', array(__CLASS__, 'refine_empty_cart'), 20);
        add_filter('the_content', array(__CLASS__, 'refine_utility_content'), 30);
        add_shortcode('threepm_plan_benefits', array(__CLASS__, 'plan_benefits_shortcode'));
        add_filter('woocommerce_get_query_vars', array(__CLASS__, 'account_query_vars'), 20);
        add_action('woocommerce_account_wallet-transactions_endpoint', array(__CLASS__, 'wallet_transactions_view'));
        add_filter('woocommerce_endpoint_wallet-transactions_title', static function ($title) { return 'Wallet transactions'; });
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'coin_product_intro'), 6);
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'coin_payment_options'), 35);
        add_action('template_redirect', array(__CLASS__, 'start_copy_cleanup'), 0);
        add_filter('elementor/widget/render_content', array(__CLASS__, 'contact_widget_links'), 20, 2);
        add_filter('elementor/widget/render_content', array(__CLASS__, 'public_widget_copy'), 25, 2);

    }

    public static function menu() {
        add_management_page('3PM Homepage', '3PM Homepage', 'manage_options', 'threepm-homepage', array(__CLASS__, 'screen'));
    }

    private static function authorize($action) {
        if (!current_user_can('manage_options')) {
            wp_die('Administrator access is required.');
        }
        check_admin_referer($action);
        if (!class_exists('\Elementor\Plugin')) {
            throw new RuntimeException('Elementor must be active.');
        }
    }

    private static function state() {
        $state = get_option(self::STATE, array());
        return is_array($state) ? $state : array();
    }

    private static function fingerprint($snapshot) {
        return hash('sha256', wp_json_encode($snapshot));
    }

    private static function snapshot($id) {
        $post = get_post($id);
        $raw = get_post_meta($id, '_elementor_data', true);
        $data = is_array($raw) ? $raw : json_decode($raw, true);
        if (!$post || !is_array($data) || !$data) {
            throw new RuntimeException('The homepage has no valid Elementor layout. Nothing was changed.');
        }
        return array(
            'elements' => $data,
            'settings' => get_post_meta($id, '_elementor_page_settings', true),
            'template' => get_post_meta($id, '_wp_page_template', true),
            'content' => $post->post_content,
            'marker' => get_post_meta($id, self::MARKER, true),
        );
    }

    private static function add_class($element, $class) {
        // Legacy sections and modern containers expose css_classes; widgets use _css_classes.
        $key = isset($element['elType']) && $element['elType'] === 'widget' ? '_css_classes' : 'css_classes';
        if (!isset($element['settings']) || !is_array($element['settings'])) {
            $element['settings'] = array();
        }
        $previous = isset($element['settings'][$key]) ? $element['settings'][$key] : '';
        $element['settings'][$key] = trim($previous . ' ' . $class);
        return $element;
    }

    public static function compose($original, $sections) {
        foreach (array('manifesto', 'paths', 'services', 'coin') as $key) {
            if (empty($sections[$key]) || !is_array($sections[$key])) {
                throw new RuntimeException('The rebuild package is incomplete: ' . $key);
            }
        }
        $ids = array_column($original, 'id');
        foreach (array('88d92fd', 'b8363c7') as $id) {
            if (!in_array($id, $ids, true)) {
                throw new RuntimeException('The homepage differs from the inspected layout. Missing section ' . $id . '. Nothing was changed.');
            }
        }
        $result = array();
        foreach ($original as $element) {
            $id = $element['id'];
            if ($id === '2f27274b') {
                $result[] = $sections['manifesto'];
                $result[] = $sections['paths'];
                continue;
            }
            // This inspected section is an empty spacer. Do not remove unknown sections.
            if ($id === 'a73f7f5') {
                continue;
            }
            if ($id === '88d92fd') {
                $result[] = self::add_class($element, 'pm-original-hero');
                // The current homepage no longer has the old introduction.
                if (!in_array('2f27274b', $ids, true)) {
                    $result[] = $sections['manifesto'];
                    $result[] = $sections['paths'];
                }
                continue;
            }
            $result[] = self::add_class($element, 'pm-preserved');
            if ($id === 'b8363c7') {
                $result[] = $sections['services'];
                $result[] = $sections['coin'];
            }
        }
        self::validate_ids($result);
        return $result;
    }

    private static function validate_ids($elements, &$seen = null) {
        if ($seen === null) {
            $seen = array();
        }
        foreach ($elements as $element) {
            if (empty($element['id']) || isset($seen[$element['id']])) {
                throw new RuntimeException('A missing or duplicate Elementor element ID was found. Nothing was saved.');
            }
            $seen[$element['id']] = true;
            if (!empty($element['elements'])) {
                self::validate_ids($element['elements'], $seen);
            }
        }
    }

    private static function save($id, $elements, $settings) {
        self::validate_ids($elements);
        $document = \Elementor\Plugin::$instance->documents->get($id);
        if (!$document) {
            throw new RuntimeException('Elementor could not open the target document.');
        }
        $saved = $document->save(array(
            'elements' => $elements,
            'settings' => is_array($settings) ? $settings : array(),
        ));
        if ($saved === false || is_wp_error($saved)) {
            throw new RuntimeException('Elementor rejected the layout save.');
        }
        // Check the saved document, rather than reporting success based only on the save call.
        $raw = get_post_meta($id, '_elementor_data', true);
        $persisted = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($persisted) || array_column($persisted, 'id') !== array_column($elements, 'id')) {
            throw new RuntimeException('The saved Elementor structure did not match the rebuild.');
        }
        // Document::save handles Elementor caches; purge this public URL in LiteSpeed as well.
        do_action('litespeed_purge_url', get_permalink($id));
        clean_post_cache($id);
    }

    public static function handle_build() {
        try {
            self::authorize('threepm_homepage_build');
            $state = self::state();
            if (!empty($state['applied'])) {
                throw new RuntimeException('The redesign is already applied. Restore the original before preparing another baseline.');
            }
            $home = (int) get_option('page_on_front');
            if (!$home || get_option('show_on_front') !== 'page') {
                throw new RuntimeException('A static homepage must be configured.');
            }
            $original = self::snapshot($home);
            $sections = json_decode(file_get_contents(__DIR__ . '/sections.json'), true);
            $data = self::compose($original['elements'], $sections);
            $draft = wp_insert_post(array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => 'Home — 3PM redesign preview',
                'post_author' => get_current_user_id(),
                'meta_input' => array(
                    '_elementor_edit_mode' => 'builder',
                    '_elementor_template_type' => 'wp-page',
                    '_elementor_version' => ELEMENTOR_VERSION,
                    '_wp_page_template' => $original['template'] ?: 'default',
                    '_elementor_page_settings' => is_array($original['settings']) ? $original['settings'] : array(),
                ),
            ), true);
            if (is_wp_error($draft)) {
                throw new RuntimeException($draft->get_error_message());
            }
            self::save($draft, $data, $original['settings']);
            update_post_meta($draft, self::MARKER, '1');
            update_post_meta($draft, self::SNAPSHOT, wp_slash($original));
            update_option(self::STATE, array('home_id' => $home, 'draft_id' => $draft, 'source_hash' => self::fingerprint($original), 'applied' => false), false);
            self::redirect('Preview created. The public homepage is unchanged.');
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function handle_apply() {
        $home = 0;
        $backup = null;
        try {
            self::authorize('threepm_homepage_apply');
            $state = self::state();
            if (empty($state['draft_id']) || !empty($state['applied'])) {
                throw new RuntimeException('Create a preview before applying the redesign.');
            }
            $home = (int) $state['home_id'];
            if ($home !== (int) get_option('page_on_front') || !current_user_can('edit_post', $home)) {
                throw new RuntimeException('The homepage changed or cannot be edited by this account.');
            }
            $current = self::snapshot($home);
            if (!hash_equals($state['source_hash'], self::fingerprint($current))) {
                throw new RuntimeException('The homepage was edited after the preview was prepared. Create a fresh preview to retain those edits.');
            }
            $draft = self::snapshot((int) $state['draft_id']);
            // Persist the entire original before any public change.
            update_post_meta($home, self::SNAPSHOT, wp_slash($current));
            if (self::fingerprint(get_post_meta($home, self::SNAPSHOT, true)) !== self::fingerprint($current)) {
                throw new RuntimeException('The recovery snapshot could not be verified. Nothing was changed.');
            }
            wp_save_post_revision($home);
            $backup = $current;
            self::save($home, $draft['elements'], $draft['settings']);
            update_post_meta($home, self::MARKER, '1');
            $state['applied'] = true;
            $state['applied_at'] = current_time('mysql', true);
            update_option(self::STATE, $state, false);
            do_action('litespeed_purge_url', get_permalink($home));
            self::redirect('Homepage updated. The original is saved for restoration.');
        } catch (Throwable $e) {
            if ($home && $backup !== null) {
                try {
                    self::save($home, $backup['elements'], $backup['settings']);
                    self::restore_marker($home, $backup['marker']);
                } catch (Throwable $rollback) {
                    self::redirect('The save failed and automatic recovery also failed. The original remains in the homepage recovery snapshot. ' . $e->getMessage(), true);
                }
            }
            self::redirect($e->getMessage(), true);
        }
    }

    private static function restore_marker($id, $value) {
        if ($value) {
            update_post_meta($id, self::MARKER, $value);
        } else {
            delete_post_meta($id, self::MARKER);
        }
    }

    public static function handle_restore() {
        try {
            self::authorize('threepm_homepage_restore');
            $state = self::state();
            if (empty($state['home_id']) || empty($state['applied'])) {
                throw new RuntimeException('There is no applied rebuild to restore.');
            }
            $home = (int) $state['home_id'];
            $original = get_post_meta($home, self::SNAPSHOT, true);
            if (!is_array($original) || empty($original['elements'])) {
                throw new RuntimeException('The recovery snapshot is unavailable.');
            }
            // Save the latest redesign first so restore itself remains reversible.
            update_post_meta($state['draft_id'], '_threepm_last_applied_layout', wp_slash(self::snapshot($home)));
            self::save($home, $original['elements'], $original['settings']);
            wp_update_post(array('ID' => $home, 'post_content' => wp_slash($original['content'])));
            update_post_meta($home, '_wp_page_template', $original['template']);
            self::restore_marker($home, $original['marker']);
            $state['applied'] = false;
            update_option(self::STATE, $state, false);
            do_action('litespeed_purge_url', get_permalink($home));
            self::redirect('The original homepage has been restored.');
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function styles() {
        // Load a small, fully scoped stylesheet sitewide: Vice navigates pages through AJAX.
        wp_enqueue_style('threepm-homepage-rebuild', plugins_url('assets/homepage.css', __FILE__), array(), (string) filemtime(__DIR__ . '/assets/homepage.css'));
    }

    public static function wrap_content($content) {
        if ((int) get_the_ID() === 539) {
            $content = self::refine_home_public_copy($content);
            $content = str_replace('Work with 3PM artists and bring a new perspective to your next project.', 'Work with vetted 3PM artists worldwide and bring a new perspective to your next project.', $content);
        }
        if (in_array((int) get_the_ID(), array(539, 885), true)) {
            $content = self::link_contact_values($content);
        }
        if ((int) get_the_ID() === 879) {
            $content = str_replace("So, what's the issues &amp; what's the fix?", 'The challenges. The 3PM approach.', $content);
            $content = str_replace("So, what's the issues & what's the fix?", 'The challenges. The 3PM approach.', $content);
            $content = self::refine_about($content);
        }
        if ((int) get_the_ID() === 1420) {
            $content = str_replace(
                array('FEATURES &amp; COLLABORATIONS', 'FEATURES & COLLABORATIONS', 'FEATURE YOUR FAVORITE 3PM ARTIST ON YOUR PROJECT AND COLLABORATE WITH THEM ON YOUR NEXT MASTERPIECE.', 'PRODUCTION'),
                array('Features &amp; collaborations', 'Features & collaborations', 'Feature your favorite 3PM artist on your project and collaborate with them on your next masterpiece.', 'Production'),
                $content
            );
        }
        if (get_post_meta(get_the_ID(), self::MARKER, true)) {
            return '<div class="pm-home">' . $content . '</div>';
        }
        return $content;
    }

    /** Present the existing Elementor widgets as compact, accessible sections. */
    private static function refine_about($content) {
        if (!class_exists('DOMDocument') || strpos($content, 'elementor-879') === false) {
            return $content;
        }
        if (class_exists('\\Elementor\\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode()) {
            return $content;
        }
        $previous_errors = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->loadHTML('<?xml encoding="UTF-8"><div id="pm-content-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($doc);
            $find = static function ($id) use ($xpath) {
                return $xpath->query('//*[@data-id="' . $id . '"]')->item(0);
            };
            $root = $xpath->query('//*[@id="pm-content-root"]')->item(0);
            $intro = $find('dc447d4');
            $coin = $find('9fa03e2');
            if (!$root || !$intro || !$coin || $xpath->query('//*[contains(@class,"pm-model-list")]')->length) {
                return $content;
            }
            $list = $doc->createElement('section');
            $list->setAttribute('class', 'pm-model-list');
            $list->setAttribute('aria-label', 'Six challenges, six ways forward');
            $intro->parentNode->insertBefore($list, $intro->nextSibling);
            $pairs = array(
                array('029f69c', 'b2eb434'), array('6384393', '722f483'),
                array('b669b4d', '5bee8ee'), array('e5826b1', '40be847'),
                array('717bc24', '569c5e9'), array('3667077', '49e19f4'),
            );
            foreach ($pairs as $pair) {
                $heading = $find($pair[0]);
                $body = $find($pair[1]);
                if (!$heading || !$body) {
                    continue;
                }
                $details = $doc->createElement('details');
                $details->setAttribute('class', 'pm-model-item');
                $details->setAttribute('name', 'pm-music-model');
                $summary = $doc->createElement('summary');
                $summary->appendChild($heading);
                $details->appendChild($summary);
                $details->appendChild($body);
                $list->appendChild($details);
            }
            $intro_text = $doc->createElement('p', 'Explore a challenge to see how the 3PM model responds.');
            $intro_text->setAttribute('class', 'pm-model-hint');
            $list->insertBefore($intro_text, $list->firstChild);

            // Group the native text editor by its existing H2/H3 headings, without changing copy.
            $groups = array();
            $group = null;
            $children = array();
            foreach ($coin->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                if ($child instanceof DOMElement && strtolower($child->tagName) === 'h2') {
                    $group = $doc->createElement('section');
                    $group->setAttribute('class', 'pm-coin-section');
                    $groups[] = $group;
                }
                if ($group) {
                    $group->appendChild($child);
                }
            }
            if (count($groups) === 9) {
                $classes = array('overview', 'contrast', 'how', 'treasury', 'launch', 'launch-benefits', 'tokenomics', 'difference', 'join');
                foreach ($groups as $index => $section) {
                    $section->setAttribute('class', 'pm-coin-section pm-coin-' . $classes[$index]);
                }
                $how = $groups[2];
                $how_children = array();
                foreach ($how->childNodes as $child) {
                    $how_children[] = $child;
                }
                $audience = null;
                foreach ($how_children as $child) {
                    if ($child instanceof DOMElement && strtolower($child->tagName) === 'h3') {
                        $audience = $doc->createElement('div');
                        $audience->setAttribute('class', 'pm-audience-card');
                        $how->appendChild($audience);
                    }
                    if ($audience) {
                        $audience->appendChild($child);
                    }
                }
                $audiences = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," pm-audience-card ")]', $how);
                $switch = $doc->createElement('div');
                $switch->setAttribute('class', 'pm-audience-switch');
                $switch->setAttribute('role', 'tablist');
                $switch->setAttribute('aria-label', 'How 3PM Coin works for you');
                $how->insertBefore($switch, $audiences->item(0));
                foreach (array('artists', 'fans') as $index => $audience_key) {
                    $panel = $audiences->item($index);
                    if (!$panel) {
                        continue;
                    }
                    $button = $doc->createElement('button', $index === 0 ? 'For artists' : 'For fans');
                    $button->setAttribute('type', 'button');
                    $button->setAttribute('role', 'tab');
                    $button->setAttribute('id', 'pm-tab-' . $audience_key);
                    $button->setAttribute('aria-controls', 'pm-panel-' . $audience_key);
                    $button->setAttribute('aria-selected', $index === 0 ? 'true' : 'false');
                    $button->setAttribute('tabindex', $index === 0 ? '0' : '-1');
                    $switch->appendChild($button);
                    $panel->setAttribute('id', 'pm-panel-' . $audience_key);
                    $panel->setAttribute('role', 'tabpanel');
                    $panel->setAttribute('aria-labelledby', 'pm-tab-' . $audience_key);
                    $panel->setAttribute('tabindex', '0');
                    if ($index === 1) {
                        $panel->setAttribute('hidden', 'hidden');
                    }
                    if ($index === 0) {
                        $artist_list = $panel->getElementsByTagName('ul')->item(0);
                        if ($artist_list) {
                            $benefits = array(
                                'Artists are coin holders too: they share access to the same coin-holder benefits available to fans.',
                                'Use $3PM Coins across music releases, exclusive content, vault memberships and private events available in the ecosystem.',
                                'Eligible purchases can earn the same community rewards offered to fans, under the current programme rules.',
                                'Build direct relationships with supporters and fellow creators while retaining ownership of your music and creative work.',
                            );
                            foreach ($benefits as $benefit) {
                                $artist_list->appendChild($doc->createElement('li', $benefit));
                            }
                        }
                    }
                    $paths = array(
                        '<path d="M9 18V5l12-2v13M9 8l12-2"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
                        '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                        '<circle cx="12" cy="12" r="9"/><path d="M15 8h-4a2 2 0 0 0 0 4h2a2 2 0 0 1 0 4H9M12 6v2m0 8v2"/>',
                        '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M7 8V6a5 5 0 0 1 10 0v2M12 13v3"/>',
                        '<path d="M3 8h18v4H3zM5 12v9h14v-9M12 8v13"/><path d="M12 8H8a3 3 0 1 1 3-3zM12 8h4a3 3 0 1 0-3-3z"/>',
                        '<path d="M12 22s9-4 9-11V4l-9-3-9 3v7c0 7 9 11 9 11zM8 11l3 3 5-6"/>',
                        '<path d="M3 7h16m-4-4 4 4-4 4M21 17H5m4-4-4 4 4 4"/>',
                    );
                    $points = array();
                    foreach ($panel->getElementsByTagName('li') as $point) {
                        $points[] = $point;
                    }
                    foreach ($points as $point_index => $point) {
                        $copy = $doc->createElement('span');
                        $copy->setAttribute('class', 'pm-point-copy');
                        while ($point->firstChild) {
                            $copy->appendChild($point->firstChild);
                        }
                        $icon = $doc->createDocumentFragment();
                        $icon->appendXML('<svg xmlns="http://www.w3.org/2000/svg" class="pm-line-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$index === 1 ? array(2, 3, 4, 6)[$point_index % 4] : $point_index % count($paths)] . '</svg>');
                        $point->appendChild($icon);
                        $point->appendChild($copy);
                    }
                }
                $join = $groups[8];
                $join_link = $join->getElementsByTagName('a')->item(0);
                if ($join_link) {
                    $join_paragraph = $join_link->parentNode;
                    $actions = $doc->createElement('div');
                    $actions->setAttribute('class', 'pm-join-actions');
                    $actions->appendChild($join_link);
                    $note = $doc->createElement('span', 'Use your balance across the 3PM ecosystem.');
                    $note->setAttribute('class', 'pm-join-note');
                    $actions->appendChild($note);
                    while ($join_paragraph->firstChild) {
                        $join_paragraph->removeChild($join_paragraph->firstChild);
                    }
                    $join_paragraph->appendChild($doc->createTextNode('Discover music, explore memberships and support independent creativity.'));
                    $join->insertBefore($actions, $join_paragraph->nextSibling);
                }
                $journey = $doc->createElement('div');
                $journey->setAttribute('class', 'pm-coin-journey');
                foreach (array(3, 4, 5) as $index) {
                    $journey->appendChild($groups[$index]);
                }
                foreach (array(0, 1, 2) as $index) {
                    $coin->appendChild($groups[$index]);
                }
                $coin->appendChild($journey);
                foreach (array(6, 7, 8) as $index) {
                    $coin->appendChild($groups[$index]);
                }
            } else {
                // Preserve all content if the author adds or removes a section later.
                foreach ($groups as $section) {
                    $coin->appendChild($section);
                }
            }
            $rendered = '';
            foreach ($root->childNodes as $child) {
                $rendered .= $doc->saveHTML($child);
            }
            return $rendered;
        } catch (Throwable $error) {
            return $content;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous_errors);
        }
    }

    private static function refine_home_public_copy($content) {
        $content = str_replace(
            array('02 / LISTENERS', '03 / COMMUNITY', 'Find your people.', 'Explore memberships', 'https://3rdpersonmusic.com/membership-levels/', 'https://3rdpersonmusic.com/wp-content/uploads/2025/10/3pm-coin-3D-wide-500x500.webp'),
            array('02 / FANS', '03 / INVESTORS', 'Build what comes next.', 'Explore investment', 'https://3rdpersonmusic.com/investors/', 'https://3rdpersonmusic.com/wp-content/uploads/2026/11/3pm-coin-transparent-web.png'),
            $content
        );
        return $content;
    }

    private static function link_contact_values($content) {
        return preg_replace_callback('/(<p\b[^>]*class="[^"]*elementor-icon-box-description[^"]*"[^>]*>)(.*?)(<\/p>)/is', static function ($match) {
            if (strpos($match[2], '<a ') !== false) {
                return $match[0];
            }
            $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
            $href = '';
            if (is_email($text)) {
                $href = 'mailto:' . $text;
            } elseif ($text === '@thirdpersonmusic') {
                $href = 'https://t.me/thirdpersonmusic';
            } elseif ($text === '@3rdpersonmusic') {
                return $match[0];
            } elseif ($text === '+27 (0) 78 176 0657') {
                $href = 'https://wa.me/27781760657';
            }
            if (!$href) {
                return $match[0];
            }
            return $match[1] . '<a class="qwNoAjax pm-contact-value" href="' . esc_url($href) . '">' . esc_html($text) . '</a>' . $match[3];
        }, $content);
    }

    /** Public deck summary. Native media, queries and theme navigation stay intact. */
    public static function public_widget_copy($content, $widget) {
        if (class_exists('\\Elementor\\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode()) {
            return $content;
        }
        $page = (int) get_the_ID();
        $id = $widget->get_id();
        if ($page === 879 && $id === '26e90c0') {
            $audience_links = '';
            foreach (array('for-artists' => 'For Artists', 'for-fans' => 'For Fans', 'for-investors' => 'For Investors') as $slug => $label) {
                $audience_links .= ' <a class="btn vice-btn vice-btn--aligncenter pm-about-audience-link" href="' . esc_url(home_url('/' . $slug . '/')) . '"><span>' . esc_html($label) . '</span></a>';
            }
            return str_replace('</p>', $audience_links . '</p>', $content);
        }
        if ($page === 879 && $id === '9fa03e2') {
            return <<<'PMABOUT'
<h2>Our Response To A Movement</h2>
<p>A growing movement of artists is choosing independence, building successful careers through innovative methods, new tools and direct connections with fans. 3PM is our response: a musician-built platform that brings music, creative talent and community support into one connected ecosystem.</p>
<p>Our approach is product first. Help artists retain ownership, reach their supporters and turn that connection into a lasting creative career. $3PM connects releases, vault memberships and community participation, with a wider services and licensing economy in development.</p>
<h2>Music made by people. Built by musicians.</h2>
<p>3PM is a project by The Mantrium, created by musicians who also build Web3 technology. Music production, storytelling, design and platform development meet in one creative practice.</p>
<p>Artists retain ownership of their work and can release elsewhere. 3PM is non-exclusive. We value human-made music: AI can assist operations, development and marketing, but our policy does not support AI-generated music or using artists' music to train AI.</p>
<p>Explore the <a href="/artists/">artists and their work</a> and our <a href="/services/">creative services</a>. Read the business model and execution priorities in our <a href="/investors/">investor overview</a>.</p>
<h2>How 3PM Coin Works</h2>
<p>Choose your perspective. Artists are also community members and coin holders, with the same eligible access and rewards as fans.</p>
<h3>For artists</h3>
<ul><li>Release non-exclusively and retain ownership of your music and creative IP.</li><li>Connect with a network of musicians, producers and collaborators, with creative services and deeper fan relationships around your work.</li></ul>
<h3>For fans</h3>
<ul><li>Use your platform balance for releases and content available through the 3PM store.</li><li>Choose a vault membership for music, video, photography and behind-the-scenes material, according to the selected tier.</li><li>Participate in eligible ecosystem rewards under the programme rules shown in your account.</li><li>Manage daily spending and HODL balances in your account. HODL balances are distinct from spendable funds and do not establish external liquidity.</li></ul>
<h2>Utility today</h2>
<p>The current experience is a platform account and wallet system, connected to music, the store and memberships. Your account records balances and activity inside 3PM.</p>
<p>A platform balance is not the same as a freely tradable blockchain token. External trading, token claims and future governance belong to a later development stage.</p>
<h2>What we are building next</h2>
<p>The roadmap extends from the existing music platform into broader artist onboarding, services, licensing and digital collectibles. Token deployment, external claims and liquidity require further technical preparation and published launch terms.</p>
<p>Progress depends on funding, product delivery, adoption and appropriate legal and security review. Roadmap items are plans, not live features or guaranteed delivery dates.</p>
<h2>Participation, with clear expectations</h2>
<p>Spend balances support activity inside 3PM. HODL balances track rewards and participation intended for the future token phase. Any future conversion or claim will depend on the final published eligibility, vesting and technical rules.</p>
<p>Coin ownership does not itself confer company equity, guaranteed income or a guaranteed market price. A future exchange launch and liquidity are not assured.</p>
<h2>Tokenomics: the proposed allocation</h2>
<p>The proposed total supply is <strong>100,000,000 $3PM</strong>. The allocation below totals 100%. It describes the proposed future token model, not a statement that tokens, vesting contracts or liquidity locks have already been deployed.</p>
<div class="pm-token-supply"><span>PROPOSED TOTAL SUPPLY</span><strong>100,000,000 <small>$3PM</small></strong></div>
<figure class="pm-financial-card"><figcaption>Proposed distribution of total supply</figcaption><div class="pm-donut-layout"><div class="pm-token-donut" role="img" aria-label="Proposed allocation of 100 million 3PM tokens. See the adjacent legend for the eight allocations."><div><strong>100M</strong><span>PROPOSED SUPPLY</span></div></div><ul class="pm-chart-legend"><li><i style="background:#caff40" aria-hidden="true"></i><span>Private presale, round 1</span><strong>10%</strong></li><li><i style="background:#8bdc82" aria-hidden="true"></i><span>Private presale, round 2 &amp; artists</span><strong>10%</strong></li><li><i style="background:#72c8b7" aria-hidden="true"></i><span>Public presale</span><strong>10%</strong></li><li><i style="background:#93a7d8" aria-hidden="true"></i><span>Launch liquidity</span><strong>10%</strong></li><li><i style="background:#d8d47c" aria-hidden="true"></i><span>Ecosystem &amp; rewards</span><strong>20%</strong></li><li><i style="background:#b7a0d6" aria-hidden="true"></i><span>Team &amp; advisors</span><strong>15%</strong></li><li><i style="background:#74958a" aria-hidden="true"></i><span>Treasury / reserve</span><strong>15%</strong></li><li><i style="background:#d2b59a" aria-hidden="true"></i><span>Marketing &amp; partnerships</span><strong>10%</strong></li></ul></div></figure>
<div class="pm-token-table-wrap" role="region" aria-label="Proposed token allocation" tabindex="0"><table class="pm-token-table"><thead><tr><th scope="col">Allocation</th><th scope="col">Share</th><th scope="col">Tokens</th><th scope="col">Proposed release terms</th></tr></thead><tbody>
<tr><th scope="row">Private presale, round 1</th><td>10%</td><td>10,000,000</td><td>Unlocked at token generation event (TGE).</td></tr>
<tr><th scope="row">Private presale, round 2 &amp; artists</th><td>10%</td><td>10,000,000</td><td>6-month cliff, then 12-month linear vesting.</td></tr>
<tr><th scope="row">Public presale</th><td>10%</td><td>10,000,000</td><td>3-month linear vesting.</td></tr>
<tr><th scope="row">Launch liquidity</th><td>10%</td><td>10,000,000</td><td>Liquidity-pool lock proposed; its final duration remains to be confirmed.</td></tr>
<tr><th scope="row">Ecosystem &amp; rewards</th><td>20%</td><td>20,000,000</td><td>Released through incentives, drops and proposed staking programmes.</td></tr>
<tr><th scope="row">Team &amp; advisors</th><td>15%</td><td>15,000,000</td><td>12-month cliff, then 24 to 36 months of linear vesting.</td></tr>
<tr><th scope="row">Treasury / reserve</th><td>15%</td><td>15,000,000</td><td>Long-term reserve; future DAO control is proposed.</td></tr>
<tr><th scope="row">Marketing &amp; partnerships</th><td>10%</td><td>10,000,000</td><td>Gradual release linked to milestones.</td></tr>
</tbody><tfoot><tr><th scope="row">Total</th><td>100%</td><td>100,000,000</td><td>Proposed model, subject to final published terms.</td></tr></tfoot></table></div>
<div class="pm-transparency-grid"><div><h3>What needs to be published before launch</h3><p>The final network and contract address, verified supply, allocation wallets, vesting start dates, liquidity arrangements, security review and claim instructions are the evidence needed to evaluate a token launch. The allocation table alone does not verify these.</p></div><div><h3>What remains provisional</h3><p>Final adoption and treasury thresholds will be published before launch, together with governance rules and reward mechanics.</p></div></div>
<p class="pm-token-source">Vesting start dates and final implementation remain to be confirmed. No price floor or investment return is guaranteed.</p>
<h2>Why 3PM is a Game-Changer</h2>
<ul><li>Artists keep creative ownership and build closer relationships with their supporters.</li><li>Listeners can explore a wider creative world through music, vaults, releases and experiences.</li><li>One connected ecosystem can support several business activities: products, memberships, creative services and licensing.</li></ul>
<h2>Find your place in the ecosystem.</h2>
<p>Listen, explore the vaults, work with an artist or learn about the business. Participation starts with music and useful experiences.</p>
<p><a href="/shop/3pm-coins/">Explore $3PM Coins</a></p>
PMABOUT;
        }
        if ($page !== 539) {
            return $content;
        }
        $replacements = array(
            'fce31866' => array('02 / LISTENERS', '02 / FANS'),
            '7a43e000' => array('03 / COMMUNITY', '03 / INVESTORS'),
            '131dfb4c' => array('Find your people.', 'Build what comes next.'),
            '6065bed2' => array('Get closer to the music through memberships, exclusive vaults, and shared experiences. Be part of the story as it unfolds.', 'Explore a musician-built platform connecting releases, memberships, creative services and a developing Web3 economy. Understand the product, the business and the work ahead.'),
            '88e67a3c' => array('Explore memberships', 'Explore investment'),
            '593f9c21' => array('Make your next move with a community of artists, producers, designers, and developers. Keep ownership of your work. Build something that feels like you.', 'Retain ownership. Release non-exclusively. Connect with creative talent, earn crypto, and build direct relationships with the people who believe in your music.'),
            '64153339' => array('Go beyond the algorithm. Discover independent music, meet the people behind it, and support the artists whose work moves you.', 'Support the artists you love and earn 10% cashback. Discover music, collect releases and explore vault memberships—your support gives something back to you.'),
            'df0d433e' => array('Explore the Daily Wallet, HODL rewards, and the project’s plans in our Black Paper.', 'Explore platform utility, the proposed token allocation, vesting and development plans in our public project overview.'),
            'fccb04d9' => array('A project in development. Read the current model and roadmap.', 'Music and memberships today. A wider token and governance model in development.'),
        );
        if (isset($replacements[$id])) {
            $content = str_replace($replacements[$id][0], $replacements[$id][1], $content);
        }
        if ($id === '88e67a3c') {
            $content = str_replace('https://3rdpersonmusic.com/membership-levels/', 'https://3rdpersonmusic.com/investors/', $content);
        }
        $audience_buttons = array(
            '01ce995d' => array('/for-artists/', 'Explore artist services', 'Explore Artist Benefits'),
            'c75b0d76' => array('/for-fans/', 'Explore the music', 'Explore Fan Benefits'),
            '88e67a3c' => array('/for-investors/', 'Explore investment', 'Explore Investor Benefits'),
        );
        if (isset($audience_buttons[$id])) {
            $button = $audience_buttons[$id];
            $content = preg_replace('/(<a\b[^>]*\bhref=")[^"]*(")/i', '$1' . esc_url(home_url($button[0])) . '$2', $content, 1);
            $content = str_replace($button[1], $button[2], $content);
        }
        if ($id === '7c71f17c') {
            return '<img width="1254" height="1254" src="https://3rdpersonmusic.com/wp-content/uploads/2026/11/3pm-coin-transparent-web.png" class="pm-transparent-coin" alt="Gold 3PM coin with subtle shading and a fading reflection" loading="lazy" decoding="async">';
        }
        return $content;
    }

    public static function contact_widget_links($content, $widget) {
        if (!in_array((int) get_the_ID(), array(539, 885), true) || $widget->get_name() !== 'icon-box' || !class_exists('DOMDocument')) {
            return $content;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->loadHTML('<?xml encoding="UTF-8"><div id="pm-contact-link-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($doc);
            $description = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," elementor-icon-box-description ")]')->item(0);
            if (!$description || $description->getElementsByTagName('a')->length) {
                return $content;
            }
            $text = trim($description->textContent);
            $settings = $widget->get_settings_for_display();
            $title = isset($settings['title_text']) ? strtoupper(wp_strip_all_tags($settings['title_text'])) : '';
            $href = !empty($settings['link']['url']) ? $settings['link']['url'] : '';
            if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
                $href = 'mailto:' . $text;
            } elseif (strpos($title, 'TELEGRAM') !== false) {
                $href = 'https://t.me/' . ltrim($text, '@');
            } elseif (!$href && strpos($title, 'INSTAGRAM') !== false) {
                $href = 'https://instagram.com/' . ltrim($text, '@');
            } elseif (!$href && strpos($title, 'TIKTOK') !== false) {
                $href = 'https://www.tiktok.com/@' . ltrim($text, '@');
            } elseif (!$href && strpos($title, 'X.COM') !== false) {
                $href = 'https://x.com/' . ltrim($text, '@');
            } elseif (!$href && strpos($title, 'WHATSAPP') !== false) {
                $href = 'https://wa.me/27781760657';
            }
            if (!$href) {
                return $content;
            }
            while ($description->firstChild) {
                $description->removeChild($description->firstChild);
            }
            $link = $doc->createElement('a', $text);
            $link->setAttribute('href', $href);
            $link->setAttribute('class', 'qwNoAjax');
            if (strpos($href, 'https://') === 0) {
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener');
            }
            $description->appendChild($link);
            $root = $xpath->query('//*[@id="pm-contact-link-root"]')->item(0);
            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $doc->saveHTML($child);
            }
            return $output;
        } catch (Throwable $error) {
            return $content;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function start_copy_cleanup() {
        if (is_admin() || is_feed() || wp_is_json_request() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        ob_start(array(__CLASS__, 'clean_rendered_copy'));
    }

    public static function clean_rendered_copy($html) {
        $first = ltrim($html);
        if ($first === '' || $first[0] === '{' || $first[0] === '[') {
            return $html;
        }
        // Single releases keep one purchase button without a repeated purchase label/link.
        if (is_singular('release')) {
            $html = preg_replace_callback('~<div\b[^>]*class="[^"]*\bqw-buylinks\b[^"]*"[^>]*>.*?</div>~is', static function ($block) {
                $markup = preg_replace('~<strong\b[^>]*>\s*Buy On:\s*</strong>\s*~i', '', $block[0]);
                if (!preg_match('~<a\b(?=[^>]*class="[^"]*\bqw-buylink\b)[^>]*href="([^"]+)"~i', $markup, $primary)) {
                    return $markup;
                }
                $path = wp_parse_url(html_entity_decode($primary[1]), PHP_URL_PATH);
                $markup = preg_replace('~\s*Label:\s*(<strong>.*?</strong>)\s*<br\s*/?>\s*Release Date:\s*(<strong>.*?</strong>)\s*<br\s*/?>~is', '<span class="pm-release-facts"><span>Label: $1</span><span>Release date: $2</span></span>', $markup);
                return preg_replace_callback('~(?:\s*/\s*)?<a\b[^>]*href="([^"]+)"[^>]*>.*?</a>~is', static function ($link) use ($path) {
                    if (preg_match('~class="[^"]*\bqw-buylink\b~i', $link[0])) return $link[0];
                    $candidate = wp_parse_url(html_entity_decode($link[1]), PHP_URL_PATH);
                    return $path && $candidate === $path ? '' : $link[0];
                }, $markup);
            }, $html);
        }
        // Restore the native review tab anchor if legacy theme markup omits it.
        $html = preg_replace('/(<li\b[^>]*id="tab-title-reviews"[^>]*>)\s*([^<]+)\s*(<\/li>)/is', '$1<a href="#tab-reviews" role="tab" aria-controls="tab-reviews" aria-selected="false" tabindex="-1">$2</a>$3', $html);
        // Leave attributes, scripts, styles and JSON intact; only change public text tokens.
        $parts = preg_split('/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<!--.*?-->|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $html;
        }
        foreach ($parts as &$part) {
            if ($part !== '' && $part[0] !== '<') {
                $part = str_replace(array('Welcome to our Black Paper.', 'Welcome to our Black Paper', 'The Black Paper', 'the Black Paper', 'our Black Paper'), array('Welcome to 3PM.', 'Welcome to 3PM', 'The 3PM vision', 'the 3PM vision', 'the 3PM vision'), $part);
                $part = str_replace('3PM is a project by The Mantrium', '<span class="pm-credit-prefix">3PM is a project by</span> <span class="pm-credit-name">The Mantrium</span>', $part);
                $part = preg_replace('/[ \t]*(?:\x{2014}|&mdash;|&#8212;|&#x2014;)[ \t]*/iu', ' - ', $part);
            }
        }
        unset($part);
        return implode('', $parts);
    }

    public static function account_query_vars($vars) {
        $vars['wallet-transactions'] = 'wallet-transactions';
        return $vars;
    }

    public static function wallet_transactions_view() {
        if (!is_user_logged_in() || !function_exists('woo_wallet')) {
            return;
        }
        if (function_exists('is_wallet_account_locked') && is_wallet_account_locked()) {
            woo_wallet()->get_template('no-access.php');
            return;
        }
        // The maintained native statement view queries only the signed-in user's records.
        do_action('woo_wallet_statement_content');
    }

    public static function refine_utility_content($content) {
        if (is_page('plan-select') && strpos($content, 'threepm-plans-grid') !== false) {
            return self::format_plan_markup($content, false);
        }
        return $content;
    }

    public static function plan_benefits_shortcode() {
        if (!shortcode_exists('threepm_plans')) {
            return '<p>Membership details are currently unavailable.</p>';
        }
        return self::format_plan_markup(do_shortcode('[threepm_plans]'), true);
    }

    private static function format_plan_markup($content, $with_benefits) {
        if (!class_exists('DOMDocument')) {
            return $content;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument('1.0', 'UTF-8');
            $doc->loadHTML('<?xml encoding="UTF-8"><div id="pm-plan-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $xpath = new DOMXPath($doc);
            $cards = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," threepm-plan-card ")]');
            foreach ($cards as $card) {
                $price = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," threepm-plan-price ")]', $card)->item(0);
                if ($price) {
                    $alternative = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," price-alt ")]', $price)->item(0);
                    if ($alternative && $price->firstChild) {
                        $amount = trim($price->firstChild->textContent);
                        $alternative->parentNode->removeChild($alternative);
                        while ($price->firstChild) {
                            $price->removeChild($price->firstChild);
                        }
                        $line = $doc->createElement('span');
                        $line->setAttribute('class', 'pm-plan-price-line');
                        $value = $doc->createElement('span', $amount);
                        $value->setAttribute('class', 'pm-plan-amount');
                        $period = $doc->createElement('small', 'per month');
                        $period->setAttribute('class', 'pm-plan-period');
                        $line->appendChild($value);
                        $line->appendChild($period);
                        $price->appendChild($line);
                        $price->appendChild($alternative);
                    }
                }
                if ($with_benefits) {
                    $name = $xpath->query('.//h3', $card)->item(0);
                    $label = $name ? $name->textContent : '';
                    $vault = strpos($label, 'Diamond') !== false ? 'Diamond' : (strpos($label, 'Gold') !== false ? 'Gold' : (strpos($label, 'Silver') !== false ? 'Silver' : 'Meme'));
                    $list = $doc->createElement('ul');
                    $list->setAttribute('class', 'pm-plan-features');
                    foreach (array(
                        $vault . ' Vault access under this membership level.',
                        'A personal 3PM account and wallet.',
                        'Explore music and community experiences across the ecosystem.',
                    ) as $benefit) {
                        $list->appendChild($doc->createElement('li', $benefit));
                    }
                    $button = $xpath->query('.//a[contains(concat(" ",normalize-space(@class)," ")," button ")]', $card)->item(0);
                    $card->insertBefore($list, $button);
                    $card->setAttribute('class', $card->getAttribute('class') . ' pm-plan-benefits-card');
                }
            }
            if ($with_benefits) {
                foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," threepm-plans-footer ")]') as $footer) {
                    $footer->parentNode->removeChild($footer);
                }
            }
            $root = $xpath->query('//*[@id="pm-plan-root"]')->item(0);
            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $doc->saveHTML($child);
            }
            return $output;
        } catch (Throwable $error) {
            return $content;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function coin_product_intro() {
        global $product;
        if (!$product || (int) $product->get_id() !== 929) {
            return;
        }
        $rate = class_exists('ThreePM_Suite') && is_callable(array('ThreePM_Suite', 'usd_per_coin')) ? (float) ThreePM_Suite::usd_per_coin() : null;
        echo '<div class="pm-coin-purchase-intro"><span class="pm-exchange-label">3PM WALLET / ECOSYSTEM ACCESS</span><p>Fund your connection to independent music. Choose a coin package and use your balance across releases, memberships and experiences.</p>';
        if ($rate !== null) {
            echo '<div class="pm-platform-rate"><span>Current platform rate</span><strong>1 $3PM <span>=</span> ' . esc_html(number_format_i18n($rate, 2)) . ' USD</strong></div>';
        }
        echo '</div>';
    }

    public static function coin_payment_options() {
        global $product;
        if (!$product || (int) $product->get_id() !== 929) {
            return;
        }
        // These currencies were verified in the site's active MyCryptoCheckout configuration.
        echo '<div class="pm-coin-payment"><span class="pm-exchange-label">CRYPTO CHECKOUT</span><div class="pm-payment-currencies">';
        foreach (array('BTC', 'ETH', 'SOL', 'USDC', 'USDT', 'BNB', 'DOGE', 'XRP') as $currency) {
            echo '<span>' . esc_html($currency) . '</span>';
        }
        echo '</div><p>Pay through MyCryptoCheckout. Available currencies and networks are confirmed at checkout.</p><div class="pm-card-payment-preview"><span class="pm-exchange-label">CARD PAYMENTS</span><div class="pm-card-graphics"><span class="pm-card-brand pm-card-visa" aria-label="Visa">VISA</span><span class="pm-card-brand" aria-label="Mastercard"><svg viewBox="0 0 40 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="15" cy="12" r="9"/><circle cx="25" cy="12" r="9"/></svg></span><span class="pm-card-brand pm-card-amex" aria-label="American Express">AMERICAN<br>EXPRESS</span></div></div></div><div class="pm-coin-purchase-steps"><span><b>01</b> Choose your package</span><span><b>02</b> Complete checkout</span><span><b>03</b> Use your 3PM wallet</span></div>';
    }

    public static function refine_empty_cart($content) {
        return preg_replace(
            '/(<h2[^>]*class="[^"]*wc-block-cart__empty-cart__title[^"]*"[^>]*>).*?(<\/h2>)/s',
            '$1Your next discovery is waiting.$2<p class="pm-empty-cart-copy">Explore new music below and add something you love.</p>',
            $content,
            1
        );
    }

    public static function prioritize_roster($posts, $query) {
        if (is_admin() || !is_page('artists')) {
            return $posts;
        }
        foreach ($posts as $index => $post) {
            if ($post->post_name === 'laszlo-van-leeuwen') {
                array_splice($posts, $index, 1);
                array_unshift($posts, $post);
                break;
            }
        }
        return $posts;
    }

    public static function interaction_script() {
        echo '<script id="threepm-presentation-interactions">';
        echo <<<'THREEPM_JS'
(function () {
    'use strict';
    if (document.documentElement.dataset.pmInteractions) return;
    document.documentElement.dataset.pmInteractions = 'ready';
    // Some legacy page scripts strip the review tab link after rendering.
    // Restore only that missing anchor, leaving WooCommerce's panel/form behavior native.
    function ensureReviewTab() {
        var tab = document.querySelector('#qwjquerycontent #tab-title-reviews');
        if (!tab || tab.querySelector('a')) return;
        var link = document.createElement('a');
        link.href = '#tab-reviews';
        link.setAttribute('role', 'tab');
        link.setAttribute('aria-controls', 'tab-reviews');
        link.setAttribute('aria-selected', tab.classList.contains('active') ? 'true' : 'false');
        link.tabIndex = tab.classList.contains('active') ? 0 : -1;
        link.textContent = tab.textContent.trim();
        tab.textContent = '';
        tab.appendChild(link);
    }
    // Keep the hero's native playlist action and also reveal the next homepage section.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.pm-original-hero a[data-addtoplaylyst]');
        if (!button) return;
        var target = document.querySelector('.pm-home .pm-manifesto');
        if (!target) return;
        event.preventDefault();
        var started = Date.now();
        function revealProject() {
            var updating = document.getElementById('qwUpdatingPlaylist');
            var busy = updating && window.getComputedStyle(updating).display !== 'none';
            // The native playlist action finishes by returning to the top. Scroll after it settles.
            if (Date.now() - started < 1400 || (busy && Date.now() - started < 6000)) {
                window.setTimeout(revealProject, 150);
                return;
            }
            if (window.jQuery) window.jQuery('html, body').stop(true);
            var offset = window.matchMedia('(max-width:767px)').matches ? 68 : 80;
            window.scrollTo({ top: Math.max(0, target.getBoundingClientRect().top + window.scrollY - offset), behavior: window.matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth' });
        }
        window.setTimeout(revealProject, 150);
    }, true);
    function ensureReleaseDescription() {
        var page = document.querySelector('#qwjquerycontent');
        if (!page || !page.querySelector('.dhrk-pageheader--release')) return;
        var content = page.querySelector('.dhrk-content');
        if (!content || content.querySelector('.pm-release-description')) return;
        var blocks = content.querySelectorAll(':scope > .dhrk-content__featured');
        var artwork = blocks[0];
        var tracks = Array.from(blocks).find(function (block) { return block.querySelector('.qw-track'); });
        if (!artwork || !tracks || artwork === tracks) return;
        var description = document.createElement('div');
        description.className = 'pm-release-description';
        var next = tracks.nextSibling;
        while (next) {
            if (next.nodeType === 1 && next.classList.contains('dhrk-prevnext')) break;
            var following = next.nextSibling;
            description.appendChild(next);
            next = following;
        }
        artwork.appendChild(description);
    }
    var classifiedButtons = new WeakSet();
    function ensureButtonHoverPalette() {
        document.querySelectorAll('#qwjquerycontent a.vice-btn, #qwjquerycontent a.elementor-button, #qwjquerycontent a.button, #qwjquerycontent button.button, #qwjquerycontent input[type="submit"], #qwjquerycontent a.pm-public-button, #qwjquerycontent .pm-join-actions > a, #qwjquerycontent a[data-addtoplaylyst], #qwjquerycontent a.threepm-plans-more').forEach(function (button) {
            if (classifiedButtons.has(button)) return;
            classifiedButtons.add(button);
            var color = window.getComputedStyle(button).backgroundColor;
            if (color !== 'rgb(202, 255, 64)' && color !== 'rgba(202, 255, 64, 1)') {
                button.classList.add('pm-hover-brand');
            }
        });
    }
    var observedPlayers = new WeakSet();
    function trackSource(link) {
        try { return new URL(link.getAttribute('data-playtrack') || '', location.href).href; } catch (error) { return ''; }
    }
    function ensureReleaseTrackState() {
        var player = document.getElementById('qwPlayerPlay');
        if (!player) return;
        var source = player.getAttribute('data-mp3');
        var playing = player.getAttribute('data-state') === 'play';
        document.querySelectorAll('#qwjquerycontent .qw-track-play .playable-mp3-link').forEach(function (link) {
            var active = playing && trackSource(link) === source;
            var state = active ? 'true' : 'false';
            if (link.getAttribute('aria-pressed') !== state) link.setAttribute('aria-pressed', state);
            if (link.getAttribute('data-pm-playing') !== state) link.setAttribute('data-pm-playing', state);
            var label = (active ? 'Pause ' : 'Play ') + (link.getAttribute('data-title') || 'track');
            if (link.getAttribute('aria-label') !== label) link.setAttribute('aria-label', label);
            var icon = link.querySelector('span');
            var iconClass = active ? 'qticon-pause' : 'qticon-caret-right';
            if (icon && icon.className !== iconClass) icon.className = iconClass;
        });
        if (!observedPlayers.has(player)) {
            observedPlayers.add(player);
            new MutationObserver(ensureReleaseTrackState).observe(player, { attributes: true, attributeFilter: ['data-state', 'data-mp3'], childList: true, subtree: true });
        }
    }
    document.addEventListener('click', function (event) {
        var link = event.target.closest('#qwjquerycontent .qw-track-play .playable-mp3-link');
        var player = document.getElementById('qwPlayerPlay');
        if (!link || !player || !player.getAttribute('data-mp3')) return;
        if (trackSource(link) !== player.getAttribute('data-mp3')) return;
        // The active row controls the existing player instead of restarting the same track.
        event.preventDefault();
        event.stopImmediatePropagation();
        player.click();
        ensureReleaseTrackState();
    }, true);
    var themedAmountFields = new WeakSet();
    function ensureAmountField() {
        var field = document.querySelector('#qwjquerycontent input[name="woo_wallet_transfer_amount"]');
        if (!field) return;
        function paint() {
            var colors = { 'background-color': '#0a130d', 'color': '#edf3e9', '-webkit-text-fill-color': '#edf3e9', 'caret-color': '#caff40' };
            Object.keys(colors).forEach(function (key) {
                // Style values normalize hex to rgb, so compare through a temporary style object.
                var probe = document.createElement('span');
                probe.style.setProperty(key, colors[key], 'important');
                if (field.style.getPropertyValue(key) !== probe.style.getPropertyValue(key) || field.style.getPropertyPriority(key) !== 'important') {
                    field.style.setProperty(key, colors[key], 'important');
                }
            });
        }
        paint();
        if (!themedAmountFields.has(field)) {
            themedAmountFields.add(field);
            new MutationObserver(paint).observe(field, { attributes: true, attributeFilter: ['style'] });
        }
    }
    function ensureHomeHero() {
        var hero = document.querySelector('#qwjquerycontent .pm-home [data-id="88d92fd"]');
        if (!hero) return;
        hero.classList.add('pm-original-hero');
        // AJAX returns may insert the column before Elementor's entrance observer is ready.
        // The hero must remain readable without that optional entrance animation.
        var column = hero.querySelector('[data-id="28b860b"]');
        if (column) column.classList.remove('elementor-invisible');
    }
    function repairPresentation() { ensureHomeHero(); ensureReviewTab(); ensureAmountField(); ensureReleaseDescription(); ensureReleaseTrackState(); ensureButtonHoverPalette(); }
    repairPresentation();
    document.addEventListener('DOMContentLoaded', repairPresentation);
    new MutationObserver(repairPresentation).observe(document.body, { childList: true, subtree: true });
    var motions = new WeakMap();
    function move(item, expand) {
        var state = motions.get(item);
        var start = item.getBoundingClientRect().height;
        if (state && state.animation) state.animation.cancel();
        item.style.height = '';
        item.style.overflow = '';
        item.open = true;
        var end = expand ? item.getBoundingClientRect().height : item.querySelector('summary').getBoundingClientRect().height + item.offsetHeight - item.clientHeight;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !item.animate) {
            item.open = expand;
            motions.set(item, { expanded: expand });
            return;
        }
        item.style.height = start + 'px';
        item.style.overflow = 'hidden';
        var animation = item.animate([{ height: start + 'px' }, { height: end + 'px' }], { duration: 420, easing: 'cubic-bezier(.22,1,.36,1)' });
        motions.set(item, { expanded: expand, animation: animation });
        animation.onfinish = function () {
            if (motions.get(item).animation !== animation) return;
            item.open = expand;
            item.style.height = '';
            item.style.overflow = '';
            motions.set(item, { expanded: expand });
        };
    }
    function selectTab(button, focus) {
        var section = button.closest('.pm-coin-how');
        var current = section.querySelector('.pm-audience-card:not([hidden])');
        var next = document.getElementById(button.getAttribute('aria-controls'));
        if (!next || current === next) { if (focus) button.focus(); return; }
        var startHeight = section.getBoundingClientRect().height;
        section.getAnimations().forEach(function (motion) { motion.cancel(); });
        section.querySelectorAll('[role="tab"]').forEach(function (tab) {
            var chosen = tab === button;
            tab.setAttribute('aria-selected', chosen ? 'true' : 'false');
            tab.tabIndex = chosen ? 0 : -1;
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) { panel.getAnimations().forEach(function (motion) { motion.cancel(); }); panel.hidden = !chosen; }
        });
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && next.animate) {
            next.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'translateY(0)' }], { duration: 240, easing: 'cubic-bezier(.22,1,.36,1)' });
        }
        if (focus) button.focus();
    }
    document.addEventListener('click', function (event) {
        var summary = event.target.closest('.elementor-879 .pm-model-item > summary');
        if (summary) {
            event.preventDefault();
            var item = summary.parentElement;
            var state = motions.get(item);
            var expand = !(state ? state.expanded : item.open);
            item.closest('.pm-model-list').querySelectorAll('.pm-model-item').forEach(function (other) {
                other.removeAttribute('name');
                if (other !== item && other.open) move(other, false);
            });
            move(item, expand);
            return;
        }
        var tab = event.target.closest('.pm-audience-switch [role="tab"]');
        if (tab) selectTab(tab, false);
    });
    document.addEventListener('keydown', function (event) {
        var tab = event.target.closest('.pm-audience-switch [role="tab"]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        var buttons = Array.from(tab.parentElement.querySelectorAll('[role="tab"]'));
        var index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (buttons.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length;
        selectTab(buttons[index], true);
    });
    // Keep native AJAX fragments hidden until their newly inserted styles are loaded.
    var navigationTimer, navigationStyles = new Set();
    function revealNavigation() {
        var links = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).filter(function (link) { return !navigationStyles.has(link) && !link.sheet && link.media !== 'print'; });
        var loads = links.map(function (link) { return new Promise(function (resolve) { link.addEventListener('load', resolve, { once: true }); link.addEventListener('error', resolve, { once: true }); }); });
        Promise.all(loads).then(function () { requestAnimationFrame(function () { requestAnimationFrame(function () { document.documentElement.classList.remove('pm-navigation-pending'); clearTimeout(navigationTimer); discoverLivelyElements(); }); }); });
    }
    document.addEventListener('click', function (event) {
        var a = event.target.closest('a[href]');
        if (!a || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || a.target === '_blank' || a.hasAttribute('download') || a.classList.contains('qwNoAjax') || a.parentElement.classList.contains('qwNoAjaxChild')) return;
        if (document.body.getAttribute('data-qwajx') !== '1' || !a.closest('#vice-ajaxcontents,#nav,#qwMusicPlaylist,#QWplayerbar,#qwFixedHeader,#qwModalContent')) return;
        var url; try { url = new URL(a.href, location.href); } catch (error) { return; }
        if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search) || /wp-admin|checkout|shop|cart|download_file=|\/product|\.(zip|jpe?g|gif|mp3|png|rar)(\?|$)/i.test(url.href)) return;
        navigationStyles = new Set(document.querySelectorAll('link[rel="stylesheet"]'));
        document.documentElement.classList.add('pm-navigation-pending');
        clearTimeout(navigationTimer); navigationTimer = setTimeout(function () { document.documentElement.classList.remove('pm-navigation-pending'); }, 8000);
    }, true);
    if (window.jQuery) window.jQuery(window).on('qtAjaxpageLoadEnd.threepmPresentation', revealNavigation);

    // Recalculate illustrative holdings without sending visitor inputs anywhere.
    function updateReturnCalculator(input) {
        var root = input.closest('.pm-return-section'); if (!root) return;
        var amount = Number(input.value), valid = input.value.trim() !== '' && Number.isFinite(amount) && amount >= 1 && amount <= 1000000000;
        input.setAttribute('aria-invalid', valid ? 'false' : 'true');
        root.querySelector('#pm-return-error').textContent = valid ? '' : 'Enter an amount from $1 to $1,000,000,000. Figures below use your last valid amount.';
        if (!valid) return;
        var dollars = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
        var quantity = amount / .05;
        root.querySelector('[data-pm-token-count]').textContent = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(quantity);
        root.querySelector('[data-pm-revenue-cap]').textContent = dollars.format(amount * 5);
        root.querySelectorAll('[data-pm-price]').forEach(function (row) {
            var value = quantity * Number(row.dataset.pmPrice), gain = value - amount;
            row.querySelector('[data-pm-value]').textContent = dollars.format(value);
            row.querySelector('[data-pm-gain]').textContent = (gain < 0 ? '−' : gain > 0 ? '+' : '') + dollars.format(Math.abs(gain));
        });
    }
    document.addEventListener('input', function (event) { if (event.target.id === 'pm-investment-amount') updateReturnCalculator(event.target); });
    var tip, tipTarget;
    function hideTip() { if (tip) tip.hidden = true; tipTarget = null; }
    function showTip(target) {
        if (!target || !target.dataset.pmTip || matchMedia('(hover:none)').matches) return;
        if (!tip) { tip = document.createElement('div'); tip.id = 'pm-interaction-tooltip'; tip.className = 'pm-interaction-tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); }
        tipTarget = target; tip.textContent = target.dataset.pmTip; tip.hidden = false;
        var box = target.getBoundingClientRect(), rect = tip.getBoundingClientRect();
        tip.style.left = Math.max(8, Math.min(innerWidth - rect.width - 8, box.left + (box.width - rect.width) / 2)) + 'px';
        tip.style.top = (box.top > rect.height + 16 ? box.top - rect.height - 10 : box.bottom + 10) + 'px';
    }
    document.addEventListener('pointerover', function (event) { var target = event.target.closest('[data-pm-tip]'); if (target && target !== tipTarget) showTip(target); });
    document.addEventListener('pointerout', function (event) { if (tipTarget && !tipTarget.contains(event.relatedTarget)) hideTip(); });
    document.addEventListener('focusin', function (event) { showTip(event.target.closest('[data-pm-tip]')); });
    document.addEventListener('focusout', hideTip);
    document.addEventListener('scroll', hideTip, true);
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') hideTip(); });
    // Preserve the complete card surface beneath the moving highlight.
    function rememberGlowSurface(tile, style) {
        if (tile.dataset.pmGlowSurface) return;
        style = style || getComputedStyle(tile);
        tile.style.setProperty('--pm-glow-base-image', style.backgroundImage);
        tile.style.setProperty('--pm-glow-base-color', style.backgroundColor);
        tile.dataset.pmGlowSurface = 'ready';
    }
    function labelPresentationControls() {
        document.querySelectorAll('#qwjquerycontent [data-addtoplaylyst]').forEach(function (el) { if (!el.hasAttribute('aria-label')) { var card = el.closest('.vice-post,.vice-modernslider-item'); var heading = card && card.querySelector('h2,h3,h4'); el.setAttribute('aria-label', 'Add ' + (heading ? heading.textContent.trim() : 'release') + ' to playlist'); } });
        document.querySelectorAll('#qwjquerycontent .gallery-item a:has(img)').forEach(function (el) { if (!el.hasAttribute('aria-label')) el.setAttribute('aria-label', 'View image: ' + (el.querySelector('img').alt || 'gallery photograph')); });
        var labels = { qwMenuToggle: 'Open menu', qwShareall: 'Share this page', qwPlayerPlay: 'Play / pause', qwPlayerPrev: 'Previous track', qwPlayerNext: 'Next track', playlistOpener: 'Show / hide playlist', qwPlayerVolume: 'Volume', qwPlayerVolumeMute: 'Mute audio', qwPlayerVolumeUnmute: 'Unmute audio', qwHidePl: 'Show / hide player' };
        Object.keys(labels).forEach(function (id) { var el = document.getElementById(id); if (!el) return; if (el.getAttribute('aria-label') !== labels[id]) el.setAttribute('aria-label', labels[id]); if (!el.matches('.qw-tooltip,[data-toggle="tooltip"]')) el.dataset.pmTip = labels[id]; if (!el.hasAttribute('href')) { el.tabIndex = 0; el.setAttribute('role', 'button'); } });
        document.querySelectorAll('#qwjquerycontent [data-playtrack]').forEach(function (el) { if (!el.getAttribute('aria-label')) el.setAttribute('aria-label', 'Play ' + (el.getAttribute('data-title') || 'track')); });
        document.querySelectorAll('#qwjquerycontent .e-child,#qwjquerycontent .vice-post,#qwjquerycontent .vice-modernslider-item,#qwjquerycontent li.product,#qwjquerycontent .pm-plan-benefits-card,#qwjquerycontent .pm-financial-card,#qwjquerycontent .pm-table-scroll,#qwjquerycontent .pm-coin-section').forEach(function (tile) {
            if (tile.matches('.pm-coin-overview,.pm-token-donut') || tile.querySelector('video,canvas')) return;
            var style = getComputedStyle(tile), rgb = style.backgroundColor.match(/[\d.]+/g);
            if (rgb && (rgb.length < 4 || Number(rgb[3]) > 0) && Number(rgb[1]) > Number(rgb[0]) * 1.08 && Number(rgb[1]) > Number(rgb[2]) * 1.03 && parseFloat(style.borderRadius) >= 8) { rememberGlowSurface(tile, style); tile.classList.add('pm-lively-tile', 'pm-glow-tile'); }
        });
        document.querySelectorAll('#qwjquerycontent .pm-lively-tile').forEach(function (el) { rememberGlowSurface(el); el.classList.add('pm-glow-tile'); });
    }
    document.addEventListener('keydown', function (event) { var el = event.target; if ((event.key === 'Enter' || event.key === ' ') && el.matches('[data-pm-tip][role="button"]:not([href])')) { event.preventDefault(); el.click(); } });
    var livelySeen = new WeakSet(), waveHost, wavePath, waveFrame = 0, amplitude = 0, targetAmplitude = 0, phase = 0, cursorX = 0, cursorY = 0, cursorTime = 0;
    var motionAllowed = function () { return !window.matchMedia('(prefers-reduced-motion: reduce)').matches; };
    var arrivals = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { if (!entry.isIntersecting) return; arrivals.unobserve(entry.target); if (motionAllowed() && entry.target.animate) entry.target.animate([{ opacity: .65, transform: 'translateY(9px)' }, { opacity: 1, transform: 'translateY(0)' }], { duration: 620, easing: 'cubic-bezier(.22,1,.36,1)' }); });
    }, { threshold: .08 }) : null;
    function drawWave(now) {
        waveFrame = 0;
        if (!waveHost || !waveHost.isConnected || !wavePath) return;
        if (!motionAllowed()) { amplitude = targetAmplitude = 0; }
        if (now - cursorTime > 55) targetAmplitude *= .8;
        amplitude += (targetAmplitude - amplitude) * .16;
        if (Math.abs(amplitude) < .025 && Math.abs(targetAmplitude) < .025) amplitude = targetAmplitude = 0;
        phase += amplitude ? .045 + Math.abs(amplitude) * .009 : 0;
        var height = waveHost.getBoundingClientRect().height, points = [], steps = Math.max(40, Math.ceil(height / 6));
        for (var i = 0; i <= steps; i++) { var t = i / steps, y = t * height, envelope = Math.sin(Math.PI * t), x = 17 + Math.sin(t * Math.PI * 6 + phase) * amplitude * envelope; points.push((i ? 'L' : 'M') + x.toFixed(2) + ',' + y.toFixed(2)); }
        wavePath.setAttribute('d', points.join(' '));
        wavePath.parentElement.setAttribute('viewBox', '0 0 34 ' + height);
        if (amplitude || targetAmplitude) waveFrame = requestAnimationFrame(drawWave);
    }
    var donutTip;
    function hideDonutTip() { if (donutTip) donutTip.classList.remove('is-visible'); }
    function enhanceDonutCharts() {
        document.querySelectorAll('#qwjquerycontent .pm-token-donut:not(.pm-donut-interactive)').forEach(function (chart, chartIndex) {
            var layout = chart.closest('.pm-donut-layout');
            var legend = layout && layout.querySelectorAll('.pm-chart-legend li');
            if (!legend || legend.length !== 8) return;
            var parts = Array.from(legend).map(function (row) { return { label: row.querySelector('span').textContent.trim(), share: parseFloat(row.querySelector('strong').textContent), color: getComputedStyle(row.querySelector('i')).backgroundColor }; });
            if (parts.some(function (part) { return !Number.isFinite(part.share); }) || parts.reduce(function (sum, part) { return sum + part.share; }, 0) !== 100) return;
            var ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('class', 'pm-donut-svg'); svg.setAttribute('viewBox', '0 0 100 100');
            var angle = -Math.PI / 2;
            function point(radius, a) { return (50 + radius * Math.cos(a)).toFixed(4) + ' ' + (50 + radius * Math.sin(a)).toFixed(4); }
            parts.forEach(function (part, index) {
                var end = angle + part.share / 100 * Math.PI * 2, middle = (angle + end) / 2;
                var d = 'M ' + point(50, angle) + ' A 50 50 0 0 1 ' + point(50, end) + ' L ' + point(33, end) + ' A 33 33 0 0 0 ' + point(33, angle) + ' Z';
                var group = document.createElementNS(ns, 'g');
                group.setAttribute('class', 'pm-donut-slice'); group.setAttribute('tabindex', '0'); group.setAttribute('role', 'img'); group.setAttribute('aria-label', part.label + ': ' + part.share + '%');
                group.style.setProperty('--pm-slice-x', (Math.cos(middle) * 3.5).toFixed(2) + 'px');
                group.style.setProperty('--pm-slice-y', (Math.sin(middle) * 3.5 - 2).toFixed(2) + 'px');
                ['hit', 'depth', 'face'].forEach(function (kind) { var path = document.createElementNS(ns, 'path'); path.setAttribute('d', d); path.setAttribute('class', 'pm-slice-' + kind); path.setAttribute('fill', kind === 'hit' ? 'transparent' : part.color); if (kind === 'hit') path.setAttribute('pointer-events', 'all'); group.appendChild(path); });
                function show(event) {
                    group.classList.add('is-active'); legend[index].classList.add('pm-legend-active');
                    if (!donutTip) { donutTip = document.createElement('div'); donutTip.id = 'pm-donut-tooltip'; donutTip.className = 'pm-donut-tip'; donutTip.setAttribute('role', 'tooltip'); document.body.appendChild(donutTip); }
                    var tipText = part.label + ' · ' + part.share + '%'; if (donutTip.textContent !== tipText) donutTip.textContent = tipText;
                    var r = group.getBoundingClientRect(), tipWidth = donutTip.getBoundingClientRect().width;
                    var x = event && typeof event.clientX === 'number' ? event.clientX : r.left + r.width / 2;
                    var y = event && typeof event.clientY === 'number' ? event.clientY : r.top;
                    donutTip.style.left = Math.max(8, Math.min(innerWidth - tipWidth - 8, x - tipWidth / 2)) + 'px';
                    donutTip.style.top = Math.max(8, y - donutTip.getBoundingClientRect().height - 16) + 'px';
                    donutTip.classList.add('is-visible'); group.setAttribute('aria-describedby', 'pm-donut-tooltip');
                }
                function hide() { group.classList.remove('is-active'); legend[index].classList.remove('pm-legend-active'); group.removeAttribute('aria-describedby'); hideDonutTip(); }
                group.addEventListener('pointerenter', show); group.addEventListener('pointermove', show); group.addEventListener('pointerleave', hide);
                group.addEventListener('focus', show); group.addEventListener('blur', hide); group.addEventListener('keydown', function (event) { if (event.key === 'Escape') hide(); });
                svg.appendChild(group); angle = end;
            });
            chart.insertBefore(svg, chart.firstChild); chart.classList.add('pm-donut-interactive'); chart.setAttribute('role', 'group'); chart.setAttribute('aria-label', 'Proposed token allocation. Focus or hover over a segment for its title and percentage.');
        });
    }
    document.addEventListener('scroll', hideDonutTip, true);
    function discoverLivelyElements() {
        enhanceDonutCharts();
        labelPresentationControls();
        var host = document.querySelector('.elementor-879 .pm-coin-overview');
        if (host && !host.querySelector('.pm-cursor-wave')) {
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('class', 'pm-cursor-wave'); svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('preserveAspectRatio', 'none');
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path'); svg.appendChild(path); host.appendChild(svg); host.classList.add('pm-wave-ready'); waveHost = host; wavePath = path; amplitude = targetAmplitude = 0; drawWave(performance.now());
            if ('ResizeObserver' in window) { var sizeObserver = new ResizeObserver(function () { if (!host.isConnected) { sizeObserver.disconnect(); return; } if (!waveFrame) waveFrame = requestAnimationFrame(drawWave); }); sizeObserver.observe(host); }
        }
        document.querySelectorAll('.pm-card,.pm-investor-model article,.pm-investor-art,.pm-artist-story,.pm-coin-difference>ul>li,.pm-artist-identity,.pm-investor-metrics>div,.pm-financial-card,.pm-investor-contact,.pm-investor-capabilities>div,.pm-coin-contrast,.pm-audience-card,.pm-coin-tokenomics,.pm-coin-join,.pm-return-calculator,.pm-plan-benefits-card,.pm-home .pm-visual,.pm-home .pm-coin-panel,#qwjquerycontent li.product').forEach(function (tile) {
            if (livelySeen.has(tile)) return; livelySeen.add(tile); tile.classList.add('pm-lively-tile'); if (arrivals) arrivals.observe(tile);
            if (tile.matches('a.pm-investor-art')) tile.setAttribute('data-pm-hover-label', 'Explore Jungle Boy ↗');
        });
    }
    document.addEventListener('pointermove', function (event) {
        if (event.pointerType === 'touch' || !motionAllowed()) return;
        var now = performance.now(), elapsed = Math.max(8, now - cursorTime), dx = event.clientX - cursorX, dy = event.clientY - cursorY;
        if (cursorTime && elapsed < 160 && waveHost && waveHost.isConnected) { var box = waveHost.getBoundingClientRect(); if (box.bottom > 0 && box.top < innerHeight) targetAmplitude = Math.min(13, Math.hypot(dx, dy) / elapsed * 11); phase += dx * .008 + dy * .004; if (!waveFrame) waveFrame = requestAnimationFrame(drawWave); }
        cursorX = event.clientX; cursorY = event.clientY; cursorTime = now;
        var tile = event.target.closest('.pm-lively-tile'); if (tile) { var r = tile.getBoundingClientRect(); tile.style.setProperty('--pm-pointer-x', (event.clientX - r.left) + 'px'); tile.style.setProperty('--pm-pointer-y', (event.clientY - r.top) + 'px'); }
    }, { passive: true });
    var livelyQueued = false;
    new MutationObserver(function (records) { if (livelyQueued || !records.some(function (record) { return record.addedNodes.length; })) return; livelyQueued = true; requestAnimationFrame(function () { livelyQueued = false; discoverLivelyElements(); }); }).observe(document.getElementById('canvas') || document.body, { childList: true, subtree: true });
    discoverLivelyElements();

})();
THREEPM_JS;
        echo '</script>';
    }

    public static function body_class($classes) {
        if (get_post_meta(get_queried_object_id(), self::MARKER, true)) {
            $classes[] = 'pm-home-enabled';
        }
        return $classes;
    }

    private static function redirect($message, $error = false) {
        set_transient('threepm_homepage_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 120);
        wp_safe_redirect(admin_url('tools.php?page=threepm-homepage'));
        exit;
    }

    private static function action_form($action, $label, $primary = false) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:8px 12px 8px 0">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        submit_button($label, $primary ? 'primary' : 'secondary', 'submit', false);
        echo '</form>';
    }

    public static function screen() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = self::state();
        echo '<div class="wrap"><h1>3PM Homepage</h1>';
        $notice = get_transient('threepm_homepage_notice_' . get_current_user_id());
        if (is_array($notice)) {
            echo '<div class="notice notice-' . (!empty($notice['error']) ? 'error' : 'success') . '"><p>' . esc_html($notice['message']) . '</p></div>';
            delete_transient('threepm_homepage_notice_' . get_current_user_id());
        }
        echo '<p>A new introduction to the project, clear paths for artists and listeners, and a sharper visual direction. The original hero, glitch effects, music player, video, gallery, blog, and contact form remain native Elementor elements.</p>';
        if (empty($state['applied'])) {
            echo '<p>Create a draft preview from the current homepage. Review it in Elementor, then apply it when ready.</p>';
            self::action_form('threepm_homepage_build', empty($state['draft_id']) ? 'Create homepage preview' : 'Rebuild preview from current homepage', true);
            if (!empty($state['draft_id']) && get_post($state['draft_id'])) {
                $preview = get_preview_post_link($state['draft_id']);
                echo '<p><a class="button" href="' . esc_url($preview) . '" target="_blank" rel="noopener">Open preview</a> ';
                echo '<a class="button" href="' . esc_url(admin_url('post.php?post=' . (int) $state['draft_id'] . '&action=elementor')) . '">Edit in Elementor</a></p>';
                self::action_form('threepm_homepage_apply', 'Apply preview to homepage');
            }
        } else {
            echo '<p>The redesign is applied. <a href="' . esc_url(get_permalink($state['home_id'])) . '">Open homepage</a>.</p>';
            self::action_form('threepm_homepage_restore', 'Restore original homepage');
        }
        echo '<p><small>Installing or activating this plugin does not change page content. Deactivating it removes its stylesheet; restore the original here before uninstalling if you want the previous layout back.</small></p></div>';
    }
}

ThreePM_Homepage_Rebuild::init();

require_once __DIR__ . '/artist-profiles.php';
register_deactivation_hook(__FILE__, static function () { wp_clear_scheduled_hook('threepm_artist_press_refresh'); });

/** Omit the homepage featured video while keeping its editable source recoverable. */
add_filter('elementor/frontend/the_content', static function ($content) {
    if ((int) get_the_ID() !== 539 || !class_exists('DOMDocument') || strpos($content, 'elementor-element-9a103e5') === false) return $content;
    if (class_exists('\\Elementor\\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode()) return $content;
    $previous = libxml_use_internal_errors(true);
    try {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="pm-home-cleanup-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($doc);
        foreach ($xpath->query('//*[@data-id="9a103e5"]') as $section) $section->parentNode->removeChild($section);
        $root = $xpath->query('//*[@id="pm-home-cleanup-root"]')->item(0);
        if (!$root) return $content;
        $result = '';
        foreach ($root->childNodes as $node) $result .= $doc->saveHTML($node);
        return $result;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
}, 110);

/** Contextual line icon for the homepage service areas, including AJAX visits. */
add_action('wp_footer', static function () {
    ?>
<script id="pm-service-icon-interaction">
(function () {
    var paths = [
        '<rect x="25" y="9" width="14" height="30" rx="7"/><path d="M19 29v3a13 13 0 0 0 26 0v-3M32 45v10M24 55h16M48 12v12M42 18h12"/>',
        '<path d="M15 10v16m0 10v18M32 10v28m0 10v6M49 10v8m0 10v26"/><rect x="10" y="26" width="10" height="10" rx="2"/><rect x="27" y="38" width="10" height="10" rx="2"/><rect x="44" y="18" width="10" height="10" rx="2"/>',
        '<circle cx="23" cy="21" r="7"/><circle cx="45" cy="25" r="6"/><path d="M9 51v-5a14 14 0 0 1 28 0v5M40 38a12 12 0 0 1 16 11v2M13 12l-5-4M37 12l5-5M30 7V3"/>',
        '<circle cx="32" cy="32" r="23"/><path d="m41 23-5 13-13 5 5-13 13-5ZM32 4v8M60 32h-8M32 60v-8M4 32h8"/>'
    ];
    function setup() {
        document.querySelectorAll('.pm-home .pm-services').forEach(function (section) {
            if (section.dataset.pmServiceIcons) return;
            var label = section.querySelector('.pm-eyebrow');
            var cards = section.querySelectorAll('.pm-native-services > .pm-card');
            if (!label || cards.length !== 4) return;
            section.dataset.pmServiceIcons = 'ready';
            var icon = document.createElement('div');
            icon.className = 'pm-service-icon';
            icon.setAttribute('aria-hidden', 'true');
            label.parentNode.insertBefore(icon, label);
            var current = -1;
            function show(index) {
                if (current === index) return;
                var animate = current !== -1;
                current = index;
                icon.dataset.service = String(index + 1);
                icon.innerHTML = '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" focusable="false">' + paths[index] + '</svg>';
                icon.classList.remove('pm-service-icon-glitch');
                if (animate) requestAnimationFrame(function () { icon.classList.add('pm-service-icon-glitch'); });
            }
            cards.forEach(function (card, index) {
                card.tabIndex = 0;
                card.addEventListener('pointerenter', function () { show(index); });
                card.addEventListener('focusin', function () { show(index); });
                card.addEventListener('pointerdown', function () { show(index); });
            });
            show(0);
        });
    }
    setup();
    var queued = false;
    new MutationObserver(function () {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; setup(); });
    }).observe(document.getElementById('qwjquerycontent') || document.body, { childList: true, subtree: true });
})();
</script>
    <?php
}, 40);

/** Green signals travel along the existing homepage graphic's 35px grid. */
add_action('wp_footer', static function () {
    ?>
<script id="pm-grid-signal-interaction">
(function () {
    var reduced = matchMedia('(prefers-reduced-motion: reduce)');
    var fine = matchMedia('(hover: hover) and (pointer: fine)');
    function setup() {
        document.querySelectorAll('.pm-home .pm-visual').forEach(function (host) {
            if (host.dataset.pmGridSignals) return;
            host.dataset.pmGridSignals = 'ready';
            var canvas = document.createElement('canvas');
            canvas.className = 'pm-grid-signals'; canvas.setAttribute('aria-hidden', 'true');
            host.appendChild(canvas);
            var ctx = canvas.getContext('2d');
            if (!ctx) { canvas.remove(); return; }
            var width = 0, height = 0, frame = 0, lastTime = -1000, waves = [], cells = [];
            var step = 35, originX = 34.5, originY = 0;
            function resize() {
                var rect = host.getBoundingClientRect(), ratio = Math.min(devicePixelRatio || 1, 2);
                width = rect.width; height = rect.height; originY = height - 34.5;
                canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            }
            var resizeObserver = new ResizeObserver(resize); resizeObserver.observe(host); resize();
            function ray(x, y, dx, dy, distance, fade) {
                if (distance <= 0 || distance > 245) return;
                var head = Math.min(distance, 210), tail = Math.max(0, distance - 65);
                if (tail >= head) return;
                var x1 = x + dx * tail, y1 = y + dy * tail, x2 = x + dx * head, y2 = y + dy * head;
                var gradient = ctx.createLinearGradient(x1, y1, x2, y2);
                gradient.addColorStop(0, 'rgba(202,255,64,0)');
                gradient.addColorStop(0.7, 'rgba(202,255,64,' + fade * 0.7 + ')');
                gradient.addColorStop(1, 'rgba(202,255,64,' + fade + ')');
                ctx.strokeStyle = gradient; ctx.lineWidth = 1.15;
                ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
            }
            function draw(now) {
                frame = 0; ctx.clearRect(0, 0, width, height);
                if (!host.isConnected) { resizeObserver.disconnect(); return; }
                if (reduced.matches || !fine.matches) { waves = []; cells = []; return; }
                cells = cells.filter(function (cell) { return now - cell.time < 850; });
                cells.forEach(function (cell) {
                    var fade = Math.pow(1 - (now - cell.time) / 850, 2);
                    ctx.fillStyle = 'rgba(202,255,64,' + 0.055 * fade + ')';
                    ctx.fillRect(cell.x, cell.y, step, step);
                    ctx.strokeStyle = 'rgba(202,255,64,' + 0.32 * fade + ')'; ctx.lineWidth = 1;
                    ctx.strokeRect(cell.x, cell.y, step, step);
                });
                waves = waves.filter(function (wave) { return now - wave.time < 1400; });
                ctx.shadowColor = '#caff40'; ctx.shadowBlur = 5;
                waves.forEach(function (wave) {
                    var age = now - wave.time, fade = Math.pow(Math.max(0, 1 - age / 1400), 1.3);
                    for (var branch = -3; branch <= 3; branch++) {
                        var distance = age * 0.23 - Math.abs(branch) * step;
                        var strength = fade * (branch === 0 ? 0.9 : 0.44);
                        ray(wave.x, wave.y + branch * step, 1, 0, distance, strength);
                        ray(wave.x, wave.y + branch * step, -1, 0, distance, strength);
                        ray(wave.x + branch * step, wave.y, 0, 1, distance, strength);
                        ray(wave.x + branch * step, wave.y, 0, -1, distance, strength);
                    }
                });
                ctx.shadowBlur = 0;
                if (waves.length || cells.length) frame = requestAnimationFrame(draw);
            }
            host.addEventListener('pointermove', function (event) {
                if (reduced.matches || !fine.matches || event.pointerType === 'touch') return;
                var now = performance.now(); if (now - lastTime < 80) return; lastTime = now;
                var rect = host.getBoundingClientRect(), x = event.clientX - rect.left, y = event.clientY - rect.top;
                var gx = Math.round((x - originX) / step) * step + originX;
                var gy = Math.round((y - originY) / step) * step + originY;
                waves.push({ x: gx, y: gy, time: now });
                cells.push({ x: Math.floor((x - originX) / step) * step + originX, y: Math.floor((y - originY) / step) * step + originY, time: now });
                if (waves.length > 12) waves.shift(); if (cells.length > 12) cells.shift();
                if (!frame) frame = requestAnimationFrame(draw);
            }, { passive: true });
        });
    }
    setup();
    var queued = false;
    new MutationObserver(function () {
        if (queued) return; queued = true;
        requestAnimationFrame(function () { queued = false; setup(); });
    }).observe(document.getElementById('qwjquerycontent') || document.body, { childList: true, subtree: true });
})();
</script>
    <?php
}, 41);

/** Live line-art coin for the homepage ecosystem illustration. */
add_action('wp_footer', static function () {
    ?>
<script id="pm-live-line-coin">
(function () {
    'use strict';
    var reduced = matchMedia('(prefers-reduced-motion: reduce)'), instances = new Set();
    function mount(widget) {
        var image = widget.querySelector('img'), canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        if (!image || !ctx) return;
        var stage = document.createElement('div');
        stage.className = 'pm-live-coin';
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', 'Spinning 3PM coin in green line art, with a faceted emblem and reeded edge');
        stage.appendChild(canvas); widget.appendChild(stage);
        image.remove(); widget.classList.add('pm-live-coin-ready');
        var size = 520, ratio = 1, frame = 0, elapsed = 0, last = 0, visible = false;
        var angle = -.36, turn = 0, tilt = -.12, dead = false;
        function project(x, y, z) {
            var xx = x * Math.cos(turn) + z * Math.sin(turn);
            var zz = -x * Math.sin(turn) + z * Math.cos(turn);
            var yy = y * Math.cos(tilt) - zz * Math.sin(tilt);
            zz = y * Math.sin(tilt) + zz * Math.cos(tilt);
            var scale = 1.13 * 850 / (850 - zz);
            return { x: 260 + xx * scale, y: 240 + yy * scale };
        }
        function line(points, alpha, width, close) {
            ctx.beginPath();
            points.forEach(function (p, i) { var q = project(p[0], p[1], p[2]); if (i) ctx.lineTo(q.x, q.y); else ctx.moveTo(q.x, q.y); });
            if (close) ctx.closePath();
            ctx.strokeStyle = 'rgba(202,255,64,' + alpha + ')'; ctx.lineWidth = width || 1; ctx.stroke();
        }
        function ring(radius, z, alpha, width) {
            var points = [];
            for (var i = 0; i <= 128; i++) { var a = i / 128 * Math.PI * 2; points.push([Math.cos(a) * radius, Math.sin(a) * radius, z]); }
            line(points, alpha, width);
        }
        function facePath(points, side, alpha, width, close) {
            line(points.map(function (p) { return [p[0] * side, p[1], side * 10]; }), alpha, width, close);
        }
        function glyph(character, x, y, rotation, side, fontSize) {
            function at(a, b) { return project(a * side, b, side * 10.5); }
            var p = at(x, y), a = at(x + Math.cos(rotation), y + Math.sin(rotation)), b = at(x - Math.sin(rotation), y + Math.cos(rotation));
            ctx.save(); ctx.transform(a.x - p.x, a.y - p.y, b.x - p.x, b.y - p.y, p.x, p.y);
            ctx.font = '500 ' + fontSize + 'px Exo, Arial, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.strokeStyle = 'rgba(202,255,64,.86)'; ctx.lineWidth = .65; ctx.strokeText(character, 0, 0); ctx.restore();
        }
        function segment(x, y, vertical, side) {
            var shape = vertical ? [[0,2],[2,0],[5,3],[5,20],[3,23],[0,21]] : [[2,0],[25,0],[28,3],[25,6],[2,6],[0,3]];
            facePath(shape.map(function (p) { return [p[0] + x, p[1] + y]; }), side, .95, 1.1, true);
        }
        function draw() {
            ctx.setTransform(ratio * size / 520, 0, 0, ratio * size / 520, 0, 0);
            ctx.clearRect(0, 0, 520, 520); turn = angle + elapsed / 18000 * Math.PI * 2;
            ctx.lineJoin = 'round'; ctx.lineCap = 'round';
            var width = 30 + 100 * Math.abs(Math.cos(turn));
            var floor = ctx.createRadialGradient(260, 454, 2, 260, 454, width);
            floor.addColorStop(0, 'rgba(202,255,64,.10)'); floor.addColorStop(1, 'rgba(202,255,64,0)');
            ctx.save(); ctx.translate(0, 410); ctx.scale(1, .1); ctx.fillStyle = floor; ctx.fillRect(100, 280, 320, 350); ctx.restore();
            var side = Math.cos(turn) >= 0 ? 1 : -1;
            ring(171, -side * 9, .20, .85);
            for (var i = 0; i < 112; i++) {
                var a = i / 112 * Math.PI * 2, x = Math.cos(a) * 171, y = Math.sin(a) * 171;
                var facing = -Math.cos(a) * Math.sin(turn);
                if (facing > -.12) line([[x,y,-9],[x,y,9]], .25 + Math.max(0, facing) * .45, .9);
            }
            ring(171, side * 9, .90, 1.15); ring(165, side * 10, .60, .85);
            ring(153, side * 10, .48, .8); ring(119, side * 10, .60, .9);
            for (var tick = 0; tick < 72; tick++) {
                var t = tick / 72 * Math.PI * 2;
                facePath([[Math.cos(t)*157,Math.sin(t)*157],[Math.cos(t)*161,Math.sin(t)*161]], side, .32, .75);
            }
            var outline = [[0,-99],[62,-65],[82,-4],[56,64],[0,99],[-56,64],[-82,-4],[-62,-65]];
            facePath(outline, side, .85, 1, true);
            var facets = [[[0,-99],[0,-40]], [[-62,-65],[-45,-35]], [[62,-65],[45,-35]], [[-82,-4],[-65,-35],[0,-40],[65,-35],[82,-4]], [[-56,64],[-46,35],[0,42],[46,35],[56,64]], [[0,99],[0,42]], [[-62,-65],[0,-40],[62,-65]], [[-56,64],[0,99],[56,64]]];
            facets.forEach(function (p) { facePath(p, side, .38, .8); });
            [-29,-3,23].forEach(function (y) { segment(-62, y, false, side); });
            segment(-39,-23,true,side); segment(-39,3,true,side);
            segment(-17,-29,false,side); segment(-17,-3,false,side);
            segment(-17,-23,true,side); segment(-17,3,true,side); segment(6,-23,true,side);
            facePath([[28,29],[28,-29],[35,-29],[44,-8],[53,-29],[60,-29],[60,29],[54,29],[54,-13],[47,7],[41,7],[34,-13],[34,29]],side,.95,1.1,true);
            '3PM'.split('').forEach(function (ch, index) { var a = -Math.PI / 2 + (index - 1) * .16; glyph(ch, Math.cos(a)*137,Math.sin(a)*137,a+Math.PI/2,side,20); });
            var words = '3RD PERSON MUSIC';
            words.split('').forEach(function (ch, index) { var a = Math.PI/2 + ((words.length-1)/2-index)*.112; glyph(ch,Math.cos(a)*137,Math.sin(a)*137,a-Math.PI/2,side,15); });
            [-1,1].forEach(function (direction) { facePath([[direction*132,-3],[direction*138,-3],[direction*138,3],[direction*132,3]],side,.8,.9,true); });
        }
        function allowed() { return visible && !reduced.matches && !document.hidden && !dead; }
        function animate(now) {
            frame = 0; if (!allowed()) { last = 0; return; }
            if (last) elapsed += Math.min(now - last, 80); last = now;
            draw(); frame = requestAnimationFrame(animate);
        }
        function update() {
            if (frame) cancelAnimationFrame(frame); frame = 0; last = 0;
            stage.dataset.motion = allowed() ? 'running' : 'paused';
            draw(); if (allowed()) frame = requestAnimationFrame(animate);
        }
        var resize = new ResizeObserver(function () {
            size = Math.max(1, stage.getBoundingClientRect().width); ratio = Math.min(devicePixelRatio || 1, 2);
            canvas.width = Math.round(size * ratio); canvas.height = Math.round(size * ratio); draw();
        }); resize.observe(stage);
        var intersection = new IntersectionObserver(function (entries) { visible = entries[0].isIntersecting; update(); }, {threshold:.02}); intersection.observe(stage);
        reduced.addEventListener('change',update); document.addEventListener('visibilitychange',update);
        var instance = {widget:widget, destroy:function () {dead=true;if(frame)cancelAnimationFrame(frame);resize.disconnect();intersection.disconnect();reduced.removeEventListener('change',update);document.removeEventListener('visibilitychange',update);}};
        instances.add(instance); draw(); update();
    }
    function setup() {
        instances.forEach(function (instance) { if (!instance.widget.isConnected) { instance.destroy(); instances.delete(instance); } });
        document.querySelectorAll('.pm-home .pm-coin .elementor-widget-image:not(.pm-live-coin-ready)').forEach(mount);
    }
    setup();
    var queued = false;
    new MutationObserver(function () { if(queued)return;queued=true;requestAnimationFrame(function(){queued=false;setup();}); }).observe(document.getElementById('qwjquerycontent') || document.body,{childList:true,subtree:true});
})();

</script>
    <?php
}, 42);

/** Homepage service headings with intentional two-line labels. */
add_filter('elementor/frontend/the_content', static function ($content) {
    if ((int) get_the_ID() !== 539) return $content;
    $labels = array(
        'Production & songwriting' => 'Production<br>&amp; Songwriting',
        'Mixing' => 'Mixing<br>&amp; Mastering',
        'Features & collaborations' => 'Features<br>&amp; Collaborations',
        'Creative direction & consultation' => 'Creative Direction<br>&amp; Consultation',
    );
    return preg_replace_callback('~(<h3\b[^>]*>)(.*?)(</h3>)~is', static function ($match) use ($labels) {
        $label = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8'));
        return isset($labels[$label]) ? $match[1] . $labels[$label] . $match[3] : $match[0];
    }, $content);
}, 111);

/** Keep existing investor links working after the public page is renamed. */
add_action('template_redirect', static function () {
    $path = wp_parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if (trim((string) $path, '/') === 'investors' && in_array($method, array('GET', 'HEAD'), true)) {
        wp_safe_redirect(home_url('/for-investors/'), 301, '3PM');
        exit;
    }
}, 1);

function threepm_update_investor_page_links($content) {
    return str_replace(
        array('href="/investors/', 'href="https://3rdpersonmusic.com/investors/'),
        array('href="/for-investors/', 'href="https://3rdpersonmusic.com/for-investors/'),
        $content
    );
}
add_filter('the_content', 'threepm_update_investor_page_links', 40);
add_filter('elementor/frontend/the_content', 'threepm_update_investor_page_links', 120);

/** Fan reward explorer and artist invitation tools, including AJAX visits. */
add_action('wp_footer', static function () {
    ?>
<script id="pm-fan-page-interactions">
(function () {
    'use strict';
    var number = new Intl.NumberFormat('en-US', {maximumFractionDigits:2});
    var dollars = new Intl.NumberFormat('en-US', {style:'currency',currency:'USD',maximumFractionDigits:2});
    function update(root) {
        var monthly = root.querySelector('#pm-fan-monthly'), months = root.querySelector('#pm-fan-months');
        if (!monthly || !months) return;
        var spend = Number(monthly.value), duration = Number(months.value);
        var validSpend = monthly.value.trim() !== '' && Number.isFinite(spend) && spend >= 0 && spend <= 1000000;
        var validMonths = months.value.trim() !== '' && Number.isInteger(duration) && duration >= 1 && duration <= 60;
        monthly.setAttribute('aria-invalid',String(!validSpend)); months.setAttribute('aria-invalid',String(!validMonths));
        root.querySelector('#pm-fan-error').textContent = validSpend && validMonths ? '' : 'Enter a monthly coin amount from 0 to 1,000,000 and a whole number of months from 1 to 60. The last valid results remain shown.';
        if (!validSpend || !validMonths) return;
        var reward = spend * .1, total = reward * duration;
        root.querySelector('[data-fan-monthly-reward]').textContent = number.format(reward);
        root.querySelector('[data-fan-total-reward]').textContent = number.format(total);
        root.querySelector('[data-fan-total-spend]').textContent = number.format(spend * duration);
        root.querySelectorAll('[data-fan-price]').forEach(function (row) {row.querySelector('[data-fan-value]').textContent = dollars.format(total * Number(row.dataset.fanPrice));});
    }
    function email(root) {
        var text = root.querySelector('#pm-fan-invite-text'), link = root.querySelector('#pm-fan-email-invite');
        if (text && link) link.href = 'mailto:?subject=' + encodeURIComponent('Your music on 3PM') + '&body=' + encodeURIComponent(text.value);
    }
    document.addEventListener('input',function(event) {
        var root = event.target.closest('.pm-for-fans'); if(!root)return;
        if(event.target.matches('#pm-fan-monthly,#pm-fan-months'))update(root);
        if(event.target.id==='pm-fan-invite-text')email(root);
    });
    document.addEventListener('click',function(event) {
        var button = event.target.closest('#pm-fan-copy-invite'); if(!button)return;
        var root = button.closest('.pm-for-fans'), text = root.querySelector('#pm-fan-invite-text'), status = root.querySelector('#pm-fan-invite-status');
        function manual(){text.focus();text.select();status.textContent='Invitation selected. Copy it and send it through your preferred channel.';}
        if(!navigator.clipboard || !navigator.clipboard.writeText){manual();return;}
        navigator.clipboard.writeText(text.value).then(function(){status.textContent='Invitation copied. Add your own note and share it with your artist.';},manual);
    });
    function setup(){document.querySelectorAll('.pm-for-fans:not([data-fan-ready])').forEach(function(root){root.dataset.fanReady='true';update(root);email(root);});}
    setup();
    var queued=false;
    new MutationObserver(function(){if(queued)return;queued=true;requestAnimationFrame(function(){queued=false;setup();});}).observe(document.getElementById('qwjquerycontent')||document.body,{childList:true,subtree:true});
})();

</script>
    <?php
}, 43);

/** Smooth fan FAQ expansion and collapse, including AJAX visits. */
add_action('wp_footer', static function () {
 ?>
<script id="pm-fan-faq-motion">
(function(){
'use strict';
var active=new WeakMap();
document.addEventListener('click',function(event){
var summary=event.target.closest('.pm-for-fans .pm-fan-faq summary');
if(!summary)return;
var details=summary.parentElement;
if(details.tagName!=='DETAILS')return;
event.preventDefault();
var previous=active.get(details);
var expanding=previous?!previous.expanding:!details.open;
var start=details.getBoundingClientRect().height;
if(previous){previous.animation.onfinish=null;previous.animation.cancel();active.delete(details);}
if(window.matchMedia('(prefers-reduced-motion: reduce)').matches||!details.animate){details.open=expanding;details.style.height='';details.style.overflow='';return;}
details.style.boxSizing='border-box';
details.style.height=start+'px';
details.style.overflow='hidden';
details.open=true;
var style=getComputedStyle(details);
var border=(parseFloat(style.borderTopWidth)||0)+(parseFloat(style.borderBottomWidth)||0);
details.style.height='auto';
var end=expanding?details.getBoundingClientRect().height:summary.getBoundingClientRect().height+border;
details.style.height=start+'px';
var animation=details.animate([{height:start+'px'},{height:end+'px'}],{duration:320,easing:'cubic-bezier(.22,1,.36,1)',fill:'both'});
active.set(details,{animation:animation,expanding:expanding});
animation.onfinish=function(){details.open=expanding;animation.cancel();details.style.height='';details.style.overflow='';active.delete(details);};
});
})();
</script>
<?php
}, 44);

/** About is a direct link in the sidebar; header audience links remain. */
add_filter('wp_nav_menu_objects', static function ($items, $args) {
    $hidden = array(922 => true);
    do {
        $changed = false;
        foreach ($items as $item) {
            if (isset($hidden[(int) $item->menu_item_parent]) && !isset($hidden[(int) $item->ID])) {
                $hidden[(int) $item->ID] = true;
                $changed = true;
            }
        }
    } while ($changed);
    return array_values(array_filter($items, static function ($item) use ($hidden) {
        if ((int) $item->ID === 922) {
            $item->classes = array_values(array_diff($item->classes, array('menu-item-has-children')));
            return true;
        }
        return !isset($hidden[(int) $item->ID]);
    }));
}, 30, 2);

/** Draw northeast arrows as vectors, including after AJAX navigation. */
add_action('wp_footer', static function () {
?>
<script id="pm-vector-link-arrows">
(function(){
'use strict';
var queued=new Set(),scheduled=false;
function replace(node){
 if(!node.parentElement||node.parentElement.closest('script,style,textarea,input,code,pre,svg,.pm-arrow-icon')||!node.nodeValue.includes('↗'))return;
 var value=node.nodeValue,expression=/↗[\uFE0E\uFE0F]?/g,fragment=document.createDocumentFragment(),last=0,match;
 while((match=expression.exec(value))){
  fragment.appendChild(document.createTextNode(value.slice(last,match.index)));
  var icon=document.createElement('span');icon.className='pm-arrow-icon';icon.setAttribute('aria-hidden','true');
  var svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');svg.setAttribute('stroke-width','1.7');svg.setAttribute('stroke-linecap','round');svg.setAttribute('stroke-linejoin','round');
  var path=document.createElementNS('http://www.w3.org/2000/svg','path');path.setAttribute('d','M5 19 19 5M5 5h14v14');svg.appendChild(path);icon.appendChild(svg);fragment.appendChild(icon);last=expression.lastIndex;
 }
 fragment.appendChild(document.createTextNode(value.slice(last)));node.replaceWith(fragment);
}
function scan(root){
 if(root.nodeType===3){replace(root);return;}
 if(root.nodeType!==1||root.closest('script,style,textarea,input,code,pre,svg,.pm-arrow-icon'))return;
 var walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),nodes=[],node;while((node=walker.nextNode()))if(node.nodeValue.includes('↗'))nodes.push(node);nodes.forEach(replace);
}
function flush(){scheduled=false;queued.forEach(function(root){if(root.isConnected)scan(root);});queued.clear();}
function setup(){
 var content=document.querySelector('#qwjquerycontent');if(content)scan(content);
 new MutationObserver(function(records){records.forEach(function(record){record.addedNodes.forEach(function(node){if(node.nodeType===1||node.nodeType===3){var element=node.nodeType===1?node:node.parentElement;if(element&&(element.closest('#qwjquerycontent')||element.querySelector('#qwjquerycontent')))queued.add(node);}});});if(queued.size&&!scheduled){scheduled=true;requestAnimationFrame(flush);}}).observe(document.body,{childList:true,subtree:true});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup,{once:true});else setup();
})();
</script>
<?php
}, 45);

/** Center the homepage journal with half-card previews on desktop. */
add_filter('elementor/frontend/the_content', static function ($content) {
    return preg_replace_callback('/<div\b[^>]*\bid="vice-elementor-post-carousel-el-539-[^"]*"[^>]*>/', static function ($match) {
        $tag = $match[0];
        foreach (array('items' => '4', 'items_tablet' => '2', 'items_mobile_hori' => '2', 'items_mobile' => '1.5', 'gap' => '16', 'center' => 'true', 'stage_padding' => '0', 'loop' => 'true') as $key => $value) {
            $tag = preg_replace('/data-' . $key . '="[^"]*"/', 'data-' . $key . '="' . $value . '"', $tag);
        }
        return $tag;
    }, $content);
}, 200);

/** Pointer delegation also covers Owl's dynamically cloned blog cards. */
add_action('wp_footer', static function () {
?>
<script id="pm-blog-delegated-tilt">
(function(){
'use strict';
var selector='.pm-home .elementor-element-6533c76 .vice-post',active=null,frame=0,point=null;
var allowed=matchMedia('(min-width:768px) and (hover:hover) and (pointer:fine) and (prefers-reduced-motion:no-preference)');
function reset(){if(active){active.classList.remove('pm-blog-tilting');active.style.removeProperty('--pm-blog-tilt-x');active.style.removeProperty('--pm-blog-tilt-y');active=null;}}
function draw(){frame=0;if(!allowed.matches||!point||!point.card.isConnected){reset();return;}var card=point.card;if(active!==card){reset();active=card;}var box=card.closest('.owl-item').getBoundingClientRect(),x=Math.max(-1,Math.min(1,(point.x-box.left)/box.width*2-1)),y=Math.max(-1,Math.min(1,(point.y-box.top)/box.height*2-1));card.classList.add('pm-blog-tilting');card.style.setProperty('--pm-blog-tilt-x',(-y*6).toFixed(2)+'deg');card.style.setProperty('--pm-blog-tilt-y',(x*7).toFixed(2)+'deg');}
document.addEventListener('pointermove',function(event){var card=event.target.closest&&event.target.closest(selector);if(!card||!allowed.matches||event.pointerType==='touch'){point=null;reset();return;}point={card:card,x:event.clientX,y:event.clientY};if(!frame)frame=requestAnimationFrame(draw);},{passive:true});
document.addEventListener('pointerout',function(event){if(active&&!active.contains(event.relatedTarget)){point=null;reset();}},{passive:true});
window.addEventListener('blur',function(){point=null;reset();});
allowed.addEventListener('change',function(){point=null;reset();});
})();
</script>
<?php
}, 48);
