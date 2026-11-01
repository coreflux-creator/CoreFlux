<?php
declare(strict_types=1);
$inCi=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$inCi&&!$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/integrations/field_map_apply.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../modules/placements/lib/jobdiva_reconciliation.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$inCi?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='coreflux_jobdiva_values_'.($inCi?'ci':gmdate('YmdHis').'_'.getmypid()); $pdo->exec('CREATE DATABASE '.$db); $pdo->exec('USE '.$db);
$apply=static function($f)use($pdo){foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../'.$f))as$sql)$pdo->exec($sql);};
foreach(['core/migrations/022_external_entity_mappings.sql','core/migrations/068_tenant_integration_field_map.sql','core/migrations/077_tenant_field_map_generalise.sql'] as $f) $apply($f);
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(80),last_name VARCHAR(80),email_primary VARCHAR(150),deleted_at DATETIME)');
$pdo->exec('CREATE TABLE companies(id BIGINT PRIMARY KEY,tenant_id BIGINT,name VARCHAR(100))');
$pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,tenant_id BIGINT,person_id BIGINT,end_client_company_id BIGINT,external_id VARCHAR(80),start_date DATE,status VARCHAR(20),deleted_at DATETIME)');
$pdo->exec("INSERT INTO people VALUES(1,10,'Synthetic','Worker','fixture@example.com',NULL)");
$pdo->exec("INSERT INTO placements VALUES(1,10,1,NULL,'jd:101','2026-10-01','active',NULL)");
$moneyColumns=['bill_rate','pay_rate','ot_multiplier','dt_multiplier','adder_pct','background_fee_total','bill_adder_pct','bill_adder_flat','bill_discount_pct','bill_discount_flat','workers_comp_pct','benefits_load_pct','c2c_overhead_pct','other_cost_per_hour','other_cost_flat'];
$pdo->exec('CREATE TABLE placement_rates(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,effective_from DATE,effective_to DATE,bill_rate_unit VARCHAR(16),pay_rate_unit VARCHAR(16),currency CHAR(3),approved_at DATETIME,created_by_user_id BIGINT,economics_snapshot_json JSON,'.implode(',',array_map(static fn($c)=>$c.' DECIMAL(16,6)',$moneyColumns)).')');
$passed=0;
$check=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$failure=static function($fn):string{try{$fn();return '';}catch(Throwable $e){return $e->getMessage();}};
$pdo->exec('ALTER TABLE placements ADD engagement_type VARCHAR(30) DEFAULT "w2"');
$apply('core/migrations/078_integration_writable_targets.sql');
$beforeCity=$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC);
$apply('core/migrations/181_placement_worksite_city.sql');
$apply('core/migrations/181_placement_worksite_city.sql');
$afterCity=$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC);
$check($afterCity[0]['worksite_city']===null&&array_diff_key($afterCity[0],['worksite_city'=>true])===$beforeCity[0],'City migration is repeatable and does not fabricate existing location data');
$check((int)$pdo->query("SELECT COUNT(*) FROM integration_writable_targets WHERE tenant_id IS NULL AND target_table='placements' AND target_column='worksite_city'")->fetchColumn()===1,'City has exactly one global mapping target after migration replay');
$contract=['start_id'=>'101','engagement_type'=>'w2','start_date'=>'2026-10-01','bill_rate'=>70,'pay_rate'=>40,'bill_rate_unit'=>'H','pay_rate_unit'=>'H','currency'=>'USD','worksite_country'=>'United States'];
$payload=['startId'=>'101','candidateId'=>'201','jobId'=>'301','startDate'=>'2026-10-01','status'=>'active','_jd_contract'=>$contract];
$snapshot=static fn()=> $pdo->query('SELECT * FROM placement_rates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$check(jobdivaSyncUpsertPlacementRates(10,1,'2026-10-01',$payload),'valid hourly source creates one rate');
$rows=$snapshot();
$check(count($rows)===1&&$rows[0]['currency']==='USD'&&$rows[0]['bill_rate_unit']==='hour'&&(float)$rows[0]['pay_rate']===40.0,'writer persists explicit source basis');
$check(jobdivaSyncUpsertPlacementRates(10,1,'2026-10-01',$payload)&&$snapshot()===$rows,'identical source replays without extra drafts');
foreach ([['currency'=>null],['pay_currency'=>'CAD'],['bill_rate_unit'=>'mystery'],['pay_rate_unit'=>null],['bill_rate_unit'=>'day'],['bill_rate_raw'=>'CAD 70/hour']] as $override) {
    $bad=$payload; $bad['_jd_contract']=array_replace($contract,$override);
    $message=$failure(fn()=>jobdivaSyncUpsertPlacementRates(10,1,'2026-10-01',$bad));
    $check(str_contains($message,'needs review')&&$snapshot()===$rows,'invalid source leaves draft rates unchanged');
    $bad['__cf_existing_placement_id']=1;
    $before=$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC);
    $message=$failure(fn()=>jobdivaSyncUpsertPlacement(10,1,null,$bad,'101'));
    $check(str_contains($message,'needs review')&&$before===$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC),'placement preflight rejects source before identity repair');
}
$bad=$payload; $bad['_jd_contract']['worksite_country']='United nonsense';
$check(str_contains($failure(fn()=>jobdivaSyncUpsertPlacement(10,1,null,$bad,'101')),'country is not recognized')&&$snapshot()===$rows,'country rejects before placement or rate writes');
$bad=$payload;$bad['_jd_contract']['worksite_city']="Hamilton\nNJ";
$beforeCity=$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC);
$check(str_contains($failure(fn()=>jobdivaSyncUpsertPlacement(10,1,null,$bad,'101')),'Worksite city')
    &&$snapshot()===$rows&&$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC)===$beforeCity,'Invalid assignment city rejects before any placement or rate writes');
