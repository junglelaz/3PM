<?php
/**
 * Plugin Name: Vice – Aggregate Global Player (3PM build)
 * Description: Aggregates ALL Release tracklists into the Vice sticky/global player while preserving each track’s original Release link. This build bakes in support for the 'track_repeatable' repeater with fields 'releasetrack_track_title' and 'releasetrack_mp3_demo' (as detected on 3rdpersonmusic.com).
 * Version: 1.0.3
 * Author: 3PM / Amanita
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) { exit; }

class Vice_Aggregate_Global_Player_3PM {
    const JS_HANDLE = 'vice-aggregate-player-js';

    // Candidate post types used by Vice/QantumThemes for releases
    private $candidate_post_types = ['release','qt_release','vice_release','album','discography'];

    // Within each track row, candidate keys for the audio URL and title
    // (3PM-specific keys FIRST to ensure we hit them)
    private $track_audio_field_candidates = ['releasetrack_mp3_demo','mp3_demo','mp3','audio','file','url','mp3_url','track_mp3','track_url'];
    private $track_title_field_candidates = ['releasetrack_track_title','title','track_title','name','trackname','track_name'];

    // 3PM-specific meta key list (first position has highest priority)
    private $tracklist_meta_keys = ['track_repeatable','release_tracks','tracklist','qt_tracklist','_tracklist','_release_tracks'];

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 30);
        add_action('admin_menu', [$this, 'admin_tools_page']);
    }

    public function enqueue_assets() {
        if (is_admin()) return;

        $playlist = $this->build_aggregated_playlist();
        if (empty($playlist)) return;

        wp_register_script(self::JS_HANDLE, plugins_url('front.js', __FILE__), [], '1.0.3', true);
        wp_localize_script(self::JS_HANDLE, 'VICE_AGG_PLAYLIST', [
            'tracks' => array_values($playlist),
        ]);
        wp_enqueue_script(self::JS_HANDLE);

        // Fallback some QT builds consume on init
        add_action('wp_footer', function() use ($playlist){
            echo "<script>window.QT_DEFAULT_PLAYLIST = ".wp_json_encode(array_values($playlist)).";</script>";
        }, 5);
    }

    private function build_aggregated_playlist() {
        $post_types = $this->detect_existing_post_types($this->candidate_post_types);
        if (empty($post_types)) return [];

        $ids = get_posts([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ]);
        if (empty($ids)) return [];

        $out = [];
        foreach ($ids as $rid) {
            $release_url   = get_permalink($rid);
            $release_title = get_the_title($rid);

            $rows = $this->get_release_tracks_rows($rid);
            if (!$rows) continue;

            foreach ($rows as $row) {
                $title = $this->first_nonempty($row, $this->track_title_field_candidates);
                $mp3   = $this->first_nonempty($row, $this->track_audio_field_candidates);

                if (!$mp3) {
                    foreach ($row as $k=>$v) {
                        if (is_string($v) && preg_match('~\.(mp3|ogg|wav)(\?.*)?$~i', $v)) { $mp3 = $v; break; }
                    }
                }

                if (!$mp3) continue;

                $mp3 = $this->to_absolute_url($mp3);

                $out[] = [
                    'title'       => $title ?: $release_title,
                    'mp3'         => esc_url_raw($mp3),
                    'link'        => esc_url_raw($release_url), // keep both keys for compatibility
                    'release_url' => esc_url_raw($release_url),
                    'release'     => $release_title,
                ];
            }
        }

        // De-dup by mp3 URL
        $unique = [];
        $seen   = [];
        foreach ($out as $t) {
            $key = $t['mp3'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $t;
        }

        return $unique;
    }

    /**
     * Fetch rows for "Release Tracks"
     * Priority:
     *   A) 3PM baked-in: serialized array under 'track_repeatable'
     *   B) ACF get_field('track_repeatable') if available
     *   C) ACF-numbered meta pattern for 'track_repeatable'
     *   D) Other common serialized arrays
     */
    private function get_release_tracks_rows($post_id) {
        // A) Direct meta array under 'track_repeatable' (observed on 3rdpersonmusic.com)
        $raw = get_post_meta($post_id, 'track_repeatable', true);
        $maybe = maybe_unserialize($raw);
        if ($this->looks_like_rows($maybe)) return $maybe;

        // B) ACF get_field
        if (function_exists('get_field')) {
            $acf = get_field('track_repeatable', $post_id);
            if ($this->looks_like_rows($acf)) return $acf;
        }

        // C) ACF-numbered meta pattern count + rows (rare for this key, but support anyway)
        $count = intval(get_post_meta($post_id, 'track_repeatable', true));
        if ($count > 0) {
            $tmp = [];
            for ($i=0; $i<$count; $i++) {
                $row = [];
                foreach (array_merge($this->track_title_field_candidates, $this->track_audio_field_candidates, ['releasetrack_artist_name','releasetrack_scurl','releasetrack_buyurl','icon_type']) as $field) {
                    $val = get_post_meta($post_id, "track_repeatable_{$i}_{$field}", true);
                    if ($val !== '') $row[$field] = $val;
                }
                if (!empty($row)) $tmp[] = $row;
            }
            if ($this->looks_like_rows($tmp)) return $tmp;
        }

        // D) Other common serialized arrays (fallback)
        foreach ($this->tracklist_meta_keys as $key) {
            $raw = get_post_meta($post_id, $key, true);
            $maybe = maybe_unserialize($raw);
            if ($this->looks_like_rows($maybe)) return $maybe;
        }

        // E) Last resort: scan all meta for any serialized array that looks like rows
        $all = get_post_meta($post_id);
        foreach ($all as $k=>$vals) {
            $v = isset($vals[0]) ? maybe_unserialize($vals[0]) : null;
            if ($this->looks_like_rows($v)) return $v;
        }

        return [];
    }

    private function looks_like_rows($val) {
        if (!is_array($val) || empty($val)) return false;
        $first = reset($val);
        return is_array($first);
    }

    private function first_nonempty(array $row, array $candidates) {
        foreach ($candidates as $k) {
            if (isset($row[$k]) && $row[$k] !== '') return $row[$k];
        }
        return null;
    }

    private function to_absolute_url($url) {
        if (!is_string($url) || $url === '') return $url;
        if (preg_match('~^https?://|^//~i', $url)) return $url; // already absolute
        if ($url[0] === '/') return home_url($url);
        if (stripos($url, 'wp-content/') === 0) return home_url('/'.$url);
        return $url;
    }

    public function admin_tools_page() {
        add_management_page('Vice Player Inspector', 'Vice Player Inspector', 'manage_options', 'vice-agg-player-inspector', function() {
            echo '<div class="wrap"><h1>Vice Aggregate Player – Inspector</h1>';
            echo '<p>3PM build with baked support for <code>track_repeatable</code>. Use this page to confirm values.</p>';
            $types = $this->detect_existing_post_types($this->candidate_post_types);
            if (empty($types)) { echo '<p><em>No Release-like post types found.</em></p></div>'; return; }
            $id = get_posts([
                'post_type' => $types,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'fields' => 'ids',
            ]);
            if (empty($id)) { echo '<p><em>No Releases found.</em></p></div>'; return; }
            $id = $id[0];
            echo '<h2>Latest Release ID: '.intval($id).'</h2>';
            $meta = get_post_meta($id);
            echo '<h3>Post Meta (raw)</h3><table class="widefat"><thead><tr><th>Meta key</th><th>Sample value (truncated)</th></tr></thead><tbody>';
            foreach ($meta as $k=>$vals) {
                $v = isset($vals[0]) ? maybe_unserialize($vals[0]) : '';
                $dump = esc_html(substr(@print_r($v, true), 0, 600));
                echo '<tr><td><code>'.$k.'</code></td><td><pre style="white-space:pre-wrap;max-width:100%">'.$dump.'</pre></td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        });
    }

    private function detect_existing_post_types(array $candidates) {
        $existing = [];
        $all = get_post_types();
        foreach ($candidates as $slug) {
            if (isset($all[$slug]) || in_array($slug, $all, true)) $existing[] = $slug;
        }
        return $existing;
    }
}

new Vice_Aggregate_Global_Player_3PM();
