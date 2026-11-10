<?php
declare(strict_types=1);
require_once __DIR__.'/../core/jobdiva/assignment_contract.php';
require_once __DIR__.'/../core/jobdiva/contract_projection.php';
require_once __DIR__.'/../core/integrations/field_map_apply.php';
require_once __DIR__.'/../modules/placements/lib/jobdiva_reconciliation.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
foreach(['W-2 Employee'=>'w2','Hourly Employee'=>'w2','1099'=>'1099','Independent Contractor'=>'1099','Corp-to-Corp'=>'c2c','crop to crop'=>'c2c','Contract-to-Hire'=>'temp_to_perm','Direct Hire'=>'direct_hire','Internal'=>'internal','Ref'=>'referral']as$raw=>$expected){
    $check(jobdivaClassificationValue($raw)===$expected,"explicit $raw normalizes");
    $check(integrationFieldMapEngagementValue($raw)===$expected&&jobdivaReconciliationNormaliseEngagement($raw)===$expected,"CSV and field mapping agree on $raw");
}
foreach(['','contractor','vendor','payroll','not W2','W2 or 1099','W2/C2C','no referral','employee pending review','corporation','subcontractor']as$raw){
    $check(jobdivaClassificationValue($raw)===null&&integrationFieldMapEngagementValue($raw)===null&&jobdivaReconciliationNormaliseEngagement($raw)===null,"ambiguous '$raw' never becomes a classification");
}
$check(jobdivaReconciliationNormaliseEngagement('Subcontract','Employment Category')==='c2c','recognized JobDiva employment category supports C2C');
$check(jobdivaReconciliationNormaliseEngagement('yes','TCS_W2')==='w2'&&jobdivaReconciliationNormaliseEngagement('no','TCS_W2')===null,'CSV explicit flag has no inverse default');
foreach([['crop to crop'=>null],['crop to crop'=>false],['W2'=>false],['OVERHEADS'=>['W2'=>true]],['_jd_candidate'=>['workerType'=>'W2']],['_jd_job'=>['employmentCategory'=>'C2C']]]as$payload){
    $check(jobdivaClassificationFromPayload($payload)['status']==='needs_review','absence, cost profile or related-record preference cannot classify an assignment');
}
$conflict=['employmentType'=>'W2','assignment'=>['workerType'=>'1099']];
$a=jobdivaClassificationFromPayload($conflict);$b=jobdivaClassificationFromPayload(array_reverse($conflict,true));
$check($a['status']==='needs_review'&&$b['status']==='needs_review','conflicting explicit source values are independent of property order');
$check(jobdivaClassificationFromPayload(['w2'=>true,'c2c'=>true])['status']==='needs_review','two positive classification flags conflict');
$check(jobdivaClassificationFromPayload(['crop to crop'=>null,'workerType'=>'1099'])['engagement_type']==='1099','negative C2C flag never converts an explicit 1099 assignment');
$check(jobdivaClassificationFromPayload(['_jd_contract'=>['employment_category'=>'','engagement_type'=>'w2','w2_flag'=>true]])['status']==='needs_review','legacy overhead-derived W2 requires refreshed source evidence');
$check(jobdivaClassificationFromPayload(['_jd_contract'=>['employment_category'=>'Subcontract','engagement_type'=>'w2','w2_flag'=>true]])['engagement_type']==='c2c','explicit salary category outranks an old synthesized default');
$base=['Start ID'=>'101','BILLING'=>[['BILL_RATE'=>70,'BILL_RATE_PER'=>'H','CURRENCY'=>'USD','START_DATE'=>'2026-10-01','COMPANY'=>[['COMPANYNAME'=>'Synthetic client']]]],'SALARY'=>[['EMPLOYMENT_CATEGORY'=>'Subcontract','SALARY'=>40,'SALARY_PER'=>'H','CURRENCY'=>'USD','SUBCONTRACT_COMPANYID'=>'501']],'OVERHEADS'=>[['W2'=>true]]];
$build=static fn(array $row,array $fallback=[]):array=>jobdivaAssignmentContractBuild([$row],$fallback,'101');
$contract=$build($base,['workerType'=>'W2']);
$check($contract['engagement_type']==='c2c'&&$contract['classification_review']['source']==='assignment_salary','exact SALARY category owns classification over Start and cost profiles');
$base['SALARY'][]=['EMPLOYMENT_CATEGORY'=>'Hourly Employee'];$conflicting=$build($base);
$check(($conflicting['engagement_type']??'')===''&&$conflicting['classification_review']['status']==='needs_review','conflicting exact SALARY rows block without first-row wins');
$projection=jobdivaContractProjectionBuild(['candidateId'=>'201','jobId'=>'301','_jd_contract'=>$conflicting],[],'101');
$check(!$projection['complete']&&$projection['classification_review']['status']==='needs_review','canonical preview exposes the classification conflict');
$tampered=$conflicting;$tampered['engagement_type']='w2';$tampered['classification_review']['status']='resolved';
$check(jobdivaClassificationFromPayload(['_jd_contract'=>$tampered])['status']==='needs_review','cached resolved status cannot hide conflicting observations');
unset($base['SALARY'][1]);$base['SALARY'][0]['EMPLOYMENT_CATEGORY']='';
$missing=$build($base);
$check(($missing['engagement_type']??'')===''&&$missing['w2_flag']===true,'selected W2 overhead remains cost evidence only');
$fromStart=$build($base,['crop to crop'=>'Y']);
$check($fromStart['engagement_type']==='c2c'&&$fromStart['classification_review']['source']==='start_assignment','explicit Start flag can fill a missing salary category');
$base['SALARY'][0]['EMPLOYMENT_CATEGORY']='W2 or 1099';
$check(($build($base,['crop to crop'=>'Y'])['engagement_type']??'')==='','Start fallback cannot mask an ambiguous exact salary category');
$check(tenantIntegrationFieldMapApplyTransform('not W2','truthy_to_w2')===null,'worker transform cannot restore substring classification');
echo "$passed passed\n";
