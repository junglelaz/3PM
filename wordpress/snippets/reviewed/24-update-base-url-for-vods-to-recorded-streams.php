<?php
/**
 * WPStream VOD base: /wpstream_vod/{slug}  ->  /stream-recording/{slug}
 * - Adjusts the CPT rewrite slug at registration time
 * - Normalizes generated permalinks
 * - Adds a hard rewrite fallback
 * - 301-redirects legacy /wpstream_vod/... to /stream-recording/...
 */

/** Detect WPStream VOD post types (by name or rewrite slug) */
if (!function_exists('detect_wpstream_vod_pts')) {
    function detect_wpstream_vod_pts() {
        $vod_pts = [];
        global $wp_post_types;
        if (!is_array($wp_post_types)) return $vod_pts;

        foreach ($wp_post_types as $pt => $obj) {
            $slug = isset($obj->rewrite['slug']) ? $obj->rewrite['slug'] : '';
            if ($pt === 'wpstream_vod' || $slug === 'wpstream_vod') {
                $vod_pts[] = $pt;
            }
        }
        // Sensible fallback if nothing matched but the classic PT likely exists
        if (empty($vod_pts) && post_type_exists('wpstream_vod')) $vod_pts[] = 'wpstream_vod';
        return array_unique($vod_pts);
    }
}

/** 1) Force VOD CPTs to use /stream-recording/ as the base */
add_filter('register_post_type_args', function ($args, $post_type) {
    $is_vod = ($post_type === 'wpstream_vod')
        || (!empty($args['rewrite']['slug']) && $args['rewrite']['slug'] === 'wpstream_vod');

    if ($is_vod) {
        if (!isset($args['rewrite']) || !is_array($args['rewrite'])) $args['rewrite'] = [];
        $args['rewrite']['slug']       = 'stream-recording';
        $args['rewrite']['with_front'] = false;
        $args['publicly_queryable']    = true;
    }
    return $args;
}, 0, 2);

/** 2) Normalize generated permalinks to /stream-recording/ */
add_filter('post_type_link', function ($permalink, $post) {
    $vod_pts = function_exists('detect_wpstream_vod_pts') ? detect_wpstream_vod_pts() : [];
    if (!empty($vod_pts) && in_array($post->post_type, $vod_pts, true)) {
        $permalink = preg_replace('#/wpstream_vod/#i', '/stream-recording/', $permalink, 1);
    }
    return $permalink;
}, 10, 2);

/** 3) Hard rewrite fallback so /stream-recording/{slug} always resolves */
add_action('init', function () {
    $vod_pts = function_exists('detect_wpstream_vod_pts') ? detect_wpstream_vod_pts() : [];
    foreach ($vod_pts as $pt) {
        add_rewrite_rule(
            '^stream-recording/([^/]+)/?$',
            'index.php?post_type=' . $pt . '&name=$matches[1]',
            'top'
        );
    }
}, 20);

/** 4) Redirect legacy /wpstream_vod/... to /stream-recording/... */
add_action('template_redirect', function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/wpstream_vod/([^/?#]+)/?$#i', $uri)) {
        $new = preg_replace('#/wpstream_vod/#i', '/stream-recording/', $uri, 1);
        wp_redirect(home_url($new), 301);
        exit;
    }
});
