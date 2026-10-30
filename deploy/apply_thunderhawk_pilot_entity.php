<?php
/** Apply the user-requested existing sync inheritance to PL-864 only. */
declare(strict_types=1);
if (PHP_SAPI!=='cli'||($argv[1]??'')!=='--apply-reviewed-existing-default') throw new RuntimeException('Explicit pilot alignment required');
$requested=rtrim((string)getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT'),'/');$root=realpath($requested);
$package=realpath(dirname(__DIR__));$run=(string)getenv('COREFLUX_PILOT_ALIGNMENT_RUN');
if (!$root||$root!==$requested||$root===$package||basename(dirname($root))==='muzqvdvqbx'
    ||!preg_match('~^/home/[^/]+\.cloudwaysapps\.com/[a-z0-9]+/public_html$~D',$root)
    ||!preg_match('/^[0-9]{6,20}$/D',$run)) throw new RuntimeException('Exact production identity and audit run required');
$manifest=json_decode((string)file_get_contents($package.'/integration-manifest.json'),true,512,JSON_THROW_ON_ERROR);
if (($manifest['product']??null)!=='full-core-staging'||!preg_match('/^[a-f0-9]{40}$/D',$manifest['commit']??'')) throw new RuntimeException('Reviewed package required');
foreach (['deploy/apply_thunderhawk_pilot_entity.php','deploy/staffing_pilot_entity_apply.php',
    'deploy/staffing_pilot_entity_inheritance.php','deploy/staffing_directory_inventory.php'] as $relative) {
    if (is_link($package.'/'.$relative)||!hash_equals((string)($manifest['files'][$relative]??''),hash_file('sha256',$package.'/'.$relative))) {
        throw new RuntimeException('Reviewed source checksum mismatch');
    }
}
require_once $root.'/core/db.php';
require_once $root.'/core/sub_tenants.php';
require_once __DIR__.'/staffing_pilot_entity_apply.php';
$pdo=getDB();
if (!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!==basename(dirname($root))) throw new RuntimeException('Database identity mismatch');
$pilot=['tenant_id'=>2,'workspace_name'=>'Thunderhawk','person_directory_id'=>2,'accounting_directory_id'=>2,
    'person_id'=>358,'placement_id'=>864,'jobdiva_ids'=>['person'=>'18424007240614','placement'=>'57862243','job'=>'27926305']];
$expected=['id'=>2,'code'=>'THUN','legal_name'=>'Thunderhawk'];
$requestId='thunderhawk-pilot-entity-'.$run;
try {
    $audit=static fn($before,$after)=>fullCorePilotEntityAudit($pdo,$pilot,$before===null?null:(int)$before,
        $after,$requestId,$manifest['commit']);
    $result=fullCorePilotApplyEntityInheritance($pdo,$pilot,$expected,
        static fn()=>effectiveTenantIdForModule('accounting',2),$audit);
    echo 'THUNDERHAWK_ENTITY_ALIGNMENT='.json_encode($result+['placement_id'=>864,'entity_id'=>2,'entity_code'=>'THUN',
        'candidate_commit'=>$manifest['commit'],'run_id'=>$run,'production_runtime_changed'=>false,
        'payroll_or_payments_executed'=>false,'arabella_changed'=>false],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    // No DB diagnostics or private row data in deployment logs.
    $known=['A separate transaction is required','Reviewed existing entity required','Transactional pilot tables required',
        'Reviewed placement unavailable','Reviewed entity or source identity changed','Existing entity or override must be preserved',
        'Placement changed before inheritance','Entity update was not retained','Unrelated placement fields changed',
        'Verified audit receipt required','Person or rate records changed'];
    $reason=in_array($e->getMessage(),$known,true)?$e->getMessage():($e instanceof PDOException?'Database operation rejected':'Unexpected guard failure');
    fwrite(STDERR,'THUNDERHAWK_ENTITY_ALIGNMENT held: '.$reason."\n");exit(2);
}
