<?php
/** Strict verification of the direct split transaction assembled by the licence checkout. */
final class ThreePM_Licence_Receipt_Verifier {
    const SYSTEM='11111111111111111111111111111111';
    const TOKEN='TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
    const MEMO='MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr';
    const COMPUTE='ComputeBudget111111111111111111111111111111';
    const USDC='EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    const GENESIS='5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
    static function units($n){if(!is_int($n)&&!(is_string($n)&&preg_match('/^(0|[1-9][0-9]{0,15})$/D',$n)))throw new RuntimeException('Invalid base units.');if($n<0||$n>1000000000000000)throw new RuntimeException('Amount outside supported range.');return (int)$n;}
    /** $quote is loaded from trusted server storage, never accepted from the payer. */
    static function transaction($tx,$signature,$quote){
        if(!preg_match('/^[1-9A-HJ-NP-Za-km-z]{80,90}$/D',$signature)||!is_array($tx)||!isset($tx['meta'])||!array_key_exists('err',$tx['meta'])||$tx['meta']['err']!==null||($tx['transaction']['signatures'][0]??'')!==$signature)throw new RuntimeException('Transaction failed or mismatched.');
        if(($quote['network']??'')!=='solana-mainnet'||!in_array($quote['asset']??'',['SOL','USDC'],true)||!preg_match('/^[a-f0-9]{64}$/D',$quote['agreement_hash']??'')||!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$quote['period']??'')||!preg_match('/^[a-f0-9]{32}$/D',$quote['id']??''))throw new RuntimeException('Invalid server quote.');
        $time=$tx['blockTime']??null;if(!is_int($time)||$time<($quote['issued_at']??PHP_INT_MAX)||$time>($quote['expires_at']??0))throw new RuntimeException('Payment does not match the quote validity window.');
        $keys=$tx['transaction']['message']['accountKeys']??[];$payer=$quote['payer']??'';
        if(($keys[0]['pubkey']??'')!==$payer||($keys[0]['signer']??false)!==true||($keys[0]['writable']??false)!==true)throw new RuntimeException('Payer or fee payer mismatch.');
        foreach(array_slice($keys,1) as $key)if(!empty($key['signer']))throw new RuntimeException('Unexpected transaction signer.');
        if(!empty($tx['meta']['innerInstructions']))throw new RuntimeException('Unexpected inner instructions.');
        $fee=self::units($tx['meta']['fee']??null);if($fee>self::units($quote['max_network_fee_lamports']??null))throw new RuntimeException('Network fee exceeds accepted quote.');
        $split=ThreePM_Contract_Lifecycle::split('licence',self::units($quote['amount_units']??null));$expected=[];$owners=[];
        foreach($split as $role=>$amount){$wallet=$quote['recipients'][$role]??'';$dest=$quote['asset']==='SOL'?$wallet:($quote['token_accounts'][$role]??'');if(!$dest||!$wallet||$wallet===$payer||isset($expected[$dest]))throw new RuntimeException('Invalid or overlapping recipients.');$expected[$dest]=['amount_units'=>$amount,'destination'=>$wallet,'role'=>$role];$owners[$dest]=$wallet;}
        $memo='3PM:licence:'.$quote['agreement_hash'].':'.$quote['period'].':'.$quote['id'];$seen=[];$memos=0;$compute=0;
        foreach(($tx['transaction']['message']['instructions']??[]) as $ix){
            $program=$ix['programId']??'';$parsed=$ix['parsed']??null;
            if($program===self::COMPUTE){if(++$compute>2)throw new RuntimeException('Unexpected compute settings.');continue;}
            if($program===self::MEMO){if(!is_string($parsed)||!hash_equals($memo,$parsed)||++$memos!==1)throw new RuntimeException('Payment reference mismatch.');continue;}
            if(!is_array($parsed))throw new RuntimeException('Unrecognized instruction.');$info=$parsed['info']??[];
            if($quote['asset']==='SOL'){
                if($program!==self::SYSTEM||($parsed['type']??'')!=='transfer'||($info['source']??'')!==$payer)throw new RuntimeException('Unexpected SOL operation.');
                $amount=self::units($info['lamports']??null);
            }else{
                if($program!==self::TOKEN||($parsed['type']??'')!=='transferChecked'||($info['authority']??'')!==$payer||($info['source']??'')!==($quote['token_accounts']['payer']??null)||($info['mint']??'')!==self::USDC||($info['tokenAmount']['decimals']??null)!==6)throw new RuntimeException('Unexpected token operation.');
                $amount=self::units($info['tokenAmount']['amount']??null);
            }
            $dest=$info['destination']??'';if(!isset($expected[$dest])||isset($seen[$dest])||$amount!==$expected[$dest]['amount_units'])throw new RuntimeException('Transfer allocation mismatch.');$seen[$dest]=true;
        }
        if($memos!==1||count($seen)!==3)throw new RuntimeException('Incomplete payment split.');
        $index=[];foreach($keys as $i=>$key)$index[$key['pubkey']]=$i;
        if($quote['asset']==='SOL'){
            foreach($expected as $dest=>$allocation){if(!isset($index[$dest]))throw new RuntimeException('Recipient absent.');$i=$index[$dest];$delta=self::units($tx['meta']['postBalances'][$i]??null)-self::units($tx['meta']['preBalances'][$i]??null);if($delta!==$allocation['amount_units'])throw new RuntimeException('Recipient balance does not reconcile.');}
            $debit=self::units($tx['meta']['preBalances'][0]??null)-self::units($tx['meta']['postBalances'][0]??null);if($debit!==self::units($quote['amount_units'])+$fee)throw new RuntimeException('Payer debit does not reconcile.');
        }else{
            $before=self::token_balances($tx['meta']['preTokenBalances']??[]);$after=self::token_balances($tx['meta']['postTokenBalances']??[]);
            foreach($expected as $dest=>$allocation){$i=$index[$dest]??-1;self::token_delta($before,$after,$i,$owners[$dest],$allocation['amount_units']);}
            self::token_delta($before,$after,$index[$quote['token_accounts']['payer']]??-1,$payer,-self::units($quote['amount_units']));
            if(self::units($tx['meta']['preBalances'][0]??null)-self::units($tx['meta']['postBalances'][0]??null)!==$fee)throw new RuntimeException('Unquoted SOL debit.');
        }
        $allocations=[];foreach($expected as $allocation)$allocations[$allocation['role']]=['amount_units'=>$allocation['amount_units'],'destination'=>$allocation['destination']];
        return ['type'=>'split_settled','network'=>'solana-mainnet','asset'=>$quote['asset'],'transaction'=>$signature,'agreement_hash'=>$quote['agreement_hash'],'period'=>$quote['period'],'amount_units'=>$quote['amount_units'],'allocations'=>$allocations,'block_time'=>$time,'slot'=>$tx['slot']??null,'network_fee_lamports'=>$fee];
    }
    static function token_balances($rows){$result=[];foreach($rows as $row){if(isset($result[$row['accountIndex']]))throw new RuntimeException('Duplicate token balance.');$result[$row['accountIndex']]=$row;}return $result;}
    static function token_delta($before,$after,$index,$owner,$expected){
        foreach([$before,$after] as $rows)if(!isset($rows[$index])||($rows[$index]['mint']??'')!==self::USDC||($rows[$index]['owner']??'')!==$owner||($rows[$index]['uiTokenAmount']['decimals']??null)!==6)throw new RuntimeException('Token balance identity mismatch.');
        $delta=self::units($after[$index]['uiTokenAmount']['amount'])-self::units($before[$index]['uiTokenAmount']['amount']);if($delta!==$expected)throw new RuntimeException('Token balances do not reconcile.');
    }
    /** Read-only mainnet lookup. No wallet signing or transaction submission. */
    static function finalized($signature,$quote){
        if(self::rpc('getGenesisHash',[])!==self::GENESIS)throw new RuntimeException('Mainnet identity mismatch.');
        $statuses=self::rpc('getSignatureStatuses',[[$signature],['searchTransactionHistory'=>true]]);$status=$statuses['value'][0]??null;
        if(!$status||($status['confirmationStatus']??'')!=='finalized'||!array_key_exists('err',$status)||$status['err']!==null)throw new RuntimeException('Awaiting finalized successful payment.');
        $tx=self::rpc('getTransaction',[$signature,['encoding'=>'jsonParsed','commitment'=>'finalized','maxSupportedTransactionVersion'=>0]]);
        $receipt=self::transaction($tx,$signature,$quote);if(($tx['slot']??null)!==($status['slot']??null))throw new RuntimeException('Finalized transaction slot mismatch.');return $receipt+['finality'=>'finalized'];
    }
    static function rpc($method,$params){
        if(!in_array($method,['getGenesisHash','getSignatureStatuses','getTransaction'],true))throw new RuntimeException('Unsupported lookup.');
        $r=wp_remote_post('https://api.mainnet-beta.solana.com',['timeout'=>20,'redirection'=>0,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params])]);
        if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)throw new RuntimeException('Payment lookup unavailable. Keep payment pending.');$j=json_decode(wp_remote_retrieve_body($r),true);if(!is_array($j)||isset($j['error'])||!array_key_exists('result',$j))throw new RuntimeException('Payment lookup failed.');return $j['result'];
    }
}
