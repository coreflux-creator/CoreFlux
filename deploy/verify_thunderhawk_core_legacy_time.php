<?php
/** Independently verify the installed historical review flow without creating deliveries. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-isolated-core-legacy-time'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root||realpath($private)!==$private||posix_geteuid()!==1003||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??''))exit(2);
$packageFile=__DIR__.'/core-legacy-time-package.json';
if(is_link($packageFile)||hash_file('sha256',$packageFile)!==$argv[2])exit(2);
$package=json_decode(file_get_contents($packageFile),true,64,JSON_THROW_ON_ERROR);
$installed=json_decode(file_get_contents($private.'/legacy-time-core-install-result.json'),true,32,JSON_THROW_ON_ERROR);
if(!$installed['passed']||$package['source_commit']!==$installed['source_commit']||$package['root']!==$root)exit(2);
$backup=$installed['private_backup'];
if(!preg_match('~^'.preg_quote($private,'~').'/legacy-time-before-[a-f0-9]{24}$~D',$backup)||realpath($backup)!==$backup)exit(2);
$before=json_decode(file_get_contents($backup.'/before.json'),true,64,JSON_THROW_ON_ERROR);
$helper=__DIR__.'/quanta_assignment_route_upgrade.php';
if(is_link($helper)||hash_file('sha256',$helper)!==$package['schema_helper_sha256'])exit(2);
require_once $root.'/core/quanta/legacy_time_delivery.php';require_once $helper;
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')exit(2);
$served=0;
foreach($package['files'] as $relative=>$file){
    $path=$root.'/'.$relative;clearstatcache(true,$path);
    if(is_link($path)||realpath($path)!==$path||hash_file('sha256',$path)!==$file['sha256']||fileowner($path)!==1003||filegroup($path)!==33)throw new RuntimeException('Installed runtime or ownership differs');
    if($relative==='index.html'||$relative==='dashboard/dist/index.html'||str_starts_with($relative,'spa-assets/')){
        $curl=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com/'.$relative);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($status!==200||!is_string($bytes)||hash('sha256',$bytes)!==$file['sha256'])throw new RuntimeException('Served asset differs');$served++;
    }
}
foreach($package['dependencies'] as $relative=>$sha)if(hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/'.$relative)))!==$sha)throw new RuntimeException('Existing dependency changed');
$pdo->exec('START TRANSACTION READ ONLY');
try{
    if(quantaPilotRouteState($pdo)!==$before['routes'])throw new RuntimeException('Existing route schema or records changed');
    if($pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)!==$before['ledger'])throw new RuntimeException('Migration ledger changed');
    if(hash_file('sha256',dirname($root).'/private_html/coreflux.thunderhawk-pilot.php')!==$package['feature_sha256'])throw new RuntimeException('Feature configuration changed');
    foreach([2002,2,999,2003] as $tenant)if(quantaTimeControlEnabled($tenant))throw new RuntimeException('Unexpected operational activation');
    setRequestTenantId(2002);
    $ids=$pdo->query('SELECT DISTINCT quanta_entry_id FROM quanta_time_imports WHERE tenant_id=2002 ORDER BY quanta_entry_id')->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)!==12)throw new RuntimeException('Expected twelve original imports');
    $hours=0;$proofs=[];
    foreach($ids as $id){
        $s=quantaLegacyTimeSnapshot(2002,$id);
        if($s['view']['status']!=='preview'||$s['view']['delivery_enabled']||$s['view']['placement_number']!=='PL-864'
            ||$s['view']['person_number']!=='P-358'||$s['payload']['body']['expected_version']!==0)throw new RuntimeException('Historical review differs from the pilot source');
        $hours+=$s['view']['hours'];$proofs[$id]=$s['local']['proof']['core_revision'];
    }
    $sites=array_values(array_unique(array_filter(array_column(quantaRoutes(2002),'worksite_id'))));
    if(count($sites)!==1)throw new RuntimeException('Pilot worksite differs');
    $inventory=quantaLegacyTimeInventory(2002,358,$sites[0]);
    if($inventory['remaining']!==12||!$inventory['complete']||count($inventory['rows'])!==12
        ||count(array_filter($inventory['rows'],static fn($r)=>$r['imported']))!==12)throw new RuntimeException('Historical inventory differs');
    if(quantaLegacyTimePending(2002)['rows']!==[])throw new RuntimeException('Unexpected prior historical attempts');
    $tables=[];
    foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if($table==='_migrations')continue;if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
        $hashes=[];$query=$pdo->query('SELECT * FROM `'.$table.'`');
        while($row=$query->fetch(PDO::FETCH_ASSOC))$hashes[]=hash('sha256',serialize($row));
        sort($hashes,SORT_STRING);$tables[$table]=['count'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
    }
    ksort($tables);if($tables!==$before['tables'])throw new RuntimeException('Existing business or integration records changed');
    $report=['passed'=>true,'checked_at'=>gmdate('c'),'source_commit'=>$package['source_commit'],'runtime_files_verified'=>count($package['files']),
        'served_frontend_files'=>$served,'file_owners_preserved'=>true,'legacy_routes_preserved'=>count($before['routes']['rows']),
        'existing_tables_preserved'=>count($tables),'migration_ledger_unchanged'=>true,'native_historical_snapshots_verified'=>count($ids),
        'original_review_proof_set_sha256'=>integrationDeliveryHash($proofs),'historical_hours_reviewed'=>$hours,
        'inventory_complete'=>true,'historical_deliveries_created'=>0,'historical_associations_created'=>0,
        'provider_writes'=>false,'operational_policy_enabled'=>false,'real_payroll_payments_postings'=>false,'production_changed'=>false,'goal_complete'=>false];
}finally{$pdo->rollBack();}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
