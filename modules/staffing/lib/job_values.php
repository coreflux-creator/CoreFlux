<?php
declare(strict_types=1);
require_once __DIR__.'/../../../core/integrations/source_values.php';
require_once __DIR__.'/../../people/lib/company_relationships.php';

function staffingJobNativeFields(): array
{
    return ['client_id','company_id','title','status','description','department','location_city','location_state','location_country','remote_policy','opened_at','closed_at'];
}

/** Validate the complete patch before any job or ownership write. */
function staffingJobValidateNative(array $patch): array
{
    if(!$patch||array_diff(array_keys($patch),staffingJobNativeFields()))throw new InvalidArgumentException('Choose the job fields to save.');
    foreach($patch as$field=>&$value){
        if(in_array($field,['client_id','company_id'],true)){$value=companyRelationshipId($value);continue;}
        if($value!==null&&!is_string($value))throw new InvalidArgumentException('Job '.$field.' must be text.');
        $value=$value===null?null:trim($value);
        if($field==='title'&&($value===null||$value===''))throw new InvalidArgumentException('A job title is required.');
        if($field==='status'&&!in_array($value,['open','active','on_hold','filled','closed','cancelled'],true))throw new InvalidArgumentException('Choose a recognized job status.');
        if($field==='remote_policy'){
            if($value==='')$value=null;
            if($value!==null&&!in_array($value,['onsite','hybrid','remote'],true))throw new InvalidArgumentException('Choose a recognized remote policy.');
        }
        if($field==='location_country'){
            if($value==='')$value=null;
            if($value!==null){$normalized=integrationSourceCountry($value);if($normalized===null)throw new InvalidArgumentException('Choose a recognized country name or code.');$value=$normalized;}
        }
        if(in_array($field,['opened_at','closed_at'],true)){
            if($value==='')$value=null;
            if($value!==null){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Use a valid calendar date for '.$field.'.');}
        }
        if($value!==null){
            $limit=['title'=>255,'department'=>120,'location_city'=>120,'location_state'=>60,'description'=>65535][$field]??null;
            if(!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)||($limit!==null&&mb_strlen($value,'UTF-8')>$limit)||($field==='description'&&strlen($value)>65535))throw new InvalidArgumentException('Job '.$field.' exceeds its supported text format or length.');
        }
    }
    unset($value);
    return$patch;
}

function staffingJobComparable(array $row): array
{
    $values=[];foreach(staffingJobNativeFields()as$field){$value=$row[$field]??null;$values[$field]=in_array($field,['client_id','company_id'],true)?($value===null?null:(int)$value):($value===null?null:(string)$value);}return$values;
}
