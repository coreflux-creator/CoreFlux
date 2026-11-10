<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/time_current.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$calls=0;$alter=null;
$GLOBALS['__quanta_transport']=static function(string $method,string $url,array $headers)use(&$calls,&$alter):array{
    $calls++;parse_str(parse_url($url,PHP_URL_QUERY),$query);
    $result=['version'=>1,'workspace_id'=>'workspace-a','request_id'=>$query['request_id'],'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'data'=>array_map(static fn(string $id):array=>['id'=>$id,'state'=>'approved','entry'=>['id'=>$id,'timesheet_status'=>'approved','payroll_excluded'=>false]],explode(',',$query['entry_ids']))];
    if($alter)$result=$alter($result,$calls);
    return ['status'=>200,'body'=>json_encode($result)];
};
$ids=array_map(static fn(int $id):string=>'entry-'.$id,range(1,51));
$result=quantaTimeStateFetch('synthetic','workspace-a',$ids);
$check(count($result['rows'])===51&&$calls===3,'bounded batches include every exact source ID');
$check(count(array_unique(array_column($result['observations'],'request_id')))===3,'each observation uses a different request nonce');
foreach([
    'wrong workspace'=>static fn(array $r):array=>array_replace($r,['workspace_id'=>'workspace-b']),
    'replayed response'=>static fn(array $r):array=>array_replace($r,['request_id'=>str_repeat('0',32)]),
    'partial response'=>static fn(array $r):array=>array_replace($r,['data'=>[]]),
    'duplicate response'=>static fn(array $r):array=>array_replace($r,['data'=>[$r['data'][0],$r['data'][0]]]),
    'missing observation'=>static fn(array $r):array=>array_replace($r,['checked_at'=>null]),
    'invalid observation'=>static fn(array $r):array=>array_replace($r,['checked_at'=>'2026-02-31T00:00:00Z']),
    'unsupported version'=>static fn(array $r):array=>array_replace($r,['version'=>2]),
]as$label=>$mutator){$alter=$mutator;$check($fails(fn()=>quantaTimeStateFetch('synthetic','workspace-a',['one','two'])),$label.' rejects the entire source check');}
$alter=static function(array $r):array{$r['data'][0]=['id'=>$r['data'][0]['id'],'state'=>'unavailable'];return $r;};
$check(quantaTimeStateFetch('synthetic','workspace-a',['one'])['rows']['one']['state']==='unavailable','missing or revoked time is explicit, never silently omitted');
foreach(['timesheet_status'=>'submitted','payroll_excluded'=>true,'id'=>'different']as$key=>$value){
    $alter=static function(array $r)use($key,$value):array{$r['data'][0]['entry'][$key]=$value;return $r;};
    $check($fails(fn()=>quantaTimeStateFetch('synthetic','workspace-a',['one'])),$key.' mismatch cannot pass as approved');
}
$calls=0;$alter=static fn(array $r,int $call):array=>$call===2?array_replace($r,['data'=>[]]):$r;
$check($fails(fn()=>quantaTimeStateFetch('synthetic','workspace-a',$ids))&&$calls===2,'later batch failure discards all earlier results');
$check($fails(fn()=>quantaTimeStateFetch('synthetic','workspace-a',['bad id'])),'invalid source ID rejected before transport');
unset($GLOBALS['__quanta_transport']);
echo "Quanta current source: {$passed} passed\n";
