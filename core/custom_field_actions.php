<?php
declare(strict_types=1);
require_once __DIR__.'/custom_field_storage.php';
require_once __DIR__.'/sub_tenants.php';

final class CustomFieldAccessException extends RuntimeException {}

function customFieldDirectoryOwner(int $tenant, string $entity): int
{
    $config = customFieldEntity($entity);
    if ($tenant <= 0 || !$config) throw new InvalidArgumentException('Choose a custom field directory.');
    return effectiveTenantIdForModule($config['owner_module'], $tenant) ?? $tenant;
}

/** Audit errors abort the business change. Values and options never enter this metadata. */
function customFieldActionAudit(PDO $pdo, int $owner, int $actor, string $event, int $target, array $metadata): void
{
    if ($actor <= 0) throw new InvalidArgumentException('An authenticated operator is required.');
    $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(?,?,?,?,?,NOW())')
        ->execute([$owner,$actor,$event,$target,json_encode($metadata,JSON_THROW_ON_ERROR)]);
}

function customFieldActionTransaction(PDO $pdo, callable $operation): mixed
{
    $outer = $pdo->inTransaction();
    $savepoint = 'custom_action_'.bin2hex(random_bytes(6));
    if ($outer) $pdo->exec('SAVEPOINT '.$savepoint); else $pdo->beginTransaction();
    try {
        $result = $operation();
        if ($outer) $pdo->exec('RELEASE SAVEPOINT '.$savepoint); else $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($outer) {
            $pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
            $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        } elseif ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Caller enforces the entity's management permission; sensitive changes need the additional permission. */
function customFieldDefinitionAction(int $tenant, string $entity, string $action, int $id, array $args, int $actor, bool $canPii): int
{
    if (!in_array($action,['create','update','archive'],true)) throw new InvalidArgumentException('Unknown definition action.');
    $owner = customFieldDirectoryOwner($tenant,$entity);
    $pdo = getDB();
    return customFieldActionTransaction($pdo,static function() use($pdo,$tenant,$owner,$entity,$action,$id,$args,$actor,$canPii): int {
        $config = customFieldEntity($entity);
        $people = $entity === 'people';
        $current = null;
        if ($action !== 'create') {
            $q = $pdo->prepare('SELECT * FROM '.$config['definition_table'].' WHERE tenant_id=? AND id=? AND deleted_at IS NULL'
                .($people ? '' : ' AND module=? AND is_active=1').' FOR UPDATE');
            $q->execute($people ? [$owner,$id] : [$owner,$id,$entity]);
            $current = $q->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new InvalidArgumentException('Custom field definition not found in this directory.');
            if (!$people) $current += ['field_key'=>$current['field_name'],'required'=>$current['is_required'],'options_json'=>$current['options']];
        }
        $data = $action === 'archive' ? [] : customFieldNormalizeDefinitionPayload($args,$action === 'create');
        if ((!empty($current['pii']) || !empty($data['pii'])) && !$canPii) throw new CustomFieldAccessException('Sensitive field management permission is required.');
        if ($current) {
            foreach (['field_key','field_type'] as $immutable) {
                if (isset($data[$immutable]) && $data[$immutable] !== $current[$immutable]) throw new InvalidArgumentException('Custom field keys and types are immutable; create a new reviewed definition.');
            }
        }
        $effective = array_replace($current ?? [],$data);
        if ($action !== 'archive') {
            $columns = $people ? customFieldPeopleDefinitionColumns() : customFieldLegacyColumns();
            foreach (['visible_to_roles_json','editable_by_roles_json'] as $column) {
                if (!empty($data[$column]) && !in_array($column,$columns,true)) throw new InvalidArgumentException('This directory cannot retain the requested field access rules.');
            }
            if (in_array($effective['field_type'],['select','multiselect'],true)) {
                // Validate even when no value is being saved.
                customFieldTypedValue(array_replace($effective,['field_type'=>'multiselect','required'=>0]),[]);
                $options = $effective['options_json'];
                if (is_string($options)) $options = json_decode($options,true,32,JSON_THROW_ON_ERROR);
                if (!$options || count(array_unique($options)) !== count($options)) throw new InvalidArgumentException('Choose a nonempty list of distinct options.');
                if ($current && array_key_exists('options_json',$data)) {
                    $old = json_decode($current['options_json'] ?? '[]',true,32,JSON_THROW_ON_ERROR);
                    if (array_diff($old,$options)) throw new InvalidArgumentException('Retain existing options so saved values keep their meaning.');
                }
            } elseif (!empty($effective['options_json'])) throw new InvalidArgumentException('Only choice fields accept options.');
        }
        if ($action === 'create') $id = customFieldDefinitionCreate($owner,$entity,$args);
        elseif ($action === 'update') customFieldDefinitionUpdate($owner,$entity,$id,$args);
        else customFieldDefinitionDelete($owner,$entity,$id);
        if (customFieldDirectoryOwner($tenant,$entity) !== $owner) throw new RuntimeException('The custom field directory changed.');
        customFieldActionAudit($pdo,$owner,$actor,'custom_field.definition.'.$action,$id,
            ['entity_type'=>$entity,'source_tenant_id'=>$tenant,'fields'=>array_keys($data)]);
        return $id;
    });
}

/** All requested fields save together; unknown or restricted fields reject the entire request. */
function customFieldValuesAction(int $tenant, string $entity, int $record, array $values, array $user, bool $canPii): array
{
    if (!$values || count($values)>200 || array_is_list($values)) throw new InvalidArgumentException('A values map with stable field keys is required.');
    $owner = customFieldDirectoryOwner($tenant,$entity);
    $pdo = getDB();
    return customFieldActionTransaction($pdo,static function() use($pdo,$tenant,$owner,$entity,$record,$values,$user,$canPii): array {
        customFieldStorageRecord($pdo,$owner,$entity,$record,true);
        $changed = [];
        ksort($values,SORT_STRING);
        foreach ($values as $key=>$value) {
            $def = customFieldStorageDefinition($pdo,$owner,$entity,$record,(string)$key,true);
            if ((!empty($def['pii']) && !$canPii) || !customFieldUserCanViewDefinition($user,$def) || !customFieldUserCanEditDefinition($user,$def)) {
                throw new CustomFieldAccessException('Permission to edit every requested custom field is required.');
            }
            $audit = static function(array $metadata) use($pdo,$tenant,$owner,$entity,$user): void {
                if (customFieldDirectoryOwner($tenant,$entity)!==$owner) throw new RuntimeException('The custom field directory changed.');
                customFieldActionAudit($pdo,$owner,(int)($user['id']??0),'custom_field.value.updated',$metadata['record_id'],
                    $metadata+['source_tenant_id'=>$tenant]);
            };
            $result = customFieldStorageWrite($pdo,$owner,$entity,$record,(string)$key,$value,$audit);
            if ($result['changed']) $changed[] = $key;
        }
        return $changed;
    });
}
