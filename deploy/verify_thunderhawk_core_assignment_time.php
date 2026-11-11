<?php
/** Independent read-only installed-byte, ledger and native provider-proof verification. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-isolated-core-assignment-time'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root||realpath($private)!==$private||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??''))exit(2);
$packageFile=__DIR__.'/core-assignment-time-package.json';
if(is_link($packageFile)||!hash_equals($argv[2],hash_file('sha256',$packageFile)))exit(2);
$package=json_decode(file_get_contents($packageFile),true,64,JSON_THROW_ON_ERROR);
$installed=json_decode(file_get_contents($private.'/assignment-time-core-install-result.json'),true,32,JSON_THROW_ON_ERROR);
if(!$installed['passed']||$package['source_commit']!==$installed['source_commit']||$package['root']!==$root)exit(2);
$backup=$installed['private_backup'];
if(!preg_match('~^'.preg_quote($private,'~').'/assignment-time-before-[a-f0-9]{24}$~D',$backup)||realpath($backup)!==$backup)exit(2);
$before=json_decode(file_get_contents($backup.'/before.json'),true,64,JSON_THROW_ON_ERROR);
require_once $root.'/core/quanta/time_review_delivery.php';
require_once $root.'/core/quanta/time_control_delivery.php';
require_once $root.'/core/migrate.php';
require_once __DIR__.'/quanta_assignment_route_upgrade.php';
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
$pdo->exec('START TRANSACTION READ ONLY');
try{
    $routes=quantaPilotRouteVerify($pdo,$before['routes']);
    $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    $new=array_values(array_filter($ledger,static fn($r)=>$r['filename']==='188_quanta_assignment_routes.sql'));
    if(count($new)!==1||!corefluxMigrationHashMatches($new[0]['sha256'],file_get_contents($root.'/core/migrations/188_quanta_assignment_routes.sql'))||!empty($new[0]['last_error'])
        ||array_values(array_filter($ledger,static fn($r)=>$r['filename']!=='188_quanta_assignment_routes.sql'))!==$before['ledger'])throw new RuntimeException('Migration ledger differs');
    foreach([2002,2,999,2003] as $tenant)if(quantaTimeControlEnabled($tenant))throw new RuntimeException('Unexpected operational time activation');
    if(hash_file('sha256',dirname($root).'/private_html/coreflux.thunderhawk-pilot.php')!==$package['feature_sha256'])throw new RuntimeException('Feature configuration changed');
    setRequestTenantId(2002);
    $ids=$pdo->query("SELECT DISTINCT quanta_entry_id FROM quanta_time_imports WHERE tenant_id=2002 ORDER BY quanta_entry_id")->fetchAll(PDO::FETCH_COLUMN);
    if(count($ids)!==12)throw new RuntimeException('Expected twelve historical source imports');
    $reviews=[];
    foreach($ids as $id){
        $snapshot=quantaTimeReviewSnapshot(2002,$id);
        if($snapshot['status']!=='unchanged'||$snapshot['placement_number']!=='PL-864')throw new RuntimeException('Existing reviewed source proof changed');
        $reviews[]=['entry_id'=>$id,'status'=>'unchanged','core_revision'=>$snapshot['context']['source_hash']];
    }
    $sites=array_values(array_unique(array_filter(array_column(quantaRoutes(2002),'worksite_id'))));
    if(count($sites)!==1)throw new RuntimeException('Pilot worksite needs review');
    try{
        $context=quantaTimeControlContext(2002,358,$sites[0])['view'];
        if($context['delivery_enabled']||$context['current_version']!==0)throw new RuntimeException('Unexpected time setup activation');
        $setup=['state'=>'read_verified','unbound_entries'=>$context['unbound_time']['count'],'activation_enabled'=>false];
    }catch(QuantaApiException $error){
        if($error->httpStatus!==403)throw $error;
        $setup=['state'=>'provider_read_permission_required','http_status'=>403,'activation_enabled'=>false];
    }
    $tables=[];
    foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if($table==='_migrations')continue;
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
        $hashes=[];$query=$pdo->query('SELECT * FROM `'.$table.'`');
        while($row=$query->fetch(PDO::FETCH_ASSOC)){if($table==='quanta_time_routes')unset($row['quanta_assignment_id']);$hashes[]=hash('sha256',serialize($row));}
        sort($hashes,SORT_STRING);$tables[$table]=['count'=>count($hashes),'sha256'=>hash('sha256',implode("\n",$hashes))];
    }
    ksort($tables);if($tables!==$before['tables'])throw new RuntimeException('Existing business or integration records changed');
    $report=['passed'=>true,'checked_at'=>gmdate('c'),'source_commit'=>$package['source_commit'],'runtime_files_verified'=>count($package['files']),
        'served_frontend_files'=>$served,'file_owners_preserved'=>true,'legacy_routes_preserved'=>count($routes['rows']),
        'existing_tables_preserved'=>count($tables),'migration_ledger_verified'=>true,'provider_review_receipts_verified'=>count($reviews),
        'all_original_review_proofs_unchanged'=>true,'time_setup'=>$setup,'operational_policy_enabled'=>false,
        'provider_writes'=>false,'real_payroll_payments_postings'=>false,'production_changed'=>false,'goal_complete'=>false];
}finally{$pdo->rollBack();}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
