<?php
/** Read-only observations of payroll operated in PurePayroll; no execution authority. */
declare(strict_types=1);

const PUREPAY_OBSERVATION_VERSION='2026-10-25.1';
const PUREPAY_OBSERVATION_CONSISTENCY='rechecked_read_not_transactional_snapshot';
const PUREPAY_OBSERVATION_AMOUNTS=['gross','bonus','reimbursements','federal_wh','social_security','medicare',
    'state_wh','local_wh','sdi_pfl','other_deductions','garnishments','net','employer_fica','employer_futa',
    'employer_suta','employer_match','funding_required'];
const PUREPAY_OBSERVATION_META=['period_start','period_end','pay_date','status','native','manual',
    'provider_mode','execution_hold','source','provider','paid_at','created_at','currency'];

function purepayObservationRefuse(): never
{
    throw new RuntimeException('PurePayroll returned an unsupported or incomplete payroll observation. No observation was saved.');
}

function purepayObservationKeys(array $value,array $keys): void
{
    if(array_diff(array_keys($value),$keys) || array_diff($keys,array_keys($value)))purepayObservationRefuse();
}

function purepayObservationToken(mixed $value,bool $run=false): bool
{
    return is_string($value) && preg_match($run?'/^[A-Za-z0-9_-]{1,128}$/D':'~^[A-Za-z0-9_:.+/\-]{1,128}$~D',$value)===1;
}

function purepayObservationDecimal(mixed $value,bool $hours=false): bool
{
    return is_string($value) && preg_match($hours
        ?'/^(?!-0\.0000$)-?(0|[1-9][0-9]{0,5})\.[0-9]{4}$/D'
        :'/^(?!-0\.00$)-?(0|[1-9][0-9]{0,10})\.[0-9]{2}$/D',$value)===1;
}

/** All allowed totals fit signed 64-bit cents, including 5,000 maximum-size lines. */
function purepayObservationCents(string $value): int
{
    if(!purepayObservationDecimal($value))purepayObservationRefuse();
    return (int)str_replace('.','',$value);
}

function purepayObservationMoney(int $value): string
{
    $negative=$value<0;$value=abs($value);
    return ($negative?'-':'').intdiv($value,100).'.'.str_pad((string)($value%100),2,'0',STR_PAD_LEFT);
}

function purepayObservationDate(mixed $value): bool
{
    if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))return false;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date && $date->format('Y-m-d')===$value;
}

function purepayObservationTimestamp(mixed $value): bool
{
    if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$value))return false;
    try {$date=new DateTimeImmutable($value);$errors=DateTimeImmutable::getLastErrors();}
    catch(Throwable $e){return false;}
    return $errors===false || (!$errors['warning_count'] && !$errors['error_count']);
}

/** Opaque provider-validated source text; Python ISO formats exceed PHP's parser.
 * Only observed_at is parsed for freshness. Never infer a source timestamp zone.
 */
function purepayObservationSourceTimestamp(mixed $value): bool
{
    if(!is_string($value))return false;
    $length=preg_match_all('/./us',$value);
    return $length!==false && $length<=40;
}

/** An absent value must carry a reason; valid zero is never confused with absence. */
function purepayObservationAvailability(array $values,array $unavailable,array $fields,callable $valid): void
{
    if(array_diff(array_keys($unavailable),$fields))purepayObservationRefuse();
    foreach($fields as $field) {
        if(!array_key_exists($field,$values))purepayObservationRefuse();
        if($values[$field]===null) {
            if(!in_array($unavailable[$field]??null,['missing','invalid'],true))purepayObservationRefuse();
        } elseif(array_key_exists($field,$unavailable) || !$valid($field,$values[$field]))purepayObservationRefuse();
    }
}

function purepayObservationSource(array $entry): void
{
    $tokens=['id','entry_id','source','person_id','source_workspace_id','revision','core_input_id'];
    $fields=[...$tokens,'date','hours','ot_hours','core','original_source'];
    if(array_diff(array_keys($entry),$fields))purepayObservationRefuse();
    foreach($entry as $key=>$value) {
        if($value===null)continue;
        if(in_array($key,$tokens,true)) {if(!purepayObservationToken($value))purepayObservationRefuse();}
        elseif($key==='date') {if(!purepayObservationDate($value))purepayObservationRefuse();}
        elseif(in_array($key,['hours','ot_hours'],true)) {if(!purepayObservationDecimal($value,true))purepayObservationRefuse();}
        else {
            if(!is_array($value))purepayObservationRefuse();
            $allowed=$key==='core'?['instance_id','time_tenant_id','time_entry_id']:['system','workspace_id','entry_id','component','revision'];
            if(array_diff(array_keys($value),$allowed))purepayObservationRefuse();
            foreach($value as $nested=>$v) {
                if($v===null)continue;
                if(!purepayObservationToken($v))purepayObservationRefuse();
            }
        }
    }
}

/** Reconstruct schema-known objects because associative JSON decoding conflates {} and []. */
function purepayObservationCanonical(mixed $value,string $field=''): mixed
{
    if(!is_array($value))return $value;
    if(in_array($field,['lines','entries','unavailable_entries'],true)) {
        return array_map(static fn($item)=>purepayObservationCanonical($item),$value);
    }
    ksort($value,SORT_STRING);$object=new stdClass();
    foreach($value as $key=>$item)$object->{$key}=purepayObservationCanonical($item,(string)$key);
    return $object;
}

