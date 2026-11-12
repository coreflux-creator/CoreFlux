<?php
declare(strict_types=1);
require_once __DIR__.'/api_bootstrap.php';
require_once __DIR__.'/custom_field_actions.php';

function customFieldHttpContext(?string $forcedEntity = null): array
{
    $ctx = api_require_auth();
    $entity = $forcedEntity ?? trim((string)api_query('entity_type',''));
    $config = customFieldEntity($entity);
    if (!$config) api_error('Custom field entity not found',404);
    $user = $ctx['user'];
    $can = static fn(string $permission): bool => $permission !== '' && rbac_legacy_can($user,$permission);
    return [$ctx,$entity,$config,$can];
}

function customFieldDefinitionsHttp(?string $forcedEntity = null): void
{
    [$ctx,$entity,$config,$can] = customFieldHttpContext($forcedEntity);
    $tenant = (int)$ctx['tenant_id'];
    $manage = $can($config['manage_permission']);
    $method = api_method();
    try {
        if ($method === 'GET') {
            if (!$manage && !$can($config['view_permission'])) api_error('Forbidden',403);
            $pii = $can($config['pii_permission']);
            $owner = customFieldDirectoryOwner($tenant,$entity);
            $definitions = customFieldFilterDefinitionsForUser(customFieldDefinitions($owner,$entity),$ctx['user'],$manage);
            foreach ($definitions as &$definition) {
                if (!empty($definition['pii']) && !$pii) {
                    $definition['sensitive_hidden'] = true;
                    unset($definition['options_json'],$definition['options']);
                }
                $definition['field_access']['editable'] = !empty($definition['field_access']['editable'])
                    && $can($config['value_manage_permission'])
                    && (empty($definition['pii']) || $can($config['pii_manage_permission']));
            }
            unset($definition);
            api_ok(['entity_type'=>$entity,'owner_tenant_id'=>$owner,'definitions'=>$definitions,'fields'=>$definitions,'count'=>count($definitions),'pii_included'=>$pii,'field_access_enforced'=>true,
                'can_manage_definitions'=>$manage,'can_manage_sensitive'=>$can($config['pii_manage_permission'])]);
        }
        if (!in_array($method,['POST','PATCH','DELETE'],true)) api_error('Method not allowed',405);
        if (!$manage) api_error('Forbidden',403,['required'=>$config['manage_permission']]);
        $action = match($method) {'POST'=>'create','PATCH'=>'update','DELETE'=>'archive'};
        $body = $method === 'DELETE' ? [] : api_json_body();
        $id = customFieldDefinitionAction($tenant,$entity,$action,(int)api_query('id',0),$body,(int)$ctx['user']['id'],$can($config['pii_manage_permission']));
        api_ok(['id'=>$id,'ok'=>true],$method==='POST'?201:200);
    } catch (CustomFieldAccessException $e) { api_error($e->getMessage(),403); }
    catch (InvalidArgumentException|JsonException $e) { api_error($e->getMessage(),422); }
    catch (PDOException $e) {
        if ((string)$e->getCode()==='23000') api_error('The field key is already reserved or its stored relationships need review.',409);
        throw $e;
    }
}

function customFieldValuesHttp(?string $forcedEntity = null): void
{
    [$ctx,$entity,$config,$can] = customFieldHttpContext($forcedEntity);
    $tenant = (int)$ctx['tenant_id'];
    $owner = customFieldDirectoryOwner($tenant,$entity);
    $record = (int)api_query('record_id',api_query('person_id',0));
    $method = api_method();
    try {
        if ($method === 'GET') {
            if (!$can($config['view_permission'])) api_error('Forbidden',403,['required'=>$config['view_permission']]);
            customFieldStorageRecord(getDB(),$owner,$entity,$record);
            $values = customFieldFilterValuesForUser(customFieldValues($owner,$entity,$record,$can($config['pii_permission'])),$ctx['user']);
            api_ok(['entity_type'=>$entity,'owner_tenant_id'=>$owner,'record_id'=>$record,'values'=>$values,'field_access_enforced'=>true,
                'can_manage_values'=>$can($config['value_manage_permission'])]);
        }
        if (!in_array($method,['POST','PUT','PATCH'],true)) api_error('Method not allowed',405);
        if (!$can($config['value_manage_permission'])) api_error('Forbidden',403,['required'=>$config['value_manage_permission']]);
        $body = api_json_body();
        if (!is_array($body['values']??null)) api_error('values map required',422);
        $expected = null; $expectedOwner = null;
        if (array_key_exists('expected_values',$body)) {
            if (!is_array($body['expected_values'])) api_error('Original values map required',422);
            $expected = $body['expected_values'];
            $expectedOwner = filter_var($body['expected_owner_tenant_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($expectedOwner === false) api_error('Original directory required',422);
        }
        $changed = customFieldValuesAction($tenant,$entity,$record,$body['values'],$ctx['user'],$can($config['pii_manage_permission']),$expected,$expectedOwner);
        api_ok(['ok'=>true,'updated'=>$changed]);
    } catch (CustomFieldAccessException $e) { api_error($e->getMessage(),403); }
    catch (CustomFieldConflictException $e) { api_error($e->getMessage(),409); }
    catch (InvalidArgumentException|JsonException $e) { api_error($e->getMessage(),422); }
}
