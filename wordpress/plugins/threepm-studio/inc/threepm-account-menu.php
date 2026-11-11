<?php
defined('ABSPATH')||exit;
add_filter('woocommerce_account_menu_items',function($items){
 $names=['dashboard'=>'Dashboard','artist-dashboard'=>'Artist Management','threepm_plans_link'=>'My Membership','woo-wallet'=>'My Wallets','my-services'=>'My Services','sync-licensing'=>'Sync Licensing','downloads'=>'My Downloads','orders'=>'My Orders','edit-account'=>'Account Details','security'=>'Security','customer-logout'=>'Logout'];
 $ordered=[];foreach($names as $key=>$label)if(isset($items[$key]))$ordered[$key]=$label;return $ordered;
},PHP_INT_MAX);
add_action('woocommerce_account_content',function(){
 if(function_exists('is_wc_endpoint_url')&&is_wc_endpoint_url('edit-account')){
  echo '<section id="account-addresses" class="threepm-account-addresses"><h2>Addresses</h2>';
  do_action('woocommerce_account_edit-address_endpoint','');
  echo '</section>';
 }
},95);
add_action('template_redirect',function(){
 if(function_exists('is_account_page')&&is_account_page()&&is_wc_endpoint_url('edit-address')&&!get_query_var('edit-address')){wp_safe_redirect(wc_get_account_endpoint_url('edit-account').'#account-addresses');exit;}
},6);

add_action('woocommerce_account_dashboard',function(){ob_start(function($html){return preg_replace('~<p>\s*<a[^>]*>Open Sync Licensing</a>\s*</p>~i','',$html);});},0);
add_action('woocommerce_account_dashboard',function(){if(ob_get_level())ob_end_flush();},PHP_INT_MAX);
