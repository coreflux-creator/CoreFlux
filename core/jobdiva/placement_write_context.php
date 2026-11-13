<?php
/** Source workspace and current business directories are separate identities. */
declare(strict_types=1);
require_once __DIR__.'/../../modules/people/lib/company_relationships.php';
require_once __DIR__.'/../../modules/placements/lib/ownership.php';
require_once __DIR__.'/contract_projection.php';
require_once __DIR__.'/../integrations/entity_mappings.php';
require_once __DIR__.'/../integrations/stable_mapping.php';

/** Preserve the physical provider pair, including an existing legacy prefix. */
function jobdivaPlacementSourceLink(PDO $pdo,int $workspace,string $type,string $external): ?array
{
    if(!$pdo->inTransaction()||!in_array($type,['person','placement'],true))throw new LogicException('A locked staffing source identity is required.');
    $q=$pdo->prepare('SELECT id,tenant_id,source_system,internal_entity_type,external_id,internal_entity_id,content_hash,sync_status FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type=? AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$workspace,$type,$external,'jd:'.$external]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($links)>1)throw new RuntimeException('Multiple saved links claim this JobDiva '.$type.'. Review the retained source identities.',409);
    return$links[0]??null;
}

function jobdivaPlacementWriteContext(PDO $pdo,int $workspace): array
{
    if(!$pdo->inTransaction())throw new LogicException('Assignment projection requires its complete write transaction.');
    $placement=companyRelationshipScope($pdo,$workspace,'placements');
    $context=['workspace'=>$workspace,'placement_owner'=>$placement['owner'],'company_owner'=>$placement['company_owner']];
    foreach(['people','staffing','accounting']as$module){
        $scope=companyRelationshipScope($pdo,$workspace,$module);
        $context[$module.'_owner']=$scope['owner'];
    }
    // Placements and their worker joins currently share a directory. Supporting
    // a different People owner requires updating the complete consuming graph.
    if($context['people_owner']!==$context['placement_owner'])throw new RuntimeException('Review People and Placements sharing before importing this assignment.',409);
    if(jobdivaPartyDirectoryScope($pdo,$placement['owner'],true)['owner']!==$context['company_owner'])throw new RuntimeException('This shared assignment cannot use a private company directory.',409);
    foreach(['staffing','accounting']as$module){
        if(companyRelationshipScope($pdo,$placement['owner'],$module)['owner']!==$context[$module.'_owner'])throw new RuntimeException('This shared assignment cannot use a private '.$module.' directory.',409);
    }
    return$context;
}

/** Resolve an exact Start before related records can change; never adopt by name. */
function jobdivaPlacementWriteIdentity(PDO $pdo,array $context,string $external,mixed $selected=null): array
{
    if(!$pdo->inTransaction()||trim($external)==='')throw new LogicException('A locked exact assignment identity is required.');
    $workspace=$context['workspace'];$owner=$context['placement_owner'];
    $selected=companyRelationshipId($selected);
    $mapping=jobdivaPlacementSourceLink($pdo,$workspace,'placement',$external);
    $mapped=$mapping?(int)$mapping['internal_entity_id']:null;
    if($selected&&$mapped&&$selected!==$mapped)throw new RuntimeException('The selected assignment conflicts with its retained JobDiva link.',409);
    $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$owner,'jd:'.$external,$external]);$exact=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $candidates=array_values(array_unique(array_filter([$mapped,$selected,...$exact])));
    if(count($candidates)>1)throw new RuntimeException('Multiple Core placements claim this JobDiva Start. Review the retained identities.',409);
    $placement=null;$owned=[];
    if($candidates){
        $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$candidates[0]]);$placement=$q->fetch(PDO::FETCH_ASSOC);
        if(!$placement)throw new RuntimeException('The linked placement is unavailable in its current directory.',409);
        if(str_starts_with((string)$placement['external_id'],'jd:')&&substr((string)$placement['external_id'],3)!==$external)throw new RuntimeException('The retained placement number conflicts with its JobDiva link. Review the exact source identity.',409);
        if($selected&&!$mapped&&!in_array($selected,$exact,true))throw new RuntimeException('Review and link this placement to the exact JobDiva Start before refreshing it.',409);
        // Internal IDs are globally stable; the accessible placement constrains
        // this conflict query without disclosing another workspace's source IDs.
        $q=$pdo->prepare("SELECT m.id FROM external_entity_mappings m JOIN placements p ON p.id=m.internal_entity_id AND p.tenant_id=? WHERE m.source_system='jobdiva' AND m.internal_entity_type='placement' AND p.id=? AND m.id<>? FOR UPDATE");
        $q->execute([$owner,(int)$placement['id'],(int)($mapping['id']??0)]);
        if($q->fetchColumn())throw new RuntimeException('This placement already has another JobDiva identity. Review its existing source links.',409);
        placementOwnershipFields($placement['coreflux_overridden_fields']??null);
        $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? FOR UPDATE');$q->execute([$owner,(int)$placement['id']]);
        $owned=jobdivaContractOwnedFields(['placement'=>$placement,'economic_parties'=>$q->fetchAll(PDO::FETCH_ASSOC)],(string)$placement['engagement_type']);
        if(array_intersect($owned,['end_client_name','end_client_company_id']))$owned=array_values(array_unique([...$owned,'end_client_name','end_client_company_id']));
    }
    return ['mapping'=>$mapping,'placement'=>$placement,'owned_fields'=>$owned];
}
