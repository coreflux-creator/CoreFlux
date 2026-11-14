<?php
/** Exact hourly earnings from retained, independently approved time. */
declare(strict_types=1);

function payrollSourceDecimal4(mixed $value,string $label): int
{
    if (is_float($value) && (!is_finite($value) || abs($value-round($value,4))>0.000000001)) {
        throw new RuntimeException($label.' must have at most four decimal places.',409);
    }
    $text=is_int($value)||is_string($value)?(string)$value:(is_float($value)&&is_finite($value)?sprintf('%.4F',$value):'');
    if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,4}))?$/D',$text,$parts)) {
        throw new RuntimeException($label.' must be a non-negative amount with at most four decimal places.',409);
    }
    $whole=$parts[1];$fraction=str_pad($parts[2]??'',4,'0');
    $digits=ltrim($whole.$fraction,'0');$limit=(string)PHP_INT_MAX;
    if (strlen($digits)>strlen($limit)||(strlen($digits)===strlen($limit)&&strcmp($digits,$limit)>0)) throw new RuntimeException($label.' is outside the supported range.',409);
    return (int)($digits===''?'0':$digits);
}

/** Hours-only adapters cannot preserve the approved rates and component amounts. */
function payrollAssertHoursOnlyExport(array $lines): void
{
    foreach ($lines as $line) {
        if (($line['earnings_basis']??'compensation')==='approved_time') {
            throw new RuntimeException('This run retains approved source earnings. The Gusto hours-only export cannot preserve those rates and amounts. Use the payroll audit export or the source-aware PurePayroll workflow.',409);
        }
    }
}

function payrollSourceProduct(int ...$values): int
{
    $result=1;
    foreach ($values as $value) {
        if ($value<0 || ($value>0 && $result>intdiv(PHP_INT_MAX,$value))) throw new RuntimeException('Approved payroll amount is outside the supported range.',409);
        $result*=$value;
    }
    return $result;
}

function payrollSourceAdd(int $left,int $right): int
{
    if ($left<0 || $right<0 || $left>PHP_INT_MAX-$right) throw new RuntimeException('Approved payroll total is outside the supported range.',409);
    return $left+$right;
}

function payrollSourceDecimalString(int $scaled): string
{
    return intdiv($scaled,10000).'.'.str_pad((string)($scaled%10000),4,'0',STR_PAD_LEFT);
}

function payrollSourceEarningCode(array $entry): string
{
    $type=(string)$entry['hour_type'];$category=strtolower((string)$entry['category']);
    if (!in_array($type,['regular','overtime','doubletime'],true)
        || (str_starts_with($category,'ot_') && $type==='regular')) {
        throw new RuntimeException('The approved earning category and hour type need review before payroll.',409);
    }
    if ($type==='overtime') return 'overtime';
    if ($type==='doubletime') return 'double_time';
    return match ($category) {
        'holiday'=>'holiday','vacation','bereavement'=>'pto','sick'=>'sick',
        'regular_billable','regular_nonbillable','custom'=>'regular',
        default=>throw new RuntimeException('The approved payable time category has no payroll earning mapping.',409),
    };
}

