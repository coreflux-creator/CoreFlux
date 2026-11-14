<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
$rules=static fn(string $key)=>staffingFieldContractRules(...explode('.',$key,2));
$check(count(array_intersect(staffingTimeEvidenceTables(),staffingFieldContractTables()))===3,'Original approval observations, JobDiva intake and immutable correction receipts are inventoried');
foreach(staffingTimeFieldDefinitions()as$table=>$fields){
    foreach($fields as$field=>$_){
        $r=$rules($table.'.'.$field);
        $check($r['definition_status']==='reviewed_core_definition'&&$r['disposition']!=='review_required'
            &&$r['write_policy']==='specialized_workflow_only','Known field has an explicit native owner and protected writer: '.$table.'.'.$field);
        foreach($r['remote_contracts']as$c)if($c['sync_enabled_by_dictionary']!==false)throw new RuntimeException('Dictionary enabled an exchange');
    }
    $check($rules($table.'.future_unreviewed_field')['disposition']==='review_required','New '.$table.' field does not inherit a reviewed decision');
}
$source=$rules('quanta_time_imports.source_approval_json');
$core=$rules('time_entries.approved_at');
$check($source['disposition']==='restricted'&&$source['remote_contracts']['quanta']['direction']==='quanta_to_core'
    &&str_contains($source['definition'],'separate from Core approval'),'Source approval remains restricted inbound evidence, separate from Core approval');
$check(empty($core['remote_contracts']['quanta']['mappings'])&&$core['remote_contracts']['quanta']['status']==='not_exchanged_raw_storage',
    'Core approval timestamp is not claimed as a provider approval write');
$status=$rules('time_entries.status')['remote_contracts']['quanta'];
$check($status['path']==='components[].status'&&$status['direction']==='core_to_quanta'
    &&str_contains($status['mappings'][0]['write_policy'],'no_source_approval'),'Core status uses the actual permissioned return contract');
$hours=$rules('time_entries.hours')['remote_contracts'];
$check($hours['quanta']['direction']==='quanta_to_core'&&$hours['purepayroll']['path']==='components[].source.hours',
    'Hours retain separate Quanta inbound and held payroll-input paths');
$check($hours['jobdiva']['status']==='legacy_adapter_hardening_required'
    &&$hours['jobdiva']['direction']==='legacy_inbound_and_outbound'
    &&isset(staffingExchangeWorkflowGaps()['jobdiva_time_review']),
    'Legacy JobDiva paths retain the explicit review gap instead of claiming safe two-way corrections');
foreach(['before_ct','after_ct','source_ct']as$field){
    $r=$rules('quanta_time_revisions.'.$field);
    $check($r['disposition']==='restricted'&&$r['unit']==='encrypted-evidence','Correction envelope remains restricted: '.$field);
    foreach($r['remote_contracts']as$c)$check(empty($c['mappings'])&&$c['status']==='restricted_not_exchanged','Raw correction evidence is not a provider payload');
}
$check(str_contains($rules('staffing_timesheets.approved_by_user_id')['definition'],'Mixed or missing reviewers remain null'),
    'Weekly summary cannot invent one approving user');
$check(str_contains($rules('time_downstream_feed.total_amount_pay')['unit'],'currency-from-rate-evidence')
    &&str_contains($rules('time_downstream_feed.total_amount_pay')['definition'],'not a paid amount'),
    'Derived amounts do not infer currency or cash settlement');
$check($rules('quanta_time_imports.is_current')['owner']==='quanta_source_evidence'
    &&str_contains($rules('quanta_time_imports.is_current')['definition'],'explicit reviewed correction'),
    'Removed component ownership is covered by the correction workflow');
$check(staffingFieldContractRules('ap_bills','total')['disposition']==='review_required',
    'Time review does not relabel unreviewed financial fields');
echo "Staffing time field contract: {$passed} passed\n";
