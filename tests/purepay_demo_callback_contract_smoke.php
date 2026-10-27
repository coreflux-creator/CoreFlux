<?php
declare(strict_types=1);
require_once __DIR__.'/../deploy/purepay_demo_callback_contract.php';
$payment='core_demo_callback_'.str_repeat('a',32);$endpoint='wh_'.str_repeat('b',12);
$data=['bill_id'=>'bill_'.$payment,'payment_id'=>$payment,'pay_status'=>'settled','amount'=>'1.00','currency'=>'USD'];
$event='evt_pmt_'.hash('sha256',coreDemoCallbackCanonical(['org_purepay_demo',$endpoint,'payment.settled',$data]));
$body=coreDemoCallbackCanonical(['id'=>$event,'type'=>'payment.settled','data'=>$data]);
$plan=['database'=>'core_hosted_callback_'.str_repeat('c',32),'owner'=>str_repeat('d',32),'created_at'=>1000,
    'source_manifest'=>['commit'=>'ae69ba56a3620bcef385b2d148b73a1f838a74ca'],
    'review'=>['organization_id'=>'org_purepay_demo','endpoint_id'=>$endpoint,'payment_id'=>$payment,'bill_id'=>'bill_'.$payment,
        'callback_url'=>'https://phpstack-1516771-6707602.cloudwaysapps.com/api/webhooks/purepay.php?t=999',
        'event_type'=>'payment.settled','event_id'=>$event,'body_sha256'=>hash('sha256',$body),
        'endpoint_sha256'=>coreDemoCallbackEndpointHash($endpoint,'whsec_synthetic_fixture_secret'),'expires_at'=>1600,
        'amount'=>'1.00','currency'=>'USD']];
$checks=0;
$check=static function(bool $value)use(&$checks):void{if(!$value)throw new RuntimeException('Fixture contract check failed');$checks++;};
$check(coreDemoCallbackPlan($plan,1000)===$plan['review']);
$check(coreDemoCallbackPlan($plan,2000,false)===$plan['review']);
$check(coreDemoCallbackCanonical(['z'=>1,'a'=>['q'=>2,'b'=>3]])==='{"a":{"b":3,"q":2},"z":1}');
foreach(['organization_id'=>'real-company','endpoint_id'=>'other','payment_id'=>'real-payment','bill_id'=>'other',
         'callback_url'=>'https://www.corefluxapp.com/api/webhooks/purepay.php?t=999','event_type'=>'payment.failed',
         'event_id'=>'evt_pmt_'.str_repeat('0',64),'body_sha256'=>str_repeat('0',64),'amount'=>'2.00','currency'=>'INR',
         'expires_at'=>1700]as$key=>$value){
    $copy=$plan;$copy['review'][$key]=$value;
    try{coreDemoCallbackPlan($copy,1000);$rejected=false;}catch(DomainException $e){$rejected=true;}$check($rejected);
}
foreach([999,1600]as$time){try{coreDemoCallbackPlan($plan,$time);$rejected=false;}catch(DomainException $e){$rejected=true;}$check($rejected);}
$check(coreDemoCallbackEndpointHash($endpoint,'whsec_synthetic_fixture_secret')!==coreDemoCallbackEndpointHash($endpoint,'whsec_different_fixture_secret'));
echo $checks," callback fixture contract checks passed\n";