/** Verified rows/rates come from payrollRunApprovedTime(), never the request body. */
function payrollApprovedHourlyEarnings(array $source): array
{
    $groups=[];$segments=[];$facts=[];$seen=[];
    foreach ($source['entries'] as $entry) {
        $id=(int)$entry['id'];$rate=$source['rates'][$id]??null;
        if ($id<=0 || isset($seen[$id]) || !is_array($rate)) throw new RuntimeException('The approved payroll source selection is incomplete or repeated.',409);
        $seen[$id]=true;
        $snapshot=json_decode((string)$entry['dimension_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        $facts[]=[
            'tenant_id'=>(int)$entry['tenant_id'],'entry_id'=>$id,'person_id'=>(int)$entry['person_id'],
            'placement_id'=>(int)$entry['placement_id'],'timesheet_id'=>(int)$entry['timesheet_id'],
            'rate_id'=>(int)$rate['id'],'source_rate_hash'=>(string)$snapshot['source_rate_hash'],
            'dimension_snapshot_hash'=>(string)$entry['dimension_snapshot_hash'],
            'approved_facts_hash'=>timeApprovedEntryFactsHash($entry),
            'hours'=>payrollSourceDecimalString(payrollSourceDecimal4($entry['hours'],'Approved hours')),
            'payable'=>(int)$entry['payable'],'category'=>$entry['category'],'hour_type'=>$entry['hour_type'],
        ];
        if ((int)$entry['payable']!==1) continue;
        if (!in_array($snapshot['engagement_type']??'', ['w2','temp_to_perm','internal'],true)
            || ($rate['pay_rate_unit']??'')!=='hour' || strtoupper((string)($rate['currency']??''))!=='USD') {
            throw new RuntimeException('Approved hourly payroll needs employee time with an explicit USD hourly rate.',409);
        }
        $code=payrollSourceEarningCode($entry);
        $hours=payrollSourceDecimal4($entry['hours'],'Approved hours');
        $base=payrollSourceDecimal4($rate['pay_rate'],'Approved hourly rate');
        $multiplier=payrollSourceDecimal4(match($entry['hour_type']) {
            'overtime'=>$rate['ot_multiplier'],'doubletime'=>$rate['dt_multiplier'],default=>'1.0000',
        },'Approved pay multiplier');
        if ($hours>0 && ($base===0 || $multiplier===0)) throw new RuntimeException('Payable approved time requires a positive rate and multiplier.',409);
        $numerator=payrollSourceProduct($hours,$base,$multiplier);
        $segment=$entry['timesheet_id'].':'.$entry['placement_id'].':'.$entry['dimension_snapshot_hash'];
        $key=$segment.':'.$code.':'.$base.':'.$multiplier;
        $groups[$key]??=['code'=>$code,'hours_scaled'=>0,'rate_scaled'=>$base,'multiplier_scaled'=>$multiplier,
            'numerator'=>0,'ids'=>[],'segment'=>$segment,'snapshot'=>$entry['dimension_snapshot_hash']];
        $groups[$key]['hours_scaled']=payrollSourceAdd($groups[$key]['hours_scaled'],$hours);
        $groups[$key]['numerator']=payrollSourceAdd($groups[$key]['numerator'],$numerator);
        $groups[$key]['ids'][]=$id;
        $segments[$segment]=payrollSourceAdd($segments[$segment]??0,$numerator);
    }
    ksort($groups,SORT_STRING);ksort($segments,SORT_STRING);
    // Round once per original staffing segment, then distribute its cents by
    // largest remainder. Regular and overtime components cannot introduce a cent.
    $denominator=10000000000;
    foreach ($segments as $segment=>$total) {
        $allocated=0;$remainders=[];
        foreach ($groups as $key=>&$group) {
            if ($group['segment']!==$segment) continue;
            $group['amount_cents']=intdiv($group['numerator'],$denominator);
            $allocated=payrollSourceAdd($allocated,$group['amount_cents']);
            $remainders[$key]=$group['numerator']%$denominator;
        }
        unset($group);
        arsort($remainders,SORT_NUMERIC);
        $target=intdiv($total,$denominator)+(($total%$denominator)>=intdiv($denominator,2)?1:0);
        $extra=$target-$allocated;
        foreach ($remainders as $key=>$unused) {if ($extra--<=0) break;$groups[$key]['amount_cents']++;}
    }
    usort($facts,static fn(array $a,array $b):int=>$a['entry_id']<=>$b['entry_id']);
    $receipt=json_encode(['version'=>1,'rounding'=>'half_up_per_approved_segment_largest_remainder','sources'=>$facts],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $earnings=[];$total=0;
    foreach ($groups as $group) {
        sort($group['ids'],SORT_NUMERIC);
        $effective=payrollSourceProduct($group['rate_scaled'],$group['multiplier_scaled']);
        $earnings[]=[
            'code'=>$group['code'],'hours'=>payrollSourceDecimalString($group['hours_scaled']),
            'rate_cents'=>$effective%1000000===0?intdiv($effective,1000000):null,
            'amount_cents'=>$group['amount_cents'],'taxable'=>1,
            'source_rate'=>payrollSourceDecimalString($group['rate_scaled']),
            'source_multiplier'=>payrollSourceDecimalString($group['multiplier_scaled']),
            'source_entry_ids_json'=>json_encode($group['ids'],JSON_THROW_ON_ERROR),
            'source_snapshot_hash'=>$group['snapshot'],
        ];
        $total=payrollSourceAdd($total,$group['amount_cents']);
    }
    return ['earnings'=>$earnings,'gross_cents'=>$total,'receipt_json'=>$receipt,'receipt_sha256'=>hash('sha256',$receipt)];
}

/** Internal fields and source hours cannot be supplied through hours_overrides. */
function payrollComputeOverrides(array $overrides,array $roster,array $sourceTime,array $economicOnly): array
{
    $allowed=array_fill_keys(array_map(static fn(array $row):int=>(int)$row['employee_id'],$roster),true);
    $result=[];
    foreach ($overrides as $employeeId=>$values) {
        if (!ctype_digit((string)$employeeId) || !isset($allowed[(int)$employeeId]) || !is_array($values)) throw new RuntimeException('Payroll overrides must identify an employee in this run.',409);
        foreach ($values as $key=>$value) {
            if (!in_array($key,['hours_regular','hours_overtime','bonus_cents','commission_cents'],true)) throw new RuntimeException('Unsupported payroll override field: '.$key,409);
            if (str_starts_with($key,'hours_')) {
                if (array_key_exists((int)$employeeId,$sourceTime) || !empty($economicOnly[(int)$employeeId])) throw new RuntimeException('Approved source hours cannot be overridden. Correct the original time before recomputing.',409);
                $value=payrollSourceDecimalString(payrollSourceDecimal4($value,'Override hours'));
            } elseif (!(is_int($value)||is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,11})$/D',(string)$value)) {
                throw new RuntimeException('Additional earnings must be non-negative whole cents.',409);
            } else $value=(int)$value;
            $result[(int)$employeeId][$key]=$value;
        }
    }
    return $result;
}
