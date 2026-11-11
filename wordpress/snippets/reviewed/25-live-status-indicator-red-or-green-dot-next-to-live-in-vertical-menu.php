<?php
/**
 * 3PM — LIVE status dot (VERTICAL MENU ONLY via item class)
 * - Add CSS class "threepm-vnav-live" to the LIVE item in your vertical menu.
 * - Offline: red constant glow
 * - Live: green blinking (auto-updates ~20s via AJAX)
 */

/* 1) Inject the dot ONLY for items with class threepm-vnav-live */
add_filter('walker_nav_menu_start_el', function($item_output, $item, $depth, $args){
    $classes = is_array($item->classes) ? array_map('strtolower', $item->classes) : [];
    if (!in_array('threepm-vnav-live', $classes, true)) return $item_output;

    $dot = '<span class="threepm-live-indicator" aria-hidden="true"></span>';
    if (stripos($item_output, '</a>') !== false) {
        return preg_replace('/<\/a>/i', $dot.'</a>', $item_output, 1);
    }
    return $item_output . $dot;
}, 20, 4);

/* 2) AJAX endpoint: { live: true|false } (defined only if not already) */
if (!function_exists('threepm_live_status_ajax')) {
    add_action('wp_ajax_nopriv_threepm_live_status', 'threepm_live_status_ajax');
    add_action('wp_ajax_threepm_live_status',        'threepm_live_status_ajax');
    function threepm_live_status_ajax(){
        $live = function_exists('threepm_is_wpstream_live') ? threepm_is_wpstream_live() : false;
        wp_send_json_success(['live' => (bool)$live]);
    }
}

/* 3) Simple WPStream live detector (define only if not already) */
if (!function_exists('threepm_is_wpstream_live')) {
    function threepm_is_wpstream_live(){
        // If WPStream exposes a helper, prefer it:
        // if (function_exists('wpstream_is_live_now')) return (bool) wpstream_is_live_now();

        $candidates = array_filter(['wpstream_product','wpstream_live','wpstream_channel','wpstream'], 'post_type_exists');
        if (empty($candidates)) return false;

        $args = [
            'post_type'      => $candidates,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ];
        if (taxonomy_exists('wpstream_format')) {
            $args['tax_query'] = [[
                'taxonomy' => 'wpstream_format',
                'field'    => 'slug',
                'terms'    => ['live'],
            ]];
        }

        $q = new WP_Query($args);
        $post_id = $q->have_posts() ? (int)$q->posts[0] : 0;

        if (!$post_id) {
            unset($args['tax_query']);
            $q = new WP_Query($args);
            $post_id = $q->have_posts() ? (int)$q->posts[0] : 0;
            if (!$post_id) return false;
        }

        foreach (['_wpstream_is_live','wpstream_is_live','_is_live','is_live','_live','live'] as $key){
            $val = get_post_meta($post_id, $key, true);
            if ($val === '' || $val === null) continue;
            if ($val === true || $val === 1 || $val === '1' || $val === 'true' || $val === 'yes') return true;
        }

        $live = apply_filters('threepm_is_live', false, $post_id);
        $opt  = get_option('threepm_is_live_override', '');
        if ($opt !== '') return in_array(strtolower((string)$opt), ['1','true','yes','on'], true);

        return (bool)$live;
    }
}

/* 4) Styles + polling JS */
add_action('wp_head', function () {
    ?>
    <style>
      /* Only items marked with our class get a dot */
      li.menu-item.threepm-vnav-live .threepm-live-indicator{
        display:inline-block;
        width:.6em; height:.6em;
        margin-left:.5em;
        border-radius:50%;
        background:#ef4444; /* offline red */
        box-shadow:0 0 6px rgba(239,68,68,.9), 0 0 12px rgba(239,68,68,.6);
        vertical-align:middle;
      }
      li.menu-item.threepm-vnav-live .threepm-live-indicator.is-live{
        background:#22c55e;
        box-shadow:0 0 6px rgba(34,197,94,.9), 0 0 12px rgba(34,197,94,.6);
        animation: threepm_vnav_blink 1s ease-in-out infinite;
      }
      @keyframes threepm_vnav_blink{ 0%,100%{opacity:1} 50%{opacity:.35} }
      @media (prefers-reduced-motion: reduce){
        li.menu-item.threepm-vnav-live .threepm-live-indicator.is-live{ animation:none; }
      }
    </style>
    <script>
      (function(){
        function setLiveState(isLive){
          document.querySelectorAll('li.menu-item.threepm-vnav-live .threepm-live-indicator').forEach(function(dot){
            dot.classList.toggle('is-live', !!isLive);
          });
        }
        function poll(){
          var url = (window.ajaxurl || '<?php echo esc_js( admin_url('admin-ajax.php') ); ?>') + '?action=threepm_live_status';
          fetch(url, {cache:'no-store', credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(data){ setLiveState(!!(data && data.success && data.data && data.data.live)); })
            .catch(function(){ setLiveState(false); });
        }
        document.addEventListener('DOMContentLoaded', function(){
          poll();
          setInterval(poll, 20000);
        });
      })();
    </script>
    <?php
});
