<?php
/** Called only by the disposable, transactional native payroll computation fixture. */
declare(strict_types=1);
require_once __DIR__.'/../../core/export_service.php';
require_once __DIR__.'/../../modules/payroll/lib/export.php';

function payrollExportCheckSourceFixture(PDO $pdo,int $tenant,int $run,int $person,int $employee,callable $check,callable $refuses): void
{
    $rows=exportDatasetFetchRows($tenant,'payroll_disbursements',['run_id'=>$run]);$row=$rows[0];
    $check(count($rows)===1 && $row['gross_pay_cents']==='92934' && $row['gross_pay_dollars']==='929.34',
        'configurable export reads the actual native calculation with exact cents');
    $check($row['regular_hours']==='8.0000' && $row['overtime_hours']==='2.0000' && $row['doubletime_hours']==='1.0000'
        && $row['pto_hours']==='0.0000' && $row['bonus_dollars']==='1.00' && $row['commission_dollars']==='0.50',
        'export separates double time from overtime and retains supplemental earnings');
    $q=$pdo->prepare('SELECT record_number FROM core_record_numbers WHERE tenant_id=? AND entity_type="person" AND record_id=?');
    $q->execute([$tenant,$person]);$number=$q->fetchColumn();
    $check($row['employee_id']===(string)$employee && $row['person_id']===(string)$person && $row['person_number']===$number
        && $row['employee_number']!=='' && $row['employee_external_id']==='',
        'export distinguishes the reviewed person link, employee number and unavailable provider ID');
    $check($row['pay_date']==='2026-10-16' && $row['period_start']==='2026-10-05' && $row['period_end']==='2026-10-11'
        && $row['calculation_review_status']==='awaiting_review' && $row['computation_revision']==='2',
        'export uses the pay period and labels an unapproved calculation accurately');
    $parts=json_decode($row['earnings_components_json'],true,512,JSON_THROW_ON_ERROR);
    $source=array_values(array_filter($parts,static fn($p)=>$p['source_rate']!==null));
    $check(count($source)===3 && in_array('65.0050',array_column($source,'source_rate'),true)
        && in_array('2.2500',array_column($source,'source_multiplier'),true)
        && array_sum(array_column($parts,'amount_cents'))===92934
        && hash('sha256',$row['source_time_json'])===$row['source_time_sha256'],
        'export retains mixed precise rates, amounts and the complete approved source receipt');
    $templateId=(int)$pdo->query("SELECT id FROM export_templates WHERE scope='platform' AND dataset='payroll_disbursements' AND name='Payroll calculation audit' ORDER BY id LIMIT 1")->fetchColumn();
    $stream=fopen('php://temp','w+');
    try {
        $result=exportTemplateRenderDatasetToStream($tenant,'payroll_disbursements',$templateId,['run_id'=>$run],$stream);
        rewind($stream);$header=fgetcsv($stream,0,',','"','\\');$data=fgetcsv($stream,0,',','"','\\');
        $csv=array_combine($header,$data);
        $check($result['rows']===1 && $csv['Gross cents']==='92934' && $csv['Person number']===$number
            && $csv['Earnings details']===$row['earnings_components_json'] && $csv['Approved time receipt']===$row['source_time_json']
            && fgetcsv($stream,0,',','"','\\')===false,
            'seeded audit CSV round-trips the full calculation and source details through the governed service');
    } finally {fclose($stream);}
    $gusto=(int)$pdo->query("SELECT id FROM export_templates WHERE scope='platform' AND dataset='payroll_disbursements' AND name='Gusto Payroll Import (default)' ORDER BY id LIMIT 1")->fetchColumn();
    $stream=fopen('php://temp','w+');
    try {
        $check($refuses(fn()=>exportTemplateRenderDatasetToStream($tenant,'payroll_disbursements',$gusto,['run_id'=>$run],$stream),'omits approved rates') && ftell($stream)===0,
            'legacy Gusto template cannot bypass source earnings protection or emit a partial CSV');
    } finally {fclose($stream);}
    $q=$pdo->prepare('SELECT COUNT(*) FROM audit_log');$q->execute();$auditBefore=(int)$q->fetchColumn();
    ob_start();
    try {
        $rejected=$refuses(fn()=>exportTemplateStreamDatasetCsv($tenant,'payroll_disbursements',$gusto,['run_id'=>$run],'test'),'omits approved rates');
        $body=ob_get_contents();
    } finally {ob_end_clean();}
    $check($rejected && $body==='' && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$auditBefore,
        'rejected download emits neither file bytes nor a success audit');
    $pdo->exec('SAVEPOINT ci_export_tamper');
    try {
        $pdo->prepare('UPDATE payroll_earnings SET amount_cents=amount_cents+1 WHERE tenant_id=? AND line_item_id=? LIMIT 1')->execute([$tenant,$row['line_item_id']]);
        $check($refuses(fn()=>payrollExportRows($tenant,$run),'Payroll calculation changed'),
            'modified earning components cannot be exported under the old calculation fingerprint');
    } finally {$pdo->exec('ROLLBACK TO SAVEPOINT ci_export_tamper');$pdo->exec('RELEASE SAVEPOINT ci_export_tamper');}
    $pdo->exec('SAVEPOINT ci_export_wrong_employee');
    try {
        $pdo->prepare('UPDATE people_employees SET tenant_id=999 WHERE id=?')->execute([$employee]);
        $check($refuses(fn()=>payrollExportRows($tenant,$run),'current People directory'),
            'an employee moved outside the owned directory blocks the entire export instead of leaking or dropping a line');
    } finally {$pdo->exec('ROLLBACK TO SAVEPOINT ci_export_wrong_employee');$pdo->exec('RELEASE SAVEPOINT ci_export_wrong_employee');}
    setRequestTenantId(999);
    try {$check($refuses(fn()=>payrollExportRows(999,$run),'unavailable in this workspace'),
        'another company cannot export the native run');}
    finally {setRequestTenantId($tenant);}
    $check($pdo->inTransaction(),'export reads preserve the caller transaction');
}

