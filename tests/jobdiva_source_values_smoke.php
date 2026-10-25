<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/jobdiva/assignment_contract.php';
require_once __DIR__ . '/../core/jobdiva/contract_projection.php';
require_once __DIR__ . '/../core/integrations/field_map_apply.php';
require_once __DIR__ . '/../modules/placements/lib/jobdiva_reconciliation.php';
$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) throw new RuntimeException($label);
    $passed++; echo "PASS $label\n";
};
foreach (['United States'=>'US','United States of America'=>'US','usa'=>'US','United Kingdom'=>'GB','GBR'=>'GB','Canada'=>'CA','India'=>'IN','United Arab Emirates'=>'AE'] as $raw=>$code) {
    $check(integrationSourceCountry($raw) === $code, "country $raw resolves exactly");
}
foreach (['United something','UN','ZZ','XX','America',''] as $raw) $check(integrationSourceCountry($raw) === null, "unrecognized country '$raw' is held");
foreach (['H'=>'hour','USD/Hour'=>'hour','$62.04 / Hour'=>'hour','CAD per day'=>'day','weekly'=>'week','fixed fee'=>'project'] as $raw=>$unit) {
    $check(integrationSourceRateUnit($raw) === $unit, "explicit unit $raw resolves exactly");
}
foreach (['','annual','biweekly','per diem','hourly or daily','hourly bonus','mystery','USD/hour/day'] as $raw) $check(integrationSourceRateUnit($raw) === null, "unknown unit '$raw' is held");
foreach (['','PER','XXX','XTS','USD/CAD','USD nonsense','USD/hour/day','$50/hour'] as $raw) $check(integrationSourceCurrency($raw) === null, "ambiguous currency '$raw' is held");
$check(integrationSourceWorksiteCity('  Montréal  ')==='Montréal', 'assignment city preserves Unicode and trims whitespace');
$check(integrationSourceWorksiteCity(null)===null&&integrationSourceWorksiteCity('   ')===null, 'missing city remains unknown');
foreach ([true,123,[],"New\nYork",str_repeat('a',256),"\xC3\x28"] as $raw) {
    $rejected=false;try{integrationSourceWorksiteCity($raw);}catch(InvalidArgumentException){$rejected=true;}
    $check($rejected,'invalid city cannot be silently coerced or truncated');
}
$check(integrationSourceWorksiteCity(str_repeat('é',255))===str_repeat('é',255),'city length is measured in Unicode characters');
$check(integrationFieldMapCoerceTargetValue("New\nYork",['target_table'=>'placements','target_column'=>'worksite_city'])===null,'mapped city uses the same validation');
$base = ['start_id'=>'101','engagement_type'=>'w2','start_date'=>'2026-10-01','client_company_name'=>'Synthetic client','worksite_city'=>'Hamilton','worksite_country'=>'United States','bill_rate'=>70,'pay_rate'=>40,'bill_rate_unit'=>'H','pay_rate_unit'=>'H','currency'=>'USD'];
$project = static fn(array $c): array => jobdivaContractProjectionBuild(['candidateId'=>'201','jobId'=>'301','_jd_contract'=>$c], [], '101');
$good = $project($base);
$cityFields=array_values(array_filter($good['fields'],static fn($row)=>$row['field']==='worksite_city'));
$check(count($cityFields)===1&&$cityFields[0]['proposed']==='Hamilton','assignment city is visible in the source comparison');
$check($good['complete'] && $good['placement']['worksite_country'] === 'US' && $good['economics']['gross_margin'] === 30.0, 'valid hourly projection normalizes country and margin');
foreach ([['currency'=>null],['bill_rate_unit'=>null],['pay_rate_unit'=>'mystery'],['pay_currency'=>'CAD'],['bill_rate_unit'=>'day']] as $override) {
    $p = $project(array_replace($base,$override));
    $check(!$p['complete'] && $p['economics']['gross_margin'] === null, 'incomplete, conflicting or non-hourly basis cannot produce trusted margin');
}
$check(!$project(array_replace($base,['worksite_country'=>'Unknown']))['complete'], 'unknown country blocks canonical review');
$basis = integrationSourceRateBasis(['bill_rate_raw'=>'USD 70/hour','pay_rate_raw'=>'USD 40/hour']);
$check($basis['errors'] === [] && $basis['currency']==='USD' && $basis['pay_rate_unit']==='hour', 'explicit amount descriptors establish basis without defaults');
$check(integrationSourceRateBasis(array_replace($base,['bill_rate_raw'=>'USD 70/day']))['errors'] !== [], 'explicit unit cannot hide a conflicting amount suffix');
$check(integrationSourceRateBasis(array_replace($base,['pay_rate_raw'=>'CAD 40/hour']))['errors'] !== [], 'single currency cannot hide a conflicting raw pay currency');
$rows = [['Start ID'=>'101','BILLING'=>[['Bill Rate'=>'USD 70/hour','Currency'=>'USD']],'SALARY'=>[['Salary'=>'CAD 40/hour','Currency'=>'CAD']]]];
$contract = jobdivaAssignmentContractBuild($rows, [], '101');
$check($contract['bill_rate_raw']==='USD 70/hour' && $contract['pay_rate_raw']==='CAD 40/hour' && integrationSourceRateBasis($contract)['errors'] !== [], 'assignment adapter retains raw descriptors and both currencies');
$errors=[];
$csv = jobdivaReconciliationNormaliseRow(['bill_rate'=>'USD 70/hour','pay_rate'=>'USD 40/hour','worksite_country'=>'United States'], [], $errors);
$check($errors===[] && $csv['bill_rate_unit']==='hour' && $csv['currency']==='USD' && $csv['worksite_country']==='US', 'CSV descriptors share the same normalization');
foreach ([['bill_rate'=>'70','pay_rate'=>'40'],['bill_rate'=>'USD 70/hour','pay_rate'=>'CAD 40/hour'],['bill_rate'=>'70','pay_rate'=>'40','bill_rate_unit'=>'mystery','pay_rate_unit'=>'H','currency'=>'USD']] as $row) {
    $errors=[]; jobdivaReconciliationNormaliseRow($row, [], $errors);
    $check($errors !== [], 'CSV missing/conflicting metadata stays in review');
}
$check(integrationFieldMapCoerceTargetValue('United States',['target_table'=>'placements','target_column'=>'worksite_country'])==='US', 'mapped country uses recognized names');
$check(integrationFieldMapCoerceTargetValue('per hour',['target_table'=>'placement_rates','target_column'=>'currency'])===null, 'field mapping cannot mistake PER for currency');
echo "$passed passed\n";
