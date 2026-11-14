<?php
/** Private approved rate values, separate from the public time/dimension record. */
declare(strict_types=1);
require_once __DIR__.'/../../staffing/lib/dimension_snapshot.php';

function timeApprovalEconomicsRequireSchema(): void
{
    $q=getDB()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('time_approval_rates','time_accounting_handoffs')");
    if ((int)$q->fetchColumn()!==2) throw new RuntimeException('Time approval needs a system update. Contact your administrator.',503);
}

function timeApprovedEntryFactsHash(array $entry): string
{
    $facts=[];
    foreach (['tenant_id','id','person_id','placement_id','period_id','timesheet_id','work_date','hours','category','hour_type','billable','payable','approved_at','approved_by_user_id','approved_via','client_approver_email'] as $field) {
        $facts[$field]=$field==='hours'?number_format((float)($entry[$field]??0),4,'.',''):(string)($entry[$field]??'');
    }
    return hash('sha256',staffingDimensionSnapshotCanonicalJson($facts));
}

/** Must participate in the approval transaction; no update or replacement path. */
function timeArchiveApprovedRate(array $entry,array $snapshot): void
{
    if (!StaffingNativeTimeEdit::active() || !getDB()->inTransaction()) throw new LogicException('Approved rates require the protected approval transaction.');
    timeApprovalEconomicsRequireSchema();
    $tenant=staffingTimeWorkspace();
    $existing=getDB()->prepare('SELECT id FROM time_approval_rates WHERE tenant_id=? AND time_entry_id=? FOR UPDATE');
    $existing->execute([$tenant,$entry['id']]);
    if ($existing->fetchColumn()) throw new RuntimeException('This entry already has retained approval evidence. Use its correction workflow.',409);
    $rate=timeResolveRateSnapshot((int)$entry['placement_id'],(string)$entry['work_date'],$tenant);
    $json=staffingDimensionSnapshotCanonicalJson($rate??[]);
    if (!$rate || (int)$rate['id']!==(int)$snapshot['rate_id']
        || !hash_equals((string)$snapshot['dimensions']['source_rate_hash'],hash('sha256',$json))) {
        throw new RuntimeException('The reviewed rate changed. Review the time again.',409);
    }
    getDB()->prepare('INSERT INTO time_approval_rates (tenant_id,time_entry_id,placement_id,rate_owner_tenant_id,rate_id,source_rate_hash,entry_facts_hash,rate_json) VALUES (?,?,?,?,?,?,?,?)')->execute([
        $tenant,$entry['id'],$entry['placement_id'],$rate['tenant_id'],$rate['id'],hash('sha256',$json),timeApprovedEntryFactsHash($entry),$json,
    ]);
}

