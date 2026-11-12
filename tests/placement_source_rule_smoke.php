<?php
declare(strict_types=1);
require_once __DIR__.'/../modules/placements/lib/source_rule_edit.php';
$n=0;
$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";};
$reject=static function(callable $f):bool{try{$f();return false;}catch(InvalidArgumentException $e){return $e->getCode()===422;}};
foreach(['chain','referral','commission']as$kind)foreach(['tenant_id','placement_id','id','coreflux_overridden_fields','unknown_column']as$field)
    $check($reject(fn()=>placementSourceRuleInput($kind,[$field=>1])),"$kind rejects $field");
foreach([
    ['chain',['portal_credentials_ct'=>'secret']],['chain',['position'=>-1]],['chain',['position'=>1.2]],['chain',['position'=>true]],
    ['chain',['company_id'=>true]],['chain',['company_id'=>'1e2']],['chain',['is_payable'=>'false']],['chain',['pwp_enabled'=>null]],
    ['chain',['portal_fee_pct'=>'1.0001']],['chain',['portal_fee_pct'=>'0.12345']],['chain',['portal_fee_flat'=>'1.001']],
    ['chain',['party_name'=>[]]],['chain',['party_name'=>"Bad\0Name"]],['chain',['submittal_id'=>str_repeat('x',65)]],
    ['referral',['start_date'=>'2026-02-30']],['referral',['fee_pct'=>'10%']],['referral',['fee_flat'=>'-1']],
    ['referral',['duration_months'=>'1.5']],['referral',['duration_months'=>1201]],['referral',['fee_flat'=>INF]],
    ['commission',['currency'=>'INR']],['commission',['payment_frequency'=>'one_time']],['commission',['due_date'=>'2026-08']],
    ['commission',['basis'=>'one_time']],['commission',['split_pct'=>true]],['commission',['flat_amount'=>'100000000.00']],
]as[$kind,$input])$check($reject(fn()=>placementSourceRuleInput($kind,$input)),"$kind rejects malformed ".array_key_first($input));
$v=placementSourceRuleInput('referral',['fee_pct'=>0.1,'fee_flat'=>'','pwp_enabled'=>'0','referrer_person_id'=>'42','end_date'=>null]);
$check($v===['fee_pct'=>'0.1000','fee_flat'=>null,'pwp_enabled'=>0,'referrer_person_id'=>42,'end_date'=>null],'Input distinguishes absent values, explicit clears, booleans, decimals and identities');
foreach([
    ['chain',['position'=>0,'party_role'=>'msp']],
    ['referral',['referrer_type'=>'vendor','fee_basis'=>'per_hour','fee_flat'=>'20.00','start_date'=>'2026-11-01']],
    ['referral',['referrer_type'=>'person','fee_basis'=>'pct_bill','fee_pct'=>'0.1000','start_date'=>'2026-11-01']],
    ['commission',['role'=>'recruiter','basis'=>'flat','flat_amount'=>'25.00','effective_from'=>'2026-11-01']],
]as[$kind,$v]){placementSourceRuleValues($kind,$v);$check(true,"$kind accepts complete matching rule");}
foreach([
    ['chain',['position'=>0,'party_role'=>'unknown']],
    ['referral',['referrer_type'=>'vendor','fee_basis'=>'pct_bill','fee_pct'=>0.1,'fee_flat'=>20,'start_date'=>'2026-11-01']],
    ['referral',['referrer_type'=>'person','fee_basis'=>'per_hour','fee_flat'=>0,'start_date'=>'2026-11-01']],
    ['commission',['role'=>'recruiter','basis'=>'flat','flat_amount'=>20,'effective_from'=>'2026-11-01','effective_to'=>'2026-10-31']],
]as[$kind,$v])$check($reject(fn()=>placementSourceRuleValues($kind,$v)),"$kind rejects inconsistent rule");
foreach(['chain_rules','referral_rules','commission_rules']as$field)$check($reject(fn()=>placementClearOverrides(1,[$field])),'Generic field handback cannot release '.$field);
echo "$n source rule validation checks passed.\n";