function payrollExportCheckSharedDirectory(PDO $pdo,int $tenant,int $run,callable $check,callable $refuses): void
{
    $pdo->exec('SAVEPOINT ci_export_shared_people');
    try {
        $pdo->prepare('UPDATE tenants SET tenant_type="sub",parent_id=?,is_active=1 WHERE id=999')->execute([$tenant]);
        $pdo->exec('INSERT INTO tenant_module_scope(tenant_id,module_key,scope_mode) VALUES(999,"people","shared") ON DUPLICATE KEY UPDATE scope_mode="shared"');
        $current=payrollCalculationRun($tenant,$run);
        $pdo->prepare('UPDATE payroll_runs SET tenant_id=999 WHERE id=?')->execute([$run]);
        $pdo->prepare('UPDATE payroll_pay_periods SET tenant_id=999 WHERE id=?')->execute([$current['pay_period_id']]);
        foreach (['payroll_earnings','payroll_taxes','payroll_deductions'] as $table) {
            $pdo->prepare('UPDATE '.$table.' SET tenant_id=999 WHERE tenant_id=? AND line_item_id IN (SELECT id FROM payroll_line_items WHERE tenant_id=? AND run_id=?)')->execute([$tenant,$tenant,$run]);
        }
        $pdo->prepare('UPDATE payroll_line_items SET tenant_id=999 WHERE tenant_id=? AND run_id=?')->execute([$tenant,$run]);
        setRequestTenantId(999);
        $current=payrollCalculationRun(999,$run);
        $hash=payrollCalculationFingerprint($current);
        $pdo->prepare('UPDATE payroll_runs SET computation_sha256=? WHERE tenant_id=999 AND id=?')->execute([$hash,$run]);
        $row=payrollExportRows(999,$run)[0];
        $check($row['people_owner_tenant_id']===(string)$tenant && $row['employee_number']!=='' && $row['person_number']!==''
            && $row['regular_hours']==='1.2345' && $row['gross_pay_cents']==='3704',
            'child-company payroll exports its explicitly shared parent employee with exact fractional hours');
        $pdo->exec('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=999 AND module_key="people"');
        $check($refuses(fn()=>payrollExportRows(999,$run),'current People directory'),
            'changing the directory to isolated is honored immediately without reusing cached ownership');
    } finally {
        $pdo->exec('ROLLBACK TO SAVEPOINT ci_export_shared_people');$pdo->exec('RELEASE SAVEPOINT ci_export_shared_people');
        setRequestTenantId($tenant);
    }
}
