<?php
/** Read-only commercial comparison for an explicitly verified staffing pilot. */
declare(strict_types=1);
require_once __DIR__.'/staffing_pilot_alignment.php';
require_once __DIR__.'/../core/jobdiva/contract_projection.php';

function fullCorePilotRateColumns(): array
{
    return ['bill_rate_unit'=>'bill_rate_unit', 'pay_rate_unit'=>'pay_rate_unit', 'currency'=>'currency',
        'bill_rate'=>'bill_rate', 'pay_rate'=>'pay_rate', 'invoice_bill_rate'=>'adjusted_bill_rate',
        'bill_discount_pct'=>'bill_discount_pct', 'payroll_load_pct'=>'adder_pct',
        'workers_comp_pct'=>'workers_comp_pct', 'benefits_load_pct'=>'benefits_load_pct',
        'c2c_overhead_pct'=>'c2c_overhead_pct', 'other_cost_per_hour'=>'other_cost_per_hour',
        'other_cost_flat'=>'other_cost_flat'];
}

/** Reuse the native proposal semantics; return statuses rather than compensation values. */
function fullCorePilotRateComparison(array $rate, array $contract, string $expectedStart): array
{
    if (($contract['start_id'] ?? null) !== $expectedStart) return ['status'=>'contract_assignment_mismatch'];
    $proposal = jobdivaContractProjectionBuild(['_jd_contract'=>$contract], ['rates'=>[$rate]], $expectedStart);
    $basis = integrationSourceRateBasis($contract);
    $fields = [];
    foreach ($proposal['fields'] as $field) {
        $name = $field['field']; $column = fullCorePilotRateColumns()[$name] ?? null;
        if ($column === null) continue;
        if (!array_key_exists($column, $rate)) $status = 'core_column_unavailable';
        elseif ($basis['errors']) $status = 'source_rate_basis_needs_review';
        elseif ((isset($contract['classification_review']) && ($contract['classification_review']['status'] ?? null)!=='resolved')
            || ($proposal['classification_review']['status'] ?? null) !== 'resolved') $status = 'source_classification_needs_review';
        elseif ($basis['bill_rate_unit']!=='hour'||$basis['pay_rate_unit']!=='hour') $status = 'source_rate_model_needs_review';
        elseif (($proposal['placement']['engagement_type'] ?? null)==='referral' && $name==='pay_rate') $status = 'derived_referral_policy_not_worker_pay';
        elseif ($field['proposed'] === null || $field['proposed'] === '') $status = 'source_value_missing';
        elseif ($field['current'] === null || $field['current'] === '') $status = 'core_value_missing';
        else $status = $field['changes'] ? 'differs_from_fresh_source' : 'matches_fresh_source';
        $fields[$name] = $status;
    }
    return ['status'=>'compared', 'approval'=>empty($rate['approved_at'])?'draft':'approved',
        'source_rate_basis_resolved'=>$basis['errors']===[], 'fields'=>$fields];
}

