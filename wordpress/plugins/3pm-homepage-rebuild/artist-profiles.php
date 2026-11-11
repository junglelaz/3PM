<?php
/** Artist profile fields and dynamic sections used by native Elementor shortcode widgets. */
if (!defined('ABSPATH')) { exit; }
final class ThreePM_Artist_Profiles {
    public static function init() {
        add_action('add_meta_boxes_artist', array(__CLASS__, 'boxes'));
        add_action('save_post_artist', array(__CLASS__, 'save'), 40);
        add_shortcode('threepm_artist_profile', array(__CLASS__, 'render'));
        add_filter('elementor/widget/render_content', static function ($content, $widget) {
            if (is_singular('artist') && $widget->get_name() === 'vice-elementor-qt-caption') {
                return str_replace('Legend Personified', 'Artist bio', $content);
            }
            return $content;
        }, 30, 2);
        add_action('woocommerce_account_content', array(__CLASS__, 'artist_press_notice'), 9);
        add_action('woocommerce_account_dashboard', array(__CLASS__, 'press_review_notice'), 15);
        add_action('woocommerce_account_content', array(__CLASS__, 'dashboard_profile_updates'), 90);
        add_action('admin_post_threepm_artist_press', array(__CLASS__, 'save_dashboard_press'));
        add_action('admin_post_threepm_refresh_artist_press', array(__CLASS__, 'admin_refresh_press'));
        add_action('admin_footer-post.php', array(__CLASS__, 'admin_press_form'));
        add_action('threepm_artist_press_refresh', array(__CLASS__, 'refresh_all'));
        add_action('init', static function () {
            if (!wp_next_scheduled('threepm_artist_press_refresh')) {
                wp_schedule_event(time() + 300, 'twicedaily', 'threepm_artist_press_refresh');
            }
        });
    }
    private static function fields() {
        return array(
            'photo' => 'Artist photo URL (choose an image from Media Library)',
            'country' => 'Country (leave blank to use existing nationality and residence)',
            'genre' => 'Genre summary (leave blank to use Artist genres)',
            'bio' => 'Artist biography',
            'websites' => 'Additional websites: one Label | https://address per line',
            'achievements' => 'Recent achievements: one achievement per line, newest first',
            'keywords' => 'Press search aliases: one exact artist name per line',
            'blog_keywords' => 'Blog matching phrases: one artist name or alias per line',
            'press' => 'Press links: one YYYY-MM-DD | Publisher | Title | https://address per line'
        );
    }
    private static function meta($id, $key) { return (string) get_post_meta($id, '_threepm_profile_' . $key, true); }
    public static function dashboard_bio($id) {
        $bio = self::meta($id, 'bio');
        if (!$bio) {
            $nodes = json_decode(get_post_meta($id, '_elementor_data', true), true);
            $bio = self::first_bio(is_array($nodes) ? $nodes : array());
        }
        if (!$bio) $bio = get_post_field('post_content', $id);
        return trim(wp_strip_all_tags(preg_replace('/<br\s*\/?\s*>|<\/p>/i', "\n\n", $bio)));
    }
    public static function sync_artist_dashboard($id) {
        $owner = (int)get_post_meta($id, '_threepm_artist_owner', true);
        if (!$owner || !class_exists('ThreePM_Market') || !ThreePM_Market::approved($owner)) throw new RuntimeException('Approved profile ownership is required.');
        if (!self::meta($id, 'bio')) update_post_meta($id, '_threepm_profile_bio', self::dashboard_bio($id));
        self::layout($id);
        clean_post_cache($id);
        do_action('litespeed_purge_post', $id);
    }
    private static function first_bio($nodes) {
        foreach ($nodes as $node) {
            if (($node['widgetType'] ?? '') === 'text-editor' && !empty($node['settings']['editor'])) { return $node['settings']['editor']; }
            $found = self::first_bio($node['elements'] ?? array());
            if ($found) { return $found; }
        }
        return '';
    }
    public static function boxes() { add_meta_box('threepm-artist-profile', '3PM Artist Profile', array(__CLASS__, 'box'), 'artist', 'normal', 'default'); }
    public static function box($post) {
        wp_nonce_field('threepm_artist_profile', 'threepm_artist_profile_nonce');
        echo '<p>These sections are added when you update the artist. Existing music, galleries, events and booking information stay editable in Elementor. Use the existing Artist data panel for the main website and social links.</p>';
        $data = json_decode(get_post_meta($post->ID, '_elementor_data', true), true);
        foreach (self::fields() as $key => $label) {
            $value = self::meta($post->ID, $key);
            if ($key === 'bio' && $value === '') { $value = self::first_bio(is_array($data) ? $data : array()); if (!$value) { $value = $post->post_content; } }
            echo '<p><label for="threepm-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><br>';
            if (in_array($key, array('photo', 'country', 'genre'), true)) {
                echo '<input class="widefat" id="threepm-' . esc_attr($key) . '" name="threepm_profile[' . esc_attr($key) . ']" type="' . ($key === 'photo' ? 'url' : 'text') . '" value="' . esc_attr($value) . '">';
            } else {
                echo '<textarea class="widefat" rows="' . ($key === 'bio' ? '9' : '3') . '" id="threepm-' . esc_attr($key) . '" name="threepm_profile[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea>';
            }
            echo '</p>';
        }
        echo '<p>When enabled, Google News checks artist names and profile keywords twice daily. Only titles, publishers, dates and links are shown. The profile shows up to six manual links and two Google News results. Artists review new Google News results before adding them to their profile. Related blog posts match the configured phrases in published 3PM post titles or their explicitly assigned artist tag.</p>';
        $status = get_post_meta($post->ID, '_threepm_press_status', true);
        if ($status) { echo '<p><strong>Press status:</strong> ' . esc_html($status) . '</p>'; }
        if (empty(self::press_settings($post->ID)['disabled'])) {
            echo '<p><button type="submit" form="threepm-press-refresh" class="button">Scan Google News now</button></p>';
        } else {
            echo '<p>Google News scanning is disabled for this artist.</p>';
        }
    }
    public static function save($id) {
        static $saving = false;
        if ($saving) { return; }
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !current_user_can('edit_post', $id)) { return; }
        if (!isset($_POST['threepm_artist_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['threepm_artist_profile_nonce'])), 'threepm_artist_profile')) { return; }
        $input = isset($_POST['threepm_profile']) && is_array($_POST['threepm_profile']) ? wp_unslash($_POST['threepm_profile']) : array();
        foreach (self::fields() as $key => $label) {
            if (!isset($input[$key]) || !is_string($input[$key])) { continue; }
            $value = $key === 'bio' ? wp_kses_post($input[$key]) : ($key === 'photo' ? esc_url_raw($input[$key], array('https', 'http')) : sanitize_textarea_field($input[$key]));
            update_post_meta($id, '_threepm_profile_' . $key, $value);
        }
        $saving = true;
        try { self::layout($id); } finally { $saving = false; }
        clean_post_cache($id);
        do_action('litespeed_purge_post', $id);
    }
    public static function admin_press_form() {
        global $post;
        if (!$post || $post->post_type !== 'artist' || !current_user_can('edit_post', $post->ID)) { return; }
        echo '<form id="threepm-press-refresh" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="threepm_refresh_artist_press">';
        echo '<input type="hidden" name="artist_id" value="' . (int) $post->ID . '">';
        wp_nonce_field('threepm_refresh_press_' . $post->ID, '_wpnonce', false);
        echo '</form>';
    }

    public static function admin_refresh_press() {
        $id = absint(self::press_input('artist_id'));
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || get_post_type($id) !== 'artist' ||
            !current_user_can('edit_post', $id)) {
            wp_die('Artist edit access is required.', '', array('response' => 403));
        }
        check_admin_referer('threepm_refresh_press_' . $id);
        self::refresh($id, true);
        wp_safe_redirect(get_edit_post_link($id, 'raw') . '#threepm-artist-profile', 303);
        exit;
    }

    private static function section($id, $part) {
        $base = substr(md5('threepm-profile-' . $id . '-' . $part), 0, 7);
        return array('id' => $base, 'elType' => 'section', 'settings' => array('_css_classes' => 'pm-artist-section'), 'elements' => array(array('id' => substr(md5($base . 'column'), 0, 7), 'elType' => 'column', 'settings' => array('_column_size' => 100), 'elements' => array(array('id' => substr(md5($base . 'widget'), 0, 7), 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => array('shortcode' => '[threepm_artist_profile section="' . $part . '"]'), 'elements' => array())))));
    }
    private static function replace_bio(&$nodes, $bio) {
        foreach ($nodes as &$node) {
            if (($node['widgetType'] ?? '') === 'text-editor') { $node['settings']['editor'] = $bio; return true; }
            if (!empty($node['elements']) && self::replace_bio($node['elements'], $bio)) { return true; }
        }
        return false;
    }
    private static function layout($id) {
        if (!class_exists('\Elementor\Plugin')) { return; }
        $document = \Elementor\Plugin::$instance->documents->get($id);
        if (!$document) { return; }
        $nodes = $document->get_elements_data();
        if (!is_array($nodes)) { $nodes = array(); }
        if (!get_post_meta($id, '_threepm_profile_layout_backup', true)) {
            update_post_meta($id, '_threepm_profile_layout_backup', wp_slash(wp_json_encode($nodes)));
            wp_save_post_revision($id);
        }
        $bio = self::meta($id, 'bio');
        if ($bio !== '') { self::replace_bio($nodes, wpautop(wp_kses_post($bio))); }
        self::order_bio_first($nodes);
        $json = wp_json_encode($nodes);
        if (strpos($json, 'threepm_artist_profile') === false) {
            array_unshift($nodes, self::section($id, 'identity'));
            $nodes[] = self::section($id, 'updates');
        }
        // Classic-editor artists receive the same dynamic sections around their existing biography.
        if (count($nodes) === 2 && $bio !== '') {
            $middle = self::section($id, 'bio');
            $nodes = array($nodes[0], $middle, $nodes[1]);
        }
        $document->save(array('elements' => $nodes));
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    private static function order_bio_first(&$nodes) {
        $gallery = null; $bio = null;
        foreach ($nodes as $index => $node) {
            if (($node['widgetType'] ?? '') === 'vice-elementor-moderngallery') { $gallery = $index; }
            if (($node['widgetType'] ?? '') === 'text-editor' && $bio === null) { $bio = $index; }
        }
        if ($gallery !== null && $bio !== null && $gallery < $bio) {
            $image = array_splice($nodes, $gallery, 1);
            array_splice($nodes, $bio, 0, $image);
        }
        foreach ($nodes as &$node) { if (!empty($node['elements'])) { self::order_bio_first($node['elements']); } }
    }
    public static function refresh_all() {
        foreach (get_posts(array('post_type' => 'artist', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids')) as $id) { self::refresh($id); }
    }
    private const PRESS_SEARCH_META = '_threepm_press_search';

    private static function press_settings($id) {
        $settings = get_post_meta($id, self::PRESS_SEARCH_META, true);
        return is_array($settings) ? $settings : array();
    }

    private static function press_terms($values, $limit) {
        $terms = array();
        foreach ($values as $value) {
            $term = sanitize_text_field($value);
            if ($term === '' || strlen($term) > 90) { continue; }
            $terms[strtolower($term)] = $term;
            if (count($terms) >= $limit) { break; }
        }
        return array_values($terms);
    }

    private static function split_press_terms($value) {
        return preg_split('/[,;·\n]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    private static function press_aliases($id, $settings) {
        return self::press_terms(array_merge(
            array($settings['full_name'] ?? '', $settings['stage_name'] ?? '', get_the_title($id)),
            self::split_press_terms(self::meta($id, 'keywords'))
        ), 9);
    }

    private static function press_context($id, $settings) {
        $genres = wp_get_post_terms($id, 'artistgenre', array('fields' => 'names'));
        return self::press_terms(array_merge(
            $settings['tags'] ?? array(),
            self::split_press_terms(self::meta($id, 'genre')),
            is_wp_error($genres) ? array() : $genres,
            self::split_press_terms(self::meta($id, 'country')),
            array((string) get_post_meta($id, '_artist_nationality', true), (string) get_post_meta($id, '_artist_resident', true)),
            self::split_press_terms((string) get_post_meta($id, '_artist_languages', true))
        ), 25);
    }

    private static function press_search_query($aliases, $context) {
        $quote = static function ($term) { return '"' . str_replace('"', '', $term) . '"'; };
        $music_terms = array('music', 'musician', 'rapper', 'singer', 'song', 'album', 'EP', 'Spotify', 'interview',
            'concert', 'festival', 'release', 'música', 'músico', 'canción', 'chanson', 'musique', 'muziek');
        $query = '(' . implode(' OR ', array_map($quote, $aliases)) . ')';
        if ($context !== null) {
            $query .= ' (' . implode(' OR ', array_map($quote, array_merge($context, $music_terms))) . ')';
        }
        if ($context === null) { return trim($query, '()'); }
        return $query . ' -site:3rdpersonmusic.com -site:junglelaz.net -site:laszlovanleeuwen.com -site:x.com -site:twitter.com -site:facebook.com -site:instagram.com -site:youtube.com -site:tiktok.com -site:hellopeter.com -site:trustpilot.com';
    }

    private static function press_feed_editions($id) {
        $editions = array('US:en' => array('en', 'US'));
        $languages = (string) get_post_meta($id, '_artist_languages', true);
        $locales = array(
            'spanish|español|castellano' => array('es-419', 'US'),
            'dutch|nederlands' => array('nl', 'NL'),
            'french|français' => array('fr', 'FR'),
            'portuguese|português' => array('pt-419', 'BR'),
            'german|deutsch' => array('de', 'DE'),
        );
        foreach ($locales as $names => $edition) {
            if (preg_match('/(?<![\pL\pN])(?:' . $names . ')(?![\pL\pN])/iu', $languages)) {
                $editions[$edition[1] . ':' . $edition[0]] = $edition;
            }
            if (count($editions) === 3) { break; }
        }
        return $editions;
    }

    private static function fetch_press_feed($query, $edition, $locale, $force) {
        $url = add_query_arg(array('q' => rawurlencode($query), 'hl' => $locale[0], 'gl' => $locale[1], 'ceid' => $edition),
            'https://news.google.com/rss/search');
        $configure_feed = static function ($feed, $request_url) use ($url, $force) {
            if ($request_url !== $url) { return; }
            $feed->set_timeout(3);
            if ($force) { $feed->enable_cache(false); }
        };
        add_action('wp_feed_options', $configure_feed, 10, 2);
        try {
            return fetch_feed($url);
        } finally {
            remove_action('wp_feed_options', $configure_feed, 10);
        }
    }

    private static function press_name_in($text, $aliases) {
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ($aliases as $alias) {
            $alias = preg_replace('/\s+/u', ' ', $alias);
            if (preg_match('/(?<![\pL\pN])' . preg_quote($alias, '/') . '(?![\pL\pN])/iu', $text)) { return true; }
        }
        return false;
    }

    private static function press_host($url) {
        return preg_replace('/^www\./', '', strtolower((string) wp_parse_url($url, PHP_URL_HOST)));
    }

    private static function publisher_press_page($url, $host, $deadline) {
        if ($deadline <= microtime(true)) { return null; }
        $response = wp_safe_remote_get($url, array(
            'timeout' => min(3, max(0.5, $deadline - microtime(true))),
            'redirection' => 3,
            'limit_response_size' => 1200000,
            'headers' => array('Accept' => 'text/html,application/xhtml+xml'),
        ));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return null; }
        $resolved = isset($response['http_response'])
            ? $response['http_response']->get_response_object()->url : $url;
        if (self::press_host($resolved) !== $host) { return null; }
        return array('url' => $resolved, 'html' => wp_remote_retrieve_body($response));
    }

    private static function press_page_matches($page, $title, $aliases) {
        if (!class_exists('DOMDocument') || $page['html'] === '') { return false; }
        $document = new DOMDocument();
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $page['html'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) { return false; }
        $xpath = new DOMXPath($document);
        $title_key = self::press_title_key(array('title' => $title));
        $title_matches = static function ($text) use ($title_key) {
            return $title_key !== '' && self::press_title_key(array('title' => $text)) === $title_key;
        };
        $matching_headline = false;
        foreach ($xpath->query('//h1 | //meta[@property="og:title"]/@content') as $heading) {
            if ($title_matches($heading->textContent)) { $matching_headline = true; break; }
        }
        if (!$matching_headline) { return false; }

        $article_text = array();
        $content = '//meta[@name="description" or @property="og:description" or @name="twitter:description"]/@content'
            . ' | //*[@itemprop="description" or @itemprop="articleBody"]'
            . ' | //article//p[not(ancestor::aside or ancestor::footer or ancestor::nav)]'
            . ' | //article//*[self::h2 or self::h3][not(ancestor::aside or ancestor::footer or ancestor::nav)]';
        foreach ($xpath->query($content) as $element) {
            $article_text[] = $element->textContent;
        }
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $schema = json_decode($script->textContent, true);
            if (!is_array($schema)) { continue; }
            $graph = is_array($schema['@graph'] ?? null) ? $schema['@graph'] : array();
            $nodes = array_merge(array($schema), $graph, array_is_list($schema) ? $schema : array());
            foreach ($nodes as $node) {
                if (!is_array($node) || !is_string($node['headline'] ?? null) || !$title_matches($node['headline'])) { continue; }
                foreach (array('description', 'alternativeHeadline', 'articleBody') as $field) {
                    if (is_string($node[$field] ?? null)) { $article_text[] = $node[$field]; }
                }
            }
        }
        return self::press_name_in(implode(' ', $article_text), $aliases);
    }

    private static function verify_publisher_press($candidate, $host, $aliases, $manual, $deadline) {
        $page = self::publisher_press_page($candidate['url'], $host, $deadline);
        if ($page && self::press_page_matches($page, $candidate['title'], $aliases)) { return $page['url']; }
        // The direct publisher URL can resolve a Google News entry that uses a browser-only redirect.
        foreach ($manual as $item) {
            if (self::press_host($item['url']) === $host && self::same_press_article($item, $candidate)) {
                $page = self::publisher_press_page($item['url'], $host, $deadline);
                return $page && self::press_page_matches($page, $candidate['title'], $aliases) ? $page['url'] : '';
            }
        }
        return '';
    }

    public static function refresh($id, $force = false) {
        $settings = self::press_settings($id);
        if (!empty($settings['disabled'])) { return; }
        $aliases = self::press_aliases($id, $settings);
        if (!$aliases) { return; }
        require_once ABSPATH . WPINC . '/feed.php';
        $context = self::press_context($id, $settings);
        $manual = self::manual_press($id);
        $deadline = microtime(true) + 13;
        $items = array();
        $seen = array();
        $received = 0;
        $checked_pages = 0;
        $opened_editions = 0;

        foreach (self::press_feed_editions($id) as $edition => $locale) {
            if (microtime(true) + 3 >= $deadline) { break; }
            $query = self::press_search_query($aliases, $edition === 'US:en' ? $context : null);
            $feed = self::fetch_press_feed($query, $edition, $locale, $force);
            if (is_wp_error($feed)) { continue; }
            $opened_editions++;
            foreach ($feed->get_items(0, 30) as $item) {
                $title = html_entity_decode(wp_strip_all_tags($item->get_title()), ENT_QUOTES, 'UTF-8');
                $source = $item->get_source();
                $publisher = $source ? wp_strip_all_tags($source->get_title()) : '';
                $host = $source ? self::press_host($source->get_permalink()) : '';
                $tags = $item->get_item_tags('', 'source');
                if (!$publisher && !empty($tags[0]['data'])) { $publisher = wp_strip_all_tags($tags[0]['data']); }
                if (!$host && !empty($tags[0]['attribs']['']['url'])) { $host = self::press_host($tags[0]['attribs']['']['url']); }
                if (!$publisher && preg_match('/ - ([^-]+)$/u', $title, $suffix)) { $publisher = trim($suffix[1]); }
                if (preg_match('/^(X|Twitter|Facebook|Instagram|TikTok|YouTube|Spotify|Amazon Music|JOOX)$/i', $publisher)) { continue; }
                if (!$publisher || preg_match('/(^|\.)(3rdpersonmusic\.com|junglelaz\.net|laszlovanleeuwen\.com|youtube\.com|spotify\.com|facebook\.com|instagram\.com|tiktok\.com|x\.com|twitter\.com|t\.co)$/', $host)) { continue; }
                $url = esc_url_raw($item->get_permalink(), array('http', 'https'));
                $date = (int) $item->get_date('U');
                if (!$url || !$date || $date > time() + DAY_IN_SECONDS) { continue; }
                if ($publisher && str_ends_with($title, ' - ' . $publisher)) { $title = substr($title, 0, -strlen(' - ' . $publisher)); }
                if (isset($seen[$url])) { continue; }
                $seen[$url] = true;
                $received++;

                if (!self::press_name_in($title . ' ' . $item->get_description(), $aliases)) {
                    if (!$host || $checked_pages >= 5 || microtime(true) >= $deadline) { continue; }
                    $checked_pages++;
                    $url = self::verify_publisher_press(compact('title', 'url'), $host, $aliases, $manual, $deadline);
                    if (!$url) { continue; }
                }
                $candidate = compact('title', 'publisher', 'url', 'date');
                $candidate['review_required'] = true;
                if (!self::contains_press_article($items, $candidate)) { $items[] = $candidate; }
            }
        }

        wp_cache_delete($id, 'post_meta');
        if ($settings !== self::press_settings($id)) { return; }
        update_post_meta($id, '_threepm_press_checked', time());
        $checked = ' Checked ' . gmdate('Y-m-d H:i') . ' UTC.';
        if (!$opened_editions) {
            update_post_meta($id, '_threepm_press_status', 'Google News could not be reached. Saved and manual coverage is retained.' . $checked);
            return;
        }

        $history = array_values(self::google_press($id));
        foreach ($items as $item) {
            if (!self::contains_press_article($history, $item)) { $history[] = $item; }
        }
        self::update_press_meta($id, '_threepm_press_items', $history);
        $status = count($items) . ' matching Google News items from ' . $received . ' press candidates across ' . $opened_editions . ' editions.';
        if ($items) { $status .= ' Matched: ' . implode(', ', array_column($items, 'publisher')) . '.'; }
        update_post_meta($id, '_threepm_press_status', $status . $checked);
        do_action('litespeed_purge_post', $id);
    }
    private const MANUAL_PRESS_LIMIT = 6;
    private const GOOGLE_PRESS_LIMIT = 2;
    private const HIDDEN_PRESS_META = '_threepm_hidden_press';

    private static function hidden_press($id) {
        $items = get_post_meta($id, self::HIDDEN_PRESS_META, true);
        return is_array($items) ? array_filter($items, 'is_array') : array();
    }

    private static function press_url($value) {
        $parts = wp_parse_url($value);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) ||
            !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true)) {
            return '';
        }
        return esc_url_raw($value, array('http', 'https'));
    }

    private static function press_date($value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return null; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $value && $value <= gmdate('Y-m-d')
            ? $date->getTimestamp() : null;
    }

    private static function manual_press($id) {
        $items = array();
        foreach (preg_split('/\R/u', self::meta($id, 'press'), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            $parts = array_map('trim', explode('|', $line, 4));
            if (count($parts) !== 4) { continue; }
            list($day, $publisher, $title, $raw_url) = $parts;
            $url = self::press_url($raw_url);
            $date = self::press_date($day);
            if (!$url || $date === null) { continue; }
            $items[hash('sha256', $line)] = compact('date', 'publisher', 'title', 'url');
        }
        return $items;
    }

    private static function google_press($id) {
        $items = array();
        foreach ((array) get_post_meta($id, '_threepm_press_items', true) as $item) {
            if (!is_array($item) || empty($item['url']) || empty($item['title']) ||
                empty($item['publisher']) || empty($item['date'])) { continue; }
            $items[hash('sha256', $item['url'])] = $item;
        }
        return $items;
    }

    private static function press_approval_key($article) {
        return '_threepm_press_approved_' . $article;
    }

    private static function press_is_approved($id, $article, $item) {
        return empty($item['review_required']) || (bool) get_post_meta($id, self::press_approval_key($article), true);
    }

    private static function press_review_lists($id, $manual, $hidden) {
        $lists = array('pending' => array(), 'approved' => array());
        foreach (self::google_press($id) as $article => $item) {
            if (self::contains_press_article($hidden, $item) || self::contains_press_article($manual, $item)) { continue; }
            $list = self::press_is_approved($id, $article, $item) ? 'approved' : 'pending';
            $lists[$list][$article] = $item;
        }
        return $lists;
    }

    private static function press_title_key($item) {
        return strtolower(preg_replace('/[^\pL\pN]+/u', '', $item['title']));
    }

    private static function same_press_article($first, $second) {
        $first_title = self::press_title_key($first);
        return explode('#', $first['url'], 2)[0] === explode('#', $second['url'], 2)[0] ||
            ($first_title !== '' && $first_title === self::press_title_key($second));
    }

    private static function contains_press_article($items, $candidate) {
        foreach ($items as $item) {
            if (self::same_press_article($item, $candidate)) { return true; }
        }
        return false;
    }

    private static function press_newest_first($first, $second) {
        return $second['date'] <=> $first['date'];
    }

    private static function press($id) {
        $manual = self::manual_press($id);
        uasort($manual, array(__CLASS__, 'press_newest_first'));
        $google = self::google_press($id);
        uasort($google, array(__CLASS__, 'press_newest_first'));
        $hidden = self::hidden_press($id);

        $visible = array();
        foreach ($manual as $item) {
            if (!self::contains_press_article($visible, $item)) { $visible[] = $item; }
            if (count($visible) >= self::MANUAL_PRESS_LIMIT) { break; }
        }
        $google_count = 0;
        foreach ($google as $article => $item) {
            if (!self::press_is_approved($id, $article, $item) ||
                self::contains_press_article($hidden, $item) ||
                self::contains_press_article($visible, $item)) { continue; }
            $visible[] = $item;
            if (++$google_count >= self::GOOGLE_PRESS_LIMIT) { break; }
        }
        usort($visible, array(__CLASS__, 'press_newest_first'));
        return $visible;
    }

    private static function dashboard_press_profile() {
        if (!is_user_logged_in() || !class_exists('ThreePM_Market')) { return 0; }
        $user = get_current_user_id();
        $account = ThreePM_Market::account($user);
        $id = (int) ($account['data']['profile_id'] ?? 0);
        return ($account['state'] ?? '') === 'approved' && get_post_type($id) === 'artist' &&
            (int) get_post_meta($id, '_threepm_artist_owner', true) === $user ? $id : 0;
    }

    private static function press_dashboard_url() {
        return add_query_arg(array('studio' => 'profile', 'tab' => 'profile'), ThreePM_Market::url());
    }

    public static function artist_press_notice() {
        global $wp;
        if (isset($wp->query_vars['artist-dashboard'])) { self::press_review_notice(); }
    }

    public static function press_review_notice() {
        $id = self::dashboard_press_profile();
        if (!$id) { return; }
        $lists = self::press_review_lists($id, self::manual_press($id), self::hidden_press($id));
        $count = count($lists['pending']);
        if (!$count) { return; }
        $message = $count === 1 ? 'Google News found a new article about you.' : 'Google News found ' . $count . ' new articles about you.';
        $link = $count === 1 ? 'Review the article' : 'Review the articles';
        echo '<div class="tpm-market"><p class="tpm-notice" role="status"><strong>' . esc_html($message) . '</strong> ';
        echo '<a class="qwNoAjax" href="' . esc_url(self::press_dashboard_url() . '#artist-press-review') . '">' . esc_html($link) . '</a> to decide what to add to your public profile.</p></div>';
    }

    private static function press_form($id) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="threepm_artist_press">';
        wp_nonce_field('threepm_artist_press_' . $id, '_wpnonce', false);
    }

    private static function press_dashboard_list($id, $items, $mode, $discovered = array()) {
        foreach ($items as $key => $item) {
            $headline = $item['title'] ?: $item['url'];
            $manual = ($item['source'] ?? '') === 'manual';
            self::press_form($id);
            echo '<input type="hidden" name="article" value="' . esc_attr($key) . '">';
            echo '<div class="tpm-manage-row"><div>';
            if ($mode === 'profile') {
                echo '<span class="tpm-badge">' . ($manual ? 'Manual' : 'Google News') . '</span> ';
            }
            echo '<strong><a class="qwNoAjax" target="_blank" rel="noopener noreferrer" href="' . esc_url($item['url']) . '">' . esc_html($headline) . '</a></strong>';
            echo '<p>' . esc_html($item['publisher'] . ' · ' . gmdate('j M Y', $item['date'])) . '</p>';
            if ($manual && self::contains_press_article($discovered, $item)) {
                echo '<p>Also found by Google News</p>';
            }
            if (!empty($item['outside_display_limit'])) {
                echo '<p>Saved outside the public display limit.</p>';
            }
            echo '</div><div class="tpm-actions">';
            if ($mode === 'available') {
                self::press_article_button('approve', 'Add to profile', $headline);
                self::press_article_button('dismiss', 'Dismiss', $headline);
            } elseif ($mode === 'hidden') {
                self::press_article_button('restore', 'Restore', $headline);
            } elseif ($manual) {
                self::press_article_button('remove', 'Remove', $headline);
            } else {
                self::press_article_button('hide', 'Hide', $headline);
            }
            echo '</div></div></form>';
        }
    }

    private static function press_article_button($operation, $label, $headline) {
        echo '<button type="submit" name="operation" value="' . esc_attr($operation) . '" aria-label="' .
            esc_attr($label . ': ' . $headline) . '">' . esc_html($label) . '</button>';
    }

    private static function dashboard_profile_articles($id, $manual, $approved) {
        $public_urls = array_column(self::press($id), 'url');
        $profile_articles = array();
        foreach ($manual + $approved as $key => $item) {
            $item['source'] = isset($manual[$key]) ? 'manual' : 'google';
            $item['outside_display_limit'] = !in_array($item['url'], $public_urls, true);
            $profile_articles[$key] = $item;
        }
        uasort($profile_articles, array(__CLASS__, 'press_newest_first'));

        echo '<div id="artist-press-on-profile"><h3>Articles on profile</h3>';
        if (!$profile_articles) { echo '<p>No articles on your profile yet.</p>'; }
        self::press_dashboard_list($id, $profile_articles, 'profile', self::google_press($id));

        echo '<p>' . count($manual) . ' of ' . self::MANUAL_PRESS_LIMIT . ' manual links used.</p>';
        if (count($manual) < self::MANUAL_PRESS_LIMIT) {
            echo '<details><summary>Add a manual article</summary>';
            self::press_form($id);
            echo '<input type="hidden" name="operation" value="add">';
            echo ThreePM_Market_UI::field('press_url', 'Article URL', '', 'url', true);
            echo ThreePM_Market_UI::field('press_title', 'Headline', '', 'text', true);
            echo ThreePM_Market_UI::field('press_publisher', 'Publisher', '', 'text', true);
            echo ThreePM_Market_UI::field('press_date', 'Publication date', '', 'date', true);
            echo '<button type="submit">Add press link</button></form></details>';
        }
        echo '</div>';
    }

    public static function dashboard_profile_updates() {
        global $wp;
        if (!isset($wp->query_vars['artist-dashboard'])) { return; }
        $studio = isset($_GET['studio']) && is_string($_GET['studio']) ? sanitize_key(wp_unslash($_GET['studio'])) : '';
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'profile';
        $id = self::dashboard_press_profile();
        if (!$id || ($studio !== '' && $studio !== 'profile') || $tab !== 'profile') { return; }
        if ($studio === '' && class_exists('ThreePM_Artist_Studio') &&
            (get_option('threepm_studio_enabled', false) || current_user_can('manage_options') || get_current_user_id() === 6)) {
            return;
        }

        self::dashboard_achievements($id);
        self::dashboard_press($id);
    }

    private static function dashboard_achievements($id) {
        echo '<section id="artist-achievements" class="tpm-market" aria-labelledby="artist-achievements-heading">';
        echo '<h2 id="artist-achievements-heading">Recent achievements</h2>';
        $messages = array(
            'achievements' => 'Recent achievements saved.',
            'achievements_invalid' => 'Use up to 4,000 characters for your achievements.',
            'achievements_retry' => 'Your achievements could not be saved. Please try again.',
        );
        $status = self::press_feedback();
        if (isset($messages[$status])) { echo '<p class="tpm-notice" role="status">' . esc_html($messages[$status]) . '</p>'; }
        self::press_form($id);
        echo '<input type="hidden" name="operation" value="achievements">';
        echo '<p>Add one achievement per line, newest first. These appear on your public profile.</p>';
        echo ThreePM_Market_UI::field('artist_achievements', 'Achievements', self::meta($id, 'achievements'), 'textarea');
        echo '<button type="submit">Save achievements</button></form></section>';
    }

    private static function press_feedback() {
        return isset($_GET['press_status']) && is_string($_GET['press_status']) ? sanitize_key(wp_unslash($_GET['press_status'])) : '';
    }

    private static function dashboard_press($id) {
        $manual = self::manual_press($id);
        $hidden = self::hidden_press($id);
        $lists = self::press_review_lists($id, $manual, $hidden);
        uasort($lists['pending'], array(__CLASS__, 'press_newest_first'));

        echo '<section id="artist-press" class="tpm-market" aria-labelledby="artist-press-heading">';
        echo '<h2 id="artist-press-heading">Press</h2>';
        echo '<p>Manage the articles shown under Latest coverage on your public profile.</p>';
        $messages = array(
            'settings' => 'Press search settings saved.',
            'settings_invalid' => 'Use up to eight distinct tags and keep each name or tag under 90 characters.',
            'added' => 'Press link added.',
            'removed' => 'Press link removed.',
            'hidden' => 'Article hidden from your public profile.',
            'approved' => 'Article added to your public profile.',
            'dismissed' => 'Article dismissed. You can bring it back from Hidden and dismissed articles.',
            'restored' => 'Article restored.',
            'review_restored' => 'Article returned to the review list.',
            'coverage_limit' => 'Hide one of your published Google News articles before adding another.',
            'invalid' => 'Enter a valid article URL, headline, publisher and publication date.',
            'duplicate' => 'That article is already in your manual links.',
            'limit' => 'Remove a manual link before adding another. The limit is six.',
            'stale' => 'That article has changed. Review the current list and try again.',
            'retry' => 'Your update could not be saved. Please try again.',
        );
        $status = self::press_feedback();
        if (isset($messages[$status])) { echo '<p class="tpm-notice" role="status">' . esc_html($messages[$status]) . '</p>'; }

        self::dashboard_profile_articles($id, $manual, $lists['approved']);

        echo '<div id="artist-press-review"><h3>Articles available</h3>';
        if ($lists['pending']) {
            echo '<p>Google News found these articles. Open each one before adding it to your public profile.</p>';
            self::press_dashboard_list($id, $lists['pending'], 'available');
        } else {
            echo '<p>No new articles to review.</p>';
        }
        echo '</div>';
        if ($hidden) {
            echo '<details><summary>Hidden and dismissed articles (' . count($hidden) . ')</summary>';
            self::press_dashboard_list($id, $hidden, 'hidden');
            echo '</details>';
        }

        $settings = self::press_settings($id);
        echo '<h3>Profile tags and press discovery</h3>';
        self::press_form($id);
        echo '<input type="hidden" name="operation" value="settings">';
        echo ThreePM_Market_UI::field('press_full_name', 'Full name', $settings['full_name'] ?? get_the_title($id));
        echo ThreePM_Market_UI::field('press_stage_name', 'Artist name', $settings['stage_name'] ?? '');
        echo ThreePM_Market_UI::field('press_tags', 'Profile tags', implode(', ', $settings['tags'] ?? array()));
        echo '<p>Enter up to eight comma-separated tags. Tags appear on your public profile. Google News uses your names, tags, genre, location and languages.</p>';
        echo '<label><input type="checkbox" name="press_enabled" value="1" ' . checked(empty($settings['disabled']), true, false) . '> Automatically search Google News</label>';
        echo '<button type="submit">Save discovery settings</button></form>';

        if (!empty($settings['disabled'])) {
            echo '<p>Automatic scanning is off.</p>';
        }
        echo '</section>';
    }

    private static function press_input($name) {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? trim(wp_unslash($_POST[$name])) : '';
    }

    private static function update_press_meta($id, $key, $value) {
        return get_post_meta($id, $key, true) === $value || update_post_meta($id, $key, wp_slash($value));
    }

    private static function apply_press_change($id, $operation) {
        $manual = self::manual_press($id);
        $hidden = self::hidden_press($id);
        $raw = self::meta($id, 'press');
        $article = self::press_input('article');

        if ($operation === 'achievements') {
            $achievements = sanitize_textarea_field(self::press_input('artist_achievements'));
            if (strlen($achievements) > 4000) { return 'achievements_invalid'; }
            return self::update_press_meta($id, '_threepm_profile_achievements', $achievements) ? 'achievements' : 'achievements_retry';
        }

        if ($operation === 'settings') {
            $full_name = sanitize_text_field(self::press_input('press_full_name'));
            $stage_name = sanitize_text_field(self::press_input('press_stage_name'));
            $submitted_tags = self::split_press_terms(self::press_input('press_tags'));
            $tags = self::press_terms($submitted_tags, 9);
            if (strlen($full_name) > 90 || strlen($stage_name) > 90 || count($tags) > 8 ||
                array_filter($submitted_tags, static function ($tag) { return strlen(sanitize_text_field($tag)) > 90; })) {
                return 'settings_invalid';
            }
            $disabled = self::press_input('press_enabled') !== '1';
            return self::update_press_meta($id, self::PRESS_SEARCH_META, compact('full_name', 'stage_name', 'tags', 'disabled')) ? 'settings' : 'retry';
        }

        if ($operation === 'add') {
            if (count($manual) >= self::MANUAL_PRESS_LIMIT) { return 'limit'; }
            $url = self::press_url(self::press_input('press_url'));
            $title = sanitize_text_field(self::press_input('press_title'));
            $publisher = sanitize_text_field(self::press_input('press_publisher'));
            $day = self::press_input('press_date');
            if (!$url || !$title || !$publisher ||
                self::press_date($day) === null || str_contains($title . $publisher . $url, '|') ||
                strlen($title) > 600 || strlen($publisher) > 200 || strlen($url) > 2048) { return 'invalid'; }
            if (self::contains_press_article($manual, compact('title', 'url'))) { return 'duplicate'; }
            $line = implode(' | ', array($day, $publisher, $title, $url));
            return self::update_press_meta($id, '_threepm_profile_press', $raw === '' ? $line : rtrim($raw, "\r\n") . "\n" . $line) ? 'added' : 'retry';
        }

        if ($operation === 'remove' && isset($manual[$article])) {
            // Keep a matching automatic result from immediately replacing the removed link.
            $removed = $manual[$article];
            $hidden[hash('sha256', $removed['url'])] = $removed;
            if (!self::update_press_meta($id, self::HIDDEN_PRESS_META, $hidden)) { return 'retry'; }
            $lines = array_filter(preg_split('/\R/u', $raw), static function ($line) use ($article) {
                return hash('sha256', $line) !== $article;
            });
            return self::update_press_meta($id, '_threepm_profile_press', implode("\n", $lines)) ? 'removed' : 'retry';
        }

        if (in_array($operation, array('approve', 'dismiss', 'hide'), true)) {
            $lists = self::press_review_lists($id, $manual, $hidden);
            $list = $operation === 'hide' ? 'approved' : 'pending';
            if (!isset($lists[$list][$article])) { return 'stale'; }
            if ($operation === 'approve') {
                if (count($lists['approved']) >= self::GOOGLE_PRESS_LIMIT) { return 'coverage_limit'; }
                return self::update_press_meta($id, self::press_approval_key($article), '1') ? 'approved' : 'retry';
            }
            $hidden[$article] = $lists[$list][$article];
            return self::update_press_meta($id, self::HIDDEN_PRESS_META, $hidden)
                ? ($operation === 'dismiss' ? 'dismissed' : 'hidden') : 'retry';
        }
        if ($operation === 'restore' && isset($hidden[$article])) {
            $target = $hidden[$article];
            $hidden = array_filter($hidden, static function ($item) use ($target) {
                return !self::same_press_article($item, $target);
            });
            if (!self::update_press_meta($id, self::HIDDEN_PRESS_META, $hidden)) { return 'retry'; }
            return self::press_is_approved($id, $article, $target) ? 'restored' : 'review_restored';
        }
        return 'stale';
    }

    public static function save_dashboard_press() {
        $id = self::dashboard_press_profile();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
            wp_die('An approved artist profile is required.', '', array('response' => 403));
        }
        check_admin_referer('threepm_artist_press_' . $id);
        $operation = self::press_input('operation');
        $status = $operation === 'achievements' ? 'achievements_retry' : 'retry';
        global $wpdb;
        $lock = '3pm-market-' . get_current_blog_id();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) === 1) {
            try {
                wp_cache_delete($id, 'post_meta');
                if (self::dashboard_press_profile() === $id) {
                    $status = self::apply_press_change($id, $operation);
                }
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
        if (in_array($status, array('achievements', 'settings', 'added', 'removed', 'approved', 'dismissed', 'hidden', 'restored', 'review_restored'), true)) {
            clean_post_cache($id);
            do_action('litespeed_purge_post', $id);
        }
        $section = $operation === 'achievements' ? '#artist-achievements' : '#artist-press';
        wp_safe_redirect(add_query_arg('press_status', $status, self::press_dashboard_url()) . $section, 303);
        exit;
    }

    private static function blog($id) {
        $phrases = preg_split('/\R/u', self::meta($id, 'blog_keywords') ?: get_the_title($id), -1, PREG_SPLIT_NO_EMPTY);
        $tag = get_term_by('slug', get_post_field('post_name', $id), 'post_tag');
        $matches = array();
        foreach (get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC')) as $post) {
            $match = $tag && has_term($tag->term_id, 'post_tag', $post);
            foreach ($phrases as $phrase) { if (trim($phrase) !== '' && stripos($post->post_title, trim($phrase)) !== false) { $match = true; break; } }
            if ($match) { $matches[] = $post; }
            if (count($matches) === 6) { break; }
        }
        return $matches;
    }
    public static function render($atts) {
        if (!is_singular('artist')) { return ''; }
        $id = get_queried_object_id();
        $atts = shortcode_atts(array('section' => 'updates'), $atts);
        ob_start();
        if ($atts['section'] === 'bio') {
            echo '<section class="pm-artist-bio"><h2>Artist bio</h2>' . wpautop(wp_kses_post(self::meta($id, 'bio'))) . '</section>';
        } elseif ($atts['section'] === 'identity') {
            $photo = self::meta($id, 'photo') ?: get_the_post_thumbnail_url($id, 'large');
            $country = self::meta($id, 'country') ?: implode(' · ', array_filter(array(get_post_meta($id, '_artist_nationality', true), get_post_meta($id, '_artist_resident', true))));
            $genres = wp_get_post_terms($id, 'artistgenre', array('fields' => 'names'));
            $genre = self::meta($id, 'genre') ?: (is_wp_error($genres) ? '' : implode(' / ', $genres));
            echo '<section class="pm-artist-identity">';
            if ($photo) { echo '<img class="pm-artist-photo" src="' . esc_url($photo) . '" alt="' . esc_attr(get_the_title($id)) . '" width="640" height="800" decoding="async">'; }
            echo '<div class="pm-artist-intro"><span class="pm-kicker">3PM ARTIST</span><h2>' . esc_html(get_the_title($id)) . '</h2><dl><div><dt>Genre</dt><dd>' . esc_html($genre ?: 'Independent music') . '</dd></div><div><dt>Country</dt><dd>' . esc_html($country ?: 'Worldwide') . '</dd></div>';
            $tags = self::press_settings($id)['tags'] ?? array();
            if ($tags) { echo '<div><dt>Tags</dt><dd>' . esc_html(implode(' · ', $tags)) . '</dd></div>'; }
            echo '</dl><div class="pm-artist-links" aria-label="Artist websites and social media">';
            foreach (array('website' => 'Artist website', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'twitter' => 'X', 'soundcloud' => 'SoundCloud', 'beatport' => 'Beatport', 'mixcloud' => 'Mixcloud', 'hearthis' => 'Hearthis') as $key => $label) {
                $url = get_post_meta($id, '_artist_' . $key, true);
                if ($url) { echo '<a class="qwNoAjax pm-artist-link" href="' . esc_url($url, array('http', 'https')) . '" target="_blank" rel="noopener noreferrer">' . esc_html($label) . '<span aria-hidden="true">↗</span></a>'; }
            }
            foreach (preg_split('/\R/u', self::meta($id, 'websites'), -1, PREG_SPLIT_NO_EMPTY) as $line) {
                $p = array_map('trim', explode('|', $line, 2));
                if (count($p) === 2 && esc_url_raw($p[1], array('http', 'https'))) { echo '<a class="qwNoAjax pm-artist-link" href="' . esc_url($p[1], array('http', 'https')) . '" target="_blank" rel="noopener noreferrer">' . esc_html($p[0]) . '<span aria-hidden="true">↗</span></a>'; }
            }
            echo '</div></div></section>';
        } else {
            echo '<div class="pm-artist-updates"><section><span class="pm-kicker">LATEST MILESTONES</span><h2>Recent achievements</h2>';
            $achievements = preg_split('/\R/u', self::meta($id, 'achievements'), -1, PREG_SPLIT_NO_EMPTY);
            if ($achievements) { echo '<ul class="pm-artist-achievements">'; foreach ($achievements as $line) { echo '<li>' . esc_html($line) . '</li>'; } echo '</ul>'; }
            else { echo '<p>New milestones will be shared here.</p>'; }
            echo '</section><section><span class="pm-kicker">IN THE PRESS</span><h2>Latest coverage</h2><div class="pm-artist-card-grid">';
            $press = self::press($id);
            foreach ($press as $item) { echo '<a class="qwNoAjax pm-artist-story" target="_blank" rel="noopener noreferrer" href="' . esc_url($item['url']) . '"><span class="pm-story-meta">' . esc_html($item['publisher']) . ' · ' . esc_html(wp_date('j M Y', $item['date'])) . '</span><h3>' . esc_html($item['title']) . '</h3><span class="pm-story-action">Read coverage ↗</span></a>'; }
            if (!$press) { echo '<p>Press coverage will appear here as it becomes available.</p>'; }
            echo '</div></section><section><span class="pm-kicker">FROM THE 3PM BLOG</span><h2>Artist stories &amp; updates</h2><div class="pm-artist-card-grid">';
            $posts = self::blog($id);
            foreach ($posts as $post) {
                echo '<a class="pm-artist-story" href="' . esc_url(get_permalink($post)) . '">';
                echo get_the_post_thumbnail($post, 'medium_large', array('loading' => 'lazy', 'class' => 'pm-artist-story-image'));
                echo '<span class="pm-story-meta">3PM · ' . esc_html(get_the_date('j M Y', $post)) . '</span><h3>' . esc_html(get_the_title($post)) . '</h3><span class="pm-story-action">Read story ↗</span></a>';
            }
            if (!$posts) { echo '<p>Artist stories are on their way.</p>'; }
            echo '</div></section></div>';
        }
        return ob_get_clean();
    }
}
ThreePM_Artist_Profiles::init();
