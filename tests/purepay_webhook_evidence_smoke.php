<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/purepay_webhooks.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$ids=['bill_id'=>'bill-1','payment_id'=>'payment-1'];
foreach(['settled','failed'] as $status){
    $event='payment.'.$status;
    $check(purepayWebhookPaymentEvidence($event,$ids+['pay_status'=>$status])['ok'],'matching '.$status.' evidence accepted');
    foreach(['created','submitted','processing','awaiting_funds','needs_review','returned','',null,[],false] as $other){
        $check(!purepayWebhookPaymentEvidence($event,$ids+['pay_status'=>$other])['ok'],'nonterminal or malformed status refused');
    }
    $check(!purepayWebhookPaymentEvidence($event,$ids)['ok'],'event type without status refused');
    $check(!purepayWebhookPaymentEvidence($event,$ids+['pay_status'=>$status,'status'=>'processing'])['ok'],'contradictory status aliases refused');
    foreach([['bill_id'=>'bill-1'],['payment_id'=>'payment-1'],['bill_id'=>[],'payment_id'=>'payment-1'],['bill_id'=>'bill-1','payment_id'=>"payment\n1"],$ids+['payable_id'=>'bill-2']]as$bad){
        $check(!purepayWebhookPaymentEvidence($event,$bad+['pay_status'=>$status])['ok'],'incomplete or conflicting identities refused');
    }
}
$check(!purepayWebhookPaymentEvidence('payment.settled',$ids+['status'=>'settled','ok'=>false])['ok'],'unsuccessful result cannot prove settlement');
foreach(['qbo_billpayment_id','mercury_request_id']as$field){
    $check(!purepayWebhookPaymentEvidence('payment.settled',$ids+['status'=>'settled',$field=>'acknowledgement-only'])['ok'],
        'accounting record or submission receipt cannot prove delivery');
}
$check(purepayWebhookPaymentEvidence('payment.settled',['payable_id'=>'bill-1','payment_id'=>'payment-1','status'=>'settled'])['ok'],'explicit payable alias supported');
echo "PurePay webhook evidence: {$passed} passed\n";
