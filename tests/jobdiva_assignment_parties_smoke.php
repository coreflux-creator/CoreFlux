<?php
/** Role evidence must not infer a work recipient from the debtor. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/jobdiva/projector.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$detail=['Start ID'=>'101','BILLING'=>[['COMPANY'=>['COMPANYID'=>'B-001','COMPANYNAME'=>'Billing Co'],
    'START_DATE'=>'2026-10-01','BILL_RATE'=>'USD 80/hour']],
    'SALARY'=>[['EMPLOYMENT_CATEGORY'=>'Hourly Employee','PAY_RATE'=>'USD 50/hour']],
    'END_CLIENT'=>['ID'=>'E-002','NAME'=>'Work Recipient']];
$contract=jobdivaAssignmentContractBuild([$detail],[],'101');
$payload=['startId'=>'101','candidateId'=>'201','jobId'=>'301','_jd_contract'=>$contract];
$check($contract['client_company_id']==='B-001'&&$contract['end_client_company_id']==='E-002','Exact assignment retains two opaque company IDs');
$evidence=jobdivaAssignmentEndClientEvidence($payload);
$check($evidence['status']==='resolved'&&$evidence['name']==='Work Recipient','Explicit end-client object is preserved');
$projection=jobdivaContractProjectionBuild($payload);
$roles=array_column($projection['participants'],null,'role');
$check($roles['bill_to_client']['external_id']==='B-001'&&$roles['bill_to_client']['settlement_channel']==='ar'
    &&$roles['end_client']['external_id']==='E-002'&&$roles['end_client']['money_flow']==='informational','Only the bill-to role is a receivable');
$fields=array_column($projection['fields'],null,'field');
$check($fields['billing_client_name']['proposed']==='Billing Co'&&$fields['end_client_name']['proposed']==='Work Recipient','Review presents each company under its own field');
$weak=['companyName'=>'Billing Co','_jd_job'=>['COMPANYNAME'=>'Requisition Co'],'_jd_customer'=>['name'=>'Contact Co'],
    '_jd_contract'=>['client_company_name'=>'Billing Co','client_company_id'=>'B-001','source'=>'EmployeeAssignmentRecordsDetail']];
$check(jobdivaAssignmentEndClientEvidence($weak)['status']==='missing'&&jobdivaProjectorEndClientNameFromPayload($weak)==='','Billing and generic enrichment cannot invent an end client');
$check(jobdivaProjectorAssignmentClientEvidence($weak)['authoritative'],'Missing end client does not discard exact billing evidence');
$conflict=$detail;$conflict['endClientCompanyId']='E-999';
$conflicted=jobdivaAssignmentContractBuild([$conflict],[],'101');
$check($conflicted['end_client_review']['status']==='ambiguous'&&!isset($conflicted['end_client_company_id']),'Conflicting recipient aliases stay unresolved');
$checks=array_column(jobdivaContractProjectionBuild(['_jd_contract'=>$conflicted])['checks'],null,'code');
$check($checks['end_client_evidence']['status']==='blocked','Conflicting end-client identities block apply');
$foreign=$detail;$foreign['Start ID']='102';$foreign['END_CLIENT']['ID']='E-999';
$isolated=jobdivaAssignmentContractBuild([$detail,$foreign],[],'101');
$check($isolated['end_client_company_id']==='E-002','Another Start cannot contaminate the selected recipient');
foreach([['endClientName'=>'Name Only'],['endClientCompanyId'=>'E-002'],[]] as $i=>$row){
    $check(jobdivaAssignmentEndClientEvidence($row)['status']===['name_only','identity_only','missing'][$i],'Incomplete recipient evidence remains explicit '.$i);
}
require_once __DIR__.'/../modules/billing/lib/billing.php';
$bill=['client_company_id'=>10,'client_name'=>'Billing Co','payment_terms'=>'NET45'];
$check(billingReceivableGroupingKey($bill)===billingReceivableGroupingKey($bill+['end_client_name'=>'Different Recipient']),'Different work recipients can share the same bill-to invoice group');
$check(billingReceivableGroupingKey($bill)!==billingReceivableGroupingKey(array_replace($bill,['client_company_id'=>11])),'Different debtors never merge through a shared end-client name');
$check(billingReceivableGroupingKey($bill)!==billingReceivableGroupingKey(array_replace($bill,['payment_terms'=>'NET90'])),'Different approved payment terms remain separate invoice groups');
require_once __DIR__.'/../core/jobdiva/staffing_field_mappings.php';
$dictionary=jobdivaStaffingFieldMappings();
$mapped=static function(string $key,array $source)use($dictionary):mixed{
    $path=$dictionary[$key]['jobdiva'][0]['normalized_contract_path'];
    if(!str_starts_with($path,'_jd_contract.'))throw new RuntimeException('Unexpected mapping path');
    return $source[substr($path,strlen('_jd_contract.'))]??null;
};
$check($mapped('placements.client_id',$contract)===$roles['bill_to_client']['external_id']
    &&$mapped('staffing_clients.company_id',$contract)===$roles['bill_to_client']['external_id'],
    'field dictionary resolves billing identity to the actual receivable party');
$check($mapped('placements.end_client_company_id',$contract)===$roles['end_client']['external_id']
    &&$mapped('placements.end_client_name',$contract)===$fields['end_client_name']['proposed'],
    'field dictionary resolves recipient fields to the actual separate work recipient');
$check($mapped('placements.end_client_company_id',$weak['_jd_contract'])===null
    &&$mapped('placements.end_client_name',$weak['_jd_contract'])===null,
    'field dictionary cannot relabel billing-only evidence as a work recipient');
echo "Assignment party evidence: {$passed} checks passed\n";
