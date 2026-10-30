<?php
/** Enable only the approved, empty financial preview; creates no credentials or financial records. */
declare(strict_types=1);

const FINANCIAL_PILOT_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const FINANCIAL_PILOT_INSTANCE = '9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const FINANCIAL_PILOT_CONFIG_SHA = '7d45926283bb30d67fc9300c317de8caa84415830e2c2b53bc687166540de548';
const FINANCIAL_PILOT_BASE = 'https://pure-pay-support.preview.emergentagent.com/api/v1';

function financialPilotConfig(string $before): string
{
    if (!str_starts_with($before, '<?php') || str_contains($before, '?>')
        || str_contains($before, 'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE')) {
        throw new RuntimeException('Private configuration cannot be changed unambiguously');
    }
    return $before."\n// Approved simulation financial observation; outbound writes are disabled.\n"
        ."define('COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE', '".FINANCIAL_PILOT_BASE."');\n";
}

function financialPilotReplace(string $target, string $prepared, string $expected, string $replacement): void
{
    if (is_link($target) || is_link($prepared) || !is_file($target) || !is_file($prepared)
        || !hash_equals($expected, hash_file('sha256', $target))
        || !hash_equals($replacement, hash_file('sha256', $prepared))) {
        throw new RuntimeException('Private configuration changed before replacement');
    }
    if (!rename($prepared, $target) || !hash_equals($replacement, hash_file('sha256', $target))) {
        throw new RuntimeException('Private configuration replacement did not verify');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--enable-reviewed-financial-preview'
    || getenv('COREFLUX_ENV') !== 'staging' || realpath(getcwd()) !== FINANCIAL_PILOT_ROOT
    || realpath(dirname(__DIR__)) === FINANCIAL_PILOT_ROOT) {
    fwrite(STDERR, "Exact isolated staging CLI and private review package required.\n"); exit(2);
}
$phase='guard';
try {
    foreach (['core/purepay_adapter.php','core/purepay_service.php'] as $source) {
        if (!is_file(__DIR__.'/../'.$source) || !is_file(FINANCIAL_PILOT_ROOT.'/'.$source)
            || hash_file('sha256',__DIR__.'/../'.$source)!==hash_file('sha256',FINANCIAL_PILOT_ROOT.'/'.$source)) {
            throw new RuntimeException('Install the reviewed runtime first');
        }
    }
    require_once FINANCIAL_PILOT_ROOT.'/core/purepay_service.php';
    require_once FINANCIAL_PILOT_ROOT.'/core/integrations/purepay_core.php';
    require_once FINANCIAL_PILOT_ROOT.'/core/sub_tenants.php';
    require_once FINANCIAL_PILOT_ROOT.'/core/RBAC.php';
    require_once FINANCIAL_PILOT_ROOT.'/core/rbac/permissions.php';
    require_once FINANCIAL_PILOT_ROOT.'/core/rbac/legacy_map.php';
    $pdo=getDB();
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true
        || integrationInstanceId()!==FINANCIAL_PILOT_INSTANCE
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1
        || purepayCoreApiBase()!==FINANCIAL_PILOT_BASE) throw new RuntimeException('Pilot identity changed');
    $staffing=$pdo->query('SELECT tenant_id,status,revision,remote_organization_id,remote_api_base,core_instance_id
        FROM purepay_core_connections ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC);
    if (count($staffing)!==1 || (int)$staffing[0]['tenant_id']!==999 || $staffing[0]['status']!=='active'
        || (int)$staffing[0]['revision']!==3 || $staffing[0]['remote_organization_id']!=='org_purepay_demo'
        || $staffing[0]['remote_api_base']!==FINANCIAL_PILOT_BASE || $staffing[0]['core_instance_id']!==FINANCIAL_PILOT_INSTANCE) {
        throw new RuntimeException('Reviewed staffing company changed');
    }
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND is_active=1 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    if (!$operator || (RBACResolver::activeMembership(4,999)['status'] ?? null)!=='active') throw new RuntimeException('Operator unavailable');
    $operator['global_role']=$operator['role']; $operator['role']=RBACResolver::personaTypeOf(4,999);
    [$module,$action]=RbacLegacyMap::resolve('accounting.bank.manage');
    if (!RBAC::hasPermission($operator,'accounting.bank.manage') || !RBACResolver::can($operator,999,$module,$action)) {
        throw new RuntimeException('Operator lacks connection authority');
    }
    $private=dirname(FINANCIAL_PILOT_ROOT).'/private_html';
    $directory=$private.'/purepay-financial-preview-20261025';
    if (is_link($private) || realpath($private)!==$private || is_link($directory)) throw new RuntimeException('Invalid recovery directory');
    if (!is_dir($directory) && !mkdir($directory,0700)) throw new RuntimeException('Cannot retain private recovery');
    if (realpath($directory)!==$directory) throw new RuntimeException('Recovery path changed');
    $lock=fopen($directory.'/operation.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another setup is active');
    $config=FINANCIAL_PILOT_ROOT.'/core/db.local.php';
    $backup=$directory.'/db.original.php'; $next=$directory.'/db.next.php'; $journal=$directory.'/receipt.json';
    foreach ([$config,$backup,$next,$journal] as $path) if (is_link($path)) throw new RuntimeException('Linked recovery file');
    $save=static function(array $receipt) use($journal):void {
        $temp=$journal.'.next';
        if (is_link($temp) || file_put_contents($temp,json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT))===false
            || !chmod($temp,0600) || !rename($temp,$journal)) throw new RuntimeException('Cannot save setup receipt');
    };
    if (!is_file($backup)) {
        if (hash_file('sha256',$config)!==FINANCIAL_PILOT_CONFIG_SHA || is_file($journal)) throw new RuntimeException('Unreviewed private configuration');
        if (!copy($config,$backup) || !chmod($backup,0600)) throw new RuntimeException('Cannot preserve configuration');
    }
    if (hash_file('sha256',$backup)!==FINANCIAL_PILOT_CONFIG_SHA) throw new RuntimeException('Recovery copy changed');
    $after=financialPilotConfig((string)file_get_contents($backup)); $afterHash=hash('sha256',$after);
    $current=hash_file('sha256',$config);
    if (!in_array($current,[FINANCIAL_PILOT_CONFIG_SHA,$afterHash],true)) throw new RuntimeException('Configuration has unrelated changes');
    $receipt=['operation'=>'purepay-financial-preview-20261025','tenant_id'=>999,'organization_id'=>'org_purepay_demo',
        'state'=>'prepared','old_config_sha256'=>FINANCIAL_PILOT_CONFIG_SHA,'new_config_sha256'=>$afterHash,
        'source_commit_guard'=>'exact uploaded adapter/service bytes','credentials_created'=>false,
        'financial_records_written'=>false,'provider_contacted'=>false];
    $phase='empty_installation_check';
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE'); $pdo->beginTransaction();
    if ($pdo->query('SELECT tenant_id FROM purepay_connections ORDER BY tenant_id FOR UPDATE')->fetchAll()
        || $pdo->query('SELECT id FROM purepay_payment_links ORDER BY id FOR UPDATE')->fetchAll()
        || $pdo->query('SELECT id FROM purepay_webhook_events ORDER BY id FOR UPDATE')->fetchAll()) {
        throw new RuntimeException('Financial setup is no longer empty; review before changing its origin');
    }
    $save($receipt);
    if ($current===FINANCIAL_PILOT_CONFIG_SHA) {
        $phase='atomic_configuration_switch'; $metadata=stat($config);
        if (file_put_contents($next,$after)===false || !chmod($next,0600)
            || !chown($next,$metadata['uid']) || !chgrp($next,$metadata['gid']) || !chmod($next,$metadata['mode']&0777)) {
            throw new RuntimeException('Cannot preserve configuration ownership');
        }
        financialPilotReplace($config,$next,FINANCIAL_PILOT_CONFIG_SHA,$afterHash);
    }
    unset($after);
    $phase='fresh_configuration_verification';
    $code='require '.var_export(FINANCIAL_PILOT_ROOT.'/core/config.php',true).'; require '.var_export(FINANCIAL_PILOT_ROOT.'/core/purepay_adapter.php',true).';'
        .' if(COREFLUX_STAGING!==true || purepayApiBase()!=='.var_export(FINANCIAL_PILOT_BASE,true).')exit(2);'
        .' try {purepayCall("fixture-no-provider-credential","POST","/bills");exit(3);}catch(PurePayApiException $e){if($e->getMessage()!=="PurePay financial preview is read-only.")exit(4);} echo "read_only_preview_verified";';
    $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes,FINANCIAL_PILOT_ROOT);
    if (!is_resource($process)) throw new RuntimeException('Cannot verify fresh configuration');
    $output=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0 || trim($output)!=='read_only_preview_verified') throw new RuntimeException('Fresh preview verification failed');
    $audit=$pdo->query("SELECT id FROM audit_log WHERE tenant_id=999 AND event='purepay.financial_preview.enabled' AND target_id=999 LIMIT 1")->fetchColumn();
    if (!$audit) $pdo->prepare("INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at)
        VALUES(999,4,'purepay.financial_preview.enabled',999,:meta,NULL,NOW())")->execute(['meta'=>json_encode($receipt,JSON_THROW_ON_ERROR)]);
    $pdo->commit();
    $receipt['state']='complete'; $receipt['completed_at']=gmdate('c'); $receipt['read_only_preview_verified']=true;
    $save($receipt);
    echo 'PUREPAY_FINANCIAL_PREVIEW='.json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // A process lost after atomic rename can safely resume this exact operation.
    // It cannot grant access: no key, webhook secret or payment link is created.
    fwrite(STDERR,'Financial preview setup stopped at '.$phase.'. Retained private recovery; inspect before retrying.' . "\n");
    exit(2);
}
