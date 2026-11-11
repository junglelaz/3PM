<?php
defined('ABSPATH')||exit;
add_action('wp_loaded',function(){
 if(!class_exists('ThreePM_Licensing'))return;
 $view=sanitize_key($_GET['licensing_view']??'dashboard');
 if(!in_array($view,['overview','dashboard','builder','review','details','payments'],true))$view='dashboard';
 remove_all_actions('woocommerce_account_sync-licensing_endpoint');
 add_action('woocommerce_account_sync-licensing_endpoint',function()use($view){echo ThreePM_Licensing::$view();});
});
add_action('wp_enqueue_scripts',function(){
 if(!function_exists('wc_get_page_permalink'))return;
 // Load site-wide so the theme's own AJAX entry into My Account is supported.
 wp_enqueue_style('threepm-account-navigation',get_stylesheet_directory_uri().'/assets/css/threepm-account-navigation.css',[],'1.2.0');
 wp_enqueue_script('threepm-account-navigation',get_stylesheet_directory_uri().'/assets/js/threepm-account-navigation.js',[],'1.2.0',true);
 $licensing=[];
 if(class_exists('ThreePM_Licensing'))foreach(['overview','dashboard','builder','review','details','payments'] as $view)$licensing[ThreePM_Licensing::url($view)]=$view;
 wp_add_inline_script('threepm-account-navigation','window.threepmAccountConfig='.wp_json_encode(['root'=>wc_get_page_permalink('myaccount'),'licensing'=>$licensing,'licensingEndpoint'=>wc_get_account_endpoint_url('sync-licensing')]).';','before');
});
