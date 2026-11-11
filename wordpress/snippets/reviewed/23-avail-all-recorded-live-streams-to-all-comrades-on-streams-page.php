<?php
/**
 * 3PM — WPStream x PMPro with per-post access toggle (same-or-lower tiers)
 * - No routing changes here.
 * - Taxonomy "Format": Live (public) / VOD (members-only by default)
 * - Auto-tag WPStream items as VOD on publish unless explicitly Live
 * - Meta box per post: Public or specific PMPro level
 * - Gating:
 *     * If "Public" → public
 *     * If a specific level → allow that level AND any lower tier(s)
 *         Hierarchy (low → high): Comrades (1) < Silver Comrades (2) < Gold Comrades (3) < Diamond Comrades (4)
 *         Unknown level names default to exact-match only.
 *     * If nothing selected → Live = public; VOD = any membership
 * - [streams_grid] lists VOD/recordings using podcast-style cards
 */

/* ---------------------------------
 * Utility: detect WPStream CPT
 * --------------------------------- */
if (!function_exists('detect_wpstream_post_type')) {
    function detect_wpstream_post_type() {
        global $wp_post_types;
        if (is_array($wp_post_types)) {
            foreach ($wp_post_types as $name => $obj) {
                if (stripos($name, 'wpstream') === 0) return $name;
            }
        }
        return 'wpstream_product'; // fallback
    }
}

/* ---------------------------------
 * Utility: normalize -> rank for tier names
 * Lower number = lower tier (broader access when selected)
 * --------------------------------- */
if (!function_exists('threepm_level_rank')) {
    function threepm_level_rank($level_name) {
        $n = strtolower(trim($level_name));
        // Adjust these if your PMPro level names change
        $map = [
            'comrades'           => 1,
            'silver comrades'    => 2,
            'gold comrades'      => 3,
            'diamond comrades'   => 4,
        ];
        return $map[$n] ?? null; // null = unknown (no hierarchy)
    }
}

/* ---------------------------------
 * Taxonomy: Format (Live / VOD)
 * --------------------------------- */
add_action('init', function () {
    $pt = detect_wpstream_post_type();

    register_taxonomy('wpstream_format', [$pt], [
        'label'             => 'Format',
        'public'            => true,
        'hierarchical'      => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'format'],
    ]);

    if (!term_exists('Live', 'wpstream_format')) wp_insert_term('Live', 'wpstream_format', ['slug' => 'live']);
    if (!term_exists('VOD',  'wpstream_format')) wp_insert_term('VOD',  'wpstream_format',  ['slug' => 'vod']);
}, 11);

/* ---------------------------------
 * Auto-tag as VOD when published
 * --------------------------------- */
add_action('save_post', function ($post_id, $post, $update) {
    $pt = detect_wpstream_post_type();
    if ($post->post_type !== $pt) return;
    if ($post->post_status !== 'publish') return;

    if (!has_term('Live', 'wpstream_format', $post_id)) {
        wp_set_object_terms($post_id, 'VOD', 'wpstream_format', true);
    }
}, 10, 3);

add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($new_status !== 'publish') return;
    $pt = detect_wpstream_post_type();
    if (get_post_type($post) !== $pt) return;

    if (!has_term('Live', 'wpstream_format', $post)) {
        wp_set_object_terms($post->ID, 'VOD', 'wpstream_format', true);
    }
}, 10, 3);

/* ---------------------------------
 * Meta box: Access toggle (Public or specific PMPro level)
 * --------------------------------- */
add_action('add_meta_boxes', function () {
    $pt = detect_wpstream_post_type();
    add_meta_box('wpstream_access_control', 'Access Control', function ($post) {
        $levels = function_exists('pmpro_getAllLevels') ? pmpro_getAllLevels(true, true) : [];
        $saved  = get_post_meta($post->ID, '_wpstream_access_level', true); // 'public' or level_id

        wp_nonce_field('save_wpstream_access_level', 'wpstream_access_nonce');

        echo '<p><strong>Who can watch this item?</strong></p>';
        echo '<div style="display:grid;gap:6px;">';

        // Public
        $checked_public = ($saved === 'public') ? 'checked' : '';
        echo '<label><input type="radio" name="wpstream_access_level" value="public" '.$checked_public.'> Everyone (Public)</label>';

        if (!empty($levels)) {
            foreach ($levels as $lvl) {
                $id   = (int)$lvl->id;
                $name = esc_html($lvl->name);
                $chk  = ($saved !== 'public' && (string)$saved === (string)$id) ? 'checked' : '';
                echo '<label><input type="radio" name="wpstream_access_level" value="'.$id.'" '.$chk.'> '.$name.'</label>';
            }
        } else {
            echo '<p style="color:#777">Paid Memberships Pro not detected or no levels defined. Public will be used.</p>';
        }

        echo '<p style="margin-top:8px;color:#777">';
        echo 'If nothing is selected: <em>Live = public; VOD = requires any membership</em>.';
        echo '</p>';
        echo '</div>';
    }, $pt, 'side', 'default');
});

