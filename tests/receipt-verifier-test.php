<?php
require __DIR__ . '/../payment-core/lifecycle.php';require __DIR__ . '/../payment-core/solana-receipts.php';
$n=0;function ok($v,$label){global $n;if(!$v)throw new Exception($label);$n++;echo "PASS $label\n";}function reject($fn,$label){try{$fn();}catch(Throwable $e){ok(true,$label);return;}throw new Exception('Did not reject: '.$label);}
$signature=str_repeat('D',88);$payer=str_repeat('A',32);$recipients=['spend'=>'GZXuLn71nrqZkxMnBDnHStF3niXXsonZvyzjA2Qd6eph','hodl'=>'HqN4ZsaHJq13r3Dkxz8CpxjsCWhREbzu3TR4Qws3yAH4','platform'=>'8H7bt29FbK2ivhokWFyv3tMXVqKCtJYogn6FP1sgnPLH'];
$q=['id'=>str_repeat('a',32),'network'=>'solana-mainnet','asset'=>'SOL','agreement_hash'=>str_repeat('b',64),'period'=>'first','payer'=>$payer,'amount_units'=>1000000,'issued_at'=>1000,'expires_at'=>1300,'max_network_fee_lamports'=>5000,'recipients'=>$recipients];
$tx=['slot'=>123,'blockTime'=>1100,'transaction'=>['signatures'=>[$signature],'message'=>['accountKeys'=>[['pubkey'=>$payer,'signer'=>true,'writable'=>true]],'instructions'=>[]]],'meta'=>['err'=>null,'fee'=>5000,'innerInstructions'=>[],'preBalances'=>[2000000,100,200,300],'postBalances'=>[995000,750100,50200,200300]]];
foreach(ThreePM_Contract_Lifecycle::split('licence',1000000) as $role=>$amount){$dest=$recipients[$role];$tx['transaction']['message']['accountKeys'][]=['pubkey'=>$dest,'signer'=>false,'writable'=>true];$tx['transaction']['message']['instructions'][]=['programId'=>ThreePM_Licence_Receipt_Verifier::SYSTEM,'parsed'=>['type'=>'transfer','info'=>['source'=>$payer,'destination'=>$dest,'lamports'=>$amount]]];}
$tx['transaction']['message']['instructions'][]=['programId'=>ThreePM_Licence_Receipt_Verifier::MEMO,'parsed'=>'3PM:licence:'.$q['agreement_hash'].':first:'.$q['id']];
$r=ThreePM_Licence_Receipt_Verifier::transaction($tx,$signature,$q);ok($r['allocations']['spend']['amount_units']===750000,'SOL exact split verified');ok(!isset($r['finality']),'parsing alone never asserts finalized status');
foreach(['failed','fee','underpayment','recipient','memo','balance','payer','expired','inner','extra-transfer','extra-signer','duplicate-memo'] as $case){$bad=$tx;switch($case){
case 'failed':$bad['meta']['err']=['InstructionError'=>1];break;
case 'fee':$bad['meta']['fee']=5001;break;
case 'underpayment':$bad['transaction']['message']['instructions'][0]['parsed']['info']['lamports']=749999;break;
case 'recipient':$bad['transaction']['message']['instructions'][0]['parsed']['info']['destination']=$payer;break;
case 'memo':$bad['transaction']['message']['instructions'][3]['parsed'].='wrong';break;
case 'balance':$bad['meta']['postBalances'][1]--;break;
case 'payer':$bad['transaction']['message']['accountKeys'][0]['pubkey']=str_repeat('B',32);break;
case 'expired':$bad['blockTime']=1301;break;
case 'inner':$bad['meta']['innerInstructions']=[['index'=>0,'instructions'=>[]]];break;
case 'extra-transfer':$bad['transaction']['message']['instructions'][]=$bad['transaction']['message']['instructions'][0];break;
case 'extra-signer':$bad['transaction']['message']['accountKeys'][1]['signer']=true;break;
case 'duplicate-memo':$bad['transaction']['message']['instructions'][]=$bad['transaction']['message']['instructions'][3];break;
}reject(fn()=>ThreePM_Licence_Receipt_Verifier::transaction($bad,$signature,$q),'reject '.$case);}
$token=$tx;$tq=$q;$tq['asset']='USDC';$tq['token_accounts']=['payer'=>str_repeat('H',32),'spend'=>str_repeat('J',32),'hodl'=>str_repeat('K',32),'platform'=>str_repeat('L',32)];
$token['transaction']['message']['accountKeys']=[['pubkey'=>$payer,'signer'=>true,'writable'=>true]];$token['transaction']['message']['instructions']=[];$token['meta']['preBalances']=[100000,0,0,0,0];$token['meta']['postBalances']=[95000,0,0,0,0];$token['meta']['preTokenBalances']=[];$token['meta']['postTokenBalances']=[];$i=1;
foreach($tq['token_accounts'] as $role=>$account){$owner=$role==='payer'?$payer:$recipients[$role];$before=$role==='payer'?2000000:100;$delta=$role==='payer'?-1000000:ThreePM_Contract_Lifecycle::split('licence',1000000)[$role];$token['transaction']['message']['accountKeys'][]=['pubkey'=>$account,'signer'=>false,'writable'=>true];
foreach(['preTokenBalances'=>$before,'postTokenBalances'=>$before+$delta] as $key=>$value)$token['meta'][$key][]=['accountIndex'=>$i,'mint'=>ThreePM_Licence_Receipt_Verifier::USDC,'owner'=>$owner,'uiTokenAmount'=>['decimals'=>6,'amount'=>(string)$value]];
if($role!=='payer')$token['transaction']['message']['instructions'][]=['programId'=>ThreePM_Licence_Receipt_Verifier::TOKEN,'parsed'=>['type'=>'transferChecked','info'=>['source'=>$tq['token_accounts']['payer'],'destination'=>$account,'authority'=>$payer,'mint'=>ThreePM_Licence_Receipt_Verifier::USDC,'tokenAmount'=>['decimals'=>6,'amount'=>(string)$delta]]]];$i++;}
$token['transaction']['message']['instructions'][]=$tx['transaction']['message']['instructions'][3];
$r=ThreePM_Licence_Receipt_Verifier::transaction($token,$signature,$tq);ok($r['asset']==='USDC','USDC checked transfers and owner balances verified');
foreach(['mint','owner','decimals','token-balance','sol-debit'] as $case){$bad=$token;switch($case){case 'mint':$bad['transaction']['message']['instructions'][0]['parsed']['info']['mint']=str_repeat('M',32);break;case 'owner':$bad['meta']['postTokenBalances'][1]['owner']=$payer;break;case 'decimals':$bad['transaction']['message']['instructions'][0]['parsed']['info']['tokenAmount']['decimals']=9;break;case 'token-balance':$bad['meta']['postTokenBalances'][1]['uiTokenAmount']['amount']='100';break;case 'sol-debit':$bad['meta']['postBalances'][0]--;break;}reject(fn()=>ThreePM_Licence_Receipt_Verifier::transaction($bad,$signature,$tq),'reject '.$case);}

