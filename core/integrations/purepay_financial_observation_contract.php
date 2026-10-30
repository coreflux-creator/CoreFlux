<?php
/** Strict read-only AP/AR evidence contract; amounts never acquire default currency. */
declare(strict_types=1);
require_once __DIR__.'/purepay_observation_contract.php';

function purepayFinancialObservationRefuse(): never
{
    throw new RuntimeException('PurePay financial evidence is incomplete, changed or unsupported. Refresh and review the source records.');
}

function purepayFinancialObservationJson(mixed $value): string
{
    $sort=static function(mixed $v)use(&$sort):mixed {
        if(!is_array($v))return $v;
        if(!array_is_list($v))ksort($v,SORT_STRING);
        foreach($v as $key=>$item)$v[$key]=$sort($item);
        return $v;
    };
    return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS);
}

function purepayFinancialObservationHash(array $data): string
{
    unset($data['observed_at'],$data['observation_hash']);
    return hash('sha256',purepayFinancialObservationJson($data));
}

/** Interpreter for the fixed, versioned provider schema, not arbitrary JSON Schema. */
function purepayFinancialObservationSchema(mixed $value,array $schema): void
{
    if($value===null && ($schema['nullable']??false))return;
    if(isset($schema['enum']) && !in_array($value,$schema['enum'],true))purepayFinancialObservationRefuse();
    switch($schema['type']) {
        case 'object':
            if(!is_array($value) || array_diff(array_keys($value),array_keys($schema['properties']))
                || array_diff($schema['required'],array_keys($value)))purepayFinancialObservationRefuse();
            foreach($value as $k=>$v)purepayFinancialObservationSchema($v,$schema['properties'][$k]);
            break;
        case 'array':
            if(!is_array($value) || !array_is_list($value) || count($value)>($schema['maxItems']??100000))purepayFinancialObservationRefuse();
            if(($schema['uniqueItems']??false) && count(array_unique($value,SORT_STRING))!==count($value))purepayFinancialObservationRefuse();
            foreach($value as $v)if(isset($schema['items']))purepayFinancialObservationSchema($v,$schema['items']);
            break;
        case 'string':
            if(!is_string($value) || $value==='' || !mb_check_encoding($value,'UTF-8')
                || mb_strlen($value)>($schema['maxLength']??1000) || preg_match('/[\x00-\x1f\x7f]/u',$value))purepayFinancialObservationRefuse();
            if(isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~uD',$value)!==1)purepayFinancialObservationRefuse();
            if(($schema['format']??null)==='date' && !purepayObservationDate($value))purepayFinancialObservationRefuse();
            if(($schema['format']??null)==='date-time' && !purepayObservationTimestamp($value))purepayFinancialObservationRefuse();
            // Exact decimal strings only, with the provider's bound and canonical zero.
            if(isset($schema['pattern']) && str_contains($schema['pattern'],'\\d{')) {
                $places=str_contains($schema['pattern'],'{8}')?8:2;
                if(!preg_match('/^(?!-0\.0+$)-?(0|[1-9][0-9]{0,10})\.[0-9]{'.$places.'}$/D',$value)
                    || (strlen(ltrim(explode('.',$value)[0],'-'))===11 && strcmp(ltrim($value,'-'),'99999999999.99'.str_repeat('0',$places-2))>0))purepayFinancialObservationRefuse();
            }
            break;
        case 'integer':
            if(!is_int($value) || $value<$schema['minimum'] || $value>$schema['maximum'])purepayFinancialObservationRefuse();break;
        case 'boolean':if(!is_bool($value))purepayFinancialObservationRefuse();break;
        default:throw new LogicException('Unsupported financial observation schema');
    }
}

function purepayFinancialObservationMissing(mixed $value,string $path=''): array
{
    $out=[];
    if(is_array($value))foreach($value as $k=>$v)$out=[...$out,...purepayFinancialObservationMissing($v,$path.'/'.$k)];
    elseif($value===null)$out[]=$path;
    return $out;
}

function purepayFinancialObservationValidate(array $data,string $organization,string $kind,string $id): array
{
    static $schemas;
    $schemas??=json_decode(file_get_contents(__DIR__.'/contracts/purepay_financial_observations.json'),true,64,JSON_THROW_ON_ERROR);
    if(!isset($schemas[$kind]) || strlen(purepayFinancialObservationJson($data))>4100000) purepayFinancialObservationRefuse();
    purepayFinancialObservationSchema($data,$schemas[$kind]);
    if($data['organization_id']!==$organization || $data['document_id']!==$id || $data['document']['id']!==$id
        || !hash_equals($data['observation_hash'],purepayFinancialObservationHash($data)))purepayFinancialObservationRefuse();
    $missing=purepayFinancialObservationMissing($data);$given=$data['unavailable_fields'];sort($missing);sort($given);
    if($missing!==$given || ($kind==='bill' && $data['collections']) || ($kind==='invoice' && $data['outgoing_payments']))purepayFinancialObservationRefuse();
    $lineIds=[];
    foreach($data['document']['line_items'] as $line)if($line['line_id']!==null) {
        if(isset($lineIds[$line['line_id']]))purepayFinancialObservationRefuse();$lineIds[$line['line_id']]=true;
    }
    foreach(['outgoing_payments','collections'] as $stream) {
        $ids=[];
        foreach($data[$stream] as $payment) {
            $identity=$payment['id']??$payment['payment_id'];
            if($identity===null || isset($ids[$identity]) || ($payment['id']!==null && $payment['payment_id']!==null && $payment['id']!==$payment['payment_id']))purepayFinancialObservationRefuse();
            $ids[$identity]=true;
            $refs=$kind==='bill'?[$payment['payable_id'],$payment['bill_id']]:[$payment['invoice_id']];
            $refs=array_filter($refs,static fn($v)=>$v!==null);
            if(!$refs || array_diff($refs,[$id]))purepayFinancialObservationRefuse();
        }
    }
    foreach([...$data['outgoing_payments'],...$data['collections'],...$data['document']['recorded_receipts'],...$data['document']['quickbooks_receipts']] as $p)
        if($p['status']!==null && $p['pay_status']!==null && $p['status']!==$p['pay_status'])purepayFinancialObservationRefuse();
    return $data;
}

/** Compare explicitly selected Core figures; selection never establishes identity. */
function purepayFinancialObservationCompare(array $data,?array $core): array
{
    $document=$data['document'];$rows=[];
    foreach(['amount'=>'total','total_amt'=>'total','subtotal'=>'subtotal','tax_total'=>'tax_total',
        'balance'=>'amount_due','paid_total'=>'amount_paid','paid_to_date'=>'amount_paid'] as $provider=>$local) {
        $remote=$document[$provider];$value=$core[$local]??null;$delta=null;
        $status=$core===null?'no_core_record':($document['currency']===null || ($core['currency']??null)===null?'currency_missing':
            ($document['currency']!==$core['currency']?'currency_differs':($remote===null || $value===null?'amount_missing':'comparable')));
        if($status==='comparable') {
            $delta=purepayObservationMoney(purepayObservationCents($remote)-purepayObservationCents($value));
            $status=$delta==='0.00'?'agrees':'differs';
        }
        $rows[]=['provider_field'=>$provider,'core_field'=>$local,'provider_value'=>$remote,'core_value'=>$value,'difference'=>$delta,'status'=>$status];
    }
    return ['core_record'=>$core,'identity'=>'not_established_by_comparison','amounts'=>$rows,
        'cash_delivery'=>'not_evaluated','ledger_posted'=>false];
}