function fullCoreStaffingPilotCommercialAlignment(PDO $pdo, array $pilot, array $contract, string $asOf): array
{
    $result = ['status'=>'identity_not_verified', 'as_of'=>$asOf, 'records_changed'=>false,
        'scope'=>'Current nonsuperseded rate versions and the billing-client company link; no raw values.',
        'limits'=>['Matching drafts are not approved compensation.',
            'Billing client, end client, legal employer and vendor are distinct roles.',
            'A source comparison does not authorize import, approval, payroll or payment.']];
    if (fullCorePilotAlignedValue($asOf,'start_date') !== $asOf) throw new InvalidArgumentException('An explicit calendar date is required');
    $identity = fullCoreStaffingPilotInventory($pdo,$pilot);
    if ($identity['status'] !== 'observed') return $result;
    foreach (['person','placement','job'] as $entity) {
        if (($identity['jobdiva_identity'][$entity]['status'] ?? null) !== 'exact_match') return $result;
    }
    if (($contract['start_id'] ?? null) !== $pilot['jobdiva_ids']['placement']) return $result;
    $columns = static function(string $table) use ($pdo): array {
        $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
        $s->execute(['t'=>$table]); return $s->fetchAll(PDO::FETCH_COLUMN);
    };
    $result['status']='observed';
    $rateColumns=$columns('placement_rates');
    $required=['id','tenant_id','placement_id','effective_from','effective_to','superseded_by','approved_at'];
    $result['rates']=['status'=>'schema_unavailable'];
    if (!array_diff($required,$rateColumns)) {
        $selected=array_values(array_unique(array_merge($required,array_intersect(array_values(fullCorePilotRateColumns()),$rateColumns))));
        $s=$pdo->prepare('SELECT '.implode(',',array_map(static fn($c)=>'`'.$c.'`',$selected)).'
            FROM placement_rates WHERE tenant_id=:t AND placement_id=:p AND superseded_by IS NULL
            AND effective_from<=:start AND (effective_to IS NULL OR effective_to>=:end) ORDER BY id LIMIT 21');
        $s->execute(['t'=>$pilot['tenant_id'],'p'=>$pilot['placement_id'],'start'=>$asOf,'end'=>$asOf]);
        $rates=$s->fetchAll(PDO::FETCH_ASSOC);
        if (count($rates)>20) $result['rates']=['status'=>'too_many_current_versions'];
        else {
            $drafts=count(array_filter($rates,static fn($r)=>empty($r['approved_at'])));
            $approved=count($rates)-$drafts;
            $result['rates']=['status'=>!$rates?'current_rate_missing':($drafts>1||$approved>1?'ambiguous_current_versions':'compared'),
                'draft_versions'=>$drafts,'approved_versions'=>$approved,
                'versions'=>array_map(static fn($r)=>fullCorePilotRateComparison($r,$contract,$pilot['jobdiva_ids']['placement']),$rates)];
        }
    }
    $result['billing_client_company']=['status'=>'schema_unavailable'];
    $companyOwner=$pilot['company_directory_id'] ?? null;
    if (!is_int($companyOwner)||$companyOwner<=0) {
        $result['billing_client_company']=['status'=>'company_directory_not_selected']; return $result;
    }
    foreach (['placements'=>['client_id'], 'staffing_clients'=>['id','tenant_id','company_id'],
        'companies'=>['id','tenant_id','deleted_at'],
        'external_entity_mappings'=>['tenant_id','source_system','internal_entity_type','internal_entity_id','external_id']] as $table=>$needed) {
        if (array_diff($needed,$columns($table))) return $result;
    }
    $sourceId=jobdivaAssignmentCompanyId($contract['client_company_id'] ?? null);
    if ($sourceId==='') { $result['billing_client_company']=['status'=>'source_identity_missing']; return $result; }
    $s=$pdo->prepare('SELECT internal_entity_id,external_id FROM external_entity_mappings
        WHERE tenant_id=:t AND source_system="jobdiva" AND internal_entity_type="company" AND BINARY external_id=BINARY :e');
    $s->execute(['t'=>$pilot['tenant_id'],'e'=>$sourceId]); $mappings=$s->fetchAll(PDO::FETCH_ASSOC);
    if (count($mappings)!==1) {
        $result['billing_client_company']=['status'=>$mappings?'source_mapping_ambiguous':'source_mapping_missing']; return $result;
    }
    $companyId=(int)$mappings[0]['internal_entity_id'];
    // Check the reverse mapping too: one Core company must not conceal two provider identities.
    $s=$pdo->prepare('SELECT external_id FROM external_entity_mappings WHERE tenant_id=:t
        AND source_system="jobdiva" AND internal_entity_type="company" AND internal_entity_id=:i');
    $s->execute(['t'=>$pilot['tenant_id'],'i'=>$companyId]); $reverse=$s->fetchAll(PDO::FETCH_COLUMN);
    if ($reverse!==[$sourceId]) { $result['billing_client_company']=['status'=>'source_mapping_ambiguous']; return $result; }
    $s=$pdo->prepare('SELECT COUNT(*) FROM companies WHERE id=:i AND tenant_id=:t AND deleted_at IS NULL');
    $s->execute(['i'=>$companyId,'t'=>$companyOwner]);
    if ((int)$s->fetchColumn()!==1) { $result['billing_client_company']=['status'=>'source_company_directory_or_active_record_mismatch']; return $result; }
    $s=$pdo->prepare('SELECT p.client_id,sc.company_id FROM placements p
        LEFT JOIN staffing_clients sc ON sc.id=p.client_id AND sc.tenant_id=:owner
        WHERE p.id=:p AND p.tenant_id=:t AND p.person_id=:person AND p.deleted_at IS NULL');
    $s->execute(['owner'=>$pilot['tenant_id'],'p'=>$pilot['placement_id'],'t'=>$pilot['tenant_id'],'person'=>$pilot['person_id']]);
    $current=$s->fetch(PDO::FETCH_ASSOC);
    $result['billing_client_company']=['status'=>!$current||empty($current['client_id'])?'core_client_link_missing'
        :(empty($current['company_id'])?'core_client_company_unresolved'
        :((int)$current['company_id']===$companyId?'matches_fresh_source':'differs_from_fresh_source'))];
    return $result;
}
