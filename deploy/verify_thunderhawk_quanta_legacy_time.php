<?php
/** Read-only native provider context using the current Core imports and exact staged candidate contract. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-isolated-quanta-legacy-time'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root||realpath($private)!==$private||posix_geteuid()!==1003
    ||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??'')||!str_starts_with(realpath(__DIR__).'/',$private.'/'))exit(2);
$manifestPath=__DIR__.'/manifest.json';
if(is_link($manifestPath)||hash_file('sha256',$manifestPath)!==$argv[2])exit(2);
$manifest=json_decode(file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
if($manifest['source_commit']!=='6c83324bea0d7c4dcf8733316759a9ef0c784b1e'
    ||array_keys($manifest['files'])!==['legacy_time_contract.php','time_control_contract.php','time_review_contract.php'])exit(2);
foreach($manifest['files'] as $name=>$sha)if(is_link(__DIR__.'/'.$name)||hash_file('sha256',__DIR__.'/'.$name)!==$sha)exit(2);
require_once $root.'/core/quanta/time_review_delivery.php';
require_once $root.'/core/quanta/time_control_delivery.php';
require_once __DIR__.'/legacy_time_contract.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1)exit(2);
$pdo->exec('START TRANSACTION READ ONLY');
try{
    foreach([2002,2,999,2003] as $tenant)if(quantaTimeControlEnabled($tenant))throw new RuntimeException('Unexpected time activation');
    setRequestTenantId(2002);
    $ids=$pdo->query('SELECT DISTINCT quanta_entry_id FROM quanta_time_imports WHERE tenant_id=2002 ORDER BY quanta_entry_id')->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)!==12)throw new RuntimeException('Expected twelve original source imports');
    $proofs=[];
    foreach($ids as $id){
        $snapshot=quantaTimeReviewSnapshot(2002,$id);$access=$snapshot['access'];
        if($snapshot['status']!=='unchanged'||$snapshot['placement_number']!=='PL-864')throw new RuntimeException('Original Core source proof changed');
        $remote=quantaGet($access['api_key'],QUANTA_CORE_CONTRACT_PATH.'/legacy-time-context',
            ['assignment_id'=>$snapshot['payload']['body']['assignment_id'],'source_entry_id'=>$id]);
        $plan=quantaLegacyTimeContext($remote,$snapshot);
        if($remote['configuration_enabled']||$remote['current']!==null||$plan['body']['expected_version']!==0)throw new RuntimeException('Unexpected operational activation or prior association');
        $owners=quantaTimeControlOwners(2002);
        quantaTimeControlOwned($remote['proof']['assignment_identity'],$access['context']['workspace']['id'],$snapshot['entry']['worker_id'],
            $access['instance_id'],$owners,$snapshot['remote']['assignment']['person']);
        $proofs[$id]=$snapshot['context']['source_hash'];
        quantaWorkspaceAssertCurrent($pdo,2002,$access);
    }
    $report=['passed'=>true,'checked_at'=>gmdate('c'),'source_commit'=>$manifest['source_commit'],
        'candidate_contract_used_privately'=>true,'core_web_runtime_changed'=>false,'provider_contexts_verified'=>count($proofs),
        'original_core_review_proofs_unchanged'=>true,'native_provider_context_hashes_verified'=>true,
        'source_proof_set_sha256'=>integrationDeliveryHash($proofs),'historical_associations_created'=>0,
        'provider_writes'=>false,'operational_policy_enabled'=>false,'real_payroll_payments_postings'=>false];
}finally{$pdo->rollBack();}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
