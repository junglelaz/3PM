<?php
/**
 * Plugin Name: 3PM – Media Session Artwork for Vice Player (v1.5)
 * Description: Prioritizes a fixed override artwork URL for iOS lock screen via Media Session API.
 * Version: 1.6.0
 * Author: 3PM
 */

if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function () {
  wp_register_script(
    'threepm-media-session',
    plugins_url('threepm-media-session.js', __FILE__),
    array(),
    (string) filemtime(__DIR__ . '/threepm-media-session.js'),
    true
  );

  $site_icon = function_exists('get_site_icon_url') ? get_site_icon_url(512) : '';
  wp_localize_script('threepm-media-session', 'THREEPM_MEDIASESSION_VARS', array(
    'fallbackArtwork' => $site_icon ?: plugins_url('fallback-512.png', __FILE__),
    'siteName'        => get_bloginfo('name'),
    'overrideArtwork' => 'https://3rdpersonmusic.com/wp-content/uploads/2025/09/3PM-The-Best-Of-Cover.png'
  ));
  wp_enqueue_script('threepm-media-session');
}, 99);

add_filter('script_loader_tag', function($tag, $handle){
  if ($handle === 'threepm-media-session') {
    $tag = preg_replace('/\sdefer(=[^\s>]+)?/i', '', $tag);
    $tag = preg_replace('/\sasync(=[^\s>]+)?/i', '', $tag);
  }
  return $tag;
}, 10, 2);

add_action('wp_print_footer_scripts', function () {
  ?>
  <script>
  try {
    if (typeof navigator !== 'undefined' && 'mediaSession' in navigator && typeof window.THREEPM_MEDIASESSION_SET === 'function') {
      window.THREEPM_MEDIASESSION_SET({});
    }
  } catch(e){}
  </script>
  <?php
}, 999);
