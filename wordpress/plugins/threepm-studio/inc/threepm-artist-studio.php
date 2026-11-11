<?php
defined('ABSPATH')||exit;
final class ThreePM_Artist_Studio {
 static function url($args=[]){return add_query_arg($args,ThreePM_Market::url());}
 static function nav($active){
  $s='<nav aria-label="Artist Management">';
  foreach(['library'=>'Music','profile'=>'Profile','podcasts'=>'Podcasts','events'=>'Events','galleries'=>'Galleries','services'=>'Services']as $key=>$label)
   $s.='<a '.($key===$active?'aria-current="page"':'').' href="'.esc_url(self::url(['studio'=>$key])).'">'.esc_html($label).'</a>';
  return $s.'</nav>';
 }
 static function render(){
  $uid=get_current_user_id();
  if(!ThreePM_Market::approved($uid))return ThreePM_Market_UI::dashboard();
  $view=sanitize_key($_GET['studio']??'');
  if(!$view)$view=isset($_GET['creator_type'])?(['podcast'=>'podcasts','event'=>'events','mediagallery'=>'galleries'][$_GET['creator_type']]??'library'):((($_GET['tab']??'')==='releases')?'editor':(($_GET['tab']??'')==='profile'?'profile':'library'));
  $body=self::nav($view==='editor'?'library':$view);
  if($view==='profile')$body.=ThreePM_Gallery_Editor::profile(ThreePM_Market_UI::profile(ThreePM_Market::account($uid)),(int)(ThreePM_Market::account($uid)['data']['profile_id']??0));
  elseif($view==='services')$body.=ThreePM_Market_UI::services($uid);
  elseif($view==='editor'){
   $body.=ThreePM_Music_Library::editor($uid);
   $body.='<aside><h3>Collectible editions</h3><p>Per-track limited editions and purchase-funded minting are in development. Publishing music does not mint an NFT.</p></aside>';
  }elseif(in_array($view,['podcasts','events','galleries'],true)){
   $type=['podcasts'=>'podcast','events'=>'event','galleries'=>'mediagallery'][$view];
   $body.=ThreePM_Creator_Content::content_section($type);
  }else $body.=ThreePM_Music_Library::library($uid);
  return ThreePM_Market_UI::shell('Artist Management',$body,false);
 }
 static function library($uid){
  $term=isset($_GET['music_search'])?sanitize_text_field(wp_unslash($_GET['music_search'])):'';
  $state=sanitize_key($_GET['music_state']??'all');if(!in_array($state,['all','draft','publish'],true))$state='all';
  $page=max(1,absint($_GET['music_page']??1));
  $q=new WP_Query(['post_type'=>'release','post_status'=>$state==='all'?['draft','publish']:$state,'posts_per_page'=>20,'paged'=>$page,'s'=>$term,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid,'orderby'=>'modified','order'=>'DESC']);
  $s='<header><h2>Your music</h2><p>Manage releases, review track lists, and choose what listeners can see.</p><a class="tpm-button" href="'.esc_url(self::url(['studio'=>'editor','tab'=>'releases'])).'">Create release</a></header>';
  $s.='<form method="get" action="'.esc_url(ThreePM_Market::url()).'"><input type="hidden" name="studio" value="library"><label>Search music<input type="search" name="music_search" value="'.esc_attr($term).'"></label><label>Visibility<select name="music_state">';
  foreach(['all'=>'All music','draft'=>'Drafts','publish'=>'Public']as $key=>$label)$s.='<option value="'.$key.'" '.selected($state,$key,false).'>'.$label.'</option>';
  $s.='</select></label><button type="submit">Filter</button></form>';
  if(!$q->posts)$s.='<p>No releases found. Create a release or change your filters.</p>';
  foreach($q->posts as $p){
   $tracks=array_values(array_filter((array)get_post_meta($p->ID,'track_repeatable',true),'is_array'));
   $s.='<article class="tpm-manage-row">'.get_the_post_thumbnail($p->ID,'thumbnail').'<div><span class="tpm-badge">'.($p->post_status==='publish'?'Public':'Draft').'</span><h3>'.esc_html($p->post_title).'</h3><p>'.count($tracks).' tracks · Updated '.esc_html(get_the_modified_date('j M Y',$p)).'</p><details><summary>Track list</summary><ol>';
   foreach($tracks as $track)$s.='<li>'.esc_html($track['releasetrack_track_title']??'Untitled track').'</li>';
   $s.='</ol></details></div><div class="tpm-actions"><a class="tpm-button" href="'.esc_url(self::url(['studio'=>'editor','tab'=>'releases','release'=>$p->ID])).'">Edit release</a>';
   if($p->post_status==='publish')$s.='<a href="'.esc_url(get_permalink($p)).'">View public page</a>';
   $s.='</div></article>';
  }
  if($q->max_num_pages>1){$s.='<nav aria-label="Music pages">';for($n=1;$n<=$q->max_num_pages;$n++)$s.='<a '.($n===$page?'aria-current="page"':'').' href="'.esc_url(self::url(['studio'=>'library','music_page'=>$n,'music_search'=>$term,'music_state'=>$state])).'">'.$n.'</a>';$s.='</nav>';}
  return $s;
 }
}
