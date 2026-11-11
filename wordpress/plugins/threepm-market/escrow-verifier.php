<?php
/** Read-only devnet verifier. Never credits wallets or advances a real engagement. */
final class ThreePM_Market_Escrow_Verifier {
    const GENESIS='EtWTRABZaYq6iMfeYKouRu166VU2xqa1wcaWoxPkrZBG';
    public static function verify_account($account,$expected){
        if(!class_exists('ThreePM_Solana_Devnet'))throw new RuntimeException('The existing Solana verification library is required.');
        if(!is_array($account)||($account['owner']??'')!==($expected['program']??null)||!empty($account['executable']))throw new RuntimeException('Escrow program owner mismatch.');
        $encoded=$account['data']??null;
        if(!is_array($encoded)||($encoded[1]??'')!=='base64'||!is_string($encoded[0]??null))throw new RuntimeException('Invalid escrow encoding.');
        $data=base64_decode($encoded[0],true);
        if($data===false||strlen($data)!==348||substr($data,0,8)!=='3PMSVC01')throw new RuntimeException('Unknown escrow layout.');
        $state=ord($data[8]);$asset=ord($data[10]);$decimals=ord($data[11]);
        if(!in_array($state,[1,2,3,4],true)||$asset!==($expected['asset']??null)||$decimals!==($asset===0?9:6))throw new RuntimeException('Escrow state or asset mismatch.');
        if(!is_string($expected['agreement_hash']??null)||!hash_equals($expected['agreement_hash'],bin2hex(substr($data,12,32))))throw new RuntimeException('Agreement fingerprint mismatch.');
        foreach(['client'=>44,'artist'=>76,'authority'=>108,'spend'=>140,'hodl'=>172,'fee'=>204,'mint'=>236]as $key=>$offset){
            if(!isset($expected[$key])||!hash_equals(ThreePM_Solana_Devnet::decode58($expected[$key]),substr($data,$offset,32)))throw new RuntimeException('Escrow '.$key.' mismatch.');
        }
        if($asset===1&&(!isset($expected['vault'])||!hash_equals(ThreePM_Solana_Devnet::decode58($expected['vault']),substr($data,316,32))))throw new RuntimeException('Escrow token vault mismatch.');
        $words=unpack('Vlow/Vhigh',substr($data,268,8));
        if($words['high']>232830)throw new RuntimeException('Escrow amount outside supported range.');
        $amount=$words['low']+$words['high']*4294967296;
        if(!is_int($expected['amount_units']??null)||$amount!==$expected['amount_units']||$amount<1||$amount>1000000000000000)throw new RuntimeException('Escrow amount mismatch.');
        return ['state'=>[1=>'funded',2=>'delivered',3=>'disputed',4=>'settled'][$state],'asset'=>$asset===0?'SOL':'test-token','amount_units'=>$amount,'resolution_hash'=>bin2hex(substr($data,284,32)),'wallet_credit_authorized'=>false];
    }
    public static function inspect($address,$expected){
        if(!class_exists('ThreePM_Solana_Devnet'))throw new RuntimeException('Solana verification dependency missing.');
        ThreePM_Solana_Devnet::decode58($address);
        if(ThreePM_Solana_Devnet::rpc('getGenesisHash')!==self::GENESIS)throw new RuntimeException('Only Solana devnet is allowed.');
        $manifest=json_decode(file_get_contents(__DIR__.'/escrow-devnet-deployment.json'),true);
        if(!$manifest||$manifest['genesis']!==self::GENESIS||$manifest['program_id']!==($expected['program']??null))throw new RuntimeException('Unrecognized escrow deployment.');
        $program=ThreePM_Solana_Devnet::rpc('getAccountInfo',[$manifest['program_id'],['encoding'=>'base64','commitment'=>'finalized']]);
        if(empty($program['value']['executable']))throw new RuntimeException('Escrow program is not executable.');
        $account=ThreePM_Solana_Devnet::rpc('getAccountInfo',[$address,['encoding'=>'base64','commitment'=>'finalized']]);
        $result=self::verify_account($account['value']??null,$expected);
        // A settled flag alone cannot prove actual split/refund transactions or reserve backing.
        return $result+['network'=>'solana-devnet','finalized_slot'=>$account['context']['slot'],'production_enabled'=>false];
    }
}
