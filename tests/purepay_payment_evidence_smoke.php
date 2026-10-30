<?php
/** Pure money, identity and cached-receipt rules; no database or provider access. */
declare(strict_types=1);
require_once __DIR__.'/../core/purepay_payment_evidence.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void {
    if (!$ok) throw new RuntimeException($label);
    $passed++;
};
foreach (['125'=>12500,'125.0'=>12500,'125.00'=>12500,'0.01'=>1,'99999999999.99'=>9999999999999] as $value=>$cents) {
    $check(purepayMoneyCents($value)===$cents,'decimal amount converts exactly');
}
foreach ([125,125.0,125.25,0.01] as $value) $check(purepayMoneyCents($value)===(int)round($value*100),'numeric API amounts convert exactly');
foreach ([null,true,false,[],new stdClass(),'', ' 125','125 ', '+125','-125','125.001','1e2', '01.00','100000000000',INF,NAN,0.001,125.000000001] as $value) {
    $check(purepayMoneyCents($value)===null,'malformed or sub-cent money is never rounded');
}
$payment=['id'=>'payment-1','bill_id'=>'bill-1','amount'=>'125.00','currency'=>'USD','status'=>'settled'];
$verify=static fn(array $data):array=>purepayPaymentEvidence($data,12500,'bill-1','payment-1');
$valid=$verify($payment);
$check($valid['ok'] && $valid['amount_cents']===12500,'exact full payment is evidence');
$rail=['moov_transfer_id'=>'transfer-1','moov_status'=>'completed','qbo_billpayment_id'=>'bookkeeping-1'];
$check($verify($payment+$rail)['ok'],'confirmed Moov payment may also have a QuickBooks receipt');
foreach ([['moov_transfer_id'=>null],['moov_transfer_id'=>''],['moov_status'=>'pending'],['moov_status'=>[]],
    ['moov_status'=>'failed'],['method'=>'quickbooks'],['method'=>'mercury']] as $badRail) {
    $check(!$verify(array_replace($payment+$rail,$badRail))['ok'],'bookkeeping cannot hide missing or contradictory rail evidence');
}
foreach (['failed'=>'failed','returned'=>'reversed','cancelled'=>'canceled'] as $status=>$railStatus) {
    $check($verify(array_replace($payment+$rail,['status'=>$status,'moov_status'=>$railStatus]))['ok'],'matching terminal rail states agree');
}
$cases=[
    ['amount'=>'40.00'], ['amount'=>'126.00'], ['amount'=>null], ['amount'=>'125.001'], ['amount'=>true],
    ['currency'=>null], ['currency'=>'CAD'], ['currency'=>'usd'], ['currency'=>['USD']],
    ['id'=>'another-payment'], ['payment_id'=>'another-payment'], ['payable_id'=>'another-bill'], ['bill_id'=>'another-bill'],
    ['amount_cents'=>125], ['gross_cents'=>12501], ['gross_cents'=>'12500'],
    ['amount_cents'=>12500,'net_cents'=>12000], ['net_cents'=>12501], ['net_cents'=>null], ['net_cents'=>'12500'],
    ['allocated'=>40], ['allocated_amount'=>null], ['allocations'=>[]], ['bill_allocations'=>[['bill_id'=>'bill-1','amount'=>40]]],
    ['partial'=>true], ['allocation'=>'vendor_credit'],
    ['status'=>null], ['pay_status'=>'failed'], ['ok'=>false], ['qbo_billpayment_id'=>'ledger-only'], ['mercury_request_id'=>'submission-only'],
];
foreach ($cases as $change) $check(!$verify(array_replace($payment,$change))['ok'],'contradictory payment result is refused');
foreach (['id','bill_id','amount','currency','status'] as $field) {
    $missing=$payment;unset($missing[$field]);
    $check(!$verify($missing)['ok'],'missing required evidence is refused');
}
$check($verify($payment+['gross_cents'=>12500,'allocated'=>0,'partial'=>false,'net_cents'=>12000,'amount_cents'=>12000])['ok'],
    'provider amount_cents is the net transfer, distinct from the gross obligation after a fee');
$check($verify(['payment_id'=>'payment-1','payable_id'=>'bill-1','amount'=>125,'currency'=>'USD','pay_status'=>'paid'])['ok'],'consistent explicit identity aliases are accepted');
$link=['amount_cents'=>'12500','purepay_bill_id'=>'bill-1','purepay_payment_id'=>'payment-1','status'=>'settled',
    'response_json'=>purepayPaymentReceipt($valid,'detail')];
$check(purepayStoredPaymentEvidence($link),'validated persisted receipt can authorize matching status');
foreach ([['amount_cents'=>'4000'],['purepay_bill_id'=>'other'],['purepay_payment_id'=>null],['status'=>'failed'],
    ['response_json'=>json_encode($payment)],['response_json'=>'invalid'],
    ['last_error_json'=>'{"payment_evidence_review":"payment_amount_mismatch"}']] as $change) {
    $check(!purepayStoredPaymentEvidence(array_replace($link,$change)),'old, changed or held cache is not evidence');
}
$check(!purepayStoredPaymentEvidence(array_replace($link,['response_json'=>str_replace('"detail"','"post_acknowledgement"',$link['response_json'])])),
    'POST acknowledgement is never a verified receipt source');
$check(!purepayStoredPaymentEvidence(array_replace($link,['response_json'=>str_replace('"detail"','"bill_history"',$link['response_json'])])),
    'compact bill history alone is never settlement evidence');
echo "PurePay payment evidence: {$passed} passed\n";
