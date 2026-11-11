<?php
require __DIR__ . '/../payment-core/lifecycle.php';
$n=0;function ok($v,$label){global $n;if(!$v)throw new Exception($label);$n++;echo "PASS $label\n";}function reject($fn,$label){try{$fn();}catch(Throwable $e){ok(true,$label);return;}throw new Exception('Did not reject: '.$label);}
$p=['id'=>'first','amount_units'=>1000000,'start'=>2000,'end'=>3000,'network'=>'solana-mainnet','asset'=>'USDC','spend'=>str_repeat('A',32),'hodl'=>str_repeat('B',32),'platform'=>str_repeat('C',32)];
function contract($kind){global $p;return ThreePM_Contract_Lifecycle::freeze($kind,1,1,10,20,['title'=>'Fixture'],'Fixture agreement',[$p]);}
function signed($kind){$r=contract($kind);$r=ThreePM_Contract_Lifecycle::sign($r,10,$r['hash'],'Test Artist',true,true,1000);return ThreePM_Contract_Lifecycle::sign($r,20,$r['hash'],'Test Client',true,false,1001);}
function evidence($r,$type){global $p;$e=['network'=>'solana-mainnet','finality'=>'finalized','transaction'=>str_repeat('D',88),'agreement_hash'=>$r['hash'],'period'=>'first','amount_units'=>1000000,'asset'=>'USDC','type'=>$type,'escrow'=>str_repeat('E',32),'allocations'=>[]];foreach(ThreePM_Contract_Lifecycle::split($r['frozen']['kind'],1000000) as $k=>$v)$e['allocations'][$k]=['amount_units'=>$v,'destination'=>$p[$k]];return $e;}
$r=contract('service');reject(fn()=>ThreePM_Contract_Lifecycle::sign($r,99,$r['hash'],'Wrong Party',true,true,1000),'non-party cannot sign');
reject(fn()=>ThreePM_Contract_Lifecycle::sign($r,10,$r['hash'],'Artist',false,true,1000),'explicit acceptance required');
reject(fn()=>ThreePM_Contract_Lifecycle::sign($r,10,$r['hash'],'Artist',true,false,1000),'artist rights declaration required');
reject(fn()=>ThreePM_Contract_Lifecycle::sign($r,10,str_repeat('f',64),'Artist',true,true,1000),'changed version cannot be signed');
$bad=$r;$bad['frozen']['terms']['title']='Changed';reject(fn()=>ThreePM_Contract_Lifecycle::verify($bad),'tampered terms rejected');
$one=ThreePM_Contract_Lifecycle::sign($r,10,$r['hash'],'Artist',true,true,1000);reject(fn()=>ThreePM_Contract_Lifecycle::sign($one,10,$one['hash'],'Artist',true,true,1001),'duplicate signature rejected');
reject(fn()=>ThreePM_Contract_Lifecycle::receipt($one,'first',evidence($one,'escrow_funded'),1002),'both parties must sign before payment');
$r=signed('service');$e=evidence($r,'escrow_funded');
foreach(['network'=>'solana-devnet','finality'=>'confirmed','amount_units'=>999999,'asset'=>'SOL','agreement_hash'=>str_repeat('a',64),'period'=>'other','type'=>'split_settled'] as $k=>$v){$wrong=$e;$wrong[$k]=$v;reject(fn()=>ThreePM_Contract_Lifecycle::receipt($r,'first',$wrong,1100),'reject mismatched '.$k);}
$funded=ThreePM_Contract_Lifecycle::receipt($r,'first',$e,1100);ok($funded['state']==='active','funding starts service without paying artist');
reject(fn()=>ThreePM_Contract_Lifecycle::approve($funded,20,1200),'cannot approve before delivery');
reject(fn()=>ThreePM_Contract_Lifecycle::deliver($funded,20,1200),'client cannot mark artist delivery');
$delivered=ThreePM_Contract_Lifecycle::deliver($funded,10,1200);reject(fn()=>ThreePM_Contract_Lifecycle::approve($delivered,10,1201),'artist cannot self-approve');
$approved=ThreePM_Contract_Lifecycle::approve($delivered,20,1201);ok($approved['state']==='release_requested','approval alone does not mark payment released');
$release=evidence($approved,'escrow_released');$release['transaction']=str_repeat('F',88);
$completed=ThreePM_Contract_Lifecycle::released($approved,$release,1300);ok($completed['state']==='completed','finalized matched split completes service');
$disputed=ThreePM_Contract_Lifecycle::dispute($approved,20,1202);reject(fn()=>ThreePM_Contract_Lifecycle::released($disputed,$release,1300),'dispute blocks ordinary release');
$wrong=$release;$wrong['escrow']=str_repeat('G',32);reject(fn()=>ThreePM_Contract_Lifecycle::released($approved,$wrong,1300),'wrong escrow release rejected');
$l=signed('licence');ok(!ThreePM_Contract_Lifecycle::coverage($l,2100),'signatures alone grant no coverage');
$le=evidence($l,'split_settled');$wrong=$le;$wrong['allocations']['hodl']['amount_units']=0;reject(fn()=>ThreePM_Contract_Lifecycle::receipt($l,'first',$wrong,2100),'incomplete split cannot activate licence');
$paid=ThreePM_Contract_Lifecycle::receipt($l,'first',$le,1900);ok($paid['state']==='scheduled'&&!ThreePM_Contract_Lifecycle::coverage($paid,1999),'paid future licence waits for start');
ok(ThreePM_Contract_Lifecycle::coverage($paid,2000),'coverage begins at paid period start');ok(!ThreePM_Contract_Lifecycle::coverage($paid,3000),'coverage expires at end boundary');
reject(fn()=>ThreePM_Contract_Lifecycle::receipt($l,'first',$le,3000),'late payment cannot reactivate expired coverage');
reject(fn()=>ThreePM_Contract_Lifecycle::receipt($paid,'first',$le,2100),'duplicate settlement rejected');
$late=ThreePM_Contract_Lifecycle::receipt($l,'first',$le,2200);ok(!ThreePM_Contract_Lifecycle::coverage($late,2100),'payment cannot backdate licence activation');ok(ThreePM_Contract_Lifecycle::coverage($late,2200),'coverage starts after actual settlement');
foreach(['service','licence'] as $kind)for($amount=1;$amount<=10001;$amount+=37)ok(array_sum(ThreePM_Contract_Lifecycle::split($kind,$amount))===$amount,'allocation conserves '.$kind.' '.$amount);
echo "TOTAL $n passed\n";
