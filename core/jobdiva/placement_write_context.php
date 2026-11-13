<?php
/** Source workspace and current business directories are separate identities. */
declare(strict_types=1);
require_once __DIR__.'/../../modules/people/lib/company_relationships.php';
require_once __DIR__.'/../../modules/placements/lib/ownership.php';
require_once __DIR__.'/contract_projection.php';
require_once __DIR__.'/../integrations/entity_mappings.php';
require_once __DIR__.'/../integrations/stable_mapping.php';
require_once __DIR__.'/../../modules/placements/lib/source_owner.php';

/** Preserve the physical provider pair, including an existing legacy prefix. */
function jobdivaPlacementSourceLink(PDO $pdo,int $workspace,string $type,string $external,?int $placementOwner=null): ?array
{
    if(!$pdo->inTransaction()||!in_array($type,['person','placement'],true))throw new LogicException('A locked staffing source identity is required.');
    $q=$pdo->prepare('SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type=? AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$workspace,$type,$external,'jd:'.$external]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
    if($type==='placement'&&$links){
        $owner=$placementOwner??jobdivaPlacementWriteContext($pdo,$workspace)['placement_owner'];$canonical=null;$primary=null;
        foreach($links as$link){
            $family=placementJobdivaSourceFamily($pdo,$owner,(int)$link['internal_entity_id']);$id=(int)$family['placement']['id'];
            if($canonical!==null&&$canonical!==$id)throw new RuntimeException('Multiple Core placements claim this JobDiva Start. Review the retained identities.',409);
            $canonical=$id;
            if($family['merged'])$primary=$family['primary'];
        }
        if($primary){
            if((int)$primary['tenant_id']!==$workspace||!in_array((int)$primary['id'],array_map('intval',array_column($links,'id')),true))
                throw new JobdivaHistoricalPlacementSource();
            return $primary;
        }
    }
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
    $mapping=jobdivaPlacementSourceLink($pdo,$workspace,'placement',$external,$owner);
    $mapped=$mapping?(int)$mapping['internal_entity_id']:null;
    $q=$pdo->prepare('SELECT id FROM placements WHERE tenant_id=? AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$owner,'jd:'.$external,$external]);$exact=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $physicalCandidates=array_values(array_unique(array_filter([$mapped,$selected,...$exact])));$families=[];$candidates=[];
    foreach($physicalCandidates as$physical){
        $family=placementJobdivaSourceFamily($pdo,$owner,$physical);$canonical=(int)$family['placement']['id'];
        $families[$canonical]=$family;$candidates[]=$canonical;
    }
    $candidates=array_values(array_unique($candidates));
    if(count($candidates)>1)throw new RuntimeException('Multiple Core placements claim this JobDiva Start. Review the retained identities.',409);
    $placement=null;$owned=[];$sourceOwner=null;
    if($candidates){
        $family=$families[$candidates[0]];$placement=$family['placement'];$sourceOwner=$family['source_owner'];
        if($family['merged']&&(!$mapping||(int)($family['primary']['id']??0)!==(int)$mapping['id']))throw new RuntimeException('Only the reviewed source owner can refresh the current placement.',409);
        if(!$family['merged']&&str_starts_with((string)$placement['external_id'],'jd:')&&substr((string)$placement['external_id'],3)!==$external)throw new RuntimeException('The retained placement number conflicts with its JobDiva link. Review the exact source identity.',409);
        if($selected&&!$mapped&&!in_array($selected,$exact,true))throw new RuntimeException('Review and link this placement to the exact JobDiva Start before refreshing it.',409);
        // Internal IDs are globally stable; the accessible placement constrains
        // this conflict query without disclosing another workspace's source IDs.
        if(!$family['merged']&&array_filter($family['links'],static fn($link)=>(int)$link['id']!==(int)($mapping['id']??0)))throw new RuntimeException('This placement already has another JobDiva identity. Review its existing source links.',409);
        placementOwnershipFields($placement['coreflux_overridden_fields']??null);
        $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? FOR UPDATE');$q->execute([$owner,(int)$placement['id']]);
        $owned=jobdivaContractOwnedFields(['placement'=>$placement,'economic_parties'=>$q->fetchAll(PDO::FETCH_ASSOC)],(string)$placement['engagement_type']);
        if(array_intersect($owned,['end_client_name','end_client_company_id']))$owned=array_values(array_unique([...$owned,'end_client_name','end_client_company_id']));
    }
    return ['mapping'=>$mapping,'placement'=>$placement,'owned_fields'=>$owned,'source_owner'=>$sourceOwner];
}
