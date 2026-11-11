<?php
/** Service policy and validation. No WordPress or financial side effects. */
final class ThreePM_Market_Domain {
    const POLICY = 'services-2026-11-03-v2-processing';
    const CATEGORIES = ['songwriting'=>'Songwriting','composition'=>'Composition','production'=>'Production','mixing'=>'Mixing','mastering'=>'Mastering','feature'=>'Paid feature'];
    const REQUIRED = ['title','description','scope','deliverables','client_inputs','revisions_scope','rights','credits','permissions','cancellation'];
    const CATEGORY_FIELDS = [
        'songwriting'=>['writing_scope'=>'Lyrics, melody or complete song','length'=>'Song length / section count','writer_shares'=>'Writer and publisher ownership','sample_policy'=>'Samples and third-party material'],
        'composition'=>['instrumentation'=>'Instrumentation / arrangement','length'=>'Duration and number of cues','writer_shares'=>'Composer and publisher ownership','sample_policy'=>'Samples and third-party material'],
        'production'=>['track_count'=>'Number of tracks','stems'=>'Stems and session-file delivery','master_rights'=>'Master ownership','writer_shares'=>'Composition ownership','royalties'=>'Producer royalties or explicitly none'],
        'mixing'=>['track_count'=>'Number of songs','stem_limit'=>'Maximum stems per song','source_specs'=>'Required source specifications','output_specs'=>'Output formats, sample rate and bit depth'],
        'mastering'=>['track_count'=>'Number of songs','source_specs'=>'Required source specifications','output_specs'=>'Output formats, sample rate and bit depth','versions'=>'Master versions included'],
        'feature'=>['length'=>'Verse/hook duration or bar count','stems'=>'Vocal files and versions','billing'=>'Artist billing and credit','publicity'=>'Name/image and promotion permissions','master_rights'=>'Master ownership','writer_shares'=>'Composition ownership','release_approval'=>'Release approval requirements'],
    ];
    public static function category_terms($category,$input,$publish) {
        if(!isset(self::CATEGORY_FIELDS[$category])||!is_array($input))throw new InvalidArgumentException('Invalid category terms.');$out=[];
        foreach(self::CATEGORY_FIELDS[$category] as $key=>$label){$v=self::text($input[$key]??'',3000);if($publish&&$v==='')throw new InvalidArgumentException('Complete: '.$label.'.');$out[$key]=$v;}
        return $out;
    }
    public static function text($value, $max=12000) {
        if (!is_string($value) || strlen($value)>$max) throw new InvalidArgumentException('Invalid or oversized text.');
        return trim(strip_tags($value));
    }
    public static function integer($value,$min,$max) {
        if (!is_scalar($value) || !preg_match('/^(0|[1-9][0-9]*)$/D',(string)$value) || strlen((string)$value)>12) throw new InvalidArgumentException('Use a whole number.');
        $n=(int)$value;if($n<$min || $n>$max)throw new InvalidArgumentException('Number outside allowed range.');return $n;
    }
    const PRICE_SCHEMA = '3pm-token-price-v1';
    const PEG = 'prelaunch-usd-0.05-v1';
    public static function coins($value) {
        if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,6})(\.[0-9]{1,2})?$/D',$value))throw new InvalidArgumentException('Use a $3PM price with at most two decimal places.');
        $parts=explode('.',$value);$n=(int)$parts[0]*1000000+(int)str_pad($parts[1]??'',6,'0');
        if($n<10000)throw new InvalidArgumentException('Minimum service price is 0.01 $3PM.');return $n;
    }
    public static function refund_coins($value){return $value==='0'||$value==='0.00'?0:self::coins($value);}
    public static function decimal($micros,$minimum=0) {
        if(!is_int($micros)||$micros<0)throw new InvalidArgumentException('Invalid display amount.');
        $whole=(string)intdiv($micros,1000000);$fraction=rtrim(str_pad((string)($micros%1000000),6,'0',STR_PAD_LEFT),'0');
        $fraction=str_pad($fraction,$minimum,'0');
        return $whole.($fraction!==''?'.'.$fraction:'');
    }
    public static function price_label($micros) {
        // USD is explanatory only; the contractual price and allocations are tokens.
        return self::decimal($micros).' $3PM ($'.self::decimal(intdiv($micros,20),2).' USD)';
    }
    public static function allocation($micros) {
        if(!is_int($micros)||$micros<10000||$micros>9999999990000||$micros%10000!==0)throw new InvalidArgumentException('Invalid token-denominated service price.');
        return ['gross_coin_micros'=>$micros,'platform_coin_micros'=>intdiv($micros,100)*15,
            'hodl_coin_micros'=>intdiv($micros,100)*5,'spend_coin_micros'=>intdiv($micros,100)*80,
            'reference_usd_micros'=>intdiv($micros,20),'valuation'=>self::PEG];
    }
    public static function settlement($units,$refund,$reason) {
        // Exact on-chain base units; remainder stays with SPEND. No hidden asset creation.
        if(!is_int($units)||$units<1||$units>1000000000000000||!is_int($refund)||$refund<0)throw new InvalidArgumentException('Invalid settlement amount.');
        if(!in_array($reason,['completed','mutual_cancellation','client_cancellation','artist_cancellation','non_delivery'],true))throw new InvalidArgumentException('Unknown resolution reason.');
        $artist_fault=in_array($reason,['artist_cancellation','non_delivery'],true);
        $fee=$artist_fault?0:intdiv($units,100)*15+intdiv(($units%100)*15,100);
        if($artist_fault && $refund!==$units)throw new InvalidArgumentException('Artist cancellation/non-delivery requires return of the entire service payment.');
        if($refund>$units-$fee || ($reason==='completed' && $refund!==0))throw new InvalidArgumentException('Refund conflicts with policy.');
        $artist=$units-$fee-$refund;$hodl=intdiv($artist,17);
        return ['client'=>$refund,'platform'=>$fee,'hodl'=>$hodl,'spend'=>$artist-$hodl,'reason'=>$reason,'policy'=>self::POLICY];
    }
    /** All amounts share the settlement asset's base unit. Costs must come from verified receipts, never browser input. */
    public static function net_settlement($units,$refund,$reason,$receiving_cost,$refund_cost) {
        foreach([$receiving_cost,$refund_cost] as $cost)if(!is_int($cost)||$cost<0||$cost>1000000000000000)throw new InvalidArgumentException('Invalid processing cost.');
        $s=self::settlement($units,$refund,$reason);
        if($refund===0&&$refund_cost!==0)throw new InvalidArgumentException('No refund transaction cost without a refund.');
        $cost=$receiving_cost+$refund_cost;
        // On refunds charge actual processing once to the gross client award. Artist award stays unchanged.
        // Otherwise processing reduces only the platform share. Never create negative balances.
        $available=$refund>0?$s['client']:$s['platform'];
        if($cost>$available)throw new InvalidArgumentException('Processing costs exceed available allocation; manual review required.');
        if($refund>0)$s['client']-=$cost;else $s['platform']-=$cost;
        $s['processing']=$cost;$s['receiving_cost']=$receiving_cost;$s['refund_cost']=$refund_cost;
        $s['gross_refund']=$refund;$s['cost_bearer']=$refund>0?'client_refund':'platform';
        return $s;
    }
    public static function listing($input,$publish) {
        if(!is_array($input))throw new InvalidArgumentException('Invalid service form.');$out=[];
        foreach(self::REQUIRED as $key){$out[$key]=self::text($input[$key]??'');if($publish&&in_array($key,['title','description'],true)&&$out[$key]==='')throw new InvalidArgumentException('Complete '.str_replace('_',' ',$key).'.');}
        $out['category']=self::text($input['category']??'',30);
        if(!isset(self::CATEGORIES[$out['category']]))throw new InvalidArgumentException('Choose a service category.');
        $out['price_coin_micros']=self::coins($input['price']??'');
        $out['price_schema']=self::PRICE_SCHEMA;$out['valuation']=self::PEG;
        $out['delivery_days']=self::integer($input['delivery_days']??'',1,365);
        $out['revisions']=self::integer($input['revisions']??'',0,20);
        $out['category_terms']=self::category_terms($out['category'],$input['category_terms'][$out['category']]??[],false);
        $out['technical_terms']=self::text($input['technical_terms']??'');

        if($publish&&($input['policy_ack']??'')!=='1')throw new InvalidArgumentException('Acknowledge the service policy to publish.');
        $out['policy']=self::POLICY;$out['assets']=['SOL','USDC'];return $out;
    }
    public static function canonical($value) {
        if(is_array($value)){if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$v)$v=self::canonical($v);unset($v);}return $value;
    }
    public static function hash($value){return hash('sha256',json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    public static function can_view($record,$user,$admin=false){return $user>0&&($admin||(int)$record['owner']===$user||(int)$record['peer']===$user);}
    public static function transition($record,$event,$user,$admin=false) {
        if(!self::can_view($record,$user,$admin))throw new RuntimeException('Engagement unavailable.');
        $state=$record['state'];$artist=(int)$record['owner']===$user;$client=(int)$record['peer']===$user;
        $rules=[
            'offer'=>['submitted','offered',$artist],
            'accept'=>['offered','accepted',$client],
            'deliver'=>['active','delivered',$artist],
            'redeliver'=>['revision_requested','delivered',$artist],
            'revision'=>['delivered','revision_requested',$client],
            'approve'=>['delivered','release_requested',$client],
        ];
        if($event==='dispute'&&in_array($state,['active','delivered','revision_requested','release_requested'],true)&&($artist||$client))return 'disputed';
        if($event==='cancel'&&in_array($state,['submitted','offered','accepted'],true)&&($artist||$client))return 'cancelled';
        if(!isset($rules[$event])||$rules[$event][0]!==$state||!$rules[$event][2])throw new RuntimeException('This action is not available in the current state.');
        // Funding/release/refund confirmation intentionally absent: only a verified financial adapter may perform those transitions.
        return $rules[$event][1];
    }
}
