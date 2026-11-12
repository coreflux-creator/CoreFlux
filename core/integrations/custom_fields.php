<?php
declare(strict_types=1);
require_once __DIR__.'/../custom_field_storage.php';
require_once __DIR__.'/../sub_tenants.php';

function integrationCustomFieldApply(PDO $pdo,int $tenant,string $integration,array $mapping,int $record,mixed $value): array
{
    $entity=(string)($mapping['target_module']??'');$key=(string)($mapping['target_column']??'');
    if(!in_array($entity,['people','placements','companies'],true)||($mapping['target_table']??null)!=='custom_field_values'
        ||!is_numeric($mapping['id']??null)||(int)$mapping['id']<=0||$integration===''||strlen($integration)>80)
        throw new InvalidArgumentException('A reviewed custom field destination mapping is required.');
    $owner=effectiveTenantIdForModule($entity,$tenant)??$tenant;
    $audit=static function(array $meta)use($pdo,$tenant,$owner,$entity,$mapping,$integration):void{
        if((effectiveTenantIdForModule($entity,$tenant)??$tenant)!==$owner)throw new RuntimeException('The custom field directory owner changed.');
        $meta+=['integration'=>$integration,'mapping_id'=>(int)$mapping['id'],'source_tenant_id'=>$tenant,'owner_tenant_id'=>$owner];
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(?,NULL,"custom_field.value.imported",?,?,NOW())')
            ->execute([$owner,$meta['record_id'],json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    return customFieldStorageWrite($pdo,$owner,$entity,$record,$key,$value,$audit,true);
}
