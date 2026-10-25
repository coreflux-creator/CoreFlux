<?php
/** Real adapter, deterministic HTTP responses; no network or financial writes. */
declare(strict_types=1);
require_once __DIR__.'/../core/purepay_adapter.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$refused=static function(callable $f):bool{try{$f();return false;}catch(PurePayApiException $e){return $e->errorCode==='incomplete_collection';}};
$item=['id'=>'payment-a','bill_id'=>'bill-a','status'=>'settled','amount'=>'125.00','currency'=>'USD'];
$valid=['contract_version'=>'2026-10-25.1','organization_id'=>'org-a','bill_id'=>'bill-a','complete'=>true,
    'total'=>1,'items'=>[$item],'consistency'=>'rechecked_read_not_transactional_snapshot','observed_at'=>'2026-10-25T02:40:00.123456+00:00'];
$response=$valid;$code=200;$calls=[];$legacy=[];$raw=null;
$GLOBALS['__purepay_transport']=static function($method,$url,$headers,$body)use(&$response,&$code,&$calls,&$legacy,&$raw):array{
    if($method!=='GET'||$body!==null)throw new RuntimeException('History reader attempted a write');
    $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;
    if($path==='/api/v1/payments')return ['status'=>200,'body'=>json_encode($legacy)];
    return ['status'=>$code,'body'=>$raw??json_encode($response)];
};
$check(purepayCompleteBillPayments('fixture','bill-a')===[$item]&&$calls===['/api/v1/bills/bill-a/payments'],'verified bill-scoped history needs no organization-wide scan');
$response['items']=array_map(static fn($i)=>['id'=>'pay-'.$i,'bill_id'=>'bill-a','status'=>null,'amount'=>null,'currency'=>null],range(1,1001));$response['total']=1001;$calls=[];
$check(count(purepayCompleteBillPayments('fixture','bill-a'))===1001&&count($calls)===1,'more than one thousand linked payments remain complete and preserve unknowns');
$response=$valid;$response['items']=[];$response['total']=0;
$check(purepayCompleteBillPayments('fixture','bill-a')===[],'explicit complete empty bill history is accepted');
foreach([
    ['contract_version'=>'unknown'],['organization_id'=>''],['bill_id'=>'different-bill'],['complete'=>false],['complete'=>'true'],
    ['total'=>0],['total'=>'1'],['total'=>10001],['items'=>null],['items'=>['unexpected'=>$item]],
    ['consistency'=>'transactional_snapshot'],['observed_at'=>'unknown'],['extra'=>'unreviewed'],
    ['items'=>[$item,$item],'total'=>2],
]as$i=>$changes){
    $response=array_replace($valid,$changes);$calls=[];
    $check($refused(fn()=>purepayCompleteBillPayments('fixture','bill-a'))&&count($calls)===1,'ambiguous bill history envelope '.$i.' never falls back or proves absence');
}
foreach([
    ['id'=>''],['id'=>[]],['bill_id'=>'other'],['status'=>''],['status'=>['settled']],['amount'=>125],
    ['amount'=>'1.001'],['amount'=>'NaN'],['currency'=>'usd'],['extra'=>'private'],
]as$i=>$changes){
    $response=$valid;$response['items']=[array_replace($item,$changes)];
    $check($refused(fn()=>purepayCompleteBillPayments('fixture','bill-a')),'malformed or conflicting bill history row '.$i.' is refused');
}
$response=$valid;$response['items']=[];$response['total']=0;$raw=str_replace('"items":[]','"items":{}',json_encode($response));
$check($refused(fn()=>purepayCompleteBillPayments('fixture','bill-a')),'an object cannot masquerade as an empty items list');$raw=null;
foreach([401,403,409,500,503]as$failure){
    $code=$failure;$response=['detail'=>'Read failed'];$calls=[];
    $check($refused(fn()=>purepayCompleteBillPayments('fixture','bill-a'))&&count($calls)===1,'HTTP '.$failure.' cannot fall back to another interpretation');
}
$code=404;$response=['detail'=>'Not Found'];$legacy=[$item,['id'=>'unrelated','payable_id'=>'other']];$calls=[];
$check(purepayCompleteBillPayments('fixture','bill-a')===[$item]&&$calls===['/api/v1/bills/bill-a/payments','/api/v1/payments'],'older provider uses only a verified complete legacy inventory and exact bill filter');
$legacy=array_map(static fn($i)=>['id'=>'pay-'.$i,'bill_id'=>'other'],range(1,1000));
$check($refused(fn()=>purepayCompleteBillPayments('fixture','bill-a')),'older provider thousand-row cap remains incomplete');
$legacy=[];
$check(purepayCompleteBillPayments('fixture','bill-a')===[],'older provider explicit complete empty list remains supported');
$code=204;$raw='';
$check($refused(fn()=>purepayCompleteBillPayments('fixture','bill-a')),'empty HTTP response cannot establish no payments');
$before=count($calls);$invalid=false;
try{purepayCompleteBillPayments('fixture',"bill\n-a");}catch(InvalidArgumentException $e){$invalid=true;}
$check($invalid&&count($calls)===$before,'invalid bill identity is rejected before provider IO');
unset($GLOBALS['__purepay_transport']);
echo "PurePay bill payment history: $passed passed\n";
