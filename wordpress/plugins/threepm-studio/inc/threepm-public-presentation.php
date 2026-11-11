<?php
defined('ABSPATH') || exit;
add_filter('elementor/widget/render_content',function($html,$widget){
 if(!is_singular('artist')||!class_exists('WP_HTML_Tag_Processor'))return $html;
 $name=$widget->get_name();
 if($name==='vice-elementor-post-carousel'&&str_contains($html,'vice-post--release')){
  $tags=new WP_HTML_Tag_Processor($html);
  while($tags->next_tag()){
   if(!$tags->has_class('vice-elementor-owl-carousel'))continue;
   $count=(int)$tags->get_attribute('data-amount');
   if($count>=0){foreach(['vice-elementor-owl-carousel','owl-carousel','owl-theme','vice-elementor-owl-theme'] as $class)$tags->remove_class($class);$tags->add_class('threepm-release-static');$tags->set_attribute('style','opacity:1;');}
   else{$tags->add_class('threepm-release-carousel');foreach(['items'=>3,'items_tablet'=>2,'items_mobile'=>1,'items_mobile_hori'=>1,'stage_padding'=>48,'gap'=>24,'loop'=>'true','center'=>'false','autoplay_timeout'=>0,'nav'=>'true','dots'=>'false'] as $key=>$value)$tags->set_attribute('data-'.$key,(string)$value);}
  }
  return $tags->get_updated_html();
 }
 if($name==='vice-elementor-moderngallery'){
  $all=get_post_meta(get_queried_object_id(),'_threepm_gallery_descriptions',true);$descriptions=is_array($all)?($all[$widget->get_id()]??[]):[];
  if(!$descriptions)return $html;
  $rows=array_values((array)$widget->get_settings('gallery'));$tags=new WP_HTML_Tag_Processor($html);$index=-1;$description='';
  while($tags->next_tag()){
   if($tags->has_class('vice-elementor-item')){$index++;$row=$rows[$index]??null;$description=$row!==null?($descriptions[hash('sha256',wp_json_encode($row))]??''):'';}
   if($description==='')continue;
   if($tags->has_class('vice-modernslider-item--moderngallery')){$tags->add_class('threepm-public-gallery-caption');$tags->set_attribute('data-gallery-description',$description);}
   if($tags->get_tag()==='IMG')$tags->set_attribute('alt',$description);
   if($tags->get_tag()==='A'){$tags->set_attribute('title',$description);$tags->set_attribute('aria-label',$description);}
  }
  return $tags->get_updated_html();
 }
 return $html;
},80,2);

// Artist credits follow release ownership, falling back to the release's track credits.
add_filter('elementor/widget/render_content',function($html,$widget){
 if(!is_front_page()||$widget->get_name()!=='vice-elementor-post-modernslider')return $html;
 return preg_replace_callback('~<a\b[^>]*href=["\x27]([^"\x27]+)["\x27][^>]*>\s*<h3\b[^>]*>.*?</h3>\s*</a>~s',function($match){
  $id=url_to_postid(html_entity_decode($match[1]));if(!$id||get_post_type($id)!=='release')return $match[0];
  $owner=(int)get_post_meta($id,'_threepm_artist_owner',true);$artists=$owner?get_posts(['post_type'=>'artist','post_status'=>'publish','numberposts'=>1,'meta_key'=>'_threepm_artist_owner','meta_value'=>$owner]):[];
  $name='';$url='';if($artists){$name=get_the_title($artists[0]);$url=get_permalink($artists[0]);}
  if(!$name){$names=[];foreach((array)get_post_meta($id,'track_repeatable',true) as $track){if(!is_array($track))continue;$credit=trim(wp_strip_all_tags($track['releasetrack_artist_name']??''));if($credit!=='')$names[$credit]=true;}$name=count($names)>1?'Various Artists':(array_key_first($names)??'');}
  if(!$name)return $match[0];$credit=$url?'<a href="'.esc_url($url).'">'.esc_html($name).'</a>':esc_html($name);
  return $match[0].'<p class="threepm-home-release-artist">'.$credit.'</p>';
 },$html);
},85,2);

add_filter('do_shortcode_tag',function($html,$tag,$atts){
 if($tag!=='threepm_artist_profile'||($atts['section']??'')!=='identity'||!is_singular('artist'))return $html;
 $id=get_queried_object_id();$languages=(string)get_post_meta($id,'_artist_languages',true);$contact=(string)get_post_meta($id,'_artist_contact',true);
 $fields='<div><dt>Languages</dt><dd>'.esc_html($languages?:'Not specified').'</dd></div><div><dt>Contact</dt><dd>'.($contact?'<a href="'.esc_attr('mailto:'.$contact).'">'.esc_html($contact).'</a>':'Not provided').'</dd></div>';
 $short='https://3pm.to/'.get_post_field('post_name',$id);$fields.='<div><dt>3PM Link</dt><dd><button type="button" class="threepm-shortlink-copy" data-copy-artist-short="'.esc_attr($short).'" aria-label="Copy 3PM profile link">'.esc_html(preg_replace('~^https://~','',$short)).'</button> <small class="threepm-copy-hint">(click to copy)</small><span role="status" class="threepm-shortlink-status"></span></dd></div>';
 return preg_replace('~</dl>~',$fields.'</dl>',$html,1);
},20,3);

add_filter('term_link',function($url,$term,$taxonomy){if(!str_contains($url,'/genre/'))return $url;$artist=get_page_by_path($term->slug,OBJECT,'artist');if(!$artist){$matches=get_posts(['post_type'=>'artist','post_status'=>'publish','title'=>$term->name,'numberposts'=>1]);$artist=$matches[0]??null;}if(!$artist||$artist->post_status!=='publish'||sanitize_title($artist->post_title)!==sanitize_title($term->name))return $url;return get_permalink($artist);},30,3);
