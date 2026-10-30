<?php
/** Exact read-only source comparison; credentials and provider bodies stay in memory. */
declare(strict_types=1);
const TH_SOURCE_ROOT='/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html';
const TH_SOURCE_HELPERS='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html/deploy';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--read-exact-pilot-source'
    ||realpath(getcwd())!==TH_SOURCE_ROOT||getenv('COREFLUX_ENV')==='staging')exit(2);
require_once TH_SOURCE_ROOT.'/core/db.php';
require_once TH_SOURCE_HELPERS.'/jobdiva_pilot_runtime.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='grcudkpvcd'
    ||$pdo->query('SELECT name FROM tenants WHERE id=2')->fetchColumn()!=='Thunderhawk')exit(2);
$report=['checked_at'=>gmdate('c'),'mode'=>'read-only-source-comparison','production_changed'=>false,
    'provider_records_written'=>false,'pilot_records_written'=>false];
try{
    // Enforce the read-only claim at the database transaction boundary.
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $report['source']=fullCoreJobdivaFreshPilot($pdo,[
        'tenant_id'=>2,'workspace_name'=>'Thunderhawk','person_directory_id'=>2,
        'accounting_directory_id'=>2,'company_directory_id'=>2,'ap_directory_id'=>2,
        'person_id'=>358,'placement_id'=>864,
        'jobdiva_ids'=>['person'=>'18424007240614','placement'=>'57862243','job'=>'27926305'],
    ],TH_SOURCE_ROOT);
    $report['source_refreshed']=($report['source']['source_refreshed']??false)===true;
}catch(Throwable $error){
    $report['source_refreshed']=false;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
}finally{if($pdo->inTransaction())$pdo->rollBack();}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['source_refreshed']?0:1);
