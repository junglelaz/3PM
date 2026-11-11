<?php
/**
 * 3PM — LIVE status text (HORIZONTAL MENU ONLY via item class)
 * - Add CSS class "threepm-hnav-live" to the LIVE item in your horizontal menu.
 * - Offline: red text + soft glow
 * - Live: green text + blinking (respects reduced motion)
 * - Auto-updates ~20s via AJAX
 */

/* AJAX endpoint { live: true|false } — defined only if missing */
if (!function_exists('threepm_live_status_ajax')) {
  add_action('wp_ajax_nopriv_threepm_live_status', 'threepm_live_status_ajax');
  add_action('wp_ajax_threepm_live_status',        'threepm_live_status_ajax');
  function threepm_live_status_ajax(){
    $live = function_exists('threepm_is_wpstream_live') ? threepm_is_wpstream_live() : false;
    wp_send_json_success(['live' => (bool)$live]);
  }
}

/* Best-effort WPStream live detector — defined only if missing */
if (!function_exists('threepm_is_wpstream_live')) {
  function threepm_is_wpstream_live(){
    // Prefer a native helper if your WPStream provides one:
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

/* Styles + polling JS (targets ONLY the horizontal LIVE item with our class) */
add_action('wp_head', function () {
  ?>
  <style>
    /* Default: OFFLINE (red) */
    li.menu-item.threepm-hnav-live > a,
    li.menu-item.threepm-hnav-live > span,
    li.menu-item.threepm-hnav-live .menu-link{
      color:#ef4444; /* red */
      text-shadow:0 0 8px rgba(239,68,68,.55);
      transition: color .2s ease, text-shadow .2s ease;
    }

    /* LIVE: green + blinking */
    li.menu-item.threepm-hnav-live.is-live > a,
    li.menu-item.threepm-hnav-live.is-live > span,
    li.menu-item.threepm-hnav-live.is-live .menu-link{
      color:#22c55e; /* green */
      text-shadow:0 0 8px rgba(34,197,94,.6);
      animation: threepm_text_blink 1s ease-in-out infinite;
    }

    @keyframes threepm_text_blink { 0%,100%{opacity:1} 50%{opacity:.35} }
    @media (prefers-reduced-motion: reduce){
      li.menu-item.threepm-hnav-live.is-live > a,
      li.menu-item.threepm-hnav-live.is-live > span,
      li.menu-item.threepm-hnav-live.is-live .menu-link{
        animation:none;
      }
    }
  </style>
  <script>
    (function(){
      function setLiveState(isLive){
        document.querySelectorAll('li.menu-item.threepm-hnav-live').forEach(function(li){
          li.classList.toggle('is-live', !!isLive);
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
