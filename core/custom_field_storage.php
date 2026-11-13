<?php
declare(strict_types=1);
require_once __DIR__.'/custom_fields.php';
require_once __DIR__.'/custom_field_types.php';

final class CustomFieldConflictException extends RuntimeException {}

function customFieldStorageRecord(PDO $pdo, int $tenant, string $entity, int $record, bool $lock = false): void
{
    $config = customFieldEntity($entity);
    if ($tenant <= 0 || $record <= 0 || !in_array($entity, ['people','placements','companies'], true)
        || !$config || $config['record_table'] !== $entity) throw new InvalidArgumentException('Choose a record in its owning directory.');
    $q = $pdo->prepare('SELECT id FROM '.$entity.' WHERE tenant_id=? AND id=? AND deleted_at IS NULL'.($lock ? ' FOR UPDATE' : ''));
    $q->execute([$tenant, $record]);
    if (!$q->fetchColumn()) throw new InvalidArgumentException('The custom field record is unavailable in this directory.');
}

/** Canonical storage is selected by the destination entity, never by the incoming payload's entity. */
function customFieldStorageDefinition(PDO $pdo,int $tenant,string $entity,int $record,string $key,bool $lock=false): array
{
    $config=customFieldEntity($entity);
    if($tenant<=0||$record<=0||!in_array($entity,['people','placements','companies'],true)||!$config
        ||!preg_match('/^[a-z][a-z0-9_]{0,79}$/D',$key))throw new InvalidArgumentException('Choose a stable custom field in its owning directory.');
    customFieldStorageRecord($pdo,$tenant,$entity,$record,$lock);
    $people=$entity==='people';
    $sql=$people?'SELECT * FROM people_custom_field_defs WHERE tenant_id=? AND field_key=? AND deleted_at IS NULL'
        :'SELECT * FROM custom_fields WHERE tenant_id=? AND field_name=? AND module=? AND deleted_at IS NULL AND is_active=1';
    $q=$pdo->prepare($sql.($lock?' FOR UPDATE':''));$q->execute($people?[$tenant,$key]:[$tenant,$key,$entity]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1)throw new InvalidArgumentException('No unique active custom field exists for this stable key.');
    $def=$rows[0];
    if(!$people)$def+=['field_key'=>$def['field_name'],'required'=>$def['is_required'],'options_json'=>$def['options']];
    return $def;
}

function customFieldStorageCurrent(PDO $pdo,int $tenant,string $entity,int $record,array $def,bool $lock=false): array
{
    $people=$entity==='people';
    // People has a legacy unique key without tenant_id. Refuse a corrupt foreign-owned collision before upsert.
    $q=$pdo->prepare(($people?'SELECT * FROM people_custom_field_values WHERE person_id=? AND field_def_id=?'
        :'SELECT * FROM custom_values WHERE tenant_id=? AND record_id=? AND field_id=?').($lock?' FOR UPDATE':''));
    $q->execute($people?[$record,(int)$def['id']]:[$tenant,$record,(int)$def['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1)throw new RuntimeException('Duplicate custom values require review.');
    if(!$rows)return ['exists'=>false,'value'=>null];
    $r=$rows[0];
    if((int)$r['tenant_id']!==$tenant)throw new RuntimeException('Custom value ownership is inconsistent; review the record.');
    $column=match($def['field_type']){'number'=>'value_number','date'=>'value_date','boolean'=>'value_boolean',default=>'value_text'};
    $raw=$people?$r[$column]:$r['value'];
    if($def['field_type']==='multiselect'&&is_string($raw))$raw=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    return ['exists'=>true,'value'=>customFieldTypedValue(array_replace($def,['required'=>0]),$raw)];
}

/** One atomic field change. Source mode fills an absent value; a clear remains an owned Core decision. */
function customFieldStorageSourceValue(array $def,string $key,mixed $value): mixed
{
    if(!empty($def['pii'])||customFieldDefinitionRoleList($def,'visible')||customFieldDefinitionRoleList($def,'editable')
        ||preg_match('/(?:ssn|tax_id|bank|routing|password|credential|token|secret|dob|birth)/i',$key)){
        throw new InvalidArgumentException('Restricted custom values require their protected owner workflow.');
    }
    $value=customFieldTypedValue($def,$value);
    if($value===null)throw new InvalidArgumentException('An incoming custom value cannot clear Core data.');
    return $value;
}

function customFieldStorageWrite(PDO $pdo,int $tenant,string $entity,int $record,string $key,mixed $value,?callable $audit=null,bool $source=false): array
{
    $outer=$pdo->inTransaction();$savepoint='custom_field_'.bin2hex(random_bytes(6));
    if($outer)$pdo->exec('SAVEPOINT '.$savepoint);else$pdo->beginTransaction();
    try{
        $def=customFieldStorageDefinition($pdo,$tenant,$entity,$record,$key,true);
        $value=$source?customFieldStorageSourceValue($def,$key,$value):customFieldTypedValue($def,$value);
        $current=customFieldStorageCurrent($pdo,$tenant,$entity,$record,$def,true);
        if($current['exists']&&$current['value']!==$value&&$source)throw new CustomFieldConflictException('Core already has a different custom value; review the source conflict.');
        $changed=!$current['exists']||$current['value']!==$value;
        if($changed){
            if($entity==='people'){
                $columns=['value_text'=>null,'value_number'=>null,'value_date'=>null,'value_boolean'=>null];
                $column=match($def['field_type']){'number'=>'value_number','date'=>'value_date','boolean'=>'value_boolean',default=>'value_text'};
                $columns[$column]=is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):(is_bool($value)?(int)$value:$value);
                $pdo->prepare('INSERT INTO people_custom_field_values(tenant_id,person_id,field_def_id,value_text,value_number,value_date,value_boolean,updated_at) VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),value_number=VALUES(value_number),value_date=VALUES(value_date),value_boolean=VALUES(value_boolean),updated_at=NOW()')
                    ->execute([$tenant,$record,(int)$def['id'],...array_values($columns)]);
            }else{
                $stored=is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):(is_bool($value)?(string)(int)$value:$value);
                $pdo->prepare('INSERT INTO custom_values(tenant_id,field_id,record_id,value) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_at=NOW()')
                    ->execute([$tenant,(int)$def['id'],$record,$stored]);
            }
            if($audit!==null)$audit(['entity_type'=>$entity,'record_id'=>$record,'field_id'=>(int)$def['id'],'field_key'=>$key,'source_fill'=>$source]);
        }
        if($outer)$pdo->exec('RELEASE SAVEPOINT '.$savepoint);else$pdo->commit();
        return ['changed'=>$changed,'value'=>$value,'definition_id'=>(int)$def['id']];
    }catch(Throwable $e){if($outer){$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}elseif($pdo->inTransaction())$pdo->rollBack();throw$e;}
}
