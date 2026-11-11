<?php
defined('ABSPATH') || exit;
// Keep existing membership rendering inside the account shell.
add_action('init',function(){
 foreach(['my-membership'] as $endpoint)add_rewrite_endpoint($endpoint,EP_PAGES);
 if(get_option('threepm_account_embedded_version')!=='1'){flush_rewrite_rules(false);update_option('threepm_account_embedded_version','1',false);}
},20);
add_filter('woocommerce_get_query_vars',function($vars){foreach(['my-membership'] as $endpoint)$vars[$endpoint]=$endpoint;return $vars;});
add_filter('woocommerce_get_endpoint_url',function($url,$endpoint){return $endpoint==='threepm_plans_link'?wc_get_account_endpoint_url('my-membership'):$url;},PHP_INT_MAX,2);
add_filter('woocommerce_account_menu_item_classes',function($classes,$endpoint){if($endpoint==='threepm_plans_link'&&is_wc_endpoint_url('my-membership'))$classes[]='is-active';return array_unique($classes);},20,2);
add_action('woocommerce_account_my-membership_endpoint',function(){
 echo '<section class="threepm-account-membership"><h2>My Membership</h2>';
 $page=get_page_by_path('plan-select');
 if($page&&$page->post_status==='publish')echo apply_filters('the_content',$page->post_content);
 else echo '<p>Membership options are temporarily unavailable.</p>';
 echo '</section>';
});
add_action('wp_enqueue_scripts',function(){
 if(!function_exists('wc_get_account_endpoint_url'))return;
 $routes=[home_url('/plan-select/')=>wc_get_account_endpoint_url('my-membership')];
 wp_add_inline_script('threepm-account-navigation','window.threepmAccountEmbedded='.wp_json_encode($routes).';','before');
},1000);