add_action('save_post', function ($post_id) {
    if (!isset($_POST['wpstream_access_nonce']) || !wp_verify_nonce($_POST['wpstream_access_nonce'], 'save_wpstream_access_level')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['wpstream_access_level'])) {
        $val = sanitize_text_field($_POST['wpstream_access_level']);
        if ($val === 'public' || ctype_digit($val)) {
            update_post_meta($post_id, '_wpstream_access_level', $val);
        } else {
            delete_post_meta($post_id, '_wpstream_access_level');
        }
    } else {
        delete_post_meta($post_id, '_wpstream_access_level'); // no explicit choice
    }
});

/* ---------------------------------
 * Front-end gating (same-or-lower tiers)
 * --------------------------------- */
add_action('template_redirect', function () {
    if (is_admin() || !is_singular()) return;

    $post_id   = get_queried_object_id();
    $ptype     = get_post_type($post_id);
    $pt_guess  = detect_wpstream_post_type();

    // Only gate WPStream CPT singles
    $is_wpstream_cpt = (is_string($ptype) && stripos($ptype, 'wpstream') === 0) || ($ptype === $pt_guess);
    if (!$is_wpstream_cpt) return;

    // Editor selection: 'public' or level_id (string)
    $sel = get_post_meta($post_id, '_wpstream_access_level', true);

    // Determine format
    $has_vod  = taxonomy_exists('wpstream_format') && has_term('VOD',  'wpstream_format', $post_id);
    $has_live = taxonomy_exists('wpstream_format') && has_term('Live', 'wpstream_format', $post_id);

    // If explicitly Public
    if ($sel === 'public') return;

    // If a specific level chosen → allow same-or-lower by name hierarchy
    if (ctype_digit((string)$sel) && function_exists('pmpro_getAllLevels') && function_exists('pmpro_hasMembershipLevel')) {
        $levels = pmpro_getAllLevels(true, true); // array of level objects
        $levels_by_id = [];
        foreach ($levels as $L) $levels_by_id[(int)$L->id] = $L;

        $sel_id = (int)$sel;
        if (isset($levels_by_id[$sel_id])) {
            $sel_name = $levels_by_id[$sel_id]->name;
            $sel_rank = threepm_level_rank($sel_name);

            // Build allowed IDs:
            $allowed_ids = [];
            if ($sel_rank !== null) {
                foreach ($levels as $L) {
                    $r = threepm_level_rank($L->name);
                    if ($r !== null && $r <= $sel_rank) { // same-or-lower
                        $allowed_ids[] = (int)$L->id;
                    }
                }
                // If mapping didn't catch any (e.g., names changed), fall back to exact match
                if (empty($allowed_ids)) $allowed_ids = [$sel_id];
            } else {
                // Unknown name → exact match only
                $allowed_ids = [$sel_id];
            }

            if (!pmpro_hasMembershipLevel($allowed_ids)) {
                wp_redirect(home_url('/sorry'));
                exit;
            }
            return; // authorized
        }
        // If somehow selected level not found, fall through to defaults below
    }

    // No explicit selection → defaults
    // Live = public; VOD (or unknown) = any membership required
    if (!$has_live && function_exists('pmpro_hasMembershipLevel')) {
        if (!pmpro_hasMembershipLevel()) {
            wp_redirect(home_url('/sorry'));
            exit;
        }
    }
    // Live falls through public
}, 9);

/* ---------------------------------
 * [streams_grid] — podcast-style cards for recordings
 * Usage: [streams_grid per_page="12" orderby="date" order="DESC" show_author="1"]
 * Shows items NOT tagged as Live (i.e., VOD/recordings)
 * --------------------------------- */
add_shortcode('streams_grid', function ($atts) {
    $a = shortcode_atts([
        'per_page'    => '12',
        'orderby'     => 'date',
        'order'       => 'DESC',
        'show_author' => '1', // 1 or 0
    ], $atts, 'streams_grid');

    $pt = detect_wpstream_post_type();

    $q = new WP_Query([
        'post_type'      => $pt,
        'posts_per_page' => max(1, (int)$a['per_page']),
        'tax_query'      => [[
            'taxonomy' => 'wpstream_format',
            'field'    => 'slug',
            'terms'    => ['live'],
            'operator' => 'NOT IN',
        ]],
        'post_status'    => 'publish',
        'orderby'        => sanitize_key($a['orderby']),
        'order'          => (strtoupper($a['order']) === 'ASC') ? 'ASC' : 'DESC',
    ]);

    if (!$q->have_posts()) return '<p>No recorded streams yet.</p>';

    ob_start();
    echo '<div class="pod-grid">';

    while ($q->have_posts()) {
        $q->the_post();

        $title     = get_the_title();
        $permalink = get_permalink();
        $author    = get_the_author();

        $thumb_html = get_the_post_thumbnail(null, 'large', [
            'loading' => 'lazy',
            'alt'     => esc_attr($title),
        ]);
        if (!$thumb_html) {
            $thumb_html = '<div class="pod-thumb-fallback" aria-hidden="true"></div>';
        }

        echo '<article class="pod-card">';
        echo '  <a class="pod-card__thumb" href="' . esc_url($permalink) . '">';
        echo        $thumb_html;
        echo '  </a>';

        if ((int)$a['show_author'] === 1) {
            echo '  <div class="pod-card__author">' . esc_html($author) . '</div>';
        }

        echo '  <h3 class="pod-card__title"><a href="' . esc_url($permalink) . '">' . esc_html($title) . '</a></h3>';
        echo '</article>';
    }

    echo '</div>';
    wp_reset_postdata();

    return ob_get_clean();
});