function purepayObservationHash(array $value): string
{
    unset($value['observation_hash'],$value['observed_at']);
    return hash('sha256',json_encode(purepayObservationCanonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}

function purepayObservationValidate(array $data,string $organization,?string $runId=null): array
{
    $fields=['contract_version','organization_id','run_id','record_version',...PUREPAY_OBSERVATION_META,
        'unavailable_fields','amount_basis','delivery_confirmation','core_match','totals','unavailable_totals',
        'lines','consistency','observation_hash','observed_at'];
    purepayObservationKeys($data,$fields);
    if($data['contract_version']!==PUREPAY_OBSERVATION_VERSION || $data['organization_id']!==$organization
        || !purepayObservationToken($data['run_id'],true) || ($runId!==null && $data['run_id']!==$runId)
        || $data['amount_basis']!=='stored_payroll_ledger' || $data['delivery_confirmation']!=='not_evaluated'
        || $data['core_match']!=='not_evaluated' || $data['consistency']!==PUREPAY_OBSERVATION_CONSISTENCY
        || !purepayObservationTimestamp($data['observed_at']))purepayObservationRefuse();
    foreach(['record_version','observation_hash'] as $key)
        if(!is_string($data[$key]) || !preg_match('/^[a-f0-9]{64}$/D',$data[$key]))purepayObservationRefuse();
    foreach(['unavailable_fields','totals','unavailable_totals','lines'] as $key)
        if(!is_array($data[$key]))purepayObservationRefuse();
    purepayObservationAvailability($data,$data['unavailable_fields'],PUREPAY_OBSERVATION_META,static function($key,$value):bool {
        if(in_array($key,['native','manual','provider_mode','execution_hold'],true))return is_bool($value);
        if(in_array($key,['period_start','period_end','pay_date'],true))return purepayObservationDate($value);
        if(in_array($key,['paid_at','created_at'],true))return purepayObservationSourceTimestamp($value);
        if($key==='currency')return is_string($value) && preg_match('/^[A-Z]{3}$/D',$value)===1;
        return purepayObservationToken($value);
    });
    purepayObservationKeys($data['totals'],PUREPAY_OBSERVATION_AMOUNTS);
    purepayObservationAvailability($data['totals'],$data['unavailable_totals'],PUREPAY_OBSERVATION_AMOUNTS,
        static fn($key,$value):bool=>purepayObservationDecimal($value));
    if(!array_is_list($data['lines']) || count($data['lines'])>5000)purepayObservationRefuse();
    $sourceCount=0;
    foreach($data['lines'] as $index=>$line) {
        if(!is_array($line))purepayObservationRefuse();
        purepayObservationKeys($line,['line_index','employee_id','hours','ot_hours','unavailable_fields','amounts','unavailable_amounts','source_time_lineage']);
        if($line['line_index']!==$index || !is_array($line['unavailable_fields'])
            || !is_array($line['amounts']) || !is_array($line['unavailable_amounts']) || !is_array($line['source_time_lineage']))purepayObservationRefuse();
        purepayObservationAvailability($line,$line['unavailable_fields'],['employee_id','hours','ot_hours'],
            static fn($key,$value):bool=>$key==='employee_id'?purepayObservationToken($value):purepayObservationDecimal($value,true));
        purepayObservationKeys($line['amounts'],PUREPAY_OBSERVATION_AMOUNTS);
        purepayObservationAvailability($line['amounts'],$line['unavailable_amounts'],PUREPAY_OBSERVATION_AMOUNTS,
            static fn($key,$value):bool=>purepayObservationDecimal($value));
        $lineage=$line['source_time_lineage'];
        purepayObservationKeys($lineage,['status','entries','unavailable_entries']);
        if(!in_array($lineage['status'],['recorded','empty','not_recorded','partial','invalid'],true)
            || !is_array($lineage['entries']) || !array_is_list($lineage['entries']) || count($lineage['entries'])>500
            || !is_array($lineage['unavailable_entries']) || !array_is_list($lineage['unavailable_entries'])
            || count($lineage['unavailable_entries'])>500)purepayObservationRefuse();
        $seen=[];
        foreach($lineage['unavailable_entries'] as $missing) {
            if(!is_int($missing) || $missing<0 || $missing>=500 || isset($seen[$missing]))purepayObservationRefuse();
            $seen[$missing]=true;
        }
        foreach($lineage['entries'] as $entry) {
            if(!is_array($entry))purepayObservationRefuse();
            purepayObservationSource($entry);
        }
        $sourceCount+=purepayObservationReferenceCount($lineage);
        if($sourceCount>10000)purepayObservationRefuse();
    }
    if(!hash_equals($data['observation_hash'],purepayObservationHash($data)))purepayObservationRefuse();
    return $data;
}

/** Omitted malformed references still count toward the bounded source window. */
function purepayObservationReferenceCount(array $lineage): int
{
    return max(count($lineage['entries']),$lineage['unavailable_entries']?max($lineage['unavailable_entries'])+1:0);
}

/** Ledger arithmetic only; equality is not evidence of Core source matching or settlement. */
function purepayObservationTotals(array $data): array
{
    $result=[];
    foreach(PUREPAY_OBSERVATION_AMOUNTS as $field) {
        $sum=0;$missing=0;
        foreach($data['lines'] as $line) {
            if($line['amounts'][$field]===null)$missing++;
            else $sum+=purepayObservationCents($line['amounts'][$field]);
        }
        $known=(bool)$data['lines'] && $missing===0;
        $total=$data['totals'][$field];
        $difference=$known && $total!==null?purepayObservationCents($total)-$sum:null;
        $result[$field]=['provider_total'=>$total,'line_total'=>$known?purepayObservationMoney($sum):null,
            'difference'=>$difference===null?null:purepayObservationMoney($difference),
            'missing_lines'=>$missing,'status'=>!$data['lines']?'no_lines':(!$known?'missing_line_amounts':
                ($total===null?'missing_provider_total':($difference===0?'matched':'different')))];
    }
    return $result;
}
