<?php
/** Reviews depend on server-verified real payment evidence, never browser claims or devnet funds. */
final class ThreePM_Market_Reviews {
    public static function eligible($e,$uid){
        if(!$e||$e['kind']!=='engagement'||(int)$e['peer']!==$uid||(int)$e['owner']===$uid)return false;
        $p=$e['data']['verified_payment']??[];
        // This receipt can only be written by a future audited chain adapter. No public/admin form writes it.
        return ($e['data']['funding']??'')==='confirmed'&&($p['verifier']??'')==='threepm-service-chain-verifier-v1'
            &&($p['network']??'')==='solana-mainnet'&&($p['commitment']??'')==='finalized'
            &&($p['engagement_id']??0)===(int)$e['id']&&($p['payer_user']??0)===$uid
            &&is_int($p['amount_units']??null)&&$p['amount_units']>0
            &&is_string($p['signature']??null)&&preg_match('/^[1-9A-HJ-NP-Za-km-z]{64,88}$/D',$p['signature'])===1
            &&is_string($p['escrow']??null)&&preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D',$p['escrow'])===1;
    }
    public static function rating($value){
        if(!is_string($value)||!preg_match('/^(?:0\.5|[1-4](?:\.[05])?|5(?:\.0)?)$/D',$value))throw new InvalidArgumentException('Choose a rating from 0.5 to 5 in half-star steps.');
        return ((int)$value*2+(str_ends_with($value,'.5')?1:0))/2;
    }
    public static function stars($value){
        static $sequence=0;$value=max(0,min(5,(float)$value));$s='<span class="tpm-stars" aria-hidden="true">';
        for($i=0;$i<5;$i++){$percent=(int)round(max(0,min(1,$value-$i))*100);$id='tpm-star-'.(++$sequence);$s.='<svg viewBox="0 0 24 24" focusable="false"><defs><linearGradient id="'.$id.'"><stop offset="'.$percent.'%" stop-color="currentColor"/><stop offset="'.$percent.'%" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z" fill="url(#'.$id.')" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>';}
        return $s.'</span>';
    }
    public static function display($service=0,$creator=0){$a=self::average($service,$creator);return self::stars($a['average']??0).'<span>'.esc_html(self::label($service,$creator)).'</span>';}
    public static function submit($e,$uid,$rating,$body){
        if(!self::eligible($e,$uid))throw new RuntimeException('Only the client with a confirmed real service payment can review this engagement.');
        $rating=self::rating($rating);$body=ThreePM_Market_Domain::text($body,3000);if($body==='')throw new RuntimeException('Please describe your experience.');
        $r=ThreePM_Market_Store::create('review:'.$e['id'],'review',$uid,(int)$e['owner'],'published',['engagement_id'=>(int)$e['id'],'service_id'=>(int)$e['data']['service_id'],'creator_id'=>(int)$e['owner'],'rating'=>$rating,'body'=>$body,'verified_purchase'=>true,'submitted_at'=>gmdate('c')]);
        self::purge($r);return $r;
    }
    public static function purge($r){$id=ThreePM_Market_Offerings::post_id($r['data']['service_id']);if($id)do_action('litespeed_purge_post',$id);$a=ThreePM_Market::account((int)$r['peer']);if(!empty($a['data']['profile_id']))do_action('litespeed_purge_post',$a['data']['profile_id']);$market=(int)get_option('threepm_market_page_market');if($market)do_action('litespeed_purge_post',$market);}
    public static function visible($service=0,$creator=0){
        $out=[];foreach(ThreePM_Market_Store::all_reviews() as $r){$d=$r['data'];if($r['state']!=='published'||empty($d['verified_purchase'])||($service&&(int)$d['service_id']!==$service)||($creator&&(int)$d['creator_id']!==$creator))continue;$out[]=$r;}return $out;
    }
    public static function average($service=0,$creator=0){$reviews=self::visible($service,$creator);$count=count($reviews);return ['count'=>$count,'average'=>$count?array_sum(array_map(fn($r)=>(float)$r['data']['rating'],$reviews))/$count:null];}
    public static function label($service=0,$creator=0){$a=self::average($service,$creator);return $a['count']?number_format($a['average'],1).'/5 · '.$a['count'].' verified review'.($a['count']===1?'':'s'):'No verified reviews yet';}
    public static function render($service){$s='<section class="tpm-detail"><h2>Verified client reviews</h2><p>'.self::display($service).'</p>';foreach(array_slice(self::visible($service),0,20) as $r)$s.='<article class="tpm-review">'.self::stars($r['data']['rating']).'<strong>'.esc_html($r['data']['rating']).'/5</strong> <span class="tpm-badge">Verified purchase</span><p>'.nl2br(esc_html($r['data']['body'])).'</p><small>'.esc_html(substr($r['data']['submitted_at'],0,10)).'</small></article>';return $s.'</section>';}
    public static function admin(){
        if(!ThreePM_Market::admin())return;echo '<div class="wrap"><h1>Service reviews</h1><p>Only confirmed real payments qualify. Hidden reviews are excluded from public averages; the original review is retained for audit. Creator ratings weight each visible review equally across all services, including archived services.</p>';
        foreach(ThreePM_Market_Store::all_reviews() as $r){echo '<article><h2>Offering #'.(int)$r['data']['service_id'].' · '.esc_html($r['data']['rating']).'/5 · '.esc_html($r['state']).'</h2><p>'.esc_html($r['data']['body']).'</p>'.ThreePM_Market_UI::form('review_moderation',$r).ThreePM_Market_UI::select('state','Visibility',['published'=>'Visible','hidden'=>'Hidden'],$r['state']).ThreePM_Market_UI::field('reason','Moderation reason','','textarea',true).ThreePM_Market_UI::end('Save review visibility').'</article>';}
        echo '</div>';
    }
}