/** An entry is scoped to its original workspace; a rate ID alone is insufficient. */
function timeApprovedRate(array $entry,int $tenant): array
{
    if ($tenant<=0 || (int)($entry['tenant_id']??0)!==$tenant || !in_array($entry['status']??'', ['approved','locked','billing_ready','payroll_ready'],true)) {
        throw new RuntimeException('Approved time is unavailable in this workspace.',409);
    }
    $json=(string)($entry['dimension_snapshot_json']??'');
    $snapshot=json_decode($json,true);
    if (!is_array($snapshot) || !hash_equals((string)($entry['dimension_snapshot_hash']??''),hash('sha256',$json))
        || !preg_match('/^[a-f0-9]{64}$/D',(string)($snapshot['source_rate_hash']??''))) {
        throw new RuntimeException('This older approval has no verified rate values. Review its original evidence before accounting.',409);
    }
    timeApprovalEconomicsRequireSchema();
    $q=getDB()->prepare('SELECT * FROM time_approval_rates WHERE tenant_id=? AND time_entry_id=?');
    $q->execute([$tenant,$entry['id']]);$archive=$q->fetch(PDO::FETCH_ASSOC);
    if ($archive) {
        $rate=json_decode($archive['rate_json'],true);
        if (!is_array($rate) || !hash_equals($snapshot['source_rate_hash'],$archive['source_rate_hash'])
            || !hash_equals($archive['source_rate_hash'],hash('sha256',$archive['rate_json']))
            || !hash_equals($archive['entry_facts_hash'],timeApprovedEntryFactsHash($entry))
            || (int)$archive['placement_id']!==(int)$entry['placement_id']
            || (int)$archive['rate_id']!==(int)$entry['rate_snapshot_id']
            || (int)$archive['rate_owner_tenant_id']!==(int)($rate['tenant_id']??0)) {
            throw new RuntimeException('The original approved time or rate evidence changed. Review its correction history.',409);
        }
    } else {
        if (($snapshot['approved_rate_version']??null)!==null) throw new RuntimeException('The archived approval rate is missing. Restore its original evidence before accounting.',409);
        // Older hashed approvals can be read only while the exact original row
        // still exists. Never manufacture a historical rate from today's values.
        require_once __DIR__.'/time.php';
        $q=getDB()->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND id=? AND placement_id=?');
        $q->execute([timePlacementGraphTenantId($tenant),$entry['rate_snapshot_id'],$entry['placement_id']]);$rate=$q->fetch(PDO::FETCH_ASSOC);
        if (!$rate || !hash_equals($snapshot['source_rate_hash'],hash('sha256',staffingDimensionSnapshotCanonicalJson($rate)))) {
            throw new RuntimeException('The original approved rate is unavailable. Review historical evidence before accounting.',409);
        }
    }
    if ((int)($rate['id']??0)!==(int)$entry['rate_snapshot_id'] || (int)($rate['placement_id']??0)!==(int)$entry['placement_id'] || empty($rate['approved_at'])) {
        throw new RuntimeException('The approved rate does not identify this placement.',409);
    }
    return $rate;
}

/** Preserve the existing staffing cost breakdown using approved values only. */
function timeApprovedEntryAmounts(array $entry,array $rate,array $snapshot): array
{
    $hours=(float)$entry['hours'];
    $multiplier=match((string)$entry['hour_type']) {'overtime'=>(float)($rate['ot_multiplier']??1.5),'doubletime'=>(float)($rate['dt_multiplier']??2),default=>1.0};
    $bill=(float)($rate['adjusted_bill_rate']??max(0,(float)$rate['bill_rate']*(1+(float)($rate['bill_adder_pct']??0)-(float)($rate['bill_discount_pct']??0))+(float)($rate['bill_adder_flat']??0)-(float)($rate['bill_discount_flat']??0)));
    $amounts=['hours'=>$hours,'revenue'=>(int)$entry['billable']===1?$hours*$bill*$multiplier:0.0,'cost'=>0.0,'wage_cost'=>0.0,'employer_load_cost'=>0.0,'workers_comp_cost'=>0.0,'benefits_cost'=>0.0,'other_direct_cost'=>0.0];
    if ((int)$entry['payable']!==1) return $amounts;
    $engagement=$snapshot['engagement_type'];$pay=$hours*(float)$rate['pay_rate']*$multiplier;
    $other=$hours*(float)($rate['other_cost_per_hour']??0);
    if ($engagement==='referral') {
        if (!isset($snapshot['referral_fee_per_hour'])) throw new RuntimeException('The approved referral payout is missing.',409);
        $amounts['cost']=$hours*(float)$snapshot['referral_fee_per_hour'];
    } elseif(in_array($engagement,['w2','temp_to_perm','internal'],true)) {
        $amounts['wage_cost']=$pay;
        $amounts['employer_load_cost']=$pay*(float)($rate['adder_pct']??0);
        $amounts['workers_comp_cost']=$pay*(float)($rate['workers_comp_pct']??0);
        $amounts['benefits_cost']=$pay*(float)($rate['benefits_load_pct']??0);
        $amounts['other_direct_cost']=$other;
        $amounts['cost']=$pay+$amounts['employer_load_cost']+$amounts['workers_comp_cost']+$amounts['benefits_cost']+$other;
    } else $amounts['cost']=$pay*(1+($engagement==='c2c'?(float)($rate['c2c_overhead_pct']??0):0))+$other;
    return $amounts;
}
