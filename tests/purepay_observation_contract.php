<?php
declare(strict_types=1);
require_once __DIR__.'/../core/integrations/purepay_observation_contract.php';
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void {
    if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";
};
$fails=static function(callable $f):bool {try{$f();return false;}catch(Throwable $e){return true;}};
$org='org_synthetic_observation';
$fixtures=[];
foreach(['full','sparse','invalid'] as $name) {
    $data=json_decode(file_get_contents(__DIR__.'/fixtures/purepay_observations/'.$name.'.json'),true,64,JSON_THROW_ON_ERROR);
    $check(purepayObservationValidate($data,$org)===$data,'provider golden '.$name.' survives exact validation and Python hash');
    staffingDictionaryAssertPayload('purepayroll_native_observation','purepayroll',$data);
    $fixtures[$name]=$data;
}
$full=$fixtures['full'];$sparse=$fixtures['sparse'];$invalid=$fixtures['invalid'];
$rehash=static function(array $value):array {$value['observation_hash']=purepayObservationHash($value);return $value;};
$totals=purepayObservationTotals($full);
$check($totals['gross']['status']==='matched' && $totals['gross']['difference']==='0.00','stored full-run gross reconciles to line gross');
$check(purepayObservationTotals($sparse)['gross']['provider_total']===null,'missing provider total is not inferred from lines');
$check(purepayObservationTotals($invalid)['net']['provider_total']===null && purepayObservationTotals($invalid)['net']['line_total']==='0.00','known zero remains distinct from an invalid provider total');
$changed=$full;$changed['totals']['gross']='99.99';$changed=$rehash($changed);
$check(purepayObservationValidate($changed,$org)===$changed && purepayObservationTotals($changed)['gross']['difference']==='-0.01','arithmetically different stored totals remain explicit differences');
$changed=$full;$changed['observed_at']='2026-10-26T00:00:00+00:00';
$check(purepayObservationHash($changed)===$full['observation_hash'],'observation timestamp does not create a new ledger version');
$changed=$full;$changed['record_version']=str_repeat('f',64);
$check(purepayObservationHash($changed)!==$full['observation_hash'],'delete and recreate incarnation changes the observation hash');
$check($fails(fn()=>purepayObservationValidate($full,'org-other')),'another organization is rejected');
$check($fails(fn()=>purepayObservationValidate($full,$org,'another-run')),'another requested run is rejected');
$mutations=[
    'extra private root field'=>static function(&$v){$v['bank_account']='private';},
    'extra private line field'=>static function(&$v){$v['lines'][0]['ssn']='private';},
    'extra private source field'=>static function(&$v){$v['lines'][0]['source_time_lineage']['entries'][0]['payment_ref']='private';},
    'cash-delivery claim'=>static function(&$v){$v['delivery_confirmation']='delivered';},
    'inferred Core match'=>static function(&$v){$v['core_match']='matched';},
    'unproven transactional snapshot'=>static function(&$v){$v['consistency']='snapshot';},
    'unknown reason'=>static function(&$v){$v['unavailable_fields']['paid_at']='guessed';},
    'null without reason'=>static function(&$v){$v['totals']['gross']=null;},
    'reason contradicting known zero'=>static function(&$v){$v['unavailable_totals']['bonus']='missing';},
    'negative zero'=>static function(&$v){$v['totals']['bonus']='-0.00';},
    'float amount'=>static function(&$v){$v['totals']['gross']=100.00;},
    'exponent amount'=>static function(&$v){$v['totals']['gross']='1e2';},
    'oversized amount'=>static function(&$v){$v['totals']['gross']='100000000000.00';},
    'invalid calendar day'=>static function(&$v){$v['pay_date']='2026-02-30';},
    'invalid timestamp'=>static function(&$v){$v['observed_at']='2026-02-30T00:00:00+00:00';},
    'duplicate line index'=>static function(&$v){$v['lines'][]=$v['lines'][0];},
    'duplicate missing source marker'=>static function(&$v){$v['lines'][0]['source_time_lineage']['unavailable_entries']=[1,1];},
];
foreach($mutations as $label=>$mutate) {
    $v=$full;$mutate($v);$v=$rehash($v);
    $check($fails(fn()=>purepayObservationValidate($v,$org)),$label.' is refused even with a matching digest');
}
$changed=$full;$changed['totals']['gross']='99.99';
$check($fails(fn()=>purepayObservationValidate($changed,$org)),'altered content with original hash is refused');
$changed=$full;$changed['lines'][0]['amounts']['gross']='-100.00';$changed['totals']['gross']='-100.00';$changed=$rehash($changed);
$check(purepayObservationValidate($changed,$org)===$changed && purepayObservationTotals($changed)['gross']['status']==='matched','stored negative correction amounts are preserved');
$large=$full;
$large['lines']=[];
for($i=0;$i<5000;$i++) {
    $line=$full['lines'][0];$line['line_index']=$i;$line['amounts']['gross']='99999999999.99';
    $large['lines'][]=$line;
}
$large=$rehash($large);purepayObservationValidate($large,$org);
$check(purepayObservationTotals($large)['gross']['line_total']==='499999999999950.00','maximum-size page uses exact signed integer cents without float rounding');
$line=$large['lines'][0];$line['line_index']=5000;$large['lines'][]=$line;
$check($fails(fn()=>purepayObservationValidate($rehash($large),$org)),'oversized run is refused without truncation');
echo "PurePayroll observation contract: $passed passed\n";
