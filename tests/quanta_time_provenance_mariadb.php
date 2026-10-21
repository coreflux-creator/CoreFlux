<?php
/** Disposable database: exercise real source preview, import/adoption and connection fences. */
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true' && getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1' && in_array('--confirm-disposable-fixture',$argv,true) && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$inCi&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/sync.php';require_once __DIR__.'/../core/migrate.php';
$password=$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
$db='coreflux_qorigin_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$observer=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$db.';charset=utf8mb4','root',$password,$options);
$migrate=static function(string $file)use($pdo):void{foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/'.$file))as$sql)$pdo->exec($sql);};
foreach(['147_quanta_time_integration.sql','148_quanta_worker_identity.sql','149_quanta_time_source_context.sql','159_quanta_workspace_binding.sql','165_quanta_time_workspace.sql']as$file)$migrate($file);
$pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,parent_id BIGINT NULL,tenant_type VARCHAR(20),name VARCHAR(80),is_active INT)');
$pdo->exec("INSERT INTO tenants VALUES(1,NULL,'master','Synthetic pilot',1),(2,NULL,'master','Other pilot',1)");
$pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20))');
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(50),last_name VARCHAR(50),email_primary VARCHAR(100),deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO people VALUES(19,1,'Synthetic','Worker','worker@example.test',NULL)");
$pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,tenant_id BIGINT,person_id BIGINT,status VARCHAR(30),engagement_type VARCHAR(30),start_date DATE,end_date DATE,actual_end_date DATE,title VARCHAR(100),end_client_name VARCHAR(100),deleted_at DATETIME NULL)');
$pdo->exec("INSERT INTO placements VALUES(42,1,19,'active','w2','2026-10-01',NULL,NULL,'Synthetic assignment','Synthetic client',NULL)");
$pdo->exec('CREATE TABLE core_record_numbers(tenant_id BIGINT,entity_type VARCHAR(32),record_id BIGINT,record_number VARCHAR(64))');
$pdo->exec("INSERT INTO core_record_numbers VALUES(1,'person',19,'P-19'),(1,'placement',42,'PL-42')");
$pdo->exec('CREATE TABLE time_entries(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,person_id BIGINT,work_date DATE,timesheet_id BIGINT,status VARCHAR(32),hours DECIMAL(6,2),hour_type VARCHAR(32),category VARCHAR(32),billable TINYINT,description TEXT,source VARCHAR(32),source_system VARCHAR(32),superseded_by_id BIGINT NULL,bill_extracted_at DATETIME NULL,ap_extracted_at DATETIME NULL,payroll_extracted_at DATETIME NULL,rate_snapshot_id BIGINT)');
$pdo->exec("INSERT INTO quanta_worker_links(tenant_id,worker_id,person_id) VALUES(1,'worker-1',19)");
$pdo->prepare('INSERT INTO quanta_time_routes(tenant_id,worker_id,worksite_id,dimension_key,placement_id,effective_from) VALUES(1,?,?,?,42,"2026-10-01")')->execute(['worker-1','site-1',quantaDimensionKey([])]);
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $f):bool{try{$f();return false;}catch(Throwable $e){return true;}};
$instance='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';putenv('COREFLUX_INTEGRATION_INSTANCE_ID='.$instance);
$key='synthetic-origin-key';$context=['workspace'=>['id'=>'workspace-a','name'=>'Synthetic pilot'],'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,
    'capabilities'=>['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts'],'execution_side_effects'=>false];
