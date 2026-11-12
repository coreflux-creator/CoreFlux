<?php
/** Included only inside the disposable native financial-observation fixture. */
require_once __DIR__.'/../../core/integrations/staffing_reconciliation.php';
$pdo->exec('CREATE TABLE time_entries(id INT PRIMARY KEY,tenant_id INT,person_id INT,placement_id INT,work_date DATE,hours DECIMAL(8,2),category VARCHAR(40),hour_type VARCHAR(30),status VARCHAR(30),approved_at DATETIME NULL,approved_by_user_id INT NULL,superseded_by_id INT NULL,rate_snapshot_id INT NULL,payable TINYINT NULL,billable TINYINT NULL,source VARCHAR(30),source_system VARCHAR(30),external_id VARCHAR(64))');
$pdo->exec('CREATE TABLE placement_rates(id INT PRIMARY KEY,tenant_id INT,placement_id INT,approved_at DATETIME NULL,currency CHAR(3),pay_rate DECIMAL(10,4),pay_rate_unit VARCHAR(20),bill_rate DECIMAL(10,4),bill_rate_unit VARCHAR(20),adjusted_bill_rate DECIMAL(10,4) NULL,ot_multiplier DECIMAL(5,2),dt_multiplier DECIMAL(5,2))');
$pdo->exec("INSERT INTO placement_rates VALUES(7,1,1,'2026-01-01','USD',65,'hour',98,'hour',NULL,1.5,2)");
$pdo->exec("INSERT INTO time_entries VALUES
    (71,1,1,1,'2026-10-05',8,'regular_billable','regular','approved','2026-10-19',7,NULL,7,1,1,'quanta','quanta','synthetic-71'),
    (72,1,1,1,'2026-10-06',8,'regular_billable','regular','submitted',NULL,NULL,NULL,NULL,1,1,'quanta','quanta','synthetic-72'),
    (73,2,1,1,'2026-10-05',999,'regular_billable','regular','approved','2026-10-19',7,NULL,7,1,1,'private','private','foreign-source')");
$wire['bill']['document']['line_items'][0]['service_start']='2026-10-05';
$wire['bill']['document']['line_items'][0]['service_end']='2026-10-18';
$wire['bill']['document']['line_items'][0]['amount']='520.00';
$wire['bill']['document']['line_items'][0]['hours']='8.00000000';
$wire['bill']['document']['currency']='USD';
$wire['bill']['unavailable_fields']=purepayFinancialObservationMissing(array_diff_key($wire['bill'],array_flip(['unavailable_fields','observed_at','observation_hash'])));
$wire['bill']['observation_hash']=purepayFinancialObservationHash($wire['bill']);
$periodCapture=$save($read());
$periodReview=purepayFinancialLinePreview($pdo,1,$instance,$owners,$periodCapture['id'],$lineId,'PL-1');
$periodLink=$lineSave($periodReview);
$fingerprint=static function()use($pdo):string{
    $out=[];foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$table){
        $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows);$out[$table]=hash('sha256',serialize($rows));
    }ksort($out);return hash('sha256',serialize($out));
};
$reconciliationOwners=['people'=>1,'placements'=>1,'time'=>1];
$comparisonRead=static fn(string $start='2026-10-05',string $end='2026-10-18',?int $before=null)=>staffingReconciliationRead($pdo,1,$instance,$reconciliationOwners,'PL-1',$start,$end,null,$before);
$beforeState=$fingerprint();$beforeCalls=count($calls);$comparison=$comparisonRead();
$check($comparison['time']['approved_hours']==='8.00'&&count($comparison['time']['entries'])===2,'placement comparison retains pending time and excludes foreign-tenant entries');
$check(count($comparison['financial_lines'])===1&&$comparison['financial_lines'][0]['association_id']===$periodLink['link']['id'],'latest immutable association is selected once despite previous reviews');
$financial=$comparison['financial_lines'][0];
$check($financial['period_relation']==='exact'&&$financial['core_amount']==='520.00'&&$financial['comparison']['monetary_difference']==='0.00','native encrypted line capture compares exact work period and frozen rates');
$check(!$financial['comparison']['allocation_proven']&&!$financial['cash_delivery_proven'],'numeric comparison does not grant allocation or payment evidence');
$check($comparison['payroll']['status']==='not_selected'&&!$comparison['provider_refreshed'],'unselected payroll and saved-source coverage remain explicit');
$check($fingerprint()===$beforeState&&count($calls)===$beforeCalls,'comparison performs no database mutation or provider request without an optional payroll selection');
$repeat=$comparisonRead();unset($repeat['read_at'],$comparison['read_at']);
$check($repeat===$comparison,'repeated comparison has no new records or changed business evidence');
$outsideComparison=$comparisonRead('2026-11-01','2026-11-15');
$check($outsideComparison['financial_lines'][0]['period_relation']==='outside'&&$outsideComparison['financial_lines'][0]['comparison']['numeric_difference']===null,'unrelated work periods cannot generate an amount match');
$check($comparisonRead('2026-10-05','2026-10-18',$periodLink['link']['id'])['financial_lines']===[],'history cursor does not repeat superseded association versions');
$check($fails(fn()=>staffingReconciliationRead($pdo,2,$instance,['people'=>1,'placements'=>1,'time'=>1],'PL-1','2026-10-05','2026-10-18')),'foreign financial tenant cannot borrow comparison evidence');
$check($fails(fn()=>staffingReconciliationRead($pdo,1,$instance,['people'=>2,'placements'=>1,'time'=>1],'PL-1','2026-10-05','2026-10-18')),'unauthorized person directory cannot resolve the comparison target');
$check($fails(fn()=>staffingReconciliationRead($pdo,1,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$reconciliationOwners,'PL-1','2026-10-05','2026-10-18')),'foreign installation cannot read encrypted association evidence');
$pdo->exec("UPDATE placement_rates SET currency='EUR' WHERE id=7");
$differentCurrency=$comparisonRead()['financial_lines'][0]['comparison'];
$check($differentCurrency['numeric_difference']==='0.00'&&$differentCurrency['monetary_difference']===null&&$differentCurrency['currency_comparison']==='different','equal amounts in changed currency remain a reconciliation exception');
$pdo->exec("UPDATE placement_rates SET currency='USD' WHERE id=7");
$pdo->exec("UPDATE time_entries SET hour_type='overtime' WHERE id=71");
$classificationConflict=$comparisonRead();
$check($classificationConflict['time']['pay_amount']===null&&$classificationConflict['financial_lines'][0]['comparison']['numeric_difference']===null,'native category disagreement cannot produce a misleading financial match');
$pdo->exec("UPDATE time_entries SET category='vacation',hour_type='pto',billable=0 WHERE id=71");
$check($comparisonRead()['time']['pay_amount']==='520.00','canonical native paid leave retains its frozen pay amount');
$pdo->exec("UPDATE time_entries SET category='regular_billable',hour_type='regular',billable=1 WHERE id=71");
$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=3 WHERE entity_type="placement" AND record_id=1');
$check($fails($comparisonRead),'merged placement cannot redirect a saved line to another identity');
$pdo->exec('UPDATE core_record_numbers SET canonical_record_id=1 WHERE entity_type="placement" AND record_id=1');

