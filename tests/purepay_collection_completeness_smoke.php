<?php
/** Real adapter pagination against bounded fake provider IO; no database or network. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/purepay_adapter.php';
$passed = 0;
$check = static function(bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException($label);
    $passed++;
    echo "PASS {$label}\n";
};
$refused = static function(callable $read): bool {
    try { $read(); return false; }
    catch (PurePayApiException $e) { return $e->errorCode === 'incomplete_collection'; }
};
$records = static fn(int $n): array => array_map(static fn($i)=>['id'=>'record-'.$i, 'payable_id'=>'bill-'.$i], range(1,$n));
$rows = $records(401);
$calls = [];
$GLOBALS['__purepay_transport'] = static function($method,$url,$headers,$body) use (&$calls,&$rows): array {
    if ($method !== 'GET' || $body !== null) throw new RuntimeException('Collection reader attempted a write');
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    $calls[] = ['path'=>parse_url($url, PHP_URL_PATH), 'query'=>$query];
    return ['status'=>200, 'body'=>json_encode(array_slice($rows,(int)($query['skip']??0),(int)($query['limit']??count($rows))))];
};
$found = purepayCompleteCollection('fixture-key','vendors');
$check(count($found)===401 && $found[400]['id']==='record-401','vendor beyond the first 200 records is included');
$check(array_column(array_column($calls,'query'),'skip')===['0','200','400'],'vendor reads advance the supported offset');
$rows = $records(200); $calls = [];
$found = purepayCompleteCollection('fixture-key','bills','approved & payable');
$check(count($found)===200 && count($calls)===2 && $calls[1]['query']['skip']==='200','exactly full last page requires an empty next page');
$check($calls[0]['query']['status']==='approved & payable' && $calls[1]['query']['status']==='approved & payable','bill status stays encoded and identical across pages');
$rows = []; $calls = [];
$check(purepayCompleteCollection('fixture-key','vendors')===[] && count($calls)===1,'explicit empty first page completes');
$rows = $records(10000); $calls = [];
$check(count(purepayCompleteCollection('fixture-key','vendors'))===10000 && count($calls)===51,'bounded complete inventory supports ten thousand rows plus terminal probe');
$rows = $records(10001); $calls = [];
$check($refused(fn()=>purepayCompleteCollection('fixture-key','vendors')) && count($calls)===51,'inventory beyond the bound is refused rather than truncated');
$rows = $records(999); $calls = [];
$check(count(purepayCompleteCollection('fixture-key','payments'))===999 && $calls[0]['query']===[],'uncapped legacy payments are read without inventing pagination parameters');
$rows = $records(1000); $calls = [];
$check($refused(fn()=>purepayCompleteCollection('fixture-key','payments')) && count($calls)===1,'legacy thousand-payment cap cannot establish absence');

$response = [];
$GLOBALS['__purepay_transport'] = static function() use (&$response): array { return ['status'=>200,'body'=>json_encode($response)]; };
foreach ([
    ['payments'=>[], 'has_more'=>true],
    ['payments'=>[], 'next_cursor'=>'later'],
    ['payments'=>[], 'total'=>1],
    ['payments'=>['items'=>[],'next'=>'https://untrusted.example/next']],
    ['unexpected'=>[]],
    [['id'=>'duplicate','payable_id'=>'bill'],['id'=>'duplicate','payable_id'=>'bill']],
    [['id'=>'payment-without-bill']],
    [['id'=>'payment','bill_id'=>'bill-a','payable_id'=>'bill-b']],
    [['id'=>'payment-a','payment_id'=>'payment-b','payable_id'=>'bill']],
    [['id'=>['malformed'],'payable_id'=>'bill']],
    ['malformed-row'],
] as $i=>$value) {
    $response = $value;
    $check($refused(fn()=>purepayCompleteCollection('fixture-key','payments')),'incomplete or ambiguous payment response '.$i.' is refused');
}
$response = ['payments'=>[['id'=>'payment','payable_id'=>'bill']]];
$check(count(purepayCompleteCollection('fixture-key','payments'))===1,'exact legacy wrapper remains supported');
foreach (['{}','{"payments":{}}'] as $raw) {
    $GLOBALS['__purepay_transport'] = static fn()=>['status'=>200,'body'=>$raw];
    $check($refused(fn()=>purepayCompleteCollection('fixture-key','payments')),'JSON object cannot masquerade as an empty payment list: '.$raw);
}
$GLOBALS['__purepay_transport'] = static fn()=>['status'=>204,'body'=>''];
$check($refused(fn()=>purepayCompleteCollection('fixture-key','payments')),'missing list body cannot establish an empty payment inventory');
$response = $records(200); $calls = 0;
$GLOBALS['__purepay_transport'] = static function() use (&$calls,$response): array { $calls++; return ['status'=>200,'body'=>json_encode($response)]; };
$check($refused(fn()=>purepayCompleteCollection('fixture-key','vendors')) && $calls===2,'ignored offset or repeated page cannot hide later vendor matches');
$calls = 0;
$GLOBALS['__purepay_transport'] = static function() use (&$calls,$response): array {
    $calls++;
    return $calls===1 ? ['status'=>200,'body'=>json_encode($response)] : ['status'=>503,'body'=>'{"detail":"temporary failure"}'];
};
$failed = false;
try { purepayCompleteCollection('fixture-key','vendors'); } catch (PurePayApiException $e) { $failed = $e->httpStatus===503; }
$check($failed && $calls===2,'second-page failure cannot return a partial successful inventory');
$calls = 0;
try { purepayCompleteCollection('fixture-key','https://untrusted.example/next'); } catch (InvalidArgumentException $e) { $calls++; }
$check($calls===1,'arbitrary collection paths are refused before provider IO');
unset($GLOBALS['__purepay_transport']);
echo "PurePay collection completeness: {$passed} passed\n";
