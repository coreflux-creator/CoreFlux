<?php
/** Current native approval facts must survive another connection's commit or rollback. */
$reviewer=$nativeInsert('users',['name'=>'Synthetic approval race reviewer','email'=>'approval-race-reviewer@example.invalid','password_hash'=>'!no-login-race','role'=>'employee']);
$reviewCompany=$nativeInsert('companies',['tenant_id'=>$tenant,'name'=>'Synthetic approval race client']);
$reviewClient=$nativeInsert('staffing_clients',['tenant_id'=>$tenant,'company_id'=>$reviewCompany,'name'=>'Synthetic approval race client']);
$reviewEntity=$nativeInsert('accounting_entities',['tenant_id'=>$tenant,'code'=>'TARR','legal_name'=>'Synthetic approval race entity','country'=>'US','base_currency'=>'USD','active'=>1]);
$approvalFinancialState=static function()use($pdo):array {
    $state=[];foreach(['accounting_events','accounting_journal_entries','accounting_journal_entry_lines','payroll_runs','ap_payments','billing_payments']as$table)$state[$table]=hash('sha256',json_encode($pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));return $state;
};
$approvalFinancialBefore=$approvalFinancialState();
foreach(['approve','apply_week']as$operation)foreach(['COMMIT','ROLLBACK']as$decision)foreach(['entry','header','period','placement','person','rate','entity','company']as$change) {
    $fixture=$nativeSeed('approval '.$operation.' '.$change.' '.$decision);extract($fixture,EXTR_PREFIX_ALL,'ar');
    $pdo->prepare('UPDATE placements SET client_id=?,accounting_entity_id=?,worksite_state="NJ",workers_comp_class="synthetic-class" WHERE id=?')->execute([$reviewClient,$reviewEntity,$ar_placement]);
    $rate=$nativeInsert('placement_rates',['tenant_id'=>$tenant,'placement_id'=>$ar_placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>60,'approved_at'=>'2026-10-01 12:00:00']);
    $plan=$operation==='apply_week'?staffingTimesheetApprovalPlan($reviewer,staffingTimesheetRow($tenant,$ar_sheet),$tenant):null;
    try {
        $pdo->beginTransaction();
        if($change==='entry')$pdo->prepare('UPDATE time_entries SET status="superseded" WHERE id=?')->execute([$ar_entry]);
        elseif($change==='header')$pdo->prepare('UPDATE staffing_timesheets SET status="locked" WHERE id=?')->execute([$ar_sheet]);
        elseif($change==='period')$pdo->prepare('UPDATE time_periods SET status="locked" WHERE id=?')->execute([$ar_period]);
        elseif($change==='placement')$pdo->prepare('UPDATE placements SET actual_end_date="2026-10-04" WHERE id=?')->execute([$ar_placement]);
        elseif($change==='person')$pdo->prepare('UPDATE people SET deleted_at=NOW() WHERE id=?')->execute([$ar_person]);
        elseif($change==='rate') {
            if($operation==='approve')$pdo->prepare('UPDATE placement_rates SET approved_at=NULL WHERE id=?')->execute([$rate]);
            else $pdo->prepare('UPDATE placement_rates SET bill_rate=101 WHERE id=?')->execute([$rate]);
        }
        elseif($change==='entity')$pdo->prepare('UPDATE accounting_entities SET active=0 WHERE id=?')->execute([$reviewEntity]);
        else $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE id=?')->execute([$reviewCompany]);
        $worker=$spawn(['mode'=>'native','entity'=>'generic_time','operation'=>$operation,'workspace'=>$tenant,'actor'=>$reviewer,'entry_id'=>$ar_entry,'timesheet_id'=>$ar_sheet,'plan'=>$plan]);
        $check($waiting($worker),'Approval waits for concurrent '.$operation.' '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
        $before=$nativeTimeState();$result=$finish($worker);
        if($decision==='COMMIT')$check(!$result['ok']&&$nativeTimeState()===$before,'Committed '.$change.' refuses stale '.$operation.' without approval or receipts: '.json_encode($result));
        else {
            $q=$pdo->prepare('SELECT status,rate_snapshot_id,dimension_snapshot_hash FROM time_entries WHERE id=?');$q->execute([$ar_entry]);$state=$q->fetch(PDO::FETCH_ASSOC);
            $check($result['ok']&&$state['status']==='approved'&&(int)$state['rate_snapshot_id']===$rate&&strlen((string)$state['dimension_snapshot_hash'])===64,'Rolled-back '.$change.' permits current '.$operation.': '.json_encode($result));
        }
    } finally {
        if($pdo->inTransaction())$pdo->rollBack();
        if($change==='period')$pdo->prepare('UPDATE time_periods SET status="open" WHERE id=?')->execute([$ar_period]);
        if($change==='entity')$pdo->prepare('UPDATE accounting_entities SET active=1 WHERE id=?')->execute([$reviewEntity]);
        if($change==='company')$pdo->prepare('UPDATE companies SET deleted_at=NULL WHERE id=?')->execute([$reviewCompany]);
    }
}
$check($approvalFinancialState()===$approvalFinancialBefore,'Concurrent native approval creates no financial execution or postings');
