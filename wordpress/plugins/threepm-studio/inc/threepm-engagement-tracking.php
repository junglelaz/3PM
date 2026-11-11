<?php
defined('ABSPATH') || exit;

/** First-party audio measurement. No raw IP addresses are persisted. */
final class ThreePM_Engagement_Tracking {
    static function boot() {
        foreach (['wp_ajax_', 'wp_ajax_nopriv_'] as $prefix) {
            add_action($prefix . 'threepm_audio_begin', [__CLASS__, 'begin']);
            add_action($prefix . 'threepm_audio_complete', [__CLASS__, 'complete']);
        }
        add_action('wp_enqueue_scripts', function () {
            wp_enqueue_script('threepm-audio-tracking', THREEPM_STUDIO_URL . '/assets/js/threepm-audio-tracking.js', [], '1.0.0', true);
            wp_add_inline_script('threepm-audio-tracking', 'window.threepmAudioEndpoint=' . wp_json_encode(admin_url('admin-ajax.php')) . ';', 'before');
        });
    }

    static function actor() {
        // REMOTE_ADDR is supplied by the server; do not trust forwarded headers.
        return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . ':' . gmdate('Y-m-d'), wp_salt('auth'));
    }

    static function source($url) {
        // Matching only. Never fetch a caller-supplied URL.
        $url = esc_url_raw($url);
        if (!$url) return null;
        $posts = get_posts(['post_type' => ['release', 'podcast'], 'post_status' => 'publish', 'numberposts' => -1]);
        $matches = [];
        foreach ($posts as $post) {
            if ($post->post_type === 'podcast') {
                if (get_post_meta($post->ID, '_podcast_resourceurl', true) === $url) $matches[] = ['post' => $post->ID, 'track' => 'podcast'];
            } else {
                $tracks = array_values(array_filter((array)get_post_meta($post->ID, 'track_repeatable', true), 'is_array'));
                foreach ($tracks as $index => $track) {
                    if (empty($track['threepm_id'])) $track['threepm_id'] = substr(hash('sha256', $post->ID . ':' . $index . ':' . ($track['releasetrack_track_title'] ?? '') . ':' . ($track['releasetrack_mp3_demo'] ?? '')), 0, 32);
                    $source = $track['releasetrack_mp3_demo'] ?? '';
                    if (str_starts_with($source, '/') && !str_starts_with($source, '//')) $source = home_url($source);
                    if ($source === $url) $matches[] = ['post' => $post->ID, 'track' => $track['threepm_id']];
                }
            }
        }
        // A compilation can reuse an artist's original recording. Credit the uniquely owned original.
        if (count($matches) > 1) {
            $owned = array_values(array_filter($matches, fn($match) => (int)get_post_meta($match['post'], '_threepm_artist_owner', true) > 0));
            if (count($owned) === 1) return $owned[0];
        }
        // Multiple owned recordings sharing one source still need explicit attribution.
        return count($matches) === 1 ? $matches[0] : null;
    }

    static function begin() {
        $actor = self::actor();
        $throttle = '3pm_audio_rate_' . $actor;
        if (get_transient($throttle)) wp_send_json_error(['message' => 'Please retry shortly.'], 429);
        set_transient($throttle, 1, 2);
        $source = self::source((string) wp_unslash($_POST['source'] ?? ''));
        if (!$source) wp_send_json_error(['message' => 'Audio not registered or source is ambiguous.'], 404);
        $owner = (int) get_post_meta($source['post'], '_threepm_artist_owner', true);
        if (current_user_can('manage_options') || ($owner && $owner === get_current_user_id())) wp_send_json_success(['excluded' => true]);
        $token = bin2hex(random_bytes(24));
        set_transient('3pm_audio_' . hash('sha256', $token), $source + ['actor' => $actor, 'started' => time()], 20 * MINUTE_IN_SECONDS);
        wp_send_json_success(['token' => $token, 'seconds' => 30]);
    }

    static function complete() {
        $token = (string) ($_POST['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/D', $token)) wp_send_json_error(['message' => 'Invalid session.'], 400);
        $key = '3pm_audio_' . hash('sha256', $token);
        $session = get_transient($key);
        if (!$session || !hash_equals($session['actor'], self::actor())) wp_send_json_error(['message' => 'Session expired.'], 410);
        if (time() - $session['started'] < 30) wp_send_json_error(['message' => 'Listen for at least 30 seconds.'], 409);
        $post = (int) $session['post'];
        if (get_post_status($post) !== 'publish') wp_send_json_error(['message' => 'Audio unavailable.'], 404);
        global $wpdb;
        $lock = '3pm-audio-' . get_current_blog_id() . '-' . $post;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== 1) wp_send_json_error(['message' => 'Retry measurement.'], 503);
        try {
            $dedup = '3pm_play_' . hash('sha256', $session['actor'] . ':' . $post . ':' . $session['track']);
            if (!get_transient($dedup)) {
                $metrics = get_post_meta($post, '_threepm_streams', true);
                if (!is_array($metrics)) $metrics = [];
                $metrics[$session['track']] = (int) ($metrics[$session['track']] ?? 0) + 1;
                if (!update_post_meta($post, '_threepm_streams', $metrics)) throw new RuntimeException('Measurement could not be saved.');
                if (!get_post_meta($post, '_threepm_streams_since', true)) update_post_meta($post, '_threepm_streams_since', gmdate('c'));
                set_transient($dedup, 1, DAY_IN_SECONDS);
            }
            delete_transient($key);
        } catch (Throwable $error) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            wp_send_json_error(['message' => 'Measurement could not be saved.'], 503);
        }
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        wp_send_json_success(['recorded' => true]);
    }

    static function stats($post, $track) {
        $metrics = get_post_meta($post, '_threepm_streams', true);
        $count = is_array($metrics) ? (int) ($metrics[$track] ?? 0) : 0;
        return '<div class="threepm-metrics"><span>' . number_format_i18n($count) . ' streams on 3PM</span></div>';
    }
}
ThreePM_Engagement_Tracking::boot();
