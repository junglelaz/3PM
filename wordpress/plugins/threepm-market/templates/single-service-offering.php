<?php
defined('ABSPATH')||exit;
get_header();
$r=ThreePM_Market_Store::get((int)get_post_meta(get_queried_object_id(),'_threepm_service_record',true));
$preview=$r&&is_user_logged_in()&&((int)$r['owner']===get_current_user_id()||ThreePM_Market::admin());
echo '<main id="threepm-service-main">';
if(ThreePM_Market_Offerings::public_record($r)||$preview)echo ThreePM_Market_UI::single_service($r,!ThreePM_Market_Offerings::public_record($r));
else echo ThreePM_Market_UI::shell('Service unavailable','<p>This offering is not currently available.</p>');
echo '</main>';get_footer();