foreach(['164_purepay_core_connection.sql','174_payroll_native_observations.sql']as$file)
    foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../../core/migrations/'.$file))as$sql)$pdo->exec($sql);
$operatingContext=['organization'=>['id'=>'org-a','name'=>'Synthetic finance'],'contract_version'=>PUREPAY_CORE_VERSION,
    'link_contract_version'=>PUREPAY_CORE_LINK_VERSION,'payroll_observation_contract_version'=>PUREPAY_OBSERVATION_VERSION,
    'capabilities'=>['read:coreflux','read:payroll'],'execution_side_effects'=>false];
$baseTransport=$GLOBALS['__purepay_transport'];
$GLOBALS['__purepay_transport']=static function($method,$url)use($baseTransport,&$operatingContext,$pdo):array{
    if(parse_url($url,PHP_URL_PATH)==='/api/v1/integrations/coreflux/context'){
        if($method!=='GET'||$pdo->inTransaction())throw new RuntimeException('Unexpected operational request');
        return ['status'=>200,'body'=>json_encode($operatingContext)];
    }
    return $baseTransport($method,$url);
};
purepayCoreConnect($pdo,1,'synthetic-reconciliation-operating-key',$instance);
$operatingReview=purepayCoreReview($pdo,1,$instance);
purepayCoreConfirm($pdo,1,7,$instance,$operatingReview['review_hash'],'org-a');
$operatingConnection=purepayCoreConnection($pdo,1);
$payrollWire=json_decode(file_get_contents(__DIR__.'/purepay_observations/full.json'),true,64,JSON_THROW_ON_ERROR);
$payrollWire['organization_id']='org-a';$payrollWire['period_start']='2026-10-05';$payrollWire['period_end']='2026-10-18';$payrollWire['pay_date']='2026-10-23';
$payrollWire['lines'][]=array_replace($payrollWire['lines'][0],['line_index'=>1,'employee_id'=>'employee-private']);
$payrollWire['observation_hash']=purepayObservationHash($payrollWire);
$payrollContext=['tenant_id'=>1,'instance_id'=>$instance,'owners'=>purepayObservationOwners($reconciliationOwners),
    'connection_id'=>(int)$operatingConnection['id'],'organization_id'=>'org-a','api_base'=>purepayCoreApiBase()];