function wp_json_encode($x){return json_encode($x);}function is_wp_error($x){return false;}function wp_remote_retrieve_response_code($r){return $r['code'];}function wp_remote_retrieve_body($r){return $r['body'];}
$rpcFixture=['getGenesisHash'=>ThreePM_Licence_Receipt_Verifier::GENESIS,'getSignatureStatuses'=>['value'=>[['confirmationStatus'=>'finalized','err'=>null,'slot'=>123]]],'getTransaction'=>$tx];
function wp_remote_post($url,$args){global $rpcFixture;if($url!=='https://api.mainnet-beta.solana.com')throw new Exception('Unexpected endpoint');$request=json_decode($args['body'],true);return ['code'=>200,'body'=>json_encode(['result'=>$rpcFixture[$request['method']]])];}
$verified=ThreePM_Licence_Receipt_Verifier::finalized($signature,$q);ok($verified['finality']==='finalized','trusted RPC finality wrapper reconciles success');
$rpcFixture['getGenesisHash']='devnet';reject(fn()=>ThreePM_Licence_Receipt_Verifier::finalized($signature,$q),'network mismatch blocks finality');$rpcFixture['getGenesisHash']=ThreePM_Licence_Receipt_Verifier::GENESIS;
$rpcFixture['getSignatureStatuses']['value'][0]['confirmationStatus']='confirmed';reject(fn()=>ThreePM_Licence_Receipt_Verifier::finalized($signature,$q),'confirmation without finality cannot settle');$rpcFixture['getSignatureStatuses']['value'][0]['confirmationStatus']='finalized';
$rpcFixture['getSignatureStatuses']['value'][0]['slot']=124;reject(fn()=>ThreePM_Licence_Receipt_Verifier::finalized($signature,$q),'inconsistent receipt slot rejected');
echo "TOTAL $n passed\n";
