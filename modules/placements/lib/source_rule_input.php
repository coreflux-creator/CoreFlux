<?php
/** Native source rule field and value validation, shared by all editors. */
declare(strict_types=1);
require_once __DIR__ . '/commission_input.php';

function placementSourceRuleFields(string $kind): array
{
    return match($kind) {
        'chain'=>['position','party_name','party_role','company_id','vendor_portal_id','portal_fee_pct','portal_fee_flat',
            'payment_terms_override','pwp_enabled','is_payable','submittal_id','vms_job_id','contract_storage_object_id'],
        'referral'=>['referrer_type','referrer_vendor_name','referrer_company_id','referrer_person_id','referrer_user_id',
            'fee_pct','fee_flat','fee_basis','payment_terms_override','pwp_enabled','duration_months','start_date','end_date','notes'],
        'commission'=>['role','user_id','plan_id','split_pct','basis','flat_amount','effective_from','effective_to','notes'],
        default=>throw new InvalidArgumentException('Unknown placement rule set.',422),
    };
}

/** Validate submitted fields before touching any company, payee, rule or graph. */
function placementSourceRuleInput(string $kind, array $input): array
{
    if ($kind === 'commission') {
        try { placementCommissionInput($input); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException($e->getMessage(),422); }
    }
    $allowed=placementSourceRuleFields($kind);
    foreach ($input as $field=>$value) {
        if (!in_array($field,$allowed,true) || (!is_scalar($value) && $value!==null)) throw new InvalidArgumentException('Invalid rule field: '.$field,422);
        if (in_array($field,['pwp_enabled','is_payable'],true)) {
            if (!in_array($value,[true,false,0,1,'0','1'],true)) throw new InvalidArgumentException($field.' must be true or false.',422);
            $input[$field]=(int)(bool)$value; continue;
        }
        if ($value === '') $value=null;
        if (in_array($field,['position','duration_months','company_id','vendor_portal_id','contract_storage_object_id',
            'referrer_company_id','referrer_person_id','referrer_user_id','user_id','plan_id'],true)) {
            if ($value!==null && (is_bool($value) || !preg_match('/^(?:0|[1-9][0-9]{0,14})$/D',(string)$value)
                || ($field!=='position' && (int)$value<=0))) throw new InvalidArgumentException('Choose a valid '.$field.'.',422);
            if ($field==='position' && ($value===null || (int)$value>2147483647)) throw new InvalidArgumentException('Enter a valid chain position.',422);
            if ($field==='duration_months' && $value!==null && (int)$value>1200) throw new InvalidArgumentException('Duration must be at most 1200 months.',422);
            $input[$field]=$value===null ? null : (int)$value; continue;
        }
        if (in_array($field,['fee_pct','split_pct','portal_fee_pct','fee_flat','flat_amount','portal_fee_flat'],true)) {
            $pct=str_ends_with($field,'_pct'); $scale=$pct?4:2;
            if ($value!==null && (is_bool($value) || !preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,'.$scale.'})?$/D',(string)$value)
                || ($pct && (float)$value>1))) throw new InvalidArgumentException('Enter a valid '.$field.' with at most '.$scale.' decimal places.',422);
            $input[$field]=$value===null?null:number_format((float)$value,$scale,'.',''); continue;
        }
        if (in_array($field,['start_date','end_date','effective_from','effective_to'],true)) {
            $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
            if ($value!==null && (!$date || $date->format('Y-m-d')!==$value || substr($value,0,4)<'1000')) throw new InvalidArgumentException('Enter a valid '.$field.'.',422);
            $input[$field]=$value; continue;
        }
        $limit=match($field) {'notes'=>65535,'submittal_id','vms_job_id'=>64,'payment_terms_override'=>40,default=>255};
        if ($value!==null && (!is_string($value) || !mb_check_encoding($value,'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value) || strlen($value)>65535 || mb_strlen($value)>$limit)) throw new InvalidArgumentException('Invalid text for '.$field.'.',422);
        $input[$field]=$value===null?null:trim($value);
    }
    return $input;
}

function placementSourceRuleValues(string $kind, array $values): void
{
    $enum=match($kind) {
        'chain'=>['party_role'=>['end_client','msp','prime_vendor','sub_vendor','direct']],
        'referral'=>['referrer_type'=>['vendor','person','user'],'fee_basis'=>['per_hour','per_invoice','one_time','pct_bill','pct_margin']],
        'commission'=>['role'=>['account_manager','lead','recruiter','team','other'],'basis'=>['net_margin','gross_margin','bill_rate','flat']],
    };
    foreach($enum as $field=>$allowed) if(!in_array($values[$field]??null,$allowed,true)) throw new InvalidArgumentException('Choose a valid '.$field.'.',422);
    if ($kind==='chain') {
        if (!isset($values['position'])) throw new InvalidArgumentException('Chain position is required.',422);
    } else {
        $start=$kind==='commission'?'effective_from':'start_date'; $end=$kind==='commission'?'effective_to':'end_date';
        if (empty($values[$start]) || (!empty($values[$end]) && $values[$end]<$values[$start])) throw new InvalidArgumentException('Review the rule start and end dates.',422);
        $pct=$kind==='commission'?'split_pct':'fee_pct'; $flat=$kind==='commission'?'flat_amount':'fee_flat';
        $basis=$values[$kind==='commission'?'basis':'fee_basis'];
        $percent=$kind==='commission'?$basis!=='flat':in_array($basis,['pct_bill','pct_margin'],true);
        if ((float)($values[$percent?$pct:$flat]??0)<=0 || ($percent && (float)$values[$pct]>1)
            || (float)($values[$percent?$flat:$pct]??0)!==0.0) throw new InvalidArgumentException('Enter one positive fee matching the selected basis; percentages use 0–1.',422);
    }
    if (!empty($values['payment_terms_override'])) {
        if (placementEconomicsNormaliseTerms($values['payment_terms_override'],'')==='') throw new InvalidArgumentException('Choose valid payment terms.',422);
    }
}