$pdo->prepare('INSERT INTO quanta_connections(id,tenant_id,status,api_key_ct) VALUES(1,1,"active",?)')->execute([encryptField($key)]);
$bind=static function()use($pdo,$key,$instance,$context):array {
    $c=$pdo->query('SELECT * FROM quanta_connections WHERE tenant_id=1')->fetch(PDO::FETCH_ASSOC);
    quantaWorkspaceBind($pdo,1,7,$c,$key,$instance,$context,quantaWorkspaceBindingHash(1,1,$key,$instance,$context,(int)$c['binding_revision']));
    return quantaWorkspaceForDelivery(1);
};
$GLOBALS['__quanta_transport']=static fn():array=>['status'=>200,'body'=>json_encode($context)];
$access=$bind();setRequestTenantId(1);
$sites=['site-1'=>['id'=>'site-1','name'=>'Synthetic site','timezone'=>'America/New_York']];
$raw=['id'=>'entry-1','worker_id'=>'worker-1','worksite_id'=>'site-1','work_date'=>'2026-10-02','timesheet_status'=>'approved','regular_hours'=>6.5,'overtime_hours'=>1.5,'hours'=>8,'description'=>'Synthetic shift'];
$entry=quantaNormalizeEntry($raw,$sites,'approved');$route=quantaRoutes(1)[0];
$seed=static function(array $entry,int $firstId)use($pdo,$route,$sites):void {
    foreach($entry['components'] as $component=>$hours) {
        [$type,$category,$billable]=quantaComponentCategory($component);
        $pdo->prepare('INSERT INTO time_entries VALUES(?,1,42,19,?,1,"approved",?,?,?,?,?,"quanta","quanta",NULL,NULL,NULL,NULL,81)')->execute([$firstId,$entry['work_date'],$hours,$type,$category,$billable,$entry['description']]);
        $pdo->prepare('INSERT INTO quanta_time_imports(tenant_id,quanta_entry_id,component,time_entry_id,timesheet_id,source_hash,source_timesheet_status,worker_id,source_worksite_id,source_worksite_name,source_dimension_values_json)
            VALUES(1,:quanta_entry_id,:component,:time_entry_id,1,:source_hash,"approved",:worker_id,:source_worksite_id,:source_worksite_name,:source_dimension_values_json)')
            ->execute(['quanta_entry_id'=>$entry['id'],'component'=>$component,'time_entry_id'=>$firstId++,
                'source_hash'=>quantaComponentHash($entry,$route,$component,$hours),'worker_id'=>$entry['worker_id'],...quantaSourceContext($entry,$sites)]);
    }
};
$seed($entry,1);
$before=$pdo->query('SELECT * FROM time_entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$preview=static fn(array $rows):array=>quantaPreviewRows(1,$rows,$sites,'approved',$access);
$import=static fn(array $rows,array $ids,array $reviews=[]):array=>quantaImportSelected(1,7,$rows,$sites,'approved',$ids,$access,$reviews);
$p=$preview([$raw]);$check($p['rows'][0]['status']==='verify_source' && $p['source_workspace']['id']==='workspace-a','matching historical import requests original company confirmation');
$check($fails(fn()=>$import([$raw],['entry-1'])),'ordinary import cannot silently backfill historical workspace');
$check($fails(fn()=>$import([$raw],['entry-1'],['entry-1'=>str_repeat('0',64)])),'wrong source review cannot adopt history');
$review=['entry-1'=>$p['rows'][0]['source_review_hash']];
$pdo->exec('UPDATE time_entries SET hours=7 WHERE id=1');
$check($preview([$raw])['rows'][0]['status']==='conflict' && $fails(fn()=>$import([$raw],['entry-1'],$review)),'edited Core time invalidates historical source association');
$pdo->exec('UPDATE time_entries SET hours=6.5 WHERE id=1');
$different=array_replace($raw,['regular_hours'=>6,'hours'=>7.5]);
$check($preview([$different])['rows'][0]['status']==='conflict','changed Quanta hours cannot establish historical origin');
$imports=quantaImportLookup(1,['entry-1']);
foreach(['worker_id'=>'another-worker','source_worksite_id'=>null,'person_id'=>20,'placement_id'=>43,'current_category'=>'sick','current_source'=>'manual_entry','superseded_by_id'=>99,'current_description'=>'changed']as$field=>$value) {
    $changed=$imports;$changed['entry-1']['regular'][$field]=$value;
    $check(quantaTimeSourceDecision($entry,$route,$changed,'workspace-a')[0]==='conflict',$field.' mismatch blocks historical association');
}
$check($fails(fn()=>quantaWorkspaceAssertCurrent($pdo,2,$access)),'foreign tenant cannot reuse source workspace proof');
$check($fails(fn()=>quantaWorkspaceAssertCurrent($pdo,1,$access,true)),'locking source check requires transaction');
$pdo->beginTransaction();quantaWorkspaceAssertCurrent($pdo,1,$access,true);$observer->exec('SET SESSION innodb_lock_wait_timeout=1');
$check($fails(fn()=>$observer->exec('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=1')),'connection cannot rotate while import holds its lock');$pdo->rollBack();
quantaWorkspaceReconfigure($pdo,1,static function()use($pdo):void{$pdo->exec('UPDATE quanta_connections SET status="revoked" WHERE tenant_id=1');});
$check($fails(fn()=>$import([$raw],['entry-1'],$review)),'disconnect after source fetch blocks the whole import');
$pdo->exec('UPDATE quanta_connections SET status="active" WHERE tenant_id=1');$newAccess=$bind();
$check($fails(fn()=>$import([$raw],['entry-1'],$review)),'reverified connection invalidates the old fetched source snapshot');
$access=$newAccess;
// Rebuild closures so source fetch and review use the new verified connection revision.
$preview=static fn(array $rows):array=>quantaPreviewRows(1,$rows,$sites,'approved',$access);
$import=static fn(array $rows,array $ids,array $reviews=[]):array=>quantaImportSelected(1,7,$rows,$sites,'approved',$ids,$access,$reviews);
$check($fails(fn()=>$import([$raw],['entry-1'],$review)),'old operator review does not survive connection revision');
$p=$preview([$raw]);$review=['entry-1'=>$p['rows'][0]['source_review_hash']];
$raw2=array_replace($raw,['id'=>'entry-2']);$entry2=quantaNormalizeEntry($raw2,$sites,'approved');$seed($entry2,3);
$p2=$preview([$raw2]);$review2=['entry-2'=>$p2['rows'][0]['source_review_hash']];
$check($fails(fn()=>$import([$raw,$raw2],['entry-1','entry-2'],$review)),'later missing review rolls back an earlier source association');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_time_imports WHERE source_workspace_id IS NOT NULL')->fetchColumn()===0,'failed batch leaves all historical provenance unassigned');
$result=$import([$raw,$raw2],['entry-1','entry-2'],$review+$review2);
$check($result['source_verified']===4 && $result['inserted']===0 && $result['updated']===0 && $result['timesheets']===[],'review associates existing components without creating or changing time');
$check($pdo->query('SELECT * FROM time_entries WHERE id<=2 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$before,'approved hours and frozen rate snapshot remain byte-for-byte unchanged');
$row=$pdo->query('SELECT * FROM quanta_time_imports WHERE time_entry_id=1')->fetch(PDO::FETCH_ASSOC);
$check($row['source_workspace_id']==='workspace-a' && (int)$row['source_workspace_verified_by']===7 && $row['source_workspace_verification']==='review' && $row['source_workspace_binding_hash']===$access['binding_hash'],'source attribution durably records company, reviewer and verified connection');
$check($import([$raw],['entry-1'],$review)['already_imported']===1,'response-loss replay skips previously associated time without duplication');
$pdo->exec("UPDATE quanta_time_imports SET source_workspace_id='workspace-other' WHERE time_entry_id=1");
$check($preview([$raw])['rows'][0]['status']==='conflict' && $fails(fn()=>$import([$raw],['entry-1'],$review)),'existing workspace identity cannot be retargeted');
$pdo->exec("UPDATE quanta_time_imports SET source_workspace_id='workspace-a' WHERE time_entry_id=1");
$snapshot=$pdo->query('SELECT * FROM quanta_time_imports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$migrate('165_quanta_time_workspace.sql');
$check($snapshot===$pdo->query('SELECT * FROM quanta_time_imports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'migration replay preserves reviewed history');
$newId=quantaTimeImportInsert($pdo,1,['quanta_entry_id'=>'new-entry','component'=>'regular','worker_id'=>'worker-1',...quantaSourceContext($entry,$sites),
    ...quantaTimeSourceFields($access,7),'time_entry_id'=>99,'timesheet_id'=>1,'source_hash'=>quantaComponentHash($entry,$route,'regular',6.5),'source_timesheet_status'=>'approved','imported_by_user_id'=>7]);
$new=$pdo->query('SELECT * FROM quanta_time_imports WHERE id='.$newId)->fetch(PDO::FETCH_ASSOC);
$check($new['source_workspace_id']==='workspace-a' && $new['source_workspace_verification']==='import' && $new['imported_at']!==null,'real import writer persists verified origin using the imported_at schema');
unset($GLOBALS['__quanta_transport']);putenv('COREFLUX_INTEGRATION_INSTANCE_ID');setRequestTenantId(null);
echo "Quanta time provenance MariaDB: {$passed} passed\n";
