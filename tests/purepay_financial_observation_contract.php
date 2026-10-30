<?php
declare(strict_types=1);
require_once __DIR__.'/../core/integrations/purepay_financial_observation_contract.php';
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
$passed=0;$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$rehash=static function(array $data):array{
    unset($data['unavailable_fields'],$data['observation_hash'],$data['observed_at']);
    $data['unavailable_fields']=purepayFinancialObservationMissing($data);
    $data['observation_hash']=purepayFinancialObservationHash($data);$data['observed_at']=gmdate('c');return $data;
};
foreach(['bill','invoice'] as $kind) {
    $data=json_decode(file_get_contents(__DIR__.'/fixtures/purepay_financial_observations/'.$kind.'.json'),true,64,JSON_THROW_ON_ERROR);
    $validate=static fn(array $v)=>purepayFinancialObservationValidate($v,'org-a',$kind,$kind.'-synthetic');
    $check($validate($data)===$data,'Python '.$kind.' evidence hash and every recorded field survive PHP validation');
    staffingDictionaryAssertPayload('purepay_financial_'.$kind.'_observation','purepay',$data);
    $check($fails(fn()=>purepayFinancialObservationValidate($data,'org-other',$kind,$data['document_id'])),'foreign '.$kind.' company rejected');
    $check($fails(fn()=>purepayFinancialObservationValidate($data,'org-a',$kind,'foreign-id')),'foreign '.$kind.' identity rejected');
    $core=['id'=>1,'tenant_id'=>1,'number'=>'CORE-1','currency'=>'USD','status'=>'partially_paid','subtotal'=>'100.00','tax_total'=>'0.00','total'=>'99.99','amount_paid'=>'60.00','amount_due'=>'39.99'];
    $result=purepayFinancialObservationCompare($data,$core);
    $check($result['amounts'][0]['difference']==='0.01' && $result['identity']==='not_established_by_comparison','one-cent difference precise without identity claim');
    $unknown=$data;$unknown['document']['currency']=null;$unknown=$rehash($unknown);$validate($unknown);
    $check(purepayFinancialObservationCompare($unknown,$core)['amounts'][0]['status']==='currency_missing','missing source currency prevents comparison');
    $core['currency']='CAD';$check(purepayFinancialObservationCompare($data,$core)['amounts'][0]['difference']===null,'different currencies never subtracted');
    $check(purepayFinancialObservationCompare($data,null)['core_record']===null,'unlinked evidence stays explicitly unlinked');
    $mutations=[
        'private field'=>static function(&$v){$v['document']['bank_account']='secret';},
        'inferred execution'=>static function(&$v){$v['execution_authority']=true;},
        'truncated enumeration'=>static function(&$v){$v['stored_record_enumeration_complete']=false;},
        'external completeness claim'=>static function(&$v){$v['external_sources_refreshed']=true;},
        'wrong document body'=>static function(&$v){$v['document']['id']='other';},
        'float money'=>static function(&$v){$v['document']['amount']=100.0;},
        'negative zero'=>static function(&$v){$v['document']['amount']='-0.00';},
        'oversized total'=>static function(&$v){$v['document']['amount']='100000000000.00';},
        'unbounded rate'=>static function(&$v){$v['document']['line_items'][0]['rate']='99999999999.99000001';},
        'invalid date'=>static function(&$v){$v['document']['due_date']='2026-02-30';},
        'duplicate line'=>static function(&$v){$v['document']['line_items'][]=$v['document']['line_items'][0];},
        'duplicate assignment'=>static function(&$v){$v['document']['assignment_ids']=['a','a'];},
        'invalid currency'=>static function(&$v){$v['document']['currency']='usd';},
    ];
    $stream=$kind==='bill'?'outgoing_payments':'collections';
    $mutations['wrong payment owner']=static function(&$v)use($stream,$kind){$v[$stream][0][$kind==='bill'?'bill_id':'invoice_id']='other';};
    $mutations['duplicate payment']=static function(&$v)use($stream){$v[$stream][]=$v[$stream][0];};
    $mutations['fractional cents']=static function(&$v)use($stream){$v[$stream][0]['fee_cents']=1.5;};
    $mutations['conflicting status']=static function(&$v)use($stream){$v[$stream][0]['pay_status']='failed';};
    foreach($mutations as $label=>$mutate){$bad=$data;$mutate($bad);$bad=$rehash($bad);$check($fails(fn()=>$validate($bad)),$kind.' rejects '.$label.' despite matching hash');}
    $bad=$data;$bad['document']['amount']='90.00';$check($fails(fn()=>$validate($bad)),'changed '.$kind.' content fails original hash');
    $bad=$data;$bad['unavailable_fields']=[];$bad['observation_hash']=purepayFinancialObservationHash($bad);
    $check($fails(fn()=>$validate($bad)),'missing '.$kind.' null markers rejected');
    $bad=$data;$bad['observed_at']='2026-02-30T00:00:00+00:00';$check($fails(fn()=>$validate($bad)),'invalid '.$kind.' observation timestamp rejected');
    $unicode=$data;$unicode['document']['doc_number']='SYN-é/₹-2026';$unicode=$rehash($unicode);$check($validate($unicode)===$unicode,'unicode source reference retained');
    $v2=json_decode(file_get_contents(__DIR__.'/fixtures/purepay_financial_observations/'.$kind.'-v2.json'),true,64,JSON_THROW_ON_ERROR);
    $check($validate($v2)===$v2,'v2 '.$kind.' labels and multiline descriptions retain Python hash');
    staffingDictionaryAssertPayload('purepay_financial_'.$kind.'_observation','purepay',$v2);
    $check($validate($data)===$data,'v1 '.$kind.' history remains readable alongside v2');
    foreach([null,'',"a\tb\r\nc",str_repeat('é',4000)] as $value){
        $changed=$v2;$changed['document']['line_items'][0]['description']=$value;$changed=$rehash($changed);
        $check($validate($changed)===$changed,'valid v2 plain text retained exactly');
    }
    foreach([str_repeat('a',4001),"a\0b","a\x1bb",35] as $value){
        $bad=$v2;$bad['document']['line_items'][0]['description']=$value;$bad=$rehash($bad);
        $check($fails(fn()=>$validate($bad)),'invalid v2 description refused');
    }
    $bad=$v2;$bad['document']['line_items'][0]['item_name']="invalid\nlabel";$bad=$rehash($bad);
    $check($fails(fn()=>$validate($bad)),'multiline exception is restricted to description');
    $bad=$data;$bad['document']['line_items'][0]['description']='unexpected v1 extension';$bad=$rehash($bad);
    $check($fails(fn()=>$validate($bad)),'v1 still rejects unversioned fields');
}
echo "Financial observation contract: $passed passed\n";
