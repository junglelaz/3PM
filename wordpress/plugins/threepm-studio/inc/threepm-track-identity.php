<?php
defined('ABSPATH') || exit;
final class ThreePM_Track_Identity {
 static function merge($id,$old,$rows){
  $old=array_values(array_filter((array)$old,'is_array'));$rows=array_values(array_filter((array)$rows,'is_array'));
  foreach($old as $i=>&$t)if(empty($t['threepm_id']))$t['threepm_id']=substr(hash('sha256',$id.':'.$i.':'.($t['releasetrack_track_title']??'').':'.($t['releasetrack_mp3_demo']??'')),0,32);unset($t);
  $used=[];$matches=[];
  foreach(['threepm_id','releasetrack_mp3_demo','releasetrack_track_title'] as $key){
   foreach($rows as $i=>$row){if(isset($matches[$i])||empty($row[$key]))continue;$candidates=[];foreach($old as $j=>$track)if(!isset($used[$j])&&($track[$key]??'')===$row[$key])$candidates[]=$j;
    // Duplicate audio/title cannot identify which track was edited or reordered.
    $same=0;foreach($rows as $k=>$other)if(!isset($matches[$k])&&($other[$key]??'')===$row[$key])$same++;
    if(count($candidates)===1&&$same===1){$matches[$i]=$candidates[0];$used[$candidates[0]]=true;}
   }
  }
  $remainingOld=array_diff(array_keys($old),array_keys($used));$remainingNew=array_diff(array_keys($rows),array_keys($matches));
  if(count($remainingOld)===1&&count($remainingNew)===1){$i=reset($remainingNew);$j=reset($remainingOld);$matches[$i]=$j;$used[$j]=true;}
  elseif($remainingOld&&$remainingNew)throw new RuntimeException('Track changes are ambiguous. Save one renamed/replaced track at a time, or use Artist Management, so playlists and statistics keep their track identities.');
  foreach($rows as $i=>&$row){
   if(isset($matches[$i])){$previous=$old[$matches[$i]];if(!empty($previous['threepm_edition_locked']))foreach(['releasetrack_track_title','releasetrack_mp3_demo','threepm_edition_quantity'] as $key)if(array_key_exists($key,$row)&&($row[$key]??null)!==($previous[$key]??null))throw new RuntimeException('An activated collectible cannot change its title, audio or edition quantity.');
    foreach($previous as $key=>$value)if(str_starts_with($key,'threepm_')&&!array_key_exists($key,$row))$row[$key]=$value;
    $row['threepm_id']=$previous['threepm_id'];
   }else $row['threepm_id']=str_replace('-','',wp_generate_uuid4());
  }unset($row);
  foreach($old as $j=>$track)if(!isset($used[$j])&&!empty($track['threepm_edition_locked']))throw new RuntimeException('An activated collectible track cannot be removed.');
  return $rows;
 }
 static function update($check,$id,$key,$value,$previous){
  static $writing=false;
  if($writing||$check!==null||$key!=='track_repeatable'||get_post_type($id)!=='release'||!is_array($value))return $check;
  // Artist Management already authenticates stable IDs and enforces edition locks.
  if(doing_action('admin_post_threepm_music'))return $check;
  try{$merged=self::merge($id,get_post_meta($id,$key,true),$value);}catch(RuntimeException $e){wp_die(esc_html($e->getMessage()),'Tracks not saved',['response'=>409,'back_link'=>true]);}
  if($merged===$value)return $check;
  $writing=true;try{return update_post_meta($id,$key,wp_slash($merged),$previous);}finally{$writing=false;}
 }
}
add_filter('update_post_metadata',['ThreePM_Track_Identity','update'],10,5);
