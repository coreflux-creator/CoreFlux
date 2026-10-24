<?php
/** One reviewed demo-origin move. No credential rotation, provider writes, or delivery edits. */
declare(strict_types=1);
require_once __DIR__.'/inspect_purepay_preview_continuity.php';
const PREVIEW_ORIGIN_CONFIG_SHA = 'a9fab583fa3b120fc0d88a3aa55f5aee8c78eef242aa9a473b2b1d46cb91d617';

function purepayPreviewOriginFingerprint(array $row): array
{
    $result = array_intersect_key($row,array_flip(['id','tenant_id','status','revision','core_instance_id',
        'remote_organization_id','remote_organization_name','remote_api_base','binding_hash','verified_by','verified_at']));
    $result['credential_cipher_sha256'] = hash('sha256',(string)($row['api_key_ct'] ?? ''));
    return $result;
}

function purepayPreviewOriginRows(PDO $pdo, bool $lock=false): array
{
    $rows = $pdo->query('SELECT * FROM integration_deliveries WHERE tenant_id=999
        AND provider IN ("purepay","purepayroll") ORDER BY id'.($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
    if (array_map(static fn(array $r): int=>(int)$r['id'],$rows) !== [2,5,7,8,9]
        || array_filter($rows,static fn(array $r): bool=>$r['state']!=='delivered' || (string)$r['connection_ref']!=='1')) {
        throw new RuntimeException('Unexpected or unresolved delivery history');
    }
    return $rows;
}

function purepayPreviewOriginAdvance(PDO $pdo, array $expected, string $ledgerHash, callable $audit): void
{
    if ($pdo->inTransaction()) throw new RuntimeException('Migration requires its own transaction');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $pdo->beginTransaction();
    try {
        $all = $pdo->query('SELECT * FROM purepay_core_connections ORDER BY tenant_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        if (count($all)!==1 || (int)$all[0]['tenant_id']!==999 || (int)$all[0]['id']!==1
            || purepayPreviewOriginFingerprint($all[0])!==$expected
            || $all[0]['remote_api_base']!==CONTINUITY_OLD_BASE || (int)$all[0]['revision']!==2
            || $all[0]['status']!=='active' || !is_string($all[0]['binding_hash'])
            || $all[0]['remote_organization_id']!==CONTINUITY_ORG || $all[0]['core_instance_id']!==CONTINUITY_INSTANCE
            || !hash_equals($ledgerHash,hash('sha256',serialize(purepayPreviewOriginRows($pdo,true))))) {
            throw new RuntimeException('Reviewed local state changed');
        }
        $pdo->prepare('UPDATE purepay_core_connections SET remote_api_base=:b,revision=revision+1,
            binding_hash=NULL,verified_by=NULL,verified_at=NULL WHERE tenant_id=999 AND id=1')
            ->execute(['b'=>CONTINUITY_CANDIDATE]);
        $audit();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** A same-volume rename keeps credentials out of partial or publicly readable temporary files. */
function purepayPreviewOriginReplace(string $target,string $prepared,string $oldHash,string $newHash): void
{
    if (is_link($target) || is_link($prepared) || !is_file($target) || !is_file($prepared)
        || !hash_equals($oldHash,hash_file('sha256',$target)) || !hash_equals($newHash,hash_file('sha256',$prepared))) {
        throw new RuntimeException('Configuration changed before replacement');
    }
    if (!rename($prepared,$target) || !hash_equals($newHash,hash_file('sha256',$target))) {
        throw new RuntimeException('Configuration replacement did not verify');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$mode=$argv[1] ?? '';
if (PHP_SAPI!=='cli' || !in_array($mode,['--move-reviewed-demo-origin','--finish-reviewed-demo-origin'],true)
    || getenv('COREFLUX_ENV')!=='staging' || realpath(getcwd())!==CONTINUITY_ROOT) {
    fwrite(STDERR,"Exact isolated staging CLI and reviewed demo operation required.\n"); exit(2);
}
$phase='guard';
try {
    if (realpath(dirname(__DIR__))===CONTINUITY_ROOT
        || !is_file(__DIR__.'/../core/integrations/purepay_core.php')
        || hash_file('sha256',__DIR__.'/../core/integrations/purepay_core.php')
            !==hash_file('sha256',CONTINUITY_ROOT.'/core/integrations/purepay_core.php')) {
        throw new RuntimeException('Install the reviewed runtime before changing its origin');
    }
    require_once CONTINUITY_ROOT.'/core/db.php';
    require_once CONTINUITY_ROOT.'/core/integrations/purepay_links.php';
    require_once CONTINUITY_ROOT.'/core/sub_tenants.php';
    require_once CONTINUITY_ROOT.'/core/RBAC.php';
    require_once CONTINUITY_ROOT.'/core/rbac/permissions.php';
    require_once CONTINUITY_ROOT.'/core/rbac/legacy_map.php';
    $pdo=getDB();
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true || integrationInstanceId()!==CONTINUITY_INSTANCE
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1
        || effectiveTenantIdForModule('people',999)!==999 || effectiveTenantIdForModule('placements',999)!==999
        || purepayApiBase()!=='https://purepay.online/api/v1') throw new RuntimeException('Environment mismatch');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND is_active=1 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    if (!$operator || (RBACResolver::activeMembership(4,999)['status'] ?? null)!=='active') throw new RuntimeException('Operator unavailable');
    $operator['global_role']=$operator['role']; $operator['role']=RBACResolver::personaTypeOf(4,999);
    [$module,$action]=RbacLegacyMap::resolve('accounting.bank.manage');
    if (!RBAC::hasPermission($operator,'accounting.bank.manage') || !RBACResolver::can($operator,999,$module,$action)) {
        throw new RuntimeException('Existing operator lacks connection authority');
    }
    $private=dirname(CONTINUITY_ROOT).'/private_html';
    $directory=$private.'/purepay-preview-origin-20261024';
    if (is_link($private) || realpath($private)!==$private || is_link($directory)) throw new RuntimeException('Invalid recovery directory');
    if (!is_dir($directory) && !mkdir($directory,0700)) throw new RuntimeException('Cannot prepare recovery directory');
    if (realpath($directory)!==$directory) throw new RuntimeException('Recovery directory escaped');
    $lock=fopen($directory.'/operation.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another origin operation is active');
    $journalPath=$directory.'/receipt.json'; $config=CONTINUITY_ROOT.'/core/db.local.php';
    $save=static function(array $value) use ($journalPath): void {
        $temporary=$journalPath.'.next';
        if (is_link($journalPath) || is_link($temporary)) throw new RuntimeException('Invalid journal file');
        if (file_put_contents($temporary,json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false
            || !chmod($temporary,0600) || !rename($temporary,$journalPath)) throw new RuntimeException('Cannot retain recovery receipt');
    };
    $audit=static function(string $event,array $meta) use ($pdo): void {
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at)
            VALUES(999,4,:e,1,:m,NULL,NOW())')->execute(['e'=>$event,'m'=>json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    if (!is_file($journalPath)) {
        if ($mode!=='--move-reviewed-demo-origin' || is_link($config) || realpath($config)!==$config
            || !hash_equals(PREVIEW_ORIGIN_CONFIG_SHA,hash_file('sha256',$config))) throw new RuntimeException('Unreviewed configuration');
        $phase='continuity_preflight';
        $process=proc_open([PHP_BINARY,__DIR__.'/inspect_purepay_preview_continuity.php','--inspect-reviewed-preview'],
            [1=>['pipe','w'],2=>['pipe','w']],$pipes,CONTINUITY_ROOT);
        if (!is_resource($process)) throw new RuntimeException('Cannot verify original evidence');
        $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $status=proc_close($process); unset($error);
        if ($status!==0 || !preg_match('/^PUREPAY_PREVIEW_CONTINUITY=(.+)$/m',$out,$match)) throw new RuntimeException('Continuity inspection failed');
        $preflight=json_decode($match[1],true,32,JSON_THROW_ON_ERROR); unset($out,$match);
        if (($preflight['continuity_verified'] ?? false)!==true || count($preflight['checks'])!==9) throw new RuntimeException('Incomplete receipt checks');
        $old=(string)file_get_contents($config);
        if (substr_count($old,CONTINUITY_OLD_BASE)!==1 || str_contains($old,CONTINUITY_CANDIDATE)) throw new RuntimeException('Ambiguous origin setting');
        $new=str_replace(CONTINUITY_OLD_BASE,CONTINUITY_CANDIDATE,$old);
        $metadata=stat($config); $next=$directory.'/db.next.php';
        if (is_link($next) || is_link($directory.'/db.original.php')
            || file_put_contents($directory.'/db.original.php',$old)===false || !chmod($directory.'/db.original.php',0600)
            || file_put_contents($next,$new)===false || !chmod($next,0600)
            || !chown($next,$metadata['uid']) || !chgrp($next,$metadata['gid']) || !chmod($next,$metadata['mode']&0777)) {
            throw new RuntimeException('Cannot preserve configuration ownership and recovery copy');
        }
        $journal=['operation'=>'purepay-preview-origin-20261024','state'=>'prepared','tenant_id'=>999,
            'old_base'=>CONTINUITY_OLD_BASE,'new_base'=>CONTINUITY_CANDIDATE,
            'old_config_sha256'=>hash('sha256',$old),'new_config_sha256'=>hash('sha256',$new),
            'before'=>purepayPreviewOriginFingerprint(purepayCoreConnection($pdo,999)),
            'ledger_sha256'=>hash('sha256',serialize(purepayPreviewOriginRows($pdo))),
            'ap_sha256'=>hash('sha256',serialize($pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC))),
            'preflight'=>$preflight,'prepared_at'=>gmdate('c')];
        unset($old,$new); $save($journal);
    } else {
        if (is_link($journalPath)) throw new RuntimeException('Invalid journal');
        $journal=json_decode((string)file_get_contents($journalPath),true,64,JSON_THROW_ON_ERROR);
        if (($journal['operation'] ?? null)!=='purepay-preview-origin-20261024'
            || $journal['old_config_sha256']!==PREVIEW_ORIGIN_CONFIG_SHA
            || $journal['old_base']!==CONTINUITY_OLD_BASE || $journal['new_base']!==CONTINUITY_CANDIDATE) {
            throw new RuntimeException('Recovery receipt mismatch');
        }
    }
    $row=purepayCoreConnection($pdo,999);
    if ($row['remote_api_base']===CONTINUITY_OLD_BASE) {
        if ($mode!=='--move-reviewed-demo-origin') throw new RuntimeException('Origin was not advanced');
        $phase='pin_transition';
        purepayPreviewOriginAdvance($pdo,$journal['before'],$journal['ledger_sha256'],
            static fn()=> $audit('purepay.core.preview_origin_migrate',[
                'old_base'=>CONTINUITY_OLD_BASE,'new_base'=>CONTINUITY_CANDIDATE,'revision_before'=>2,'revision_after'=>3,
                'receipt'=>'purepay-preview-origin-20261024','delivery_history_preserved'=>true]));
        $journal['state']='pin_changed'; $save($journal); $row=purepayCoreConnection($pdo,999);
    }
    $fingerprint=purepayPreviewOriginFingerprint($row);
    foreach (['id','tenant_id','status','core_instance_id','remote_organization_id','remote_organization_name','credential_cipher_sha256'] as $field) {
        if ($fingerprint[$field]!==$journal['before'][$field]) throw new RuntimeException('Saved provider identity changed');
    }
    if ((int)$row['revision']!==3 || $row['remote_api_base']!==CONTINUITY_CANDIDATE
        || !hash_equals($journal['ledger_sha256'],hash('sha256',serialize(purepayPreviewOriginRows($pdo))))
        || !hash_equals($journal['ap_sha256'],hash('sha256',serialize($pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC))))) {
        throw new RuntimeException('Migration recovery state changed');
    }
    if (hash_file('sha256',$config)===$journal['old_config_sha256']) {
        $phase='configuration_transition';
        purepayPreviewOriginReplace($config,$directory.'/db.next.php',$journal['old_config_sha256'],$journal['new_config_sha256']);
        $journal['state']='configuration_changed'; $save($journal);
    }
    if (is_link($config) || !hash_equals($journal['new_config_sha256'],hash_file('sha256',$config))) throw new RuntimeException('New configuration mismatch');
    if ($mode==='--move-reviewed-demo-origin') {
        flock($lock,LOCK_UN); fclose($lock);
        // Fresh PHP process must load the new private constant before native review.
        $process=proc_open([PHP_BINARY,__FILE__,'--finish-reviewed-demo-origin'],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,CONTINUITY_ROOT);
        if (!is_resource($process) || proc_close($process)!==0) throw new RuntimeException('Fresh review incomplete; rerun the same operation to recover');
        exit(0);
    }
    $phase='fresh_company_review';
    if (purepayCoreApiBase()!==CONTINUITY_CANDIDATE) throw new RuntimeException('New runtime origin unavailable');
    $bodies=[]; $inputs=[];
    foreach (purepayPreviewOriginRows($pdo) as $delivery) {
        $payload=json_decode((string)decryptField($delivery['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        if ($delivery['provider']==='purepay') $bodies[$delivery['entity_type']]=$payload['body'];
        else $inputs[$delivery['entity_type']]=$payload['body'];
    }
    $continuity=purepayPreviewContinuityRead((string)decryptField($row['api_key_ct']),$bodies,$inputs);
    if (!$continuity['continuity_verified']) throw new RuntimeException('Fresh receipt comparison failed');
    $review=purepayCoreReview($pdo,999,CONTINUITY_INSTANCE);
    if (!$review['verified']) purepayCoreConfirm($pdo,999,4,CONTINUITY_INSTANCE,$review['review_hash'],CONTINUITY_ORG,
        static fn()=> $audit('purepay.core.preview_origin_confirm',['base'=>CONTINUITY_CANDIDATE,'receipt'=>'purepay-preview-origin-20261024']));
    if (!purepayCoreReview($pdo,999,CONTINUITY_INSTANCE)['verified']) throw new RuntimeException('Native company review not verified');
    if (!hash_equals($journal['ledger_sha256'],hash('sha256',serialize(purepayPreviewOriginRows($pdo))))
        || !hash_equals($journal['ap_sha256'],hash('sha256',serialize($pdo->query('SELECT * FROM purepay_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC))))) {
        throw new RuntimeException('Delivery history or separate AP connection changed');
    }
    $journal['state']='complete'; $journal['completed_at']=gmdate('c'); $journal['postflight']=$continuity; $save($journal);
    echo 'PUREPAY_PREVIEW_ORIGIN='.json_encode(['complete'=>true,'tenant_id'=>999,'organization_id'=>CONTINUITY_ORG,
        'old_base'=>CONTINUITY_OLD_BASE,'new_base'=>CONTINUITY_CANDIDATE,'revision'=>3,'native_company_review_verified'=>true,
        'original_deliveries_unchanged'=>true,'credential_unchanged'=>true,'ap_connection_unchanged'=>true,
        'provider_record_writes'=>false,'recovery_receipt'=>$journalPath],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) {
    fwrite(STDERR,'PurePay preview move stopped at '.$phase.'. Retained recovery evidence; no provider records were written. Retry the same guarded operation after inspection.' . "\n");
    exit(2);
}
