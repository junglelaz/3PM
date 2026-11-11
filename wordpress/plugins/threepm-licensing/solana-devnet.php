<?php
/** Admin-only devnet lab. No production payment or licence-acceptance hooks. */
defined('ABSPATH') || exit;
final class ThreePM_Solana_Devnet {
    const RPC='https://api.devnet.solana.com';
    const GENESIS='EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG';
    public static function boot(){
        add_action('wp_ajax_threepm_solana_devnet',[__CLASS__,'ajax']);
        add_action('threepm_solana_expire_nonce',function($key){delete_option($key);});
        add_action('wp_enqueue_scripts',[__CLASS__,'assets']);
    }
    public static function assets(){
        if(!current_user_can('manage_options'))return;
        wp_enqueue_script('threepm-solana-web3',plugins_url('assets/vendor/solana-web3-1.98.4.min.js',__FILE__),[],'1.98.4',true);
        wp_enqueue_script('threepm-solana-devnet',plugins_url('assets/solana-devnet.js',__FILE__),['threepm-solana-web3'],'0.2.2',true);
    }
    public static function program(){
        $file=__DIR__.'/devnet-deployment.json';
        if(!file_exists($file))return '';
        $d=json_decode(file_get_contents($file),true);
        return isset($d['genesis'],$d['program_id']) && $d['genesis']===self::GENESIS ? $d['program_id']:'';
    }
    public static function panel($id,$r){
        if(!current_user_can('manage_options'))return '';
        $program=self::program();
        $config=['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('threepm_solana_devnet'),'licence'=>$id,'hash'=>$r['hash'],'rpc'=>self::RPC,'genesis'=>self::GENESIS,'program'=>$program];
        return '<section class="tpm-devnet" data-solana-config="'.esc_attr(wp_json_encode($config)).'"><h2>Solana devnet lab</h2><p><strong>Administrator test tools. No live payments.</strong> Connect and verify a wallet without accepting a licence. Test receipts acknowledge an immutable version on devnet only; they grant no music rights, activate no coverage and create no wallet credits.</p><p>The existing airdrop wallet address is unchanged. Use separate devnet test wallets. Never enter a seed phrase or private key here.</p><div class="tpm-actions"><button type="button" data-sol="connect">Connect Phantom</button><button type="button" data-sol="verify" disabled>Verify wallet ownership</button><button type="button" data-sol="disconnect" disabled>Disconnect</button><button type="button" data-sol="status">Check devnet</button><button type="button" data-sol="selftest">Run signature safeguards test</button></div><p data-sol-status role="status" aria-live="polite">Not connected. Payments disabled.</p><details><summary>Test program acknowledgement</summary><p>Program: <code>'.esc_html($program?:'Not deployed — transaction controls unavailable').'</code></p><label>Counterparty devnet wallet address<input type="text" data-sol-client autocomplete="off" maxlength="44"></label><label class="tpm-check"><input type="checkbox" data-sol-consent> I am testing on devnet. This is not legal agreement acceptance. Devnet network and account-creation costs are paid by the signing test wallet.</label><div class="tpm-actions"><button type="button" data-sol="propose" disabled>Create devnet test receipt</button><button type="button" data-sol="acknowledge" disabled>Acknowledge as test counterparty</button><button type="button" data-sol="receipt">Check recorded devnet receipt</button></div><p data-sol-cost></p><p data-sol-receipt></p></details></section>';
    }
    public static function decode58($value){
        if(!is_string($value)||strlen($value)<32||strlen($value)>44)throw new InvalidArgumentException('Invalid Solana address.');
        $alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';$bytes=[];
        foreach(str_split($value) as $ch){$carry=strpos($alphabet,$ch);if($carry===false)throw new InvalidArgumentException('Invalid address character.');for($i=0;$i<count($bytes);$i++){$carry+=$bytes[$i]*58;$bytes[$i]=$carry&255;$carry>>=8;}while($carry){$bytes[]=$carry&255;$carry>>=8;}}
        $raw=str_repeat("\0",strspn($value,'1'));foreach(array_reverse($bytes) as $b)$raw.=chr($b);
        if(strlen($raw)!==32)throw new InvalidArgumentException('Solana addresses must decode to 32 bytes.');return $raw;
    }
    public static function rpc($method,$params=[]){
        if(!in_array($method,['getGenesisHash','getSlot','getAccountInfo'],true))throw new RuntimeException('RPC operation disabled.');
        $res=wp_remote_post(self::RPC,['timeout'=>20,'redirection'=>0,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params])]);
        if(is_wp_error($res)||wp_remote_retrieve_response_code($res)!==200)throw new RuntimeException('Devnet RPC unavailable. No transaction was submitted.');
        $json=json_decode(wp_remote_retrieve_body($res),true);if(!is_array($json)||isset($json['error'])||!array_key_exists('result',$json))throw new RuntimeException('Invalid devnet RPC response.');return $json['result'];
    }
    public static function chain(){if(self::rpc('getGenesisHash')!==self::GENESIS)throw new RuntimeException('Network mismatch. Only Solana devnet is allowed.');}
    public static function challenge_message($wallet,$nonce,$hash,$expires){
        return "3PM DEVNET WALLET OWNERSHIP TEST\nOrigin: ".untrailingslashit(home_url())."\nWallet: ".$wallet."\nNetwork: solana:devnet\nDraft SHA-256: ".$hash."\nNonce: ".$nonce."\nExpires (UTC): ".gmdate('c',$expires)."\nThis verifies wallet control only. It does not sign a licence, authorize payment, transfer assets, grant rights or activate coverage.";
    }
    public static function verify_signature($message,$signature,$key){
        if(!function_exists('sodium_crypto_sign_verify_detached'))throw new RuntimeException('Server Ed25519 verification unavailable; verification is disabled.');
        return strlen($signature)===64 && strlen($key)===32 && sodium_crypto_sign_verify_detached($signature,$message,$key);
    }
    public static function ajax(){
        if(!is_user_logged_in()||!current_user_can('manage_options'))wp_send_json_error(['message'=>'Administrator devnet access required.'],403);
        check_ajax_referer('threepm_solana_devnet','nonce');nocache_headers();
        try{
            $id=absint($_POST['licence']??0);$r=ThreePM_Licensing::record($id);if(!$r)throw new RuntimeException('Draft unavailable.');
            if(!hash_equals($r['hash'],(string)($_POST['draft_hash']??'')))throw new RuntimeException('The draft changed. Reload before verifying or creating a test receipt.');
            $op=sanitize_key($_POST['op']??'');$session=hash('sha256',wp_get_session_token());
            if($op==='status'){
                self::chain();$program=self::program();$info=$program?self::rpc('getAccountInfo',[$program,['encoding'=>'base64','commitment'=>'finalized']]):null;
                wp_send_json_success(['cluster'=>'devnet','genesis'=>self::GENESIS,'slot'=>self::rpc('getSlot',[['commitment'=>'finalized']]),'program'=>$program,'executable'=>(bool)($info['value']['executable']??false),'payments_enabled'=>false]);
            }
            if($op==='selftest'){
                if(!function_exists('sodium_crypto_sign_keypair'))throw new RuntimeException('Sodium extension unavailable.');
                $pair=sodium_crypto_sign_keypair();$pk=sodium_crypto_sign_publickey($pair);$sk=sodium_crypto_sign_secretkey($pair);$m=self::challenge_message('synthetic-test-wallet','random-test-nonce',$r['hash'],time()+300);$sig=sodium_crypto_sign_detached($m,$sk);
                $checks=['valid_signature'=>self::verify_signature($m,$sig,$pk),'changed_message_rejected'=>!self::verify_signature($m.'changed',$sig,$pk),'changed_key_rejected'=>!self::verify_signature($m,$sig,sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),'short_signature_rejected'=>!self::verify_signature($m,substr($sig,1),$pk),'base58_system_key'=>self::decode58(str_repeat('1',32))===str_repeat("\0",32),'payments_disabled'=>true];sodium_memzero($sk);
                if(in_array(false,$checks,true))throw new RuntimeException('A signature safeguard failed.');wp_send_json_success(['checks'=>$checks,'note'=>'Synthetic in-memory keys only; no wallet, email or blockchain write.']);
            }
            if($op==='challenge'){
                $wallet=sanitize_text_field($_POST['wallet']??'');self::decode58($wallet);
                $nonce=bin2hex(random_bytes(24));$expires=time()+300;$message=self::challenge_message($wallet,$nonce,$r['hash'],$expires);
                set_transient('threepm_sol_ch_'.$nonce,['user'=>get_current_user_id(),'session'=>$session,'licence'=>$id,'hash'=>$r['hash'],'wallet'=>$wallet,'expires'=>$expires,'message'=>$message],300);
                wp_send_json_success(['challenge'=>$nonce,'message'=>$message,'expires'=>$expires]);
            }
            if($op==='verify'){
                $nonce=sanitize_text_field($_POST['challenge']??'');if(!preg_match('/^[a-f0-9]{48}$/D',$nonce))throw new RuntimeException('Invalid challenge.');
                $c=get_transient('threepm_sol_ch_'.$nonce);
                if(!$c||$c['expires']<=time()||$c['user']!==get_current_user_id()||$c['session']!==$session||$c['licence']!==$id||!hash_equals($c['hash'],$r['hash']))throw new RuntimeException('Challenge expired, changed or belongs to another session.');
                $signature=base64_decode((string)($_POST['signature']??''),true);
                if($signature===false||!self::verify_signature($c['message'],$signature,self::decode58($c['wallet'])))throw new RuntimeException('Invalid wallet signature.');
                $used='threepm_sol_used_'.$nonce;if(!add_option($used,1,'','no'))throw new RuntimeException('Challenge already used.');
                wp_schedule_single_event(time()+600,'threepm_solana_expire_nonce',[$used]);delete_transient('threepm_sol_ch_'.$nonce);
                update_post_meta($id,'_devnet_wallet_proof',['wallet'=>$c['wallet'],'hash'=>$r['hash'],'session'=>$session,'verified_at'=>time(),'purpose'=>'wallet_ownership_only','network'=>'devnet']);
                wp_send_json_success(['wallet'=>$c['wallet'],'hash'=>$r['hash'],'verified'=>true,'licence_signed'=>false,'payments_enabled'=>false]);
            }
            if($op==='prepare'){
                self::chain();$program=self::program();if(!$program)throw new RuntimeException('Test program is not deployed.');
                $proof=get_post_meta($id,'_devnet_wallet_proof',true);
                if(!$proof||$proof['session']!==$session||$proof['hash']!==$r['hash']||$proof['verified_at']<time()-900)throw new RuntimeException('Verify your wallet for this draft version first.');
                $client=sanitize_text_field($_POST['client']??'');self::decode58($client);if($client===$proof['wallet'])throw new RuntimeException('Use a separate counterparty test wallet.');
                // Bind only a one-way test-domain digest, not private deal data or identity.
                $test_hash=hash('sha256','3PM DEVNET ONLY|'.$r['hash']);
                $proposal=['creator'=>$proof['wallet'],'client'=>$client,'hash'=>$test_hash,'draft_hash'=>$r['hash'],'program'=>$program,'network'=>'devnet'];
                // An existing immutable proposal must retain the same parties for the same version.
                $old=get_post_meta($id,'_devnet_proposal',true);
                if($old&&$old['draft_hash']===$r['hash']&&$old!==$proposal)throw new RuntimeException('This version already has a different test counterparty. Edit the draft for a new version.');
                update_post_meta($id,'_devnet_proposal',$proposal);wp_send_json_success($proposal);
            }
            if($op==='proposal'){
                $p=get_post_meta($id,'_devnet_proposal',true);
                if(!$p||$p['draft_hash']!==$r['hash']||$p['program']!==self::program())throw new RuntimeException('No test proposal for this version.');
                wp_send_json_success($p);
            }
            if($op==='receipt'){
                self::chain();$p=get_post_meta($id,'_devnet_proposal',true);if(!$p||$p['draft_hash']!==$r['hash'])throw new RuntimeException('No test proposal for this version.');
                $address=sanitize_text_field($_POST['receipt']??'');self::decode58($address);
                $account=self::rpc('getAccountInfo',[$address,['encoding'=>'base64','commitment'=>'finalized']]);$v=$account['value']??null;
                if(!$v||$v['owner']!==self::program()||$v['executable'])throw new RuntimeException('Finalized program receipt not found.');
                $data=base64_decode($v['data'][0]??'',true);
                if($data===false||strlen($data)!==105||substr($data,0,8)!=='3PMDEV01'||substr($data,8,32)!==self::decode58($p['creator'])||substr($data,40,32)!==self::decode58($p['client'])||bin2hex(substr($data,72,32))!==$p['hash']||ord($data[104])>1)throw new RuntimeException('Receipt does not match this draft and its test parties.');
                $result=['address'=>$address,'creator_acknowledged'=>true,'client_acknowledged'=>ord($data[104])===1,'cluster'=>'devnet','payments_enabled'=>false,'coverage_active'=>false,'draft_hash'=>$r['hash']];update_post_meta($id,'_devnet_receipt',$result);wp_send_json_success($result);
            }
            throw new RuntimeException('Operation disabled.');
        }catch(Throwable $e){wp_send_json_error(['message'=>$e->getMessage()],400);}
    }
}
ThreePM_Solana_Devnet::boot();