$pdo->exec('UPDATE placement_rates SET approved_at="2026-10-01 12:00:00"');
$approved=$snapshot()[0];
$changed=$payload; $changed['_jd_contract']['bill_rate']=75;
$check(jobdivaSyncUpsertPlacementRates(10,1,'2026-10-01',$changed)&&count($snapshot())===2&&$snapshot()[0]===$approved,'source correction preserves approved history');
$rows=$snapshot();
$check(jobdivaSyncUpsertPlacementRates(10,1,'2026-10-01',$changed)&&$snapshot()===$rows,'correction replay preserves one draft');
$missing=['bill_rate'=>75,'pay_rate'=>40,'effective_from'=>'2026-10-01'];
$insert=integrationFieldMapInsertPlacementRateRow(10,1,$missing);
$check($insert['id']===0&&str_contains($insert['error'],'need_review')&&$snapshot()===$rows,'generic mapped insert cannot manufacture hourly USD metadata');
$plan=jobdivaReconciliationBuildRowPlan($pdo,10,10,1,['start_id'=>'101','bill_rate'=>75,'pay_rate'=>40],[]);
$check($plan['outcome']==='blocked'&&str_contains(implode(' ',$plan['errors']),'currency'),'CSV plan does not inherit old rate currency or units');
$plan=jobdivaReconciliationBuildRowPlan($pdo,10,10,1,['start_id'=>'101','bill_rate'=>75,'pay_rate'=>40,'bill_rate_unit'=>'hour','pay_rate_unit'=>'hour','currency'=>'USD'],[]);
$check($plan['outcome']!=='blocked','complete explicit CSV rate basis remains reviewable');
$check($snapshot()===$rows,'CSV previews never mutate rate records');
$mapping=$pdo->prepare('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,external_field,internal_field,source_path,target_module,target_table,target_column,linked_entity) VALUES(10,"jobdiva","placement",:external,:internal,:source,"placements","placement_rates",:target,"placement_rates")');
foreach(['bill_rate','pay_rate','bill_rate_unit','pay_rate_unit','currency']as$field)$mapping->execute(['external'=>$field,'internal'=>$field,'source'=>$field,'target'=>$field]);
$context=['placement'=>1,'placement_rates'=>(int)$rows[1]['id']];
$mapped=['employmentType'=>'W2','bill_rate'=>75,'pay_rate'=>40,'bill_rate_unit'=>'USD/Hour','pay_rate_unit'=>'CAD/Hour','currency'=>'USD'];
$summary=integrationFieldMapApplyAll(10,'jobdiva','placement',$mapped,$context);
$check($summary['written']===0&&$summary['errors']!==[]&&$snapshot()===$rows,'mapped mixed currencies cannot disappear during unit coercion');
$mapped['pay_rate_unit']='hourly bonus';
$summary=integrationFieldMapApplyAll(10,'jobdiva','placement',$mapped,$context);
$check($summary['written']===0&&$summary['errors']!==[]&&$snapshot()===$rows,'one invalid mapped unit blocks the entire rate bucket');
$mapped['pay_rate_unit']='USD/Hour';unset($mapped['currency']);
$summary=integrationFieldMapApplyAll(10,'jobdiva','placement',$mapped,$context);
$check($summary['written']===5&&$summary['errors']===[]&&$snapshot()===$rows,'mapped combined descriptors persist their explicit currency');
$pdo->exec('UPDATE placements SET external_id="legacy:keep" WHERE id=1');
$placementBefore=$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC);
$peopleBefore=$pdo->query('SELECT * FROM people')->fetchAll(PDO::FETCH_ASSOC);
$invalidContracts=[
    array_replace($contract,['engagement_type'=>'','employment_category'=>'','w2_flag'=>true]),
    array_replace($contract,['engagement_type'=>'W2 or 1099']),
    array_replace($contract,['classification_review'=>jobdivaClassificationReview([
        ['key'=>'workerType','path'=>'assignment.workerType','value'=>'W2'],
        ['key'=>'employmentType','path'=>'assignment.employmentType','value'=>'1099'],
    ],'start_assignment')]),
];
foreach($invalidContracts as$badContract){
    $bad=$payload;$bad['_jd_contract']=$badContract;$bad['__cf_existing_placement_id']=1;
    $message=$failure(fn()=>jobdivaSyncUpsertPlacementRates(10,1,'2026-10-01',$bad));
    $check(str_contains($message,'classification needs review')&&$snapshot()===$rows,'unresolved classification cannot change source rates');
    $message=$failure(fn()=>jobdivaSyncUpsertPlacement(10,1,null,$bad,'101'));
    $check(str_contains($message,'classification needs review')&&$pdo->query('SELECT * FROM placements')->fetchAll(PDO::FETCH_ASSOC)===$placementBefore,'classification preflight preserves an existing external identity');
    $result=jobdivaProjectorProjectPlacement(10,$bad,null,['external_id'=>'101','existing_placement_id'=>1,'payload_is_enriched'=>true]);
    $check(!$result['projected']&&str_contains(implode(' ',$result['errors']),'classification needs review')&&$pdo->query('SELECT * FROM people')->fetchAll(PDO::FETCH_ASSOC)===$peopleBefore,'projector rejects classification before person creation');
}
$pdo->exec('UPDATE placements SET external_id="jd:101" WHERE id=1');
$pdo->exec('INSERT INTO tenant_integration_field_map(tenant_id,integration,entity_type,external_field,internal_field,source_path,target_module,target_table,target_column,linked_entity) VALUES(10,"jobdiva","placement","assignment.customCategory","engagement_type","assignment.customCategory","placements","placements","engagement_type","self")');
$gap=$payload;unset($gap['_jd_contract']['engagement_type']);$gap['assignment']=['customCategory'=>'C2C'];
$mappedReview=jobdivaClassificationPayloadForTenant(10,$gap);
$check(jobdivaClassificationRequire($mappedReview)==='c2c'&&$mappedReview['_jd_contract']['classification_review']['source']==='mapped_source','configured assignment field resolves a genuine classification gap');
$gap['assignment']['customCategory']='1099';
$refreshed=jobdivaClassificationPayloadForTenant(10,array_replace($mappedReview,['assignment'=>$gap['assignment']]));
$check(jobdivaClassificationRequire($refreshed)==='1099','cached mapping evidence is refreshed from the current source');
$pdo->exec('UPDATE tenant_integration_field_map SET enabled=0 WHERE internal_field="engagement_type"');
$check(str_contains($failure(fn()=>jobdivaClassificationRequire(jobdivaClassificationPayloadForTenant(10,$mappedReview))),'needs review'),'disabled classification mapping cannot survive in a cached contract');
$pdo->exec('UPDATE tenant_integration_field_map SET enabled=1 WHERE internal_field="engagement_type"');
$authoritative=$payload;$authoritative['_jd_contract']['employment_category']='Hourly Employee';$authoritative['assignment']=['customCategory'=>'C2C'];
$check(jobdivaClassificationRequire(jobdivaClassificationPayloadForTenant(10,$authoritative))==='w2','configured source mapping cannot override an explicit salary category');
$authoritative['_jd_contract']['employment_category']='W2 or 1099';
$check(str_contains($failure(fn()=>jobdivaClassificationRequire(jobdivaClassificationPayloadForTenant(10,$authoritative))),'needs review'),'mapping cannot hide an ambiguous authoritative category');
$otherTenant=jobdivaClassificationPayloadForTenant(12,$gap);
$check(str_contains($failure(fn()=>jobdivaClassificationRequire($otherTenant)),'needs review'),'another workspace cannot borrow the classification mapping');
$withoutContract=$gap;unset($withoutContract['_jd_contract']);$withoutContract=jobdivaClassificationPayloadForTenant(10,$withoutContract);
$check(jobdivaClassificationRequire($withoutContract)==='1099'&&!isset($withoutContract['_jd_contract']),'mapped classification does not fabricate a financial assignment contract');
$pdo->exec('UPDATE tenant_integration_field_map SET enabled=0 WHERE internal_field="engagement_type"');
$check(str_contains($failure(fn()=>jobdivaClassificationRequire(jobdivaClassificationPayloadForTenant(10,$withoutContract))),'needs review'),'thin-payload cached evidence also respects disabled mappings');
$unclassified=$mapped;unset($unclassified['employmentType']);
$summary=integrationFieldMapApplyAll(10,'jobdiva','placement',$unclassified,$context);
$check($summary['written']===0&&str_contains(implode(' ',$summary['errors']),'classification_needs_review')&&$snapshot()===$rows,'mapped financial writes cannot bypass missing assignment classification');
$pdo->exec('USE information_schema'); $pdo->exec('DROP DATABASE '.$db);
echo "$passed passed\n";
