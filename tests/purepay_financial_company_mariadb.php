<?php
/** Real company review/rotation transactions; synthetic credentials and HTTP only. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/purepay_service.php';
require_once __DIR__.'/../core/payment_rails/purepay_driver.php';
require_once __DIR__.'/../core/migrate.php';
$password=$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='coreflux_financial_company_'.getmypid();$pdo->exec('CREATE DATABASE '.$database);$pdo->exec('USE '.$database);
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo 'PASS '.$label."\n";};
$fails=static function(callable $action):bool{try{$action();return false;}catch(Throwable $e){return true;}};
$base=['contract_version'=>'2026-10-25.1','organization'=>['id'=>'org-a','name'=>'Synthetic Alpha'],'key_scope'=>'write','execution_authority'=>false];
$key='fixture-finance-alpha';$rotated='fixture-finance-alpha-rotated';$beta='fixture-finance-beta';$read='fixture-finance-alpha-read';
$contexts=[$key=>$base,$rotated=>$base,$read=>array_replace($base,['key_scope'=>'read']),
    $beta=>array_replace($base,['organization'=>['id'=>'org-b','name'=>'Synthetic Beta']])];
$calls=[];$interleave=null;$failure=null;
$GLOBALS['__purepay_transport']=static function($method,$url,$headers)use(&$contexts,&$calls,&$interleave,&$failure,$pdo):array{
    if($pdo->inTransaction() || $method!=='GET')throw new RuntimeException('Provider reads must be outside database transactions; no provider mutations');
    $credential=substr($headers[0],strlen('Authorization: Bearer '));$path=parse_url($url,PHP_URL_PATH);$calls[]=[$method,$path];
    $body=$path==='/api/v1/context'?($contexts[$credential]??[]):['wallet'=>['balance_cents'=>12500]];
    if($interleave!==null){$fn=$interleave;$interleave=null;$fn($path);}
    if($failure!==null)return ['status'=>$failure,'body'=>json_encode(['detail'=>'never expose '.$credential])];
    if(!in_array($path,['/api/v1/context','/api/v1/wallet'],true))throw new RuntimeException('Unexpected route');
    return ['status'=>200,'body'=>json_encode($body)];
};
$review=static fn(int $tenant=1)=>purepayFinancialReview($pdo,$tenant);
$confirm=static fn(array $r,int $tenant=1)=>purepayFinancialConfirm($pdo,$tenant,7,$r['review_hash'],$r['organization']['id']);
$store=static fn(string $token,int $tenant=1,string $scope='read_only')=>purepayStoreConnection($tenant,$token,'Synthetic finance',7,$scope);
$driver=new PurePayRailDriver();
try {
    foreach(['146_purepay_rail.sql','176_purepay_financial_company.sql','176_purepay_financial_company.sql']as$file)
        foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);
    $check($fails(fn()=>purepayFinancialRequire($pdo,1)),'missing connection has no financial authority');
    $store($key);
    $check(!$review()['verified'] && !$driver->isConfiguredForTenant(1),'valid key does not confirm a company or enable payouts');
    $check(array_unique(array_column($calls,1))===['/api/v1/context'],'unconfirmed connection reads identity only, never a wallet');
    $saved=purepayFinancialConnection($pdo,1);
    $check(!str_contains($saved['api_key_ct'],$key) && purepayFinancialKey($saved)===$key,'key encrypted at rest');
    $check($fails(fn()=>purepaySaveWebhookSecret(1,'whsec_fixture')),'unconfirmed company cannot install callback secret');
    $r=$review();
    $check($fails(fn()=>purepayFinancialConfirm($pdo,1,7,$r['review_hash'],'org-b')),'wrong displayed company cannot be confirmed');
    $check($fails(fn()=>purepayFinancialConfirm($pdo,1,7,str_repeat('0',64),'org-a')),'foreign review hash cannot confirm');
    $check($fails(fn()=>purepayFinancialConfirm($pdo,1,0,$r['review_hash'],'org-a')),'review requires an administrator');
    $auditFail=static function(){throw new RuntimeException('Synthetic unavailable audit');};
    $check($fails(fn()=>purepayFinancialConfirm($pdo,1,7,$r['review_hash'],'org-a',$auditFail))
        && (int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_organizations')->fetchColumn()===0 && !$review()['verified'],
        'audit failure rolls back company claim and confirmation together');
    $contexts[$key]['organization']['name']='Renamed Alpha';
    $check($fails(fn()=>$confirm($r)),'name changed after display requires another review');$contexts[$key]=$base;
    $confirm($review());$binding=purepayFinancialRequire($pdo,1,null,true);
    $check($binding['remote_organization_id']==='org-a' && !$driver->isConfiguredForTenant(1),'confirmed read-only connection stays read-only');
    $confirm($review());
    $check((int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_organizations')->fetchColumn()===1,'confirmation replay retains one permanent organization claim');
    $summary=purepayFinancialSummary($pdo,1);
    $check($summary['verified'] && !str_contains(json_encode($summary),$key) && !isset($summary['binding_hash']),'summary exposes company and review status only');
    purepaySaveWebhookSecret(1,'whsec_fixture');
    $check(purepayGetConnection(1)['webhook_secret']==='whsec_fixture','confirmed company accepts callback secret');
    $claim=$pdo->query('SELECT * FROM purepay_financial_organizations')->fetchAll(PDO::FETCH_ASSOC);
    $before=purepayFinancialConnection($pdo,1);
    $check($fails(fn()=>$store($beta)) && purepayFinancialConnection($pdo,1)===$before,'wrong-company replacement rolls credential update back');
    $check($fails(fn()=>$store($read,1,'read_write')) && purepayFinancialConnection($pdo,1)===$before,'provider read-only key cannot obtain local write scope');
    $old=$review();$store($rotated);
    $check(!$review()['verified'] && $fails(fn()=>purepayFinancialRequire($pdo,1)) && $fails(fn()=>$confirm($old)),'key rotation invalidates confirmation and old review');
    $confirm($review());
    $check($pdo->query('SELECT * FROM purepay_financial_organizations')->fetchAll(PDO::FETCH_ASSOC)===$claim,'same-company rotation preserves permanent claim');
    purepayRevokeConnection(1);
    $check($fails(fn()=>purepayFinancialRequire($pdo,1)) && $fails(fn()=>$store($beta)),'disconnect does not free identity or permit retargeting');
    $store($rotated);$confirm($review());
    $store($key,2);$r2=$review(2);
    $check($fails(fn()=>$confirm($r2,2)) && !$review(2)['verified'],'same organization cannot bind to another Core company with another key');
    $store($beta,2);$confirm($review(2),2);
    $check(purepayFinancialRequire($pdo,2)['remote_organization_id']==='org-b','unconfirmed key can be corrected to the right company');
    $contexts[$rotated]['key_scope']='read';
    $check($fails(fn()=>purepayFinancialRequire($pdo,1,null,true)),'changed provider permissions invalidate fresh verification');$contexts[$rotated]=$base;
    $contexts[$rotated]['organization']=['id'=>'org-b','name'=>'Synthetic Beta'];
    $check($fails(fn()=>purepayFinancialRequire($pdo,1,null,true)) && $fails(fn()=>$review()),'provider company drift cannot reuse reviewed credentials');$contexts[$rotated]=$base;
    $r=$review();
    $interleave=static function()use($store,$key){$store($key);};
    $check($fails(fn()=>$confirm($r)) && !$review()['verified'],'rotation during confirmation network read cannot approve a stale review');
    $confirm($review());
    $interleave=static function()use($store,$rotated){$store($rotated);};
    $check($fails(fn()=>purepayFinancialRequire($pdo,1,null,true)),'rotation during a fresh identity check invalidates its result');
    $confirm($review());
    $failure=401;
    try {$review();throw new RuntimeException('Expected failure');} catch(PurePayApiException $e){$check(!str_contains($e->getMessage(),$rotated),'provider error text cannot disclose key material');}
    $failure=null;
    purepayFlagConnectionError(1,'Fixture outage');
    $check(purepayProbeConnection(1)['wallet_balance_cents']===12500 && purepayFinancialSummary($pdo,1)['verified'],'reviewed connection recovers from temporary probe failure');
    // A lock held by another physical connection models competing confirmations.
    $contexts['fixture-company-race']=array_replace($base,['organization'=>['id'=>'org-race','name'=>'Synthetic race']]);
    $store('fixture-company-race',3);$store('fixture-company-race',4);$r4=$review(4);
    $other=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $other->beginTransaction();$c3=purepayFinancialConnection($pdo,3);
    $other->prepare('INSERT INTO purepay_financial_organizations VALUES(?,?,?,?,7,NOW())')->execute([hash('sha256',purepayApiBase()),'org-race',3,$c3['id']]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    $check($fails(fn()=>$confirm($r4,4)) && !$pdo->inTransaction(),'competing organization claim waits then safely rolls back');
    $other->commit();
    $check($fails(fn()=>$confirm($review(4),4)) && !$review(4)['verified'],'committed competing claim remains exclusive');
    $confirm($review(3),3);$check(purepayFinancialSummary($pdo,3)['verified'],'winning company can finish confirmation');
    $check(count(array_filter($calls,static fn($call)=>$call[0]!=='GET'))===0,'workflow performs no provider mutations');
    echo "PurePay financial company: {$passed} passed\n";
} finally {
    if(isset($other)&&$other->inTransaction())$other->rollBack();
    if($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP DATABASE '.$database);
}
