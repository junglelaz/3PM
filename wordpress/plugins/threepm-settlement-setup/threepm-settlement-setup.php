<?php
/**
 * Plugin Name: 3PM Settlement Setup
 * Description: Administrator reserve configuration and wallet ownership verification. Payment execution is separate.
 * Version: 1.0.0
 * Requires PHP: 8.1
 */
defined('ABSPATH')||exit;
final class ThreePM_Settlement_Setup {
    static function boot(){
        add_action('admin_menu',function(){add_submenu_page('woocommerce','3PM Settlement Setup','3PM Settlement Setup','manage_woocommerce','threepm-settlement',[__CLASS__,'screen']);});
        add_action('admin_enqueue_scripts',function(){if(($_GET['page']??'')==='threepm-settlement')wp_enqueue_script('threepm-settlement-setup',plugins_url('assets/setup.js',__FILE__),[],'1.0.0',true);});
        add_action('wp_ajax_threepm_reserve_challenge',[__CLASS__,'challenge']);add_action('wp_ajax_threepm_reserve_verify',[__CLASS__,'verify']);
    }
    static function config(){
        $config=json_decode(file_get_contents(__DIR__.'/destinations.json'),true,512,JSON_THROW_ON_ERROR);
        $wallets=$config['wallets']??[];if(count($wallets)!==3||count(array_unique($wallets))!==3)throw new RuntimeException('Reserve destinations must be distinct.');
        foreach(['spendReserve','hodlReserve','platformFees'] as $role)self::decode($wallets[$role]??'');return $config;
    }
    static function decode($value){
        if(!is_string($value)||strlen($value)<32||strlen($value)>44)throw new InvalidArgumentException('Invalid wallet address.');
        $alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';$bytes=[];
        foreach(str_split($value) as $ch){$carry=strpos($alphabet,$ch);if($carry===false)throw new InvalidArgumentException('Invalid address character.');for($i=0;$i<count($bytes);$i++){$carry+=$bytes[$i]*58;$bytes[$i]=$carry&255;$carry>>=8;}while($carry){$bytes[]=$carry&255;$carry>>=8;}}
        $raw=str_repeat("\0",strspn($value,'1'));foreach(array_reverse($bytes) as $b)$raw.=chr($b);if(strlen($raw)!==32)throw new InvalidArgumentException('Invalid address length.');return $raw;
    }
    static function guard(){if(!current_user_can('manage_woocommerce'))wp_send_json_error(['message'=>'Administrator access required.'],403);check_ajax_referer('threepm_reserves','nonce');}
    static function role(){ $role=sanitize_text_field(wp_unslash($_POST['role']??''));if(!in_array($role,['spendReserve','hodlReserve','platformFees'],true))throw new RuntimeException('Unknown reserve.');return $role; }
    static function binding(){return hash_hmac('sha256',get_current_user_id().':'.wp_get_session_token(),wp_salt('auth'));}
    static function challenge(){
        self::guard();try{
            if(!function_exists('sodium_crypto_sign_verify_detached'))throw new RuntimeException('This server cannot verify wallet signatures.');
            $role=self::role();$wallet=self::config()['wallets'][$role];$id=bin2hex(random_bytes(32));$expires=time()+300;
            $message="3PM RESERVE WALLET OWNERSHIP VERIFICATION\nOrigin: ".untrailingslashit(home_url())."\nPurpose: Verify control of the configured reserve wallet\nRole: ".$role."\nWallet: ".$wallet."\nAdministrator account: ".get_current_user_id()."\nNonce: ".$id."\nExpires (UTC): ".gmdate('c',$expires)."\nThis message does not authorize payments, transfers, contract acceptance or deployment.";
            set_transient('3pm_reserve_challenge_'.$id,['role'=>$role,'wallet'=>$wallet,'message'=>$message,'expires'=>$expires,'binding'=>self::binding()],300);
            wp_send_json_success(['id'=>$id,'message'=>$message,'wallet'=>$wallet]);
        }catch(Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],400);}
    }
    static function verify(){
        self::guard();$lock='';try{
            $role=self::role();$id=(string)($_POST['challenge']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$id))throw new RuntimeException('Invalid challenge.');
            global $wpdb;$lock='3pm-reserve-'.substr($id,0,48);if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)throw new RuntimeException('Verification is busy. Retry.');
            $c=get_transient('3pm_reserve_challenge_'.$id);
            if(!$c||$c['expires']<time()||$c['role']!==$role||!hash_equals($c['binding'],self::binding())||$c['wallet']!==self::config()['wallets'][$role])throw new RuntimeException('Challenge expired or belongs to another session.');
            $encoded=$_POST['signature']??'';if(!is_string($encoded)||strlen($encoded)>100)throw new RuntimeException('Invalid signature.');$signature=base64_decode($encoded,true);
            if($signature===false||strlen($signature)!==64||!function_exists('sodium_crypto_sign_verify_detached')||!sodium_crypto_sign_verify_detached($signature,$c['message'],self::decode($c['wallet'])))throw new RuntimeException('Wallet signature did not verify.');
            $proof=['wallet'=>$c['wallet'],'role'=>$role,'administrator'=>get_current_user_id(),'verified_at'=>gmdate('c'),'message'=>$c['message'],'signature_base64'=>$encoded];
            if(!update_option('threepm_reserve_proof_'.$role,$proof,false))throw new RuntimeException('Could not store verification. Retry.');
            delete_transient('3pm_reserve_challenge_'.$id);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));$lock='';
            wp_send_json_success(['message'=>'Wallet control verified.','verified_at'=>$proof['verified_at']]);
        }catch(Throwable $e){if($lock){global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}wp_send_json_error(['message'=>$e->getMessage()],400);}
    }
    static function screen(){
        if(!current_user_can('manage_woocommerce'))return;$config=self::config();
        echo '<div class="wrap" data-reserve-setup data-endpoint="'.esc_url(admin_url('admin-ajax.php')).'" data-nonce="'.esc_attr(wp_create_nonce('threepm_reserves')).'"><h1>3PM Settlement Setup</h1><p>These public addresses were supplied by the site owner. Verify control by signing a message in Phantom for each account. Never enter a recovery phrase or private key.</p><div class="notice notice-warning inline"><p><strong>Live payments are not enabled.</strong> Signing, settlement verification, wallet accounting and production deployment still need integration and end-to-end validation.</p></div><table class="widefat striped"><thead><tr><th>Destination</th><th>Public Solana address</th><th>Wallet control</th><th>Action</th></tr></thead><tbody>';
        foreach(['spendReserve'=>'Spend Reserve','hodlReserve'=>'HODL Reserve','platformFees'=>'Platform fees'] as $role=>$label){$address=$config['wallets'][$role];$proof=get_option('threepm_reserve_proof_'.$role,[]);$verified=($proof['wallet']??'')===$address;
            echo '<tr data-role="'.esc_attr($role).'" data-wallet="'.esc_attr($address).'"><td>'.esc_html($label).'</td><td><code>'.esc_html($address).'</code></td><td data-proof-status>'.($verified?'Verified '.esc_html($proof['verified_at']):'Not yet verified').'</td><td><button type="button" class="button" data-reserve-verify>Verify control in Phantom</button></td></tr>';
        }
        echo '</tbody></table><p role="status" aria-live="polite" data-reserve-status></p><h2>Payment rules</h2><ul><li><strong>Services:</strong> Funds remain in escrow until completed work is approved and release is confirmed.</li><li><strong>Licensing:</strong> The agreed allocation settles before the paid coverage period activates.</li></ul><p>These are wallet-owner addresses. Token payments must use the corresponding token accounts for the selected mint.</p></div>';
    }
}
ThreePM_Settlement_Setup::boot();
