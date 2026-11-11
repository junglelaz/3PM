<?php
/** Payment-independent contract rules. Persist transitions under a database lock. */
final class ThreePM_Contract_Lifecycle {
    static function canonical($value){if(is_array($value)){if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$v)$v=self::canonical($v);}return $value;}
    static function hash($value){return hash('sha256',json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    static function freeze($kind,$source,$revision,$artist,$client,$terms,$document,$periods){
        if(!in_array($kind,['service','licence'],true)||$source<1||$revision<1||$artist<1||$client<1||$artist===$client||!is_array($terms)||!is_string($document)||trim($document)===''||!is_array($periods)||!$periods)throw new InvalidArgumentException('Invalid agreement.');
        if($kind==='service'&&count($periods)!==1)throw new InvalidArgumentException('Service escrow funds one complete agreement.');
        $end=0;$seen=[];
        foreach($periods as $p){
            if(!is_array($p)||!is_string($p['id']??null)||isset($seen[$p['id']])||!is_int($p['amount_units']??null)||$p['amount_units']<1||$p['amount_units']>1000000000000000||!is_int($p['start']??null)||!is_int($p['end']??null)||$p['start']>=$p['end']||($end&&$p['start']<$end))throw new InvalidArgumentException('Invalid payment periods.');
            if(($p['network']??'')!=='solana-mainnet'||!in_array($p['asset']??'',['SOL','USDC'],true))throw new InvalidArgumentException('Production asset and network required.');
            foreach(['spend','hodl','platform'] as $key)if(!is_string($p[$key]??null)||!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D',$p[$key]))throw new InvalidArgumentException('Recipient address required.');
            if(count(array_unique([$p['spend'],$p['hodl'],$p['platform']]))!==3)throw new InvalidArgumentException('Keep artist backing, HODL backing and platform receipts separate.');
            $seen[$p['id']]=true;$end=$p['end'];
        }
        $frozen=['schema'=>'threepm-contract-v1','kind'=>$kind,'source'=>$source,'revision'=>$revision,'artist'=>$artist,'client'=>$client,'terms'=>$terms,'document'=>$document,'periods'=>$periods];
        return ['frozen'=>$frozen,'hash'=>self::hash($frozen),'signatures'=>[],'receipts'=>[],'state'=>'awaiting_signatures','events'=>[]];
    }
    static function verify($r){if(!is_array($r)||!isset($r['frozen'],$r['hash'])||!hash_equals(self::hash($r['frozen']),$r['hash']))throw new RuntimeException('The agreement has changed.');}
    static function event($r,$name,$at,$data=[]){$previous=$r['events']?end($r['events'])['hash']:str_repeat('0',64);$entry=['event'=>$name,'at'=>$at,'data'=>$data,'previous'=>$previous];$entry['hash']=self::hash($entry);$r['events'][]=$entry;return $r;}
    static function sign($r,$user,$expected_hash,$name,$accepted,$rights,$at){
        self::verify($r);if($r['state']!=='awaiting_signatures'||!hash_equals($r['hash'],$expected_hash)||!in_array($user,[$r['frozen']['artist'],$r['frozen']['client']],true)||$accepted!==true||!is_int($at)||$at<1||!is_string($name)||strlen(trim($name))<2||strlen($name)>200)throw new RuntimeException('Sign the exact agreement from your own authenticated account.');
        if($user===$r['frozen']['artist']&&$rights!==true)throw new RuntimeException('Confirm your rights and authority before signing.');
        if(isset($r['signatures'][$user]))throw new RuntimeException('This party already signed.');
        $r['signatures'][$user]=['user'=>$user,'name'=>trim($name),'at'=>$at,'agreement_hash'=>$r['hash'],'explicit_acceptance'=>true,'rights_confirmed'=>$user===$r['frozen']['artist']];
        if(count($r['signatures'])===2)$r['state']='awaiting_payment';return self::event($r,'signed',$at,['user'=>$user]);
    }
    static function period($r,$id){foreach($r['frozen']['periods'] as $p)if($p['id']===$id)return $p;throw new RuntimeException('Unknown payment period.');}
    static function split($kind,$amount){
        if(!is_int($amount)||$amount<1||$amount>1000000000000000)throw new InvalidArgumentException('Invalid amount.');
        $percent=$kind==='service'?15:($kind==='licence'?20:0);if(!$percent)throw new InvalidArgumentException('Unknown allocation.');
        $fee=intdiv($amount,100)*$percent+intdiv(($amount%100)*$percent,100);$hodl=intdiv($amount,20);return ['spend'=>$amount-$fee-$hodl,'hodl'=>$hodl,'platform'=>$fee];
    }
    /** Evidence must come from a server-side independent chain verifier, never a browser flag. */
    static function receipt($r,$period_id,$evidence,$at){
        self::verify($r);$p=self::period($r,$period_id);
        if(count($r['signatures'])!==2||in_array($r['state'],['cancelled','disputed'],true))throw new RuntimeException('Agreement is not payable.');
        if(isset($r['receipts'][$period_id]))throw new RuntimeException('Payment already recorded.');
        if(($evidence['network']??'')!=='solana-mainnet'||($evidence['finality']??'')!=='finalized'||!is_string($evidence['transaction']??null)||!preg_match('/^[1-9A-HJ-NP-Za-km-z]{80,90}$/D',$evidence['transaction'])||($evidence['agreement_hash']??'')!==$r['hash']||($evidence['period']??'')!==$period_id||($evidence['amount_units']??null)!==$p['amount_units']||($evidence['asset']??'')!==$p['asset'])throw new RuntimeException('Payment evidence does not match the signed agreement.');
        foreach($r['receipts'] as $old)if($old['transaction']===$evidence['transaction'])throw new RuntimeException('Transaction already used.');
        if($r['frozen']['kind']==='service'){
            if(($evidence['type']??'')!=='escrow_funded'||empty($evidence['escrow']))throw new RuntimeException('Service funds must be held in escrow.');
            $r['state']='active';
        }else{
            if(($evidence['type']??'')!=='split_settled')throw new RuntimeException('Licence allocation must settle before activation.');
            $split=self::split('licence',$p['amount_units']);
            foreach($split as $key=>$amount)if(($evidence['allocations'][$key]['amount_units']??null)!==$amount||($evidence['allocations'][$key]['destination']??'')!==$p[$key])throw new RuntimeException('Licence allocation mismatch.');
            if($at>=$p['end'])throw new RuntimeException('Late payment requires review; expired coverage cannot silently reactivate.');
            $r['state']=$at<$p['start']?'scheduled':'active';
        }
        $r['receipts'][$period_id]=$evidence+['recorded_at'=>$at];return self::event($r,'payment_confirmed',$at,['period'=>$period_id,'transaction'=>$evidence['transaction']]);
    }
    static function deliver($r,$artist,$at){self::verify($r);if($r['frozen']['kind']!=='service'||$artist!==$r['frozen']['artist']||!in_array($r['state'],['active','revision_requested'],true))throw new RuntimeException('Only the artist can submit a funded service.');$r['state']='delivered';return self::event($r,'delivered',$at);}
    static function approve($r,$client,$at){self::verify($r);if($r['state']!=='delivered'||$client!==$r['frozen']['client'])throw new RuntimeException('Only the client can approve the delivery.');$r['state']='release_requested';return self::event($r,'release_approved',$at,['user'=>$client]);}
    static function dispute($r,$user,$at){self::verify($r);if($r['frozen']['kind']!=='service'||!in_array($user,[$r['frozen']['artist'],$r['frozen']['client']],true)||!in_array($r['state'],['active','delivered','revision_requested','release_requested'],true))throw new RuntimeException('Dispute unavailable.');$r['state']='disputed';return self::event($r,'disputed',$at,['user'=>$user]);}
    static function released($r,$evidence,$at){
        self::verify($r);if($r['frozen']['kind']!=='service'||$r['state']!=='release_requested'||($evidence['type']??'')!=='escrow_released'||($evidence['network']??'')!=='solana-mainnet'||($evidence['finality']??'')!=='finalized'||($evidence['agreement_hash']??'')!==$r['hash'])throw new RuntimeException('Confirmed escrow release required.');
        $p=$r['frozen']['periods'][0];if(($evidence['asset']??'')!==$p['asset']||($evidence['amount_units']??null)!==$p['amount_units']||($evidence['period']??'')!==$p['id'])throw new RuntimeException('Release amount, asset or period mismatch.');$funded=$r['receipts'][$p['id']]??[];
        if(!is_string($evidence['transaction']??null)||!preg_match('/^[1-9A-HJ-NP-Za-km-z]{80,90}$/D',$evidence['transaction'])||($evidence['escrow']??null)!==($funded['escrow']??null)||empty($evidence['escrow'])||($evidence['transaction']??'')===($funded['transaction']??''))throw new RuntimeException('Release must match the funded escrow and a new transaction.');
        foreach(self::split('service',$p['amount_units']) as $key=>$amount)if(($evidence['allocations'][$key]['amount_units']??null)!==$amount||($evidence['allocations'][$key]['destination']??'')!==$p[$key])throw new RuntimeException('Release allocation mismatch.');
        $r['release']=$evidence;$r['state']='completed';return self::event($r,'released',$at);
    }
    static function coverage($r,$at){self::verify($r);if($r['frozen']['kind']!=='licence'||count($r['signatures'])!==2||in_array($r['state'],['cancelled','disputed'],true))return false;foreach($r['frozen']['periods'] as $p)if(isset($r['receipts'][$p['id']])&&$r['receipts'][$p['id']]['type']==='split_settled'&&$at>=max($p['start'],$r['receipts'][$p['id']]['recorded_at'])&&$at<$p['end'])return true;return false;}
}
