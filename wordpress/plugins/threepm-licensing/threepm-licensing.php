<?php
/**
 * Plugin Name: 3PM License Request Tool
 * Description: Private licence drafts, agreement previews and native My Account integration. Signing and financial operations are gated pending reviewed integrations.
 * Version: 0.3.7
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/domain.php';
final class ThreePM_Licensing {
    const TYPE='threepm_licence';
    public static function boot() {
        add_action('init',[__CLASS__,'init']);
        add_action('admin_init',[__CLASS__,'upgrade_pages']);
        add_action('admin_post_threepm_repair_kirki',[__CLASS__,'repair_kirki']);
        add_filter('woocommerce_account_menu_items',function($items){$logout=$items['customer-logout']??null; unset($items['customer-logout']); $items['sync-licensing']='Sync Licensing'; if($logout)$items['customer-logout']=$logout; return $items;});
        add_action('woocommerce_account_sync-licensing_endpoint',function(){echo self::dashboard();});
        add_action('woocommerce_account_dashboard',function(){echo '<p><a class="button" href="'.esc_url(wc_get_account_endpoint_url('sync-licensing')).'">Open Sync Licensing</a></p>';});
        add_action('admin_post_threepm_licence_save',[__CLASS__,'save']);
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
        add_action('template_redirect',[__CLASS__,'privacy']);
        add_action('admin_menu',function(){add_management_page('3PM Licensing readiness','3PM Licensing','manage_options','threepm-licensing',[__CLASS__,'readiness']);});
        add_shortcode('threepm_license_overview',[__CLASS__,'overview']);
        add_shortcode('threepm_license_builder',[__CLASS__,'builder']);
        add_shortcode('threepm_license_review',[__CLASS__,'review']);
        add_shortcode('threepm_license_details',[__CLASS__,'details']);
        add_shortcode('threepm_license_dashboard',[__CLASS__,'dashboard']);
        add_shortcode('threepm_license_payments',[__CLASS__,'payments']);
    }
    public static function init() {
        register_post_type(self::TYPE,['public'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>['title','author']]);
        add_rewrite_endpoint('sync-licensing',EP_PAGES);
    }
    public static function activate() {
        self::init();
        // Installation prepares draft pages only. Publishing remains a separate reviewable action.
        foreach(['overview'=>['Music Licensing','music-licensing'],'builder'=>['Create a Licence','license-builder'],'review'=>['Review a Licence','license-review'],'details'=>['Licence Details','license-details']] as $key=>$page) {
            if (!get_option('threepm_licence_page_'.$key)) {
                $id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>$page[0],'post_name'=>$page[1],'post_content'=>'[threepm_license_'.$key.']'],true);
                if(!is_wp_error($id))update_option('threepm_licence_page_'.$key,$id,false);
            }
        }
        flush_rewrite_rules();
    }
    public static function upgrade_pages() {
        if(!current_user_can('manage_options') || get_option('threepm_licence_pages_version')==='0.3.2')return;
        $ok=true;
        foreach(['overview'=>['Music Licensing','music-licensing'],'builder'=>['Create a Licence','license-builder'],'review'=>['Review a Licence','license-review'],'details'=>['Licence Details','license-details'],'dashboard'=>['Sync Licensing','licensing-dashboard'],'payments'=>['Licensing Payments & Receipts','licensing-payments']] as $key=>$page){
            $id=(int)get_option('threepm_licence_page_'.$key);$post=$id?get_post($id):null;
            $content='[threepm_license_'.$key.']';
            if(!$post){
                $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$page[0],'post_name'=>$page[1],'post_content'=>$content],true);
                if(is_wp_error($id)){$ok=false;continue;}
                update_option('threepm_licence_page_'.$key,$id,false);
            } elseif($key==='dashboard' && $post->post_type==='page' && trim($post->post_content)===$content && $post->post_name==='sync-licensing'){
                if(is_wp_error(wp_update_post(['ID'=>$id,'post_name'=>'licensing-dashboard'],true)))$ok=false;
            } elseif($post->post_type==='page' && trim($post->post_content)===$content && $post->post_status==='draft'){
                if(is_wp_error(wp_update_post(['ID'=>$id,'post_status'=>'publish'],true)))$ok=false;
            }
        }
        if($ok){update_option('threepm_licence_pages_version','0.3.2',false);flush_rewrite_rules(false);}
    }
    public static function navigation(){
        $out='<nav class="tpm-workspace-nav" aria-label="Licensing workspace">';
        foreach(['overview'=>'Overview','dashboard'=>'My requests','builder'=>'Create request','payments'=>'Payments & receipts'] as $key=>$label)$out.='<a href="'.esc_url(self::url($key)).'">'.esc_html($label).'</a>';
        return $out.'</nav>';
    }
    public static function assets() {
        // Load once globally to support the existing Vice AJAX page navigation.
        wp_enqueue_style('threepm-licensing',plugins_url('assets/licensing.css',__FILE__),[],'0.3.7');
        wp_enqueue_script('threepm-licensing',plugins_url('assets/licensing.js',__FILE__),[],'0.3.7',true);
    }
    public static function privacy() {
        $ids=array_map(function($k){return (int)get_option('threepm_licence_page_'.$k);},['builder','review','details','dashboard','payments']);
        if(is_page($ids) || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('sync-licensing'))) {
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            nocache_headers(); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
    }
    public static function url($key,$id=0) {
        $p=(int)get_option('threepm_licence_page_'.$key);
        $url=$p ? get_permalink($p) : home_url('/license-'.$key.'/');
        return $id ? add_query_arg('licence',$id,$url):$url;
    }
    public static function shell($title,$body,$eyebrow='3PM / SYNC LICENSING') {return '<section class="tpm-license"><p class="tpm-eyebrow">'.esc_html($eyebrow).'</p><h1>'.esc_html($title).'</h1>'.self::navigation().$body.'</section>';}
    public static function notice(){return '<div class="tpm-notice" role="note"><strong>Draft workspace</strong> — Save and review terms now. Legal signing, client sharing and payments are not yet available. No licence is granted by saving a draft.</div>';}
    public static function login(){return self::shell('Your music. Your terms.','<p>Use your existing 3PM account to create and manage licence requests.</p><a class="button" href="'.esc_url(wc_get_page_permalink('myaccount')).'">Sign in to My Account</a>');}
    public static function record($id) {
        $p=get_post($id);
        if(!$p || $p->post_type!==self::TYPE || !is_user_logged_in() || (int)$p->post_author!==get_current_user_id())return null;
        $snapshot=get_post_meta($id,'_snapshot',true);
        if(!is_array($snapshot) || !isset($snapshot['terms'],$snapshot['hash'],$snapshot['revision']))return null;
        if(!hash_equals($snapshot['hash'],ThreePM_Licence_Domain::hash($snapshot['terms'])))return null;
        return ['post'=>$p]+$snapshot;
    }
    public static function fields(){return [
        'Parties'=>['licensor_name'=>'Creator legal name','licensor_email'=>'Creator email','licensor_company'=>'Creator company and registration details','licensor_address'=>'Creator legal address','licensor_authority'=>'Creator signatory name, role and authority','client_name'=>'Client legal name','client_email'=>'Client email','client_company'=>'Client company and registration details','client_address'=>'Client legal address','client_authority'=>'Client signatory name, role and authority'],
        'Music & project'=>['title'=>'Track title','version'=>'Exact recording / version','master_id'=>'Master identifier (ISRC or unique reference)','composition_id'=>'Composition identifier (ISWC or unique reference)','writers'=>'Writers, shares and publishers','master_control'=>'Master owners, labels and documented authority','composition_control'=>'Composition owners and documented authority','permissions'=>'Collaborators, samples and third-party permissions','project'=>'Project name and description','deliverables'=>'Agreed deliverables and secure delivery method'],
        'Scope & restrictions'=>['exclusive_scope'=>'Exclusivity scope (media, territory, term and sector)','restrictions'=>'Other restrictions / prohibited uses','credit'=>'Credit wording and placement (or explicitly no credit required)'],
        'Termination & disputes'=>['breach'=>'Breach grounds and notice procedure','remedy'=>'Remedy period for breaches other than non-payment','existing_publications'=>'Treatment of already-published content after cancellation or expiry','jurisdiction'=>'Proposed courts / dispute forum (requires legal review)']
    ];}
    public static function overview() {
        return self::shell("Your music.\nOne clear agreement.",'<p class="tpm-lead">Turn an agreed music licensing deal into one request: define the use, agree the terms and track each licensed period.</p>'.self::notice().'<div class="tpm-actions"><a class="button" href="'.esc_url(self::url('builder')).'">Build a licence request ↗</a><a href="'.esc_url(wc_get_account_endpoint_url('sync-licensing')).'">My Sync Licensing</a></div><div class="tpm-grid"><article><span>01 / DEFINE</span><h2>Make the scope clear.</h2><p>Select the exact recording, composition rights, media, territory and coverage dates. You retain copyright ownership.</p></article><article><span>02 / AGREE</span><h2>One version. Both parties.</h2><p>The planned signing flow binds both acceptances to the same agreement and payment schedule. Connecting a wallet never counts as signing.</p></article><article><span>03 / GET PAID</span><h2>Payment unlocks coverage.</h2><p>When settlement is enabled, confirmed payment activates its agreed period. Recurring requests are not automatic collection.</p></article></div><h2>A clear split, every payment.</h2><div class="tpm-grid"><article><strong class="tpm-number">75%</strong><p>$3PM Coins in the Spend Wallet</p></article><article><strong class="tpm-number">5%</strong><p>$3PM Coins in the HODL Wallet</p></article><article><strong class="tpm-number">20%</strong><p>3PM platform fee</p></article></div><p>Before the off-platform token launch, 1 $3PM Coin = $0.05 USD. A $1,000 base licence payment allocates 15,000 Coins to the Spend Wallet, 1,000 Coins to the HODL Wallet and $200 to 3PM. Additional transaction charges are excluded from this split.</p><details><summary>Payments, costs and refunds</summary><p>The client pays sending and immediate settlement costs, including applicable account creation. The creator pays cash-out conversion and network costs and HODL claim costs. Charges must be shown before authorization. No future withdrawal allowance, client top-ups or treasury fee subsidy.</p><p>No voluntary refunds, subject to mandatory legal remedies. Termination and refunds are separate.</p><p>Planned Spend Wallet cash-out will show redemption value, asset/network, rate, costs and expected/minimum net receipt. It is not enabled in this draft release.</p></details><details><summary>HODL Wallet and launch</summary><p>Launch targets require both 5,000 paying users and a $500,000 treasury excluding Spend Wallet backing. Solana is the intended token network. Once claims open, one HODL Coin becomes one on-chain $3PM Coin. The full balance is available; users choose amount, timing and frequency and pay claim costs. The $0.05 prelaunch accounting value does not guarantee a future market price.</p></details><p class="tpm-muted">3rd Person Music (Pty) Ltd operates the platform. The creator is the Licensor and the client is the Licensee. 3PM does not verify music ownership through a wallet connection or blockchain record. Agreement templates require South African legal review before production use.</p>');
    }
    public static function builder() {
        if(!is_user_logged_in())return self::login();
        $id=absint($_GET['licence']??0); $r=$id?self::record($id):null;
        if($id&&!$r)return self::shell('Request unavailable','<p>This request is not available to your account.</p>');
        $t=$r['terms']??[]; $v=function($key)use($t){return esc_attr($t[$key]??'');};
        ob_start(); echo self::notice();
        if(isset($_GET['saved']))echo '<p role="status">Draft saved. Review the agreement before any further action.</p>';
        ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tpm-builder">
        <input type="hidden" name="action" value="threepm_licence_save"><input type="hidden" name="licence_id" value="<?php echo $id; ?>"><input type="hidden" name="revision" value="<?php echo $r['revision']??0; ?>">
        <?php wp_nonce_field('threepm_licence_save'); ?>
        <nav class="tpm-steps" aria-label="Builder sections"><a href="#tpm-parties">01 Parties</a><a href="#tpm-music">02 Music</a><a href="#tpm-rights">03 Rights</a><a href="#tpm-payments">04 Payments</a></nav>
        <?php foreach(self::fields() as $section=>$fields): ?><fieldset id="<?php echo $section==='Parties'?'tpm-parties':($section==='Music & project'?'tpm-music':'tpm-'.sanitize_title($section)); ?>"><legend><?php echo esc_html($section); ?></legend><div class="tpm-fields">
        <?php foreach($fields as $key=>$label): ?><label><?php echo esc_html($label); ?><input name="terms[<?php echo esc_attr($key); ?>]" type="<?php echo str_ends_with($key,'email')?'email':'text'; ?>" maxlength="2000" value="<?php echo $v($key); ?>" <?php echo in_array($key,['title','version','licensor_name','licensor_email','client_name','client_email','project'],true)?'required':''; ?>></label><?php endforeach; ?>
        </div></fieldset><?php endforeach; ?>
        <fieldset id="tpm-rights"><legend>Rights, media & territory</legend><div class="tpm-fields">
        <?php self::select('preset','Licence preset',['organic'=>'Organic online content','advertising'=>'Paid advertising','film'=>'Film / video'],$t); self::select('exclusivity','Exclusivity',['non-exclusive'=>'Non-exclusive','exclusive'=>'Exclusive'],$t); ?>
        <div class="tpm-territory-picker"><label>Filter territories<input type="search" data-country-search placeholder="Type to filter the territory list" aria-controls="tpm-territories"></label><label>Licensed territories<select id="tpm-territories" multiple size="6" name="terms[territories][]" data-countries required><?php foreach(['WORLD'=>'Worldwide']+WC()->countries->get_countries() as $code=>$name)echo '<option value="'.esc_attr($code).'" '.(in_array($code,$t['territories']??[],true)?'selected':'').'>'.esc_html($name).'</option>'; ?></select><small>Choose Worldwide or individual countries. Use Ctrl / Command to select several. Filtering does not change your selection.</small></label></div>
        <label>Licence starts<input type="date" name="terms[start]" value="<?php echo $v('start'); ?>" required></label><label>Licence ends<input type="date" name="terms[end]" value="<?php echo $v('end'); ?>"></label>
        </div><?php self::checkbox('perpetuity','In perpetuity',$t); ?>
        <div class="tpm-checks"><?php foreach(['sync'=>'Synchronization rights for the underlying composition','master'=>'Master-use rights for this recording','organic'=>'Organic use','paid'=>'Paid advertising','all_social'=>'All social media platforms','youtube'=>'YouTube','instagram'=>'Instagram','tiktok'=>'TikTok','facebook'=>'Facebook','x'=>'X','tv'=>'TV commercials','corporate'=>'Corporate videos','streaming'=>'Web streaming','excerpts'=>'Excerpts permitted','edits'=>'Edits permitted','looping'=>'Looping permitted','alterations'=>'Alterations permitted'] as $key=>$label)self::checkbox($key,$label,$t); ?></div></fieldset>
        <fieldset id="tpm-payments"><legend>Payment schedule</legend><p>Dates use UTC. Each period starts and ends on the stated calendar date. No automatic collection is authorized.</p><div class="tpm-fields"><?php self::select('payment_mode','Payment terms',['upfront'=>'Upfront','recurring'=>'Recurring requests'],$t);self::select('cadence','Cadence',['custom'=>'Custom','weekly'=>'Weekly','monthly'=>'Monthly','quarterly'=>'Quarterly','annual'=>'Annual'],$t); ?>
        <?php $assets=[''=>'Select proposed asset / network','USDC / Solana'=>'USDC / Solana','SOL / Solana'=>'SOL / Solana']; if(!empty($t['asset_network'])&&!isset($assets[$t['asset_network']]))$assets[$t['asset_network']]=$t['asset_network'].' (saved proposal)'; self::select('asset_network','Proposed asset / network',$assets,$t); ?><small>Proposal only — payments remain disabled.</small>
        </div><div class="tpm-schedule" data-schedule><?php $rows=$t['schedule']??[['amount_cents'=>null,'due'=>'','from'=>'','to'=>'']]; foreach($rows as $i=>$row)self::schedule_row($i,$row,($t['payment_mode']??'upfront')==='recurring'); ?></div><button type="button" data-add-payment>Add payment period</button><p>Allocation on save: 75% Spend Wallet · 5% HODL Wallet · 20% 3PM platform fee.</p><p data-allocation aria-live="polite"></p>
        <p>Seven full days of grace follow the unpaid due date. Planned reminders notify both parties. Cancellation and the treatment of existing publications require reviewed terms before enforcement.</p></fieldset>
        <fieldset><legend>Fresh rights declaration</legend><label class="tpm-check"><input type="checkbox" name="rights_master" value="1" required> I control or have documented authority to license the master recording, including all label, collaborator and sample permissions.</label><label class="tpm-check"><input type="checkbox" name="rights_composition" value="1" required> I control or have documented authority to license the underlying composition, including writers, publishers and third-party permissions.</label><label class="tpm-check"><input type="checkbox" name="rights_conflicts" value="1" required> I have checked existing commitments and confirm they do not conflict with this scope, including any exclusivity.</label><p>This records your representation. It does not independently verify ownership or remove liability. These confirmations reset on every save; signing will require a fresh declaration again.</p></fieldset>
        <div class="tpm-actions"><button type="submit">Save draft & review ↗</button><a href="<?php echo esc_url(wc_get_account_endpoint_url('sync-licensing')); ?>">Back to dashboard</a></div></form><?php
        return self::shell($id?'Edit your request.':'Build your licence.',ob_get_clean());
    }
    public static function select($key,$label,$options,$t){echo '<label>'.esc_html($label).'<select name="terms['.esc_attr($key).']">';foreach($options as $value=>$text)echo '<option value="'.esc_attr($value).'" '.selected($t[$key]??'', $value,false).'>'.esc_html($text).'</option>';echo '</select></label>';}
    public static function checkbox($key,$label,$t){echo '<label class="tpm-check"><input type="checkbox" name="terms['.esc_attr($key).']" value="1" '.checked(!empty($t[$key]),true,false).'>'.esc_html($label).'</label>';}
    public static function schedule_row($i,$r,$recurring=false){echo '<div class="tpm-payment">';foreach(['amount'=>'Base price (USD)','due'=>'Payment due','from'=>'Coverage starts','to'=>'Coverage ends'] as $key=>$label){$val=$key==='amount'?(isset($r['amount_cents'])?number_format($r['amount_cents']/100,2,'.',''):''):($r[$key]??'');echo '<label><span'.($key==='due'?' data-due-label':'').'>'.esc_html($key==='due'&&$recurring&&$i===0?'First payment due':$label).'</span><input name="schedule['.$i.']['.$key.']" type="'.($key==='amount'?'text':'date').'" '.($key==='amount'?'inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?"':'').' value="'.esc_attr($val).'" '.($key!=='to'?'required':'').'></label>';}echo '<button type="button" data-remove-payment aria-label="Remove payment period">Remove</button></div>';}
    public static function save() {
        if(!is_user_logged_in())wp_die('Sign in first.',403);
        check_admin_referer('threepm_licence_save');
        $id=absint($_POST['licence_id']??0);$r=$id?self::record($id):null;
        if($id&&!$r)wp_die('Request unavailable.',403);
        if($r && (int)($_POST['revision']??-1)!==$r['revision'])wp_die('This draft changed in another session. Reload it before saving.',409);
        $lock='threepm_licence_lock_'.($id?:'new_'.get_current_user_id());
        if(!add_option($lock,time(),'','no'))wp_die('A save is already processing. Retry shortly; an administrator can inspect a stale lock.',409);
        try {
            foreach(['rights_master','rights_composition','rights_conflicts'] as $k)if(($_POST[$k]??'')!=='1')throw new InvalidArgumentException('Fresh confirmation of both rights and conflicting commitments is required.');
            // Re-read after acquiring lock to reject concurrent stale edits.
            if($id){clean_post_cache($id);$r=self::record($id);if((int)$_POST['revision']!==$r['revision'])throw new InvalidArgumentException('Draft changed. Reload before saving.');}
            $raw=wp_unslash($_POST['terms']??[]); if(!is_array($raw))throw new InvalidArgumentException('Invalid form.');$t=[];
            foreach(self::fields() as $fields)foreach($fields as $key=>$label){if(isset($raw[$key])&&!is_scalar($raw[$key]))throw new InvalidArgumentException('Invalid field.');$t[$key]=sanitize_textarea_field(substr((string)($raw[$key]??''),0,2000));}
            foreach(['title','version','licensor_name','licensor_email','client_name','client_email','project'] as $k)if(!$t[$k])throw new InvalidArgumentException('Complete the required parties, music and project fields.');
            foreach(['licensor_email','client_email'] as $k)if(!is_email($t[$k]))throw new InvalidArgumentException('Enter valid party email addresses.');
            foreach(['preset'=>['organic','advertising','film'],'exclusivity'=>['non-exclusive','exclusive'],'payment_mode'=>['upfront','recurring'],'cadence'=>['weekly','monthly','quarterly','annual','custom']] as $k=>$values){if(!in_array($raw[$k]??'', $values,true))throw new InvalidArgumentException('Invalid selection.');$t[$k]=$raw[$k];}
            foreach(['perpetuity','sync','master','organic','paid','all_social','youtube','instagram','tiktok','facebook','x','tv','corporate','streaming','excerpts','edits','looping','alterations'] as $k)$t[$k]=isset($raw[$k])&&$raw[$k]==='1';
            if(!$t['sync']&&!$t['master'])throw new InvalidArgumentException('Select the rights being licensed.');
            if(!$t['organic']&&!$t['paid'])throw new InvalidArgumentException('Specify organic use or paid advertising.');
            if(!array_filter(array_intersect_key($t,array_flip(['all_social','youtube','instagram','tiktok','facebook','x','tv','corporate','streaming']))))throw new InvalidArgumentException('Select at least one permitted medium.');
            if($t['exclusivity']==='exclusive'&&!$t['exclusive_scope'])throw new InvalidArgumentException('Define the exclusive scope.');
            $allowed=['WORLD'=>'Worldwide']+WC()->countries->get_countries();$territories=$raw['territories']??[];
            if(!is_array($territories)||!count($territories)||array_diff($territories,array_keys($allowed)))throw new InvalidArgumentException('Choose valid territories.');
            if(in_array('WORLD',$territories,true)&&count($territories)>1)throw new InvalidArgumentException('Choose Worldwide alone or specific countries.');
            $t['territories']=array_values(array_unique($territories));sort($t['territories']);
            foreach(['start','end','asset_network'] as $k)$t[$k]=sanitize_text_field($raw[$k]??'');
            $t['schedule']=ThreePM_Licence_Domain::schedule(wp_unslash($_POST['schedule']??[]),$t['start'],$t['end'],$t['perpetuity'],$t['payment_mode']);
            $t['template']=ThreePM_Licence_Domain::TEMPLATE;$t['governing_law']='South Africa — proposed, subject to legal review';$t['grace_days']=7;$t['automatic_collection']=false;
            $t['refund_policy']='No voluntary refunds, subject to mandatory legal remedies. Termination does not automatically produce a refund.';
            $t['fee_policy']='Client pays sending, immediate settlement and applicable account creation costs. Creator pays cash-out conversion/network and HODL claim costs. No treasury subsidy or future client-funded withdrawal allowance.';
            $t['allocation']=['spend_percent'=>75,'hodl_percent'=>5,'platform_percent'=>20,'usd_per_coin'=>'0.05'];
            $t['platform_role']='3rd Person Music (Pty) Ltd operates the platform; the creator is Licensor and the client is Licensee. Copyright ownership is not transferred.';
            $t['late_payment_policy']='Seven full calendar days after the due date are a reminder-only grace period. Both parties are to be notified. If still unpaid, the proposed licence is cancelled for further use. Continued use without permission may infringe rights and lead to legal action. Enforcement and existing-publication treatment await legal review.';
            $revision=($r['revision']??0)+1;
            if(!$id){$id=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'private','post_author'=>get_current_user_id(),'post_title'=>$t['title'].' — '.$t['client_name']],true);if(is_wp_error($id))throw new RuntimeException('Could not save request.');}
            // Store terms, hash and revision together, so readers cannot see a mixed version.
            $snapshot=['terms'=>$t,'hash'=>ThreePM_Licence_Domain::hash($t),'revision'=>$revision];
            if(!update_post_meta($id,'_snapshot',wp_slash($snapshot)))throw new RuntimeException('Draft storage failed. No new version was confirmed.');
            add_post_meta($id,'_draft_history',wp_slash(['revision'=>$revision,'terms'=>$t,'hash'=>ThreePM_Licence_Domain::hash($t),'creator'=>get_current_user_id(),'at'=>gmdate('c'),'rights_master'=>true,'rights_composition'=>true,'no_conflict'=>true,'acceptance'=>false]));
            delete_option($lock);wp_safe_redirect(add_query_arg('saved','1',self::url('details',$id)));exit;
        } catch(Throwable $e){delete_option($lock);wp_die(esc_html($e->getMessage()),'Draft not saved',['response'=>400,'back_link'=>true]);}
    }
    public static function dashboard() {
        if(!is_user_logged_in())return self::login();
        $posts=get_posts(['post_type'=>self::TYPE,'post_status'=>'private','author'=>get_current_user_id(),'numberposts'=>-1,'orderby'=>'modified']);
        $records=[];foreach($posts as $post){$r=self::record($post->ID);if($r)$records[]=$r;}
        $views=['drafts'=>'Drafts','sent'=>'Sent','active'=>'Active','expired'=>'Expired','recurring'=>'Recurring','overdue'=>'Overdue'];
        $view=is_string($_GET['view']??null)?sanitize_key($_GET['view']):'drafts';if(!isset($views[$view]))$view='drafts';
        $recurring=array_values(array_filter($records,function($r){return $r['terms']['payment_mode']==='recurring';}));
        $body=self::notice().'<p>New licence allocation: 75% Spend Wallet · 5% HODL Wallet · 20% 3PM platform fee.</p><div class="tpm-actions"><a class="button" href="'.esc_url(self::url('builder')).'">New licence request ↗</a><a href="'.esc_url(wc_get_account_endpoint_url(get_option('woocommerce_woo_wallet_endpoint','my-wallet'))).'">Open existing Spend Wallet</a></div><div class="tpm-grid"><article><strong class="tpm-number">'.count($records).'</strong><p>Draft requests</p></article><article><strong class="tpm-number">0</strong><p>Active licences</p></article><article><strong class="tpm-number">$0</strong><p>Licensing payments received</p></article></div><nav class="tpm-tabs" aria-label="Request status">';
        foreach($views as $key=>$label){$count=$key==='drafts'?count($records):($key==='recurring'?count($recurring):0);$body.='<a '.($view===$key?'aria-current="page" ':'').'href="'.esc_url(add_query_arg('view',$key,self::url('dashboard'))).'">'.$label.' <span>'.$count.'</span></a>';}
        $body.='</nav><h2>'.esc_html($views[$view]).'</h2>';
        $visible=$view==='drafts'?$records:($view==='recurring'?$recurring:[]);
        if($view==='recurring')$body.='<p>Proposed recurring schedules only. No automatic collection, payment reminders or coverage is active.</p>';
        if(!$visible){$messages=['drafts'=>'Create your first request to save the agreed music, use and payment schedule.','sent'=>'No requests sent. Client invitations stay disabled while signing is under review.','active'=>'No active licences. A draft or devnet receipt never activates coverage.','expired'=>'No expired licences. Draft end dates do not create licensed periods.','recurring'=>'No recurring schedules yet. Choose recurring payment terms in the builder.','overdue'=>'No overdue payments. Unpaid draft schedules do not create a debt or start cancellation.'];$body.='<article><p>'.esc_html($messages[$view]).'</p></article>';}
        foreach($visible as $r){$t=$r['terms'];$body.='<article class="tpm-request"><span class="tpm-badge">Draft · v'.$r['revision'].' · Coverage inactive</span><h2>'.esc_html($t['title']).'</h2><p>'.esc_html($t['client_name'].' · '.$t['version'].' · '.count($t['schedule']).' proposed payment(s)').'</p><div class="tpm-actions"><a href="'.esc_url(self::url('details',$r['post']->ID)).'">Details & agreement ↗</a><a href="'.esc_url(self::url('builder',$r['post']->ID)).'">Edit draft</a></div></article>';}
        return self::shell('Sync Licensing',$body);
    }
    public static function payments(){
        if(!is_user_logged_in())return self::login();
        return self::shell('Payments & receipts',self::notice().'<div class="tpm-grid"><article><strong class="tpm-number">$0</strong><p>Licensing payments received</p></article><article><strong class="tpm-number">0</strong><p>Payment receipts</p></article><article><strong class="tpm-number">0</strong><p>Licensed periods activated</p></article></div><h2>Payment history</h2><article><p>No licensing payments have been collected. Proposed amounts are shown inside each draft and are not invoices or payment demands.</p></article><h2>Receipts & coverage</h2><p>No payment receipts or coverage certificates have been issued. Administrator devnet test receipts are separate technical records and grant no music rights.</p><h2>Recurring reminders</h2><p>The planned policy includes a seven-day reminder-only grace period, notifying the client and the 3PM creator. Reminders and cancellation are disabled in this workspace.</p><div class="tpm-actions"><a class="button" href="'.esc_url(self::url('dashboard')).'">View my requests</a><a href="'.esc_url(wc_get_account_endpoint_url(get_option('woocommerce_woo_wallet_endpoint','my-wallet'))).'">Existing Spend Wallet</a></div>');
    }
    public static function details(){return self::review();}
    public static function review() {
        if(!is_user_logged_in())return self::login();$id=absint($_GET['licence']??0);$r=self::record($id);
        if(!$r)return self::shell('Private licence request','<p>Client links are not enabled in this draft release. Existing drafts are accessible only to their creator.</p>');
        $t=$r['terms'];ob_start();echo self::notice(); ?>
        <p class="tpm-badge">3PM-L-<?php echo $id; ?> · Draft v<?php echo $r['revision']; ?> · No licensed coverage</p>
        <p class="tpm-lead"><?php echo esc_html($t['title'].' / '.$t['version']); ?></p>
        <div class="tpm-grid"><article><span>LICENSEE</span><h2><?php echo esc_html($t['client_name']); ?></h2><p><?php echo esc_html($t['project']); ?></p></article><article><span>SCOPE</span><h2><?php echo esc_html(ucfirst($t['exclusivity'])); ?></h2><p><?php echo esc_html(implode(', ',$t['territories'])); ?></p></article><article><span>TERM</span><h2><?php echo esc_html($t['start']); ?></h2><p><?php echo $t['perpetuity']?'In perpetuity':esc_html('Until '.$t['end']); ?></p></article></div>
        <h2>Full agreement preview</h2><p><strong>Unreviewed template. Not a signed agreement.</strong> <?php echo esc_html($t['platform_role']); ?></p>
        <?php foreach(self::fields() as $section=>$fields){echo '<details open><summary>'.esc_html($section).'</summary><dl>';foreach($fields as $key=>$label)echo '<dt>'.esc_html($label).'</dt><dd>'.nl2br(esc_html($t[$key]?:'Not specified — must be resolved before signing')).'</dd>';echo '</dl></details>';}
        echo '<details open><summary>Rights and usage</summary><dl>';foreach(['preset','exclusivity','exclusive_scope','territories','start','end','perpetuity','sync','master','organic','paid','all_social','youtube','instagram','tiktok','facebook','x','tv','corporate','streaming','excerpts','edits','looping','alterations','asset_network','cadence'] as $k){$x=$t[$k];$val=is_bool($x)?($x?'Permitted / selected':'Not permitted / not selected'):(is_array($x)?implode(', ',$x):$x);echo '<dt>'.esc_html(ucwords(str_replace('_',' ',$k))).'</dt><dd>'.esc_html($val?:'Not specified').'</dd>';}echo '</dl></details>';
        ?><h2>Agreed payment schedule — draft</h2><div class="tpm-table"><table><thead><tr><th>Due</th><th>Coverage</th><th>Base USD</th><th>Spend Coins</th><th>HODL Coins</th><th>Platform USD</th></tr></thead><tbody><?php foreach($t['schedule'] as $p){$a=ThreePM_Licence_Domain::allocation($p['amount_cents'], $t['allocation']);echo '<tr><td>'.esc_html($p['due']).'</td><td>'.esc_html($p['from'].' → '.($p['to']??'In perpetuity')).'</td><td>'.number_format($p['amount_cents']/100,2).'</td><td>'.number_format($a['spend_coin_micros']/1000000,2).'</td><td>'.number_format($a['hodl_coin_micros']/1000000,2).'</td><td>'.rtrim(rtrim(number_format($a['fee_usd_micros']/1000000,6,'.',''),'0'),'.').'</td></tr>';} ?></tbody></table></div>
        <p>Exact allocation uses integer USD millionths and coin millionths. Display amounts may be rounded; the base allocation is never rounded in the ledger calculation. External payout rounding requires a reviewed provider policy. No charges have been quoted or authorized.</p>
        <?php foreach(['governing_law','refund_policy','fee_policy','late_payment_policy'] as $k)echo '<p><strong>'.esc_html(ucwords(str_replace('_',' ',$k))).':</strong> '.esc_html($t[$k]).'</p>'; ?>
        <p>Creator represents control or documented authority over both master and composition, all necessary third-party permissions and no conflicting commitments. This representation does not independently verify rights or eliminate liability. Signing requires renewed confirmation and non-infringement representations in the reviewed template.</p>
        <h2>Acceptance & verification</h2><p>Neither party has signed. Saving a draft or connecting a wallet does not constitute acceptance or payment authorization. Changes require fresh acceptance by both parties.</p><p class="tpm-hash">Version fingerprint (SHA-256): <?php echo esc_html($r['hash']); ?></p>
        <h2>Payments, receipts & deliverables</h2><p>No payments received. No receipts issued. No coverage activated. Deliverables remain private and are not uploaded to a blockchain.</p><div class="tpm-actions"><a class="button" href="<?php echo esc_url(self::url('builder',$id)); ?>">Edit draft</a><button disabled>Signing pending legal review</button><button disabled>Payment unavailable</button><button type="button" data-print-licence>Print draft agreement</button></div>
        <?php echo ThreePM_Solana_Devnet::panel($id,$r); return self::shell('Review every detail.',ob_get_clean());
    }
    public static function repair_kirki(){
        if(!current_user_can('manage_options'))wp_die('Administrator access required.',403);
        check_admin_referer('threepm_repair_kirki');
        $class='Kirki\\Manager\\PluginActiveEvents';
        if(!is_callable([$class,'create_page_stage_tables']))wp_die('Installed Kirki setup method is unavailable.');
        global $wpdb;
        // Kirki also deletes stage rows in deleted_post. Its documented fallback supports
        // hosts whose existing posts table cannot participate in this foreign key.
        // Scope the compatibility change to creation of the missing staging table only.
        $table=$wpdb->prefix.KIRKI_PAGE_STAGES_TABLE;
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        $compat=function($sql)use($wpdb,$table,$exists){
            if(!$exists && strpos($sql,'CREATE TABLE IF NOT EXISTS '.$table.' (')===0){
                $sql=preg_replace('/,\\s*FOREIGN KEY \(post_id\) REFERENCES '.preg_quote($wpdb->prefix.'posts','/').'\(ID\) ON DELETE CASCADE/','',$sql);
            }
            return $sql;
        };
        add_filter('query',$compat);
        try{$ok=$class::create_page_stage_tables();}finally{remove_filter('query',$compat);}

        set_transient('threepm_kirki_repair_'.get_current_user_id(),$ok?'Kirki staging tables verified. Installer cleanup can now run.':'Kirki setup failed: '.$wpdb->last_error,300);
        wp_safe_redirect(admin_url('tools.php?page=threepm-licensing'));exit;
    }
    public static function readiness(){
        $result=get_transient('threepm_kirki_repair_'.get_current_user_id());if($result)echo '<div class="notice notice-info"><p>'.esc_html($result).'</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="threepm_repair_kirki">';wp_nonce_field('threepm_repair_kirki');echo '<p><button class="button">Repair missing Kirki staging tables</button> Uses Kirki’s installed setup routine; existing data is retained.</p></form>';
        echo '<div class="wrap"><h1>3PM Licensing readiness</h1><p>Draft-only release with an administrator-only Solana devnet lab. Live payments, wallet credits, automatic mail, legal signing and public client links remain disabled. Test receipts grant no rights or coverage.</p><ul><li>Existing WordPress authentication and WooCommerce account endpoint are reused.</li><li>Spend Wallet: '.(function_exists('woo_wallet')?'TeraWallet detected; linked, not credited':'TeraWallet not detected').'.</li><li>HODL source ledger and atomic wallet reservation adapter: not verified.</li><li>Legal template, seven-day cancellation wording and existing-publication treatment: awaiting review.</li><li>Provider onboarding, signed webhooks, custody, reconciliation and payout quotes: not configured.</li><li>Production Solana settlement program, token mint and claim authorization: not deployed. Devnet wallet verification and test receipt controls are separate administrator tools.</li></ul><h2>Prepared pages</h2><ul>';
        foreach(['overview','builder','review','details','dashboard','payments'] as $key){$id=(int)get_option('threepm_licence_page_'.$key);echo '<li><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html(ucfirst($key)).'</a> — '.esc_html(get_post_status($id)).'</li>';}
        echo '</ul><p>Deactivation removes account hooks. Draft pages and records are retained. No existing wallet balance or payment setting is changed.</p></div>';
    }
}
require_once __DIR__.'/solana-devnet.php';
ThreePM_Licensing::boot();
register_activation_hook(__FILE__,['ThreePM_Licensing','activate']);
register_deactivation_hook(__FILE__,function(){flush_rewrite_rules();});
