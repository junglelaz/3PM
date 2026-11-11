<?php
/** Persist exactly the text reviewed with the terms; never regenerate an accepted document. */
final class ThreePM_Market_Agreement {
    const TEMPLATE='3pm-service-draft-v4-processing';
    private static function line($value){if(!is_string($value)||strlen($value)>12000)throw new InvalidArgumentException('Invalid agreement text.');return trim($value);}
    public static function freeze($terms){
        foreach(['artist_legal_name','client_legal_name','project','final_scope','deadline_utc'] as $field)if(self::line($terms[$field]??'')==='')throw new InvalidArgumentException('Missing agreement '.$field.'.');
        if((int)($terms['artist_user']??0)<1||(int)($terms['client_user']??0)<1||(int)$terms['artist_user']===(int)$terms['client_user'])throw new InvalidArgumentException('Agreement requires two distinct account holders.');
        $t=$terms['service'];foreach(ThreePM_Market_Domain::REQUIRED as $required){if(self::line($t[$required]??'')==='')throw new InvalidArgumentException('Complete agreement detail: '.str_replace('_',' ',$required).'.');}$a=ThreePM_Market_Domain::allocation($t['price_coin_micros']);
        $terms['template']=self::TEMPLATE;$terms['split']=$a;
        $body="3PM SERVICE AGREEMENT — DRAFT\n\n";
        $body.="Artist: ".$terms['artist_legal_name']."\nClient: ".$terms['client_legal_name']."\nService: ".$t['title']."\n";
        $body.="Price: ".ThreePM_Market_Domain::price_label($t['price_coin_micros'])."\n\n";
        $body.="PROJECT\n".$terms['project']."\n\nAGREED SCOPE\n".$terms['final_scope']."\nDelivery deadline (UTC): ".$terms['deadline_utc']."\n";
        foreach(['scope'=>'Scope and exclusions','deliverables'=>'Deliverables','client_inputs'=>'Required client inputs','rights'=>'Rights and ownership','credits'=>'Credits','permissions'=>'Third-party permissions and confidentiality','revisions_scope'=>'Revision scope','technical_terms'=>'Additional technical terms'] as $key=>$label){$v=self::line($t[$key]??'');if($v!=='')$body.="\n".strtoupper($label)."\n".$v."\n";}
        foreach(ThreePM_Market_Domain::CATEGORY_FIELDS[$t['category']] as $key=>$label){$v=self::line($t['category_terms'][$key]??'');if(!$v)throw new InvalidArgumentException('Incomplete category terms: '.$label);$body.="\n".strtoupper($label)."\n".$v."\n";}
        $body.="\nDELIVERY AND REVIEW\nIncluded revision rounds: ".$t['revisions'].". The client has seven days to review a submitted delivery. Expiry triggers reminders or review, never automatic payout. Requests outside agreed revision scope require a separate agreed change.\n";
        $body.="\nPAYMENT AND ALLOCATION\nThe service price is denominated in $3PM. Before off-site launch, one $3PM equals USD 0.05. USD amounts in brackets are references. A separate expiring payment quote specifies SOL or USDC on Solana, the exact amount, network costs and escrow destination. Wallet connection alone is not acceptance or payment.\n";
        $body.="Confirmed funding stays held until delivery approval or an authorized dispute resolution. The full-payment allocation is ".ThreePM_Market_Domain::decimal($a['spend_coin_micros'])." $3PM SPEND (80%), ".ThreePM_Market_Domain::decimal($a['hodl_coin_micros'])." $3PM HODL (5%), and ".ThreePM_Market_Domain::decimal($a['platform_coin_micros'])." $3PM-equivalent platform fee (15%). Artist credits require verified settlement and reconciliation.\n";
        $body.="\nCANCELLATION AND REFUNDS\nArtist cancellation or failure to deliver waives the 15% platform fee. Other agreed refunds retain 15% of the original service price. Every refund deducts the actual documented processing costs of receiving the payment and issuing the refund from the gross refund award. These costs are charged once, without markup, and itemized before execution. For completed services, processing costs come from the platform share and do not reduce the artist allocation. No artist credits are created for refunded amounts. Mandatory rights are not excluded.\n";
        $body.="\nDISPUTES\nEither party may raise a dispute. A dispute freezes ordinary release. 3PM administrators decide the allocation using the agreed scope and submitted evidence; execution requires a separate authorized escrow-wallet signature. A decision alone is not confirmation that funds moved.\n";
        $body.="\nRIGHTS AND CHANGES\nPayment does not imply an unlisted copyright assignment, royalty waiver or promotional right. Only the explicitly stated rights apply. Changes to the listing do not modify this agreement. Material changes require a new version reviewed by both parties.\n";
        $body.="\nDRAFT STATUS\nThis document is not yet accepted. Contract signing and funding remain unavailable until their integrations are enabled.\nTemplate: ".self::TEMPLATE."\nPolicy: ".ThreePM_Market_Domain::POLICY."\n";
        return ['terms'=>$terms,'terms_hash'=>ThreePM_Market_Domain::hash($terms),'text'=>$body,'text_sha256'=>hash('sha256',$body),'template'=>self::TEMPLATE];
    }
    public static function verify($document){
        return is_array($document)&&isset($document['terms'],$document['terms_hash'],$document['text'],$document['text_sha256'])&&is_string($document['text'])&&is_string($document['terms_hash'])&&is_string($document['text_sha256'])&&hash_equals($document['terms_hash'],ThreePM_Market_Domain::hash($document['terms']))&&hash_equals($document['text_sha256'],hash('sha256',$document['text']));
    }
}
