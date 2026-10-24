<?php
/** Real transactional origin change, rollback, and fresh review in a disposable database. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true)
    && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$inCi && !$local) throw new RuntimeException('Explicit disposable database required');
putenv('COREFLUX_DISABLE_DATABASE=1'); putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
define('COREFLUX_STAGING',true);
require_once __DIR__.'/../core/integrations/purepay_links.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../deploy/migrate_purepay_preview_origin.php';
putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.CONTINUITY_OLD_BASE);
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='coreflux_origin_'.getmypid(); $pdo->exec('CREATE DATABASE '.$database); $pdo->exec('USE '.$database);
$passed=0;
$check=static function(bool $value,string $label) use (&$passed): void { if (!$value) throw new RuntimeException($label); $passed++; };
$fails=static function(callable $call): bool { try {$call();return false;} catch (Throwable $e) {return true;} };
try {
    foreach (['146_purepay_rail.sql','164_purepay_core_connection.sql','158_integration_deliveries.sql','162_integration_delivery_receipt.sql'] as $migration) {
        foreach (coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$migration)) as $sql) $pdo->exec($sql);
    }
    $pdo->exec('CREATE TABLE migration_audit (event VARCHAR(50)) ENGINE=InnoDB');
    $key='fixture-meridian-existing-key'; $cipher=encryptField($key);
    $context=['organization'=>['id'=>CONTINUITY_ORG,'name'=>'Meridian Holdings LLC'],'contract_version'=>PUREPAY_CORE_VERSION,
        'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,'capabilities'=>['read:coreflux','read:payroll'],'execution_side_effects'=>false];
    $GLOBALS['__purepay_transport']=static function($method,$url) use ($context): array {
        if ($method!=='GET' || !str_ends_with($url,'/context')) throw new RuntimeException('Only organization reads are allowed');
        return ['status'=>200,'body'=>json_encode($context)];
    };
    $pdo->prepare('INSERT INTO purepay_core_connections(id,tenant_id,status,revision,api_key_ct,core_instance_id,remote_organization_id,
        remote_organization_name,remote_api_base,verified_by,verified_at) VALUES(1,999,"active",2,?,?,?,?,?,4,NOW())')
        ->execute([$cipher,CONTINUITY_INSTANCE,CONTINUITY_ORG,'Meridian Holdings LLC',CONTINUITY_OLD_BASE]);
    $row=purepayCoreConnection($pdo,999);
    $pdo->prepare('UPDATE purepay_core_connections SET binding_hash=? WHERE id=1')
        ->execute([purepayCoreHash($row,$key,CONTINUITY_INSTANCE,$context)]);
    $pdo->prepare('INSERT INTO purepay_connections(tenant_id,api_key_ct,key_scope,status) VALUES(999,?,"read_only","active")')->execute([encryptField('separate-ap-fixture')]);
    $ap=$pdo->query('SELECT * FROM purepay_connections')->fetchAll(PDO::FETCH_ASSOC);
    foreach ([2,5,7,8,9] as $id) {
        $hash=hash('sha256','fixture-'.$id);
        $pdo->prepare('INSERT INTO integration_deliveries(id,tenant_id,provider,connection_ref,binding_hash,entity_type,entity_tenant_id,
            entity_id,operation,source_hash,remote_hash,review_hash,dedupe_hash,subject_hash,payload_ct,state,idempotency_key,created_by,expires_at)
            VALUES(?,999,?,"1",?,"person",999,15,"fixture",?,?,?,?,?, ?,"delivered",?,4,DATE_ADD(NOW(),INTERVAL 1 DAY))')
            ->execute([$id,$id<7?'purepayroll':'purepay',$hash,$hash,$hash,$hash,$hash,$hash,encryptField('fixture-body'),$hash]);
    }
    $original=purepayCoreConnection($pdo,999); $expected=purepayPreviewOriginFingerprint($original);
    $ledger=purepayPreviewOriginRows($pdo); $ledgerHash=hash('sha256',serialize($ledger));
    $audit=static fn()=> $pdo->exec('INSERT INTO migration_audit VALUES("origin_changed")');
    $check(!isset($expected['api_key_ct']) && !str_contains(json_encode($expected),$key),'Fingerprint excludes credential content');
    $check(purepayCoreReview($pdo,999,CONTINUITY_INSTANCE)['verified'],'Original company review is valid');
    $changed=$expected; $changed['revision']=1;
    $check($fails(fn()=>purepayPreviewOriginAdvance($pdo,$changed,$ledgerHash,$audit)),'Stale connection review refused');
    $check($fails(fn()=>purepayPreviewOriginAdvance($pdo,$expected,str_repeat('f',64),$audit)),'Changed ledger refused');
    $pdo->exec('UPDATE integration_deliveries SET state="uncertain" WHERE id=2');
    $check($fails(fn()=>purepayPreviewOriginAdvance($pdo,$expected,$ledgerHash,$audit)),'Unresolved receipt blocks migration');
    $pdo->exec('UPDATE integration_deliveries SET state="delivered" WHERE id=2');
    $pdo->exec('INSERT INTO purepay_core_connections(tenant_id) VALUES(998)');
    $check($fails(fn()=>purepayPreviewOriginAdvance($pdo,$expected,$ledgerHash,$audit)),'Other metadata company blocks shared configuration change');
    $pdo->exec('DELETE FROM purepay_core_connections WHERE tenant_id=998');
    $auditFailure=static function() use ($pdo): void {$pdo->exec('INSERT INTO migration_audit VALUES("rollback")');throw new RuntimeException('Fixture audit failure');};
    $check($fails(fn()=>purepayPreviewOriginAdvance($pdo,$expected,$ledgerHash,$auditFailure)),'Audit failure rolls back origin change');
    $check(purepayCoreConnection($pdo,999)===$original && (int)$pdo->query('SELECT COUNT(*) FROM migration_audit')->fetchColumn()===0,'Original pin and audit restored together');
    purepayPreviewOriginAdvance($pdo,$expected,$ledgerHash,$audit);
    $moved=purepayCoreConnection($pdo,999);
    $check((int)$moved['revision']===3 && $moved['remote_api_base']===CONTINUITY_CANDIDATE && $moved['binding_hash']===null
        && $moved['verified_by']===null && $moved['verified_at']===null,'New origin invalidates pending review authorization');
    $check($moved['api_key_ct']===$cipher && purepayPreviewOriginRows($pdo)===$ledger,'Credential and immutable deliveries retained');
    $check($fails(fn()=>purepayCoreReview($pdo,999,CONTINUITY_INSTANCE)),'Interrupted configuration update leaves access closed');
    putenv('COREFLUX_PUREPAY_PREVIEW_API_BASE='.CONTINUITY_CANDIDATE);
    $review=purepayCoreReview($pdo,999,CONTINUITY_INSTANCE);
    $check(!$review['verified'] && $review['review_hash']!==$original['binding_hash'],'Changing the configured origin does not reuse old authorization');
    purepayCoreConfirm($pdo,999,4,CONTINUITY_INSTANCE,$review['review_hash'],CONTINUITY_ORG);
    $check(purepayCoreReview($pdo,999,CONTINUITY_INSTANCE)['verified'],'Fresh native company review completes the transition');
    $check($fails(fn()=>purepayPreviewOriginAdvance($pdo,$expected,$ledgerHash,$audit)),'A replay cannot advance the origin twice');
    $check((int)$pdo->query('SELECT COUNT(*) FROM migration_audit')->fetchColumn()===1,'Exactly one origin event retained');
    $check($pdo->query('SELECT * FROM purepay_connections')->fetchAll(PDO::FETCH_ASSOC)===$ap,'Separate AP credential and state unchanged');
    echo 'PASS '.$passed." PurePay preview origin database assertions\n";
} finally {
    unset($GLOBALS['__purepay_transport']); $pdo->exec('DROP DATABASE '.$database);
}