$peopleEvidence=[['employee_id'=>$payrollWire['lines'][0]['employee_id'],'person'=>integrationRecordReference($instance,1,'person','P-1'),'link_status'=>'linked','source_line_count'=>1],
    ['employee_id'=>'employee-private','person'=>integrationRecordReference($instance,2,'person','P-2'),'link_status'=>'linked','source_line_count'=>1]];
$savePayrollCapture=static function(array $data)use($pdo,$payrollContext,$peopleEvidence):void{
    $data['observation_hash']=purepayObservationHash($data);purepayObservationValidate($data,'org-a');
    $sourceHash=integrationDeliveryHash($peopleEvidence);
    $saved=['tenant_id'=>1,'context'=>$payrollContext,'observation'=>$data,'source_hash'=>$sourceHash,
        'comparison'=>['totals'=>purepayObservationTotals($data),'people'=>$peopleEvidence,'source_time_match'=>'not_evaluated','cash_delivery'=>'not_evaluated','ledger_posted'=>false]];
    $pdo->prepare('INSERT INTO payroll_native_observations(tenant_id,connection_id,organization_id,run_id,observation_hash,core_source_hash,payload_ct,observed_by) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([1,$payrollContext['connection_id'],'org-a',$data['run_id'],$data['observation_hash'],$sourceHash,encryptField(integrationDeliveryJson($saved)),7]);
};
$savePayrollCapture($payrollWire);
$combinedRead=static fn()=>staffingReconciliationRead($pdo,1,$instance,$reconciliationOwners,'PL-1','2026-10-05','2026-10-18',$payrollWire['run_id']);
$protectedBefore=$fingerprint();$combined=$combinedRead();
$check(count($combined['payroll']['lines'])===1&&$combined['payroll']['lines'][0]['employee_id']===$payrollWire['lines'][0]['employee_id'],'saved payroll exposes only the exact reviewed person reference');
$check(!str_contains(json_encode($combined),'employee-private'),'unrelated payroll employee evidence is absent from the response');
$check($combined['payroll']['period_relation']==='exact'&&$combined['payroll']['lines'][0]['comparison']['monetary_difference']==='-420.00','selected payroll compares matching period with approved frozen pay');
$check($fingerprint()===$protectedBefore,'combined payroll and financial comparison makes no database changes');
$payrollWire['currency']=null;$payrollWire['unavailable_fields']['currency']='missing';$savePayrollCapture($payrollWire);
$unknownPayroll=$combinedRead()['payroll']['lines'][0]['comparison'];
$check($unknownPayroll['numeric_difference']==='-420.00'&&$unknownPayroll['monetary_difference']===null,'historical payroll currency absence remains explicit despite numeric comparison');
$pdo->exec("INSERT INTO time_entries VALUES(74,1,1,3,'2026-10-05',8,'regular_billable','regular','approved','2026-10-19',7,NULL,7,1,1,'quanta','quanta','other-placement')");
$splitPayroll=$combinedRead()['payroll'];
$check($splitPayroll['other_placement_entries']===1&&$splitPayroll['lines'][0]['comparison']['numeric_difference']===null,'person payroll is not assigned wholly to one of several placements');
$operatingContext['organization']['id']='other-organization';
$check($fails($combinedRead),'changed payroll organization cannot be combined with financial company evidence');
$operatingContext['organization']['id']='org-a';
