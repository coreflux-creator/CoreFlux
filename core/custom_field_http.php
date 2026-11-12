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
            $definitions = customFieldFilterDefinitionsForUser(customFieldDefinitions(customFieldDirectoryOwner($tenant,$entity),$entity),$ctx['user'],$manage);
            foreach ($definitions as &$definition) {
                if (!empty($definition['pii']) && !$pii) {
                    $definition['sensitive_hidden'] = true;
                    unset($definition['options_json'],$definition['options']);
                }
            }
            unset($definition);
            api_ok(['entity_type'=>$entity,'definitions'=>$definitions,'fields'=>$definitions,'count'=>count($definitions),'pii_included'=>$pii,'field_access_enforced'=>true]);
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
            api_ok(['entity_type'=>$entity,'record_id'=>$record,'values'=>$values,'field_access_enforced'=>true]);
        }
        if (!in_array($method,['POST','PUT','PATCH'],true)) api_error('Method not allowed',405);
        if (!$can($config['value_manage_permission'])) api_error('Forbidden',403,['required'=>$config['value_manage_permission']]);
        $body = api_json_body();
        if (!is_array($body['values']??null)) api_error('values map required',422);
        $changed = customFieldValuesAction($tenant,$entity,$record,$body['values'],$ctx['user'],$can($config['pii_manage_permission']));
        api_ok(['ok'=>true,'updated'=>$changed]);
    } catch (CustomFieldAccessException $e) { api_error($e->getMessage(),403); }
    catch (InvalidArgumentException|JsonException $e) { api_error($e->getMessage(),422); }
}
