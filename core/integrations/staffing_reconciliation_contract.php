<?php
/** Read-only comparisons. Numeric agreement never establishes source allocation or payment delivery. */
declare(strict_types=1);
require_once __DIR__.'/purepay_observation_contract.php';

function staffingReconciliationPeriod(string $start,string $end): array
{
    if(!purepayObservationDate($start)||!purepayObservationDate($end)||$start>$end
        ||(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days>365)
        throw new InvalidArgumentException('Choose a valid work period of at most one year.');
    return ['start'=>$start,'end'=>$end];
}

function staffingReconciliationRelation(?string $start,?string $end,array $period): string
{
    if(!purepayObservationDate($start)||!purepayObservationDate($end)||$start>$end)return 'unknown';
    if($end<$period['start']||$start>$period['end'])return 'outside';
    if($start===$period['start']&&$end===$period['end'])return 'exact';
    if($start>=$period['start']&&$end<=$period['end'])return 'within';
    if($start<=$period['start']&&$end>=$period['end'])return 'contains';
    return 'overlap';
}

/** Decimal input with no implicit rounding, exponent notation, or missing-to-zero conversion. */
function staffingReconciliationScaled(mixed $value,int $scale,int $digits): int
{
    if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException('A source decimal is unavailable.');
    $value=(string)$value;
    if(!preg_match('/^(0|[1-9][0-9]{0,'.($digits-1).'})(?:\.([0-9]+))?$/D',$value,$m))
        throw new InvalidArgumentException('A source decimal is unsupported.');
    $fraction=$m[2]??'';
    if(strlen($fraction)>$scale&&trim(substr($fraction,$scale),'0')!=='')throw new InvalidArgumentException('A source decimal needs a precision review.');
    return (int)$m[1]*(10**$scale)+(int)str_pad(substr($fraction,0,$scale),$scale,'0');
}

function staffingReconciliationHours(int $hundredths): string
{
    return intdiv($hundredths,100).'.'.str_pad((string)($hundredths%100),2,'0',STR_PAD_LEFT);
}

/** Same per-entry rounding as native billing: adjusted rate * multiplier to four places, then hours to cents. */
function staffingReconciliationCharge(string $hours,string $rate,string $multiplier,bool $billing=true): string
{
    $h=staffingReconciliationScaled($hours,2,4);$r=staffingReconciliationScaled($rate,4,6);
    $m=staffingReconciliationScaled($multiplier,2,2);
    if($m===0||$r>intdiv(PHP_INT_MAX-50,max(1,$m)))throw new InvalidArgumentException('A source multiplier is unsupported.');
    if(!$billing){
        $product=$r*$m;
        if($product>intdiv(PHP_INT_MAX-500000,max(1,$h)))throw new InvalidArgumentException('A source amount is too large.');
        return purepayObservationMoney(intdiv($h*$product+500000,1000000));
    }
    $effective=intdiv($r*$m+50,100);
    if($effective>intdiv(PHP_INT_MAX-5000,max(1,$h)))throw new InvalidArgumentException('A source amount is too large.');
    return purepayObservationMoney(intdiv($h*$effective+5000,10000));
}

function staffingReconciliationTime(array $entries,array $rates,array $period): array
{
    $rows=[];$approvedHours=0;$payHours=0;$billHours=0;$currencies=[];$pay=0;$bill=0;
    $payComplete=true;$billComplete=true;$hoursComplete=true;$payHoursComplete=true;$billHoursComplete=true;$approved=0;
    foreach($entries as$entry){
        if($entry['work_date']<$period['start']||$entry['work_date']>$period['end'])continue;
        $issues=[];$active=empty($entry['superseded_by_id']);
        $accepted=$active&&$entry['status']==='approved'&&!empty($entry['approved_at'])&&!empty($entry['approved_by_user_id']);
        $rate=$rates[(int)($entry['rate_snapshot_id']??0)]??null;$payAmount=null;$billAmount=null;$currency=null;
        $hours=null;
        try{$hours=staffingReconciliationScaled($entry['hours'],2,4);}catch(InvalidArgumentException $e){$issues[]='hours_unavailable';}
        if(!$active)$issues[]='superseded';elseif(!$accepted)$issues[]='not_approved';
        if($accepted){
            $approved++;if($hours!==null)$approvedHours+=$hours;else$hoursComplete=false;
            if(!$rate||empty($rate['approved_at'])||(int)$rate['placement_id']!==(int)$entry['placement_id'])$issues[]='approved_rate_missing';
            elseif(!is_string($rate['currency'])||!preg_match('/^[A-Z]{3}$/D',$rate['currency']))$issues[]='currency_missing';
            else{$currency=$rate['currency'];$currencies[$currency]=true;}
            $category=$entry['hour_type']?:$entry['category'];
            $multiplier=match($category){
                'regular','REG','regular_billable','regular_nonbillable','holiday','vacation','sick','bereavement'=>'1.00',
                'OT','overtime','OT_billable','OT_nonbillable'=>$rate['ot_multiplier']??null,
                'DT','doubletime','DT_billable','DT_nonbillable'=>$rate['dt_multiplier']??null,
                default=>null,
            };
            if($multiplier===null)$issues[]='earning_category_unmapped';
            $pricingIssues=$issues;
            foreach(['pay','bill']as$side){
                $enabled=$entry[$side==='pay'?'payable':'billable']??null;
                if($enabled===null){$issues[]=$side.'_eligibility_unknown';${$side.'Complete'}=false;${$side.'HoursComplete'}=false;continue;}
                if((int)$enabled!==1)continue;
                if($hours!==null)${$side.'Hours'}+=$hours;else${$side.'HoursComplete'}=false;
                if(!$pricingIssues&&$currency!==null){
                    try{
                        if(($rate[$side.'_rate_unit']??null)!=='hour')throw new InvalidArgumentException('Non-hourly basis');
                        $base=$side==='bill'?($rate['adjusted_bill_rate']??$rate['bill_rate']):$rate['pay_rate'];
                        ${$side.'Amount'}=staffingReconciliationCharge((string)$entry['hours'],(string)$base,(string)$multiplier,$side==='bill');
                        ${$side}+=purepayObservationCents(${$side.'Amount'});
                    }catch(InvalidArgumentException $e){$issues[]=$side.'_rate_unavailable';}
                }
                if(${$side.'Amount'}===null)${$side.'Complete'}=false;
            }
        }
        $rows[]=['id'=>(int)$entry['id'],'date'=>$entry['work_date'],'category'=>$entry['category'],
            'hours'=>$hours===null?null:staffingReconciliationHours($hours),'status'=>$entry['status'],
            'approved'=>$accepted,'approved_at'=>$entry['approved_at'],'approved_by'=>$entry['approved_by_user_id']===null?null:(int)$entry['approved_by_user_id'],
            'superseded_by'=>$entry['superseded_by_id']===null?null:(int)$entry['superseded_by_id'],
            'source'=>$entry['source_system']??$entry['source'],'source_reference'=>$entry['external_id']??null,
            'rate_snapshot_id'=>$entry['rate_snapshot_id']===null?null:(int)$entry['rate_snapshot_id'],
            'currency'=>$currency,'pay_amount'=>$payAmount,'bill_amount'=>$billAmount,'issues'=>array_values(array_unique($issues))];
    }
    $currency=count($currencies)===1?array_key_first($currencies):null;
    return ['entries'=>$rows,'approved_entries'=>$approved,'approved_hours'=>$hoursComplete?staffingReconciliationHours($approvedHours):null,
        'payable_hours'=>$payHoursComplete?staffingReconciliationHours($payHours):null,'billable_hours'=>$billHoursComplete?staffingReconciliationHours($billHours):null,
        'currency'=>$currency,'pay_amount'=>$approved&&$currency&&$payComplete?purepayObservationMoney($pay):null,
        'bill_amount'=>$approved&&$currency&&$billComplete?purepayObservationMoney($bill):null,
        'amount_basis'=>'approved_frozen_hourly_rates_before_tax_and_one_time_items',
        'original_client_approval_proven'=>false];
}

function staffingReconciliationDifference(?string $provider,?string $core,?string $providerCurrency,?string $coreCurrency,bool $periodComparable): array
{
    $numeric=null;
    if($periodComparable&&$provider!==null&&$core!==null&&purepayObservationDecimal($provider)&&purepayObservationDecimal($core))
        $numeric=purepayObservationMoney(purepayObservationCents($provider)-purepayObservationCents($core));
    $currency=$providerCurrency===null||$coreCurrency===null?'unknown':($providerCurrency===$coreCurrency?'same':'different');
    return ['numeric_difference'=>$numeric,'currency_comparison'=>$currency,
        'monetary_difference'=>$currency==='same'?$numeric:null,'allocation_proven'=>false];
}
