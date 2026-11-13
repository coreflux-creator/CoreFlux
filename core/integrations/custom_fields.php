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
    if($entity==='companies'){
        require_once __DIR__.'/../../modules/people/lib/company_source_ownership.php';
        require_once __DIR__.'/../jobdiva/party_scope.php';
        // Use the current directory policy, including during a long source pull.
        $owner=jobdivaPartyDirectoryScope($pdo,$tenant,$pdo->inTransaction())['owner'];
        $q=$pdo->prepare('SELECT deleted_at FROM companies WHERE tenant_id=? AND id=?');$q->execute([$owner,$record]);$deleted=$q->fetchColumn();
        if($deleted!==false&&$deleted!==null)return companySourceWithRecord($pdo,$owner,'companies',$record,static function(array $row)use($pdo,$tenant,$owner,$integration,$mapping,$record,$key,$value):array{
            if(empty($row['_merged_source']))throw new RuntimeException('The company merge changed. Refresh the source identity.',409);
            $state=companySourceState($row,'companies');
            $link=mappingFindExternal($tenant,$integration,'company',$record);
            $source=['system'=>$integration,'workspace_id'=>$tenant,'external_id'=>(string)($link['external_id']??'')];
            if(!$link||$integration!=='jobdiva'||mappingHash(['source'=>$state['source']])!==mappingHash(['source'=>$source])){
                throw new RuntimeException('The merged company custom proposal needs its exact source identity.',409);
            }
            $def=customFieldStorageDefinition($pdo,$owner,'companies',(int)$row['_canonical_company_id'],$key,true);
            $value=customFieldStorageSourceValue($def,$key,$value);
            $proposal=['mapping_id'=>(int)$mapping['id'],'definition_id'=>(int)$def['id'],'value'=>$value];
            $changed=mappingHash(['proposal'=>$state['merged_custom_proposals'][$key]??null])!==mappingHash(['proposal'=>$proposal]);
            if($changed){
                $state['merged_custom_proposals'][$key]=$proposal;$state['revision']++;
                companySourcePersist($pdo,$owner,'companies',$record,[],$state);
            }
            return ['changed'=>false,'proposal_changed'=>$changed,'held_for_merge_review'=>true,'value'=>$value,'definition_id'=>(int)$def['id']];
        },false,true);
    }
    return integrationCustomFieldWrite($pdo,$tenant,$owner,$entity,$integration,$mapping,$record,$key,$value);
}

function integrationCustomFieldWrite(PDO $pdo,int $tenant,int $owner,string $entity,string $integration,array $mapping,int $record,string $key,mixed $value): array
{
    $audit=static function(array $meta)use($pdo,$tenant,$owner,$entity,$mapping,$integration):void{
        if((effectiveTenantIdForModule($entity,$tenant)??$tenant)!==$owner)throw new RuntimeException('The custom field directory owner changed.');
        $meta+=['integration'=>$integration,'mapping_id'=>(int)$mapping['id'],'source_tenant_id'=>$tenant,'owner_tenant_id'=>$owner];
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(?,NULL,"custom_field.value.imported",?,?,NOW())')
            ->execute([$owner,$meta['record_id'],json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    return customFieldStorageWrite($pdo,$owner,$entity,$record,$key,$value,$audit,true);
}
