<?php
/**
 * Plugin Name: 3PM Artist Studio
 * Description: Native artist libraries, profile presentation and first-party engagement measurement.
 * Version: 0.2.42
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce, threepm-market
 */
defined('ABSPATH') || exit;
define('THREEPM_STUDIO_URL', untrailingslashit(plugin_dir_url(__FILE__)));

add_action('admin_menu', function () {
    add_options_page('3PM Artist Studio', '3PM Artist Studio', 'manage_options', 'threepm-studio', function () {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>3PM Artist Studio</h1><p>When public rollout is off, the new studio is available only to administrators and the existing Artist QA account (ID 6). Other artists retain their current dashboard.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('threepm_studio_rollout');
        echo '<input type="hidden" name="action" value="threepm_studio_rollout"><label><input type="checkbox" name="enabled" value="1" ' . checked(get_option('threepm_studio_enabled', false), true, false) . '> Enable Artist Studio for all approved artists and public profiles</label><p><button class="button button-primary">Save rollout setting</button></p></form><p>Solana minting is disabled until the purchase flow has passed deployment tests.</p></div>';
    });
});
add_action('admin_post_threepm_studio_rollout', function () {
    if (!current_user_can('manage_options')) wp_die('Administrator access required.', 'Forbidden', ['response' => 403]);
    check_admin_referer('threepm_studio_rollout');
    update_option('threepm_studio_enabled', isset($_POST['enabled']), false);
    do_action('litespeed_purge_all');
    wp_safe_redirect(admin_url('options-general.php?page=threepm-studio&saved=1'));
    exit;
});
add_action('plugins_loaded', function () {
    require_once __DIR__ . '/inc/threepm-hodl.php';
    require_once __DIR__ . '/inc/threepm-account-menu.php';
    require_once __DIR__ . '/inc/threepm-track-identity.php';
    require_once __DIR__ . '/inc/threepm-public-presentation.php';
    require_once __DIR__ . '/inc/threepm-profile-url.php';
    require_once __DIR__ . '/inc/threepm-account-embedded.php';
    require_once __DIR__ . '/inc/threepm-sales-stats.php';
    require_once __DIR__ . '/inc/threepm-gallery-analytics.php';
    require_once __DIR__ . '/inc/threepm-engagement-tracking.php';
    if (!class_exists('ThreePM_Market_UI')) return;
    if (!get_option('threepm_studio_enabled', false) && !current_user_can('manage_options') && get_current_user_id() !== 6) return;
    foreach (['threepm-profile-links.php', 'threepm-gallery-editor.php', 'threepm-music-library.php', 'threepm-library-presentation.php', 'threepm-creator-content.php', 'threepm-gallery-analytics.php', 'threepm-engagement-tracking.php'] as $file) require_once __DIR__ . '/inc/' . $file;
    // A stale browser tab must not use the legacy save handler, which discards stable track IDs.
    add_action('admin_post_threepm_market', function () {
        if (($_POST['op'] ?? '') === 'release') wp_die('The music editor has been updated. Reopen Music in your Artist Dashboard before saving.', 'Refresh music editor', ['response' => 409, 'back_link' => true]);
    }, 1);
}, 30);

add_action('wp_enqueue_scripts',function(){wp_enqueue_style('threepm-workspace',THREEPM_STUDIO_URL.'/assets/css/threepm-workspace.css',[],'0.2.42');},100);

add_action('admin_enqueue_scripts',function($hook){if(!in_array($hook,['post.php','post-new.php'],true))return;$screen=get_current_screen();if(!$screen||!in_array($screen->post_type,['artist','mediagallery'],true))return;wp_enqueue_script('threepm-gallery-editor',THREEPM_STUDIO_URL.'/assets/js/threepm-gallery-editor.js',[],'0.2.42',true);wp_enqueue_style('threepm-native-profile',THREEPM_STUDIO_URL.'/assets/css/threepm-native-profile.css',[],'0.2.42');});

add_filter('woocommerce_account_menu_items',function($items){if(isset($items['artist-dashboard']))$items['artist-dashboard']='Artist Management';return $items;},999);
add_action('wp_enqueue_scripts',function(){global $wp_scripts;if(isset($wp_scripts->registered['threepm-account-navigation'])){$wp_scripts->registered['threepm-account-navigation']->src=THREEPM_STUDIO_URL.'/assets/js/threepm-account-navigation.js';$wp_scripts->registered['threepm-account-navigation']->ver='0.2.42';}},999);

add_action('wp_enqueue_scripts',function(){if(is_singular('artist'))wp_enqueue_script('threepm-public-profile',THREEPM_STUDIO_URL.'/assets/js/threepm-public-profile.js',[],'0.2.42',true);});
