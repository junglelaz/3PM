<?php
final class ThreePM_Market_UI {
    public static function icon($name){
        $paths=[
            'profile'=>'<circle cx="12" cy="8" r="3"/><path d="M5 21v-3a7 7 0 0 1 14 0v3"/>',
            'market'=>'<path d="M3 9h18l-2-6H5L3 9Z M4 9v12h16V9 M9 21v-7h6v7 M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
            'services'=>'<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V3h8v4 M3 12a22 22 0 0 0 18 0 M12 12v4"/>',
            'contract'=>'<path d="M14 2H5v20h14V7l-5-5Z M14 2v6h5 M8 12h8 M8 16h5"/>',
            'songwriting'=>'<path d="m4 16 11-11 4 4L8 20H4v-4Z M13 7l4 4 M12 21h8"/>',
            'composition'=>'<path d="M9 18V5l11-2v13 M9 9l11-2"/><ellipse cx="6" cy="18" rx="3" ry="2"/><ellipse cx="17" cy="16" rx="3" ry="2"/>',
            'production'=>'<path d="M4 10v4 M8 5v14 M12 2v20 M16 7v10 M20 10v4"/>',
            'mixing'=>'<path d="M5 3v7m0 4v7 M12 3v12m0 4v2 M19 3v3m0 4v11"/><circle cx="5" cy="12" r="2"/><circle cx="12" cy="17" r="2"/><circle cx="19" cy="8" r="2"/>',
            'mastering'=>'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2"/><path d="M5 12a7 7 0 0 1 7-7 M12 19a7 7 0 0 0 7-7"/>',
            'feature'=>'<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v2a7 7 0 0 0 14 0v-2 M12 19v3 M8 22h8"/>',
            'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
            'revision'=>'<path d="M3 10a9 9 0 0 1 15-5l3 3 M21 3v5h-5 M21 14a9 9 0 0 1-15 5l-3-3 M3 21v-5h5"/>',
            'star'=>'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
            'plus'=>'<path d="M12 5v14 M5 12h14"/>'];
        return '<svg class="tpm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name]??$paths['services']).'</svg>';
    }
    public static function e($s){return esc_html((string)$s);}
    public static function field($name,$label,$value='',$type='text',$required=false){
        $id='tpm-'.preg_replace('/[^a-z0-9_-]/i','-',$name);$attr=' id="'.esc_attr($id).'" name="'.esc_attr($name).'"'.($required?' required':'');
        return '<label for="'.esc_attr($id).'">'.self::e($label).($type==='textarea'?'<textarea'.$attr.' rows="4">'.esc_textarea($value).'</textarea>':'<input'.$attr.' type="'.esc_attr($type).'" value="'.esc_attr($value).'">').'</label>';
    }
    public static function select($name,$label,$options,$selected){$s='<label>'.self::e($label).'<select name="'.esc_attr($name).'">';foreach($options as $value=>$text)$s.='<option value="'.esc_attr($value).'" '.selected((string)$selected,(string)$value,false).'>'.self::e($text).'</option>';return $s.'</select></label>';}
    public static function form($op,$r=null){return '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('threepm_market','_wpnonce',true,false).'<input type="hidden" name="action" value="threepm_market"><input type="hidden" name="op" value="'.esc_attr($op).'"><input type="hidden" name="record" value="'.(int)($r['id']??0).'"><input type="hidden" name="version" value="'.(int)($r['version']??0).'">';}
    public static function end($label){return '<button type="submit">'.self::e($label).'</button></form>';}
    public static function shell($title,$body,$show_nav=true){return '<section class="tpm-market"><p class="tpm-kicker">3PM / ARTISTS & SERVICES</p><h1>'.self::e($title).'</h1>'.($show_nav?'<nav aria-label="Artist and service workspace"><a href="'.esc_url(ThreePM_Market::url('services')).'">'.self::icon('services').'My Services</a><a href="'.esc_url(ThreePM_Market::url('market')).'">'.self::icon('market').'Service Market</a><a href="'.esc_url(ThreePM_Market::url('engagements')).'">'.self::icon('contract').'My engagements</a></nav>':'').(isset($_GET['saved'])?'<p role="status">Your changes were saved.</p>':'').$body.'</section>';}
    public static function login(){return self::shell('Your 3PM account','<p>Sign in to manage your profile and service projects.</p><a href="'.esc_url(wc_get_page_permalink('myaccount')).'">Sign in</a>');}
    public static function policy(){return '<div class="tpm-notice"><strong>Service policy</strong><p>Prices are set in $3PM tokens, with a USD reference in brackets. Until off-site launch, 1 $3PM = $0.05 USD. Clients pay the quoted equivalent in SOL or USDC on Solana. Your listed price splits into 80% SPEND, 5% HODL and 15% 3PM. Funds stay held until client approval or an administrator dispute decision. Seven days to review delivery; no automatic payout.</p><p>Artist cancellation or failure to deliver waives the 15% platform fee; other refunds retain it. Every refund deducts actual receiving and refund-processing costs, itemized before execution. For completed services, processing costs come from 3PM’s 15% share.</p></div>';}
    public static function gate(){return '<p class="tpm-notice"><strong>Payments are not enabled yet.</strong> You can prepare offerings and discuss projects. Contract signing, payments and wallet credits will open after testing.</p>';}
    public static function dashboard(){
        if(!is_user_logged_in())return self::login();$uid=get_current_user_id();$a=ThreePM_Market::account($uid);
        if(!$a||$a['state']!=='approved'){
            $body=$a?'<p>Artist application: '.self::e($a['state']).'.</p>':'';
            if($a&&!empty($a['data']['decision_reason']))$body.='<p>'.self::e($a['data']['decision_reason']).'</p>';
            if(!$a||in_array($a['state'],['rejected','revoked'],true))$body.=self::form('apply',$a).self::field('artist_name','Artist name',$a['data']['artist_name']??'','text',true).self::field('bio','Tell us about your music and services',$a['data']['bio']??'','textarea',true).self::end('Apply for Artist status');
            return self::shell('Become a 3PM Artist',$body,false);
        }
        $tab=sanitize_key($_GET['tab']??'profile');$body='<p>Account type: <strong>Artist</strong></p><nav aria-label="Artist editing sections">';foreach(['profile'=>'Profile & gallery','releases'=>'Music releases','services'=>'Your services']as $k=>$v)$body.='<a href="'.esc_url(add_query_arg('tab',$k,ThreePM_Market::url())).'">'.self::e($v).'</a>';$body.='</nav>';
        if($tab==='services')$body.=self::services($uid);elseif($tab==='releases')$body.=self::releases($uid);else $body.=self::profile($a);
        return self::shell('Artist Dashboard',$body);
    }
    public static function profile($a){
        $d=$a['data'];$id=(int)($d['profile_id']??0);$photo=$id?get_post_thumbnail_id($id):0;$links=$id?get_post_meta($id,'_threepm_profile_websites',true):'';
        $done=(!empty($d['artist_name'])?1:0)+(!empty($d['bio'])?1:0)+($photo?1:0)+($links?1:0);
        $s='<h2>Your public profile</h2><p>Profile checklist: '.$done.'/4 complete — artist name, biography, photo and links.</p>';
        if($id)$s.='<p><a href="'.esc_url(get_permalink($id)).'">View profile</a></p>';
        $s.=self::form('profile',$a).self::field('artist_name','Artist name',$d['artist_name']??'','text',true).self::field('bio','Biography',$d['bio']??'','textarea',true).self::field('links','External links — one Label | https://address per line',$links,'textarea').self::field('photo','Profile image — JPEG, PNG or WebP, up to 5 MB','','file');
        if($photo)$s.=wp_get_attachment_image($photo,'thumbnail');
        $s.='<h3>Gallery</h3><p>Add one image per save, up to 20 images.</p>'.self::field('gallery_image','Gallery image','','file').self::field('gallery_alt','Image description / alt text');
        $images=$id?(array)get_post_meta($id,'_threepm_market_gallery',true):[];$opts=['0'=>'Keep all images'];foreach(array_filter($images)as $img){$s.=wp_get_attachment_image((int)$img,'thumbnail');$opts[(string)$img]='Remove image '.$img;}
        $s.=self::select('remove_image','Remove an image from this gallery',$opts,'0').self::select('state','Profile visibility',['draft'=>'Draft','publish'=>'Public'],$id?get_post_status($id):'draft');return $s.self::end('Save profile');
    }
    public static function releases($uid){
        $s='<h2>Your music</h2>';foreach(get_posts(['post_type'=>'release','post_status'=>['draft','publish'],'numberposts'=>100,'meta_key'=>'_threepm_artist_owner','meta_value'=>$uid])as $p)$s.='<p><a href="'.esc_url(add_query_arg(['tab'=>'releases','release'=>$p->ID],ThreePM_Market::url())).'">'.self::e($p->post_title).'</a> · '.self::e($p->post_status).'</p>';
        $id=absint($_GET['release']??0);$p=null;if($id){try{$p=ThreePM_Market::native_owner($id,'release',$uid);}catch(Throwable $e){return $s.'<p>Release unavailable.</p>';}}
        $s.='<a href="'.esc_url(add_query_arg('tab','releases',ThreePM_Market::url())).'">New release</a>'.self::form('release').'<input type="hidden" name="release_id" value="'.$id.'"><input type="hidden" name="modified" value="'.esc_attr($p->post_modified_gmt??'').'">';
        $s.=self::field('title','Release title',$p->post_title??'','text',true).self::select('release_type','Release type',['single'=>'Single','ep'=>'EP','mixtape'=>'Mixtape','album'=>'Album'],$id?get_post_meta($id,'_threepm_release_type',true):'single').self::field('description','Release description',$p?wp_strip_all_tags($p->post_content):'','textarea').self::field('release_date','Release date',$id?get_post_meta($id,'general_release_details_release_date',true):'','date',true).self::field('label','Label',$id?get_post_meta($id,'general_release_details_label',true):'').self::field('cover','Cover image','','file');
        $tracks=$id?(array)get_post_meta($id,'track_repeatable',true):[];$tracks=array_values(array_filter($tracks,'is_array'));$tracks[]=[];
        foreach($tracks as $i=>$track){$s.='<fieldset><legend>Track '.($i+1).'</legend>';foreach(['title'=>['Title','releasetrack_track_title'],'artists'=>['Artists','releasetrack_artist_name'],'preview'=>['Public MP3 preview URL','releasetrack_mp3_demo'],'embed'=>['SoundCloud / YouTube URL','releasetrack_scurl'],'buy'=>['Purchase / download URL','releasetrack_buyurl']]as $key=>$spec)$s.=self::field('tracks['.$i.']['.$key.']',$spec[0],$track[$spec[1]]??'',in_array($key,['title','artists'],true)?'text':'url');$s.='</fieldset>';}
        $s.='<p>Save to add another track. Clear a track title to remove it. Preview links are public; never use private delivery links here.</p>'.self::select('state','Release visibility',['draft'=>'Draft','publish'=>'Public'],$p->post_status??'draft');return $s.self::end('Save release');
    }
    public static function creator_services(){
        if(!is_user_logged_in())return self::login();if(!ThreePM_Market::approved(get_current_user_id()))return self::dashboard();
        if(!empty($_GET['preview_offering'])){try{$r=ThreePM_Market::record(absint($_GET['preview_offering']),'service');ThreePM_Market::own($r);return self::single_service($r,true);}catch(Throwable $e){return self::shell('Offering unavailable','<p>This offering is not available to your account.</p>');}}
        return self::shell('My Services',self::services(get_current_user_id()));
    }
    public static function services($uid){
        $records=ThreePM_Market_Store::list('service',$uid);$id=absint($_GET['record']??0);$new=isset($_GET['new']);
        $s='<div class="tpm-toolbar"><p>Turn your creative skills into clearly defined services.</p><a class="tpm-button" href="'.esc_url(add_query_arg('new','1',ThreePM_Market::url('services'))).'">'.self::icon('plus').'Create service offering</a></div>';
        $counts=['published'=>0,'draft'=>0,'paused'=>0,'archived'=>0];foreach($records as $r)$counts[$r['state']]++;
        $s.='<div class="tpm-stats">';foreach($counts as $state=>$count)$s.='<div><strong>'.$count.'</strong><span>'.self::e(ucfirst($state)).'</span></div>';$s.='</div>';
        if($id||$new){$r=null;if($id){try{$r=ThreePM_Market::record($id,'service');ThreePM_Market::own($r);}catch(Throwable $e){return $s.'<p>Service unavailable.</p>';}}
            return $s.'<p><a href="'.esc_url(ThreePM_Market::url('services')).'">← All my services</a></p><h2>'.($r?'Edit offering':'Create an offering').'</h2>'.self::service_form($r);
        }
        if(!$records)$s.='<div class="tpm-empty"><h2>Your first offering starts here.</h2><p>Choose a service, set your $3PM price, and describe the scope, delivery and rights. Save a draft whenever you need more time.</p></div>';
        foreach($records as $r){$t=$r['data']['terms'];$s.='<article class="tpm-manage-row"><div><span class="tpm-badge">'.self::e(ucfirst($r['state'])).(!empty($r['data']['moderation_hold'])?' · Admin hold':'').'</span><h3>'.self::e($t['title']?:'Untitled offering').'</h3><p>'.self::e(ThreePM_Market_Domain::price_label($t['price_coin_micros'])).'</p></div><div class="tpm-actions"><a class="tpm-button" href="'.esc_url(ThreePM_Market::url('services',$r['id'])).'">Manage</a><a href="'.esc_url(ThreePM_Market_Offerings::permalink($r)).'">'.(ThreePM_Market_Offerings::public_record($r)?'View listing':'Preview').'</a></div></article>';}
        return $s;
    }
    public static function service_form($r=null,$admin=false){
$html=self::detailed_service_form($r,$admin);
$html=str_replace('Describe exactly what clients receive. Required details are checked when you publish.','Start with the essentials. Secondary details can be completed when agreeing a project.',$html);
$html=str_replace(['On approved settlement: SPEND ',' · HODL ',' · 3PM fee '],['On approved settlement, receive ',' in your SPEND wallet for immediate use + ',' in your HODL wallet. 3PM fee = '],$html);
$start=strpos($html,'<form ');$open=strpos($html,'>',$start)+1;$prefix=substr($html,0,$open);$body=substr($html,$open);
$core='';$body=preg_replace_callback('~<label\b[^>]*>.*?</label>~s',function($m)use(&$core){if(preg_match('~name="terms\[(category|title|description|price|delivery_days|revisions)\]"~',$m[0])){$core.=$m[0];return '';}return $m[0];},$body);
$cut=strpos($body,'<details><summary>Review platform payment and refund policy');if($cut===false)return $html;
return $prefix.'<fieldset><legend>Crucial Info</legend>'.$core.'</fieldset><details class="tpm-secondary-info"><summary style="cursor:pointer;display:flex;align-items:center;gap:12px;padding:18px 20px;margin:16px 0;border:1px solid #caff4066;border-radius:8px;background:#caff400d;font-weight:600;color:#caff40;list-style:none"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg><span>Secondary Info</span><small style="margin-left:auto;font-size:10px!important;font-weight:300!important;letter-spacing:.14em;text-transform:uppercase;color:#a2b09a;line-height:1.5">Click to expand / collapse</small></summary><p>Optional for listing. Confirm relevant delivery, ownership and permissions details before accepting an agreement.</p>'.substr($body,0,$cut).'</details>'.substr($body,$cut);
}
public static function detailed_service_form($r=null,$admin=false){
        $t=$r['data']['terms']??[];$s='<p>Describe exactly what clients receive. Required details are checked when you publish.</p>'; $s.=self::form('service',$r).($admin?'<input type="hidden" name="admin_editor" value="1">':'').self::select('terms[category]','Service category',ThreePM_Market_Domain::CATEGORIES,$t['category']??'songwriting');
        $labels=['title'=>'Service title','description'=>'Public description','scope'=>'Scope, quantity limits and exclusions','deliverables'=>'Deliverables and file formats','client_inputs'=>'Required client project details and source files','revisions_scope'=>'Included revisions and extra-work rules','rights'=>'Ownership, licence, exclusivity and royalty shares','credits'=>'Credit wording and placement','permissions'=>'Third-party permissions, confidentiality and portfolio use','cancellation'=>'Cancellation and refund terms consistent with 3PM policy'];
        foreach($labels as $k=>$label)$s.=self::field('terms['.$k.']',$label,$t[$k]??'',$k==='title'?'text':'textarea');
        $s.=self::field('terms[price]','Listed price ($3PM tokens)',isset($t['price_coin_micros'])?ThreePM_Market_Domain::decimal($t['price_coin_micros']):'','text',true).self::field('terms[delivery_days]','Turnaround in days after funding and required inputs',$t['delivery_days']??7,'number',true).self::field('terms[revisions]','Included revision rounds',$t['revisions']??2,'number',true);
        foreach(ThreePM_Market_Domain::CATEGORY_FIELDS as $category=>$fields){
            $s.='<fieldset data-service-category="'.esc_attr($category).'"><legend>'.self::e(ThreePM_Market_Domain::CATEGORIES[$category]).' details</legend>';
            foreach($fields as $key=>$label)$s.=self::field('terms[category_terms]['.$category.']['.$key.']',$label,($t['category']??'songwriting')===$category?($t['category_terms'][$key]??''):'','textarea');$s.='</fieldset>';
        }
        $s.='<details><summary>Review platform payment and refund policy</summary>'.self::policy().'</details>';
        $s.=self::field('terms[technical_terms]','Additional technical terms (optional)',$t['technical_terms']??'','textarea');
        if(isset($t['price_coin_micros'])){$alloc=ThreePM_Market_Domain::allocation($t['price_coin_micros']);$s.='<p>Listed price: '.self::e(ThreePM_Market_Domain::price_label($t['price_coin_micros'])).'</p><p>On approved settlement: SPEND '.self::e(ThreePM_Market_Domain::price_label($alloc['spend_coin_micros'])).' · HODL '.self::e(ThreePM_Market_Domain::price_label($alloc['hodl_coin_micros'])).' · 3PM fee '.self::e(ThreePM_Market_Domain::price_label($alloc['platform_coin_micros'])).'.</p>';}
        if($admin)$s.='<fieldset><legend>Administrator moderation</legend><label><input type="checkbox" name="moderation_hold" value="1" '.(!empty($r['data']['moderation_hold'])?'checked':'').'> Hold this offering offline (choose Paused or Archived below)</label>'.self::field('moderation_note','Reason visible to the creator',$r['data']['moderation_note']??'','textarea').'</fieldset>';
        elseif(!empty($r['data']['moderation_hold']))$s.='<p class="tpm-notice">This offering is on administrator hold. '.self::e($r['data']['moderation_note']??'').'</p>';
        $s.='<label class="tpm-check"><input type="checkbox" name="terms[policy_ack]" value="1"> I agree to the displayed service, fee and refund policy for this listing.</label>'.self::select('state','Listing status',['draft'=>'Draft','published'=>'Published','paused'=>'Paused','archived'=>'Archived'],$r['state']??'draft');return $s.self::end('Save service');
    }
    public static function creator($r){$a=ThreePM_Market::account((int)$r['owner']);return ['name'=>$a['data']['artist_name']??'3PM creator','profile'=>(int)($a['data']['profile_id']??0)];}
    public static function service_card($r){
        $t=$r['data']['terms'];$creator=self::creator($r);$category=ThreePM_Market_Domain::CATEGORIES[$t['category']]??'Service';
        $url=ThreePM_Market_Offerings::permalink($r);$summary=mb_strlen($t['description'])>180?mb_substr($t['description'],0,177).'…':$t['description'];
        return '<article class="tpm-card tpm-offering"><div class="tpm-card-art" aria-hidden="true">'.self::icon($t['category']).'<span>'.self::e($category).'</span><b>3PM / SERVICES</b></div><div class="tpm-card-body"><p class="tpm-kicker">'.self::e($creator['name']).'</p><h3><a href="'.esc_url($url).'">'.self::e($t['title']).'</a></h3><p>'.self::e($summary).'</p><p class="tpm-rating">'.ThreePM_Market_Reviews::display((int)$r['id']).'</p><div class="tpm-card-bottom"><strong>'.self::e(ThreePM_Market_Domain::price_label($t['price_coin_micros'])).'</strong><span>'.(int)$t['delivery_days'].' days · '.(int)$t['revisions'].' revisions</span></div></div></article>';
    }
    public static function single_service($r,$preview=false){
        $t=$r['data']['terms'];$creator=self::creator($r);
        $s='<p><a href="'.esc_url(ThreePM_Market::url('market')).'">← All services</a></p>';
        if($preview)$s.='<p class="tpm-notice">Private preview — this offering is not visible in the Service Market.</p>';
        $s.='<div class="tpm-listing-layout"><div><p class="tpm-kicker">'.self::e(ThreePM_Market_Domain::CATEGORIES[$t['category']]).' / '.self::e($creator['name']).'</p><p class="tpm-lead">'.nl2br(self::e($t['description'])).'</p>';
        if($creator['profile']&&get_post_status($creator['profile'])==='publish')$s.='<a href="'.esc_url(get_permalink($creator['profile'])).'">View artist profile →</a>';
        foreach(['scope'=>'What’s included','deliverables'=>'What you’ll receive','client_inputs'=>'What to provide','revisions_scope'=>'Revisions','rights'=>'Rights and ownership','credits'=>'Credits','permissions'=>'Permissions and confidentiality','cancellation'=>'Cancellation terms','technical_terms'=>'Technical details'] as $key=>$label)if(!empty($t[$key]))$s.='<section class="tpm-detail"><h2>'.self::e($label).'</h2><p>'.nl2br(self::e($t[$key])).'</p></section>';
        $s.=ThreePM_Market_Reviews::render((int)$r['id']);
        $s.='<details><summary>Service specifications</summary>';foreach($t['category_terms']??[] as $key=>$value)$s.='<h3>'.self::e(ThreePM_Market_Domain::CATEGORY_FIELDS[$t['category']][$key]??$key).'</h3><p>'.nl2br(self::e($value)).'</p>';$s.='</details><details><summary>3PM payment and refund policy</summary>'.self::policy().'</details></div>';
        $s.='<aside class="tpm-order-panel"><p><strong>'.self::e($creator['name']).'</strong><br>Creator rating: '.ThreePM_Market_Reviews::display(0,(int)$r['owner']).'</p><p class="tpm-kicker">SERVICE PRICE</p><p class="tpm-price">'.self::e(ThreePM_Market_Domain::price_label($t['price_coin_micros'])).'</p><dl><dt>'.self::icon('clock').'Turnaround</dt><dd>'.(int)$t['delivery_days'].' days after funding and required inputs</dd><dt>'.self::icon('revision').'Included revisions</dt><dd>'.(int)$t['revisions'].'</dd></dl><p class="tpm-notice">Project enquiries are open. Contract signing and crypto payments are not available yet.</p>';
        if(!$preview&&is_user_logged_in()&&get_current_user_id()!==(int)$r['owner'])$s.=self::form('request').'<input type="hidden" name="listing" value="'.(int)$r['id'].'"><input type="hidden" name="listing_version" value="'.(int)$r['version'].'"><input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'">'.self::field('legal_name','Your legal name / contracting party','','text',true).self::field('project','Project brief and required inputs','','textarea',true).self::end('Send project request');
        elseif(!is_user_logged_in())$s.='<a class="tpm-button" href="'.esc_url(wc_get_page_permalink('myaccount')).'">Sign in to enquire</a>';
        elseif((int)$r['owner']===get_current_user_id())$s.='<a class="tpm-button" href="'.esc_url(ThreePM_Market::url('services',$r['id'])).'">Manage your offering</a>';
        $s.='<p>No payment is taken when you send a request.</p></aside></div>';return self::shell($t['title']?:'Service offering',$s);
    }
    public static function market(){
        $id=absint($_GET['record']??0);if($id){$r=ThreePM_Market_Store::get($id);return ThreePM_Market_Offerings::public_record($r)?self::single_service($r):self::shell('Service unavailable','<p>This listing is not available.</p>');}
        $category=sanitize_key($_GET['category']??'');$q=sanitize_text_field($_GET['search']??'');
        $s='<p class="tpm-lead">Find your next collaborator.</p><p>Songwriters, producers, engineers and featured artists. Clear scope, transparent prices, and a project built around your music.</p><div class="tpm-toolbar"><a class="tpm-button" href="'.esc_url(ThreePM_Market::url('services')).'">Offer your services</a><span>Prices in $3PM · USD reference in brackets</span></div><p class="tpm-notice">Browse offerings and send project requests. Crypto payments are not enabled yet.</p>';
        $s.='<form class="tpm-filters" method="get" action="'.esc_url(ThreePM_Market::url('market')).'">'.self::field('search','Search offerings',$q).self::select('category','Service category',[''=>'All services']+ThreePM_Market_Domain::CATEGORIES,$category).self::end('Find services');
        $matches=[];foreach(ThreePM_Market_Store::list('service',0,true) as $r){$t=$r['data']['terms'];$creator=self::creator($r);if(!ThreePM_Market_Offerings::public_record($r)||($category&&$category!==$t['category'])||($q&&stripos($t['title'].' '.$t['description'].' '.$creator['name'],$q)===false))continue;$matches[]=$r;}
        $pages=max(1,(int)ceil(count($matches)/12));$page=min($pages,max(1,absint($_GET['market_page']??1)));$s.='<p>'.count($matches).' offering'.(count($matches)===1?'':'s').'</p><div class="tpm-cards">';foreach(array_slice($matches,($page-1)*12,12) as $r)$s.=self::service_card($r);$s.='</div>';if($pages>1){$s.='<nav aria-label="Market pages">';if($page>1)$s.='<a href="'.esc_url(add_query_arg(['category'=>$category,'search'=>$q,'market_page'=>$page-1],ThreePM_Market::url('market'))).'">← Previous</a>';$s.='<span>Page '.$page.' of '.$pages.'</span>';if($page<$pages)$s.='<a href="'.esc_url(add_query_arg(['category'=>$category,'search'=>$q,'market_page'=>$page+1],ThreePM_Market::url('market'))).'">Next →</a>';$s.='</nav>'; }
        if(!$matches)$s.='<div class="tpm-empty"><h2>'.($q||$category?'No matching services yet.':'The next collaboration starts with you.').'</h2><p>'.($q||$category?'Try another category or a shorter search.':'Creators are preparing their offerings. If you make music, bring your skills to the market.').'</p><a href="'.esc_url(($q||$category)?ThreePM_Market::url('market'):ThreePM_Market::url('services')).'">'.($q||$category?'Reset filters':'Create your first offering').' →</a></div>';
        $s.='<div class="tpm-how"><div><b>01 / Find your creator</b><p>Explore services and review the scope and delivery terms.</p></div><div><b>02 / Share your project</b><p>Send your brief and agree the details with the creator.</p></div><div><b>03 / Review your agreement</b><p>Your project gets a tailored agreement.</p></div></div>';
        return self::shell('Service Market',$s);
    }
    public static function engagements(){
        if(!is_user_logged_in())return self::login();$s=self::gate();$id=absint($_GET['record']??0);$uid=get_current_user_id();
        if(!$id){foreach(ThreePM_Market_Store::list('engagement',$uid)as $r)$s.='<article class="tpm-card"><h2><a href="'.esc_url(ThreePM_Market::url('engagements',$r['id'])).'">'.self::e($r['data']['service']['title']).'</a></h2><p>'.self::e($r['state']).' · '.((int)$r['owner']===$uid?'You are the artist':'You are the client').'</p></article>';return self::shell('Your service engagements',$s);}
        try{$r=ThreePM_Market::record($id,'engagement');}catch(Throwable $e){return self::shell('Engagement unavailable','<p>This engagement is not available to your account.</p>');}$d=$r['data'];
        $s.='<h2>'.self::e($d['service']['title']).'</h2><dl><dt>Engagement</dt><dd>'.self::e($r['state']).'</dd><dt>Payment</dt><dd>'.self::e($d['funding']).'</dd><dt>Settlement</dt><dd>'.self::e($d['settlement']).'</dd></dl><h3>Project brief</h3><p>'.nl2br(self::e($d['project'])).'</p>';
        if(isset($d['agreement'])){
            $document=$d['agreement_document']??null;
            if(!ThreePM_Market_Agreement::verify($document)||!hash_equals($d['agreement_hash'],ThreePM_Market_Domain::hash($d['agreement']))||!hash_equals($d['agreement_hash'],$document['terms_hash']))return self::shell('Agreement unavailable','<p>Agreement integrity check failed. Contact 3PM.</p>');
            $s.='<article class="tpm-agreement"><h2>Service agreement</h2><div style="white-space:pre-wrap">'.self::e($document['text']).'</div><p class="tpm-hash">Agreement fingerprint: '.self::e($document['terms_hash']).'<br>Document fingerprint: '.self::e($document['text_sha256']).'</p></article>';
        }
        if($r['state']==='submitted'&&(int)$r['owner']===$uid)$s.=self::form('engagement',$r).'<input type="hidden" name="event" value="offer">'.self::field('legal_name','Artist legal name / authorized contracting party','','text',true).self::field('final_scope','Final project scope, inputs and delivery terms',$d['project'],'textarea',true).self::field('deadline','Agreed delivery deadline (UTC)','','date',true).self::end('Prepare client agreement');
        if(in_array($r['state'],['submitted','offered'],true)&&in_array($uid,[(int)$r['owner'],(int)$r['peer']],true))$s.=self::form('engagement',$r).'<input type="hidden" name="event" value="cancel">'.self::end('Cancel unfunded request');
        foreach($d['deliveries']??[] as $file){
            $url=wp_nonce_url(add_query_arg(['action'=>'threepm_delivery','engagement'=>$r['id'],'file'=>$file['key']],admin_url('admin-post.php')),'threepm_delivery_'.$r['id'].'_'.$file['key']);
            $s.='<p><a href="'.esc_url($url).'">Download '.self::e($file['name']).'</a> · '.(int)$file['bytes'].' bytes</p>';
        }
        foreach($d['updates']??[] as $update)$s.='<article class="tpm-card"><h3>'.self::e(ucwords($update['event'])).'</h3><p>'.nl2br(self::e($update['note'])).'</p><small>'.self::e($update['at']).'</small></article>';
        if(isset($d['review_due']))$s.='<p>Delivery review due: '.self::e($d['review_due']).'. No automatic payout.</p>';
        $artist=(int)$r['owner']===$uid;$client=(int)$r['peer']===$uid;
        if($artist&&in_array($r['state'],['active','revision_requested'],true))$s.=self::form('engagement',$r).'<input type="hidden" name="event" value="'.($r['state']==='active'?'deliver':'redeliver').'">'.self::field('note','Delivery description','','textarea',true).self::field('delivery_file','Private delivery file (up to 20 MB)','','file').self::end('Submit delivery for review');
        if($client&&$r['state']==='delivered'){
            if(($d['revisions_used']??0)<$d['service']['revisions'])$s.=self::form('engagement',$r).'<input type="hidden" name="event" value="revision">'.self::field('note','Requested revisions within agreed scope','','textarea',true).self::end('Request revision');
            $s.=self::form('engagement',$r).'<input type="hidden" name="event" value="approve"><p>Approval requests settlement. Wallet signing and verified payment confirmation are separate steps.</p>'.self::end('Approve delivery and request settlement');
        }
        if(($artist||$client)&&in_array($r['state'],['active','delivered','revision_requested','release_requested'],true))$s.=self::form('engagement',$r).'<input type="hidden" name="event" value="dispute">'.self::field('note','Dispute reason and supporting evidence','','textarea',true).self::end('Open dispute and freeze release');
        if(ThreePM_Market::admin()&&$r['state']==='disputed')$s.=self::form('engagement',$r).'<input type="hidden" name="event" value="resolve">'.self::select('resolution_reason','Resolution reason',['completed'=>'Work completed','mutual_cancellation'=>'Agreed cancellation','client_cancellation'=>'Client cancellation','artist_cancellation'=>'Artist cancellation','non_delivery'=>'Failure to deliver'],'mutual_cancellation').self::field('refund_tokens','Gross refund award in $3PM before processing deductions','','text',true).self::field('note','Decision and evidence','','textarea',true).'<p>Actual receiving and refund costs must be verified and itemized before execution. Recording a decision does not move funds.</p>'.self::end('Record proposed resolution');
        if(isset($d['resolution']))$s.='<h3>Proposed resolution</h3><p>'.nl2br(self::e($d['resolution']['note'])).'</p><p>'.self::e(str_replace('_',' ',$d['resolution']['execution'])).'</p>';
        if(ThreePM_Market_Reviews::eligible($r,$uid)){if(ThreePM_Market_Store::key('review:'.$r['id']))$s.='<p>Your review has been submitted for this engagement.</p>';else $s.=self::form('review',$r).'<h2>Review this service</h2>'.self::select('rating','Your rating',[''=>'Choose rating','0.5'=>'0.5/5','1'=>'1/5','1.5'=>'1.5/5','2'=>'2/5','2.5'=>'2.5/5','3'=>'3/5','3.5'=>'3.5/5','4'=>'4/5','4.5'=>'4.5/5','5'=>'5/5'],'').self::field('review','Your experience (published without your account name)','','textarea',true).self::end('Publish verified review');}
        $s.='<details><summary>Activity history</summary><ol>';foreach($d['history']??[]as $h)$s.='<li>'.self::e($h['at'].' — '.str_replace('_',' ',$h['event'])).'</li>';$s.='</ol></details>';return self::shell('Service engagement',$s);
    }
    public static function admin(){
        if(!ThreePM_Market::admin()){echo '<p>3PM administrator access is required.</p>';return;}
        echo '<div class="wrap"><h1>3PM Artist approvals</h1><p>Artist status does not change membership, shop or administrator permissions. Each decision is recorded.</p>';
        foreach(ThreePM_Market_Store::list('artist')as $r){echo '<article><h2>'.self::e($r['data']['artist_name']).' — '.self::e($r['state']).'</h2><p>Account ID '.(int)$r['owner'].'</p><p>'.self::e($r['data']['bio']).'</p>'.self::form('decision',$r).self::select('decision','Decision',['approved'=>'Approve','rejected'=>'Reject','suspended'=>'Suspend','revoked'=>'Revoke'],$r['state']).self::field('profile_id','Existing native Artist profile ID (optional; link only after verifying ownership)',$r['data']['profile_id']??'','number').self::field('release_ids','Existing release IDs to link (comma-separated; verify ownership first)').self::field('reason','Decision reason','','textarea',true).self::end('Record decision').'</article>';}
        echo '</div>';
    }
}
