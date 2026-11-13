<?php
/** Current JobDiva ownership follows a reviewed merge, never a rewritten provider pair. */
declare(strict_types=1);
require_once __DIR__.'/identity.php';
require_once __DIR__.'/../../../core/integrations/identity.php';

final class JobdivaHistoricalPlacementSource extends RuntimeException
{
    public function __construct(){parent::__construct('This retained historical source is not the reviewed owner of current placement updates.',409);}
}

function placementJobdivaSourceFamily(PDO $pdo,int $owner,int $id): array
{
    if(!$pdo->inTransaction()||$owner<=0||$id<=0)throw new LogicException('Retained placement sources require their scoped write transaction.');
    $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$placement=$q->fetch(PDO::FETCH_ASSOC);
    if(!$placement)throw new RuntimeException('The linked placement is unavailable in its current directory.',409);
    $q=$pdo->prepare('SELECT * FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND record_id=? FOR UPDATE');$q->execute([$owner,$id]);$number=$q->fetch(PDO::FETCH_ASSOC);
    if($number&&((int)$number['canonical_record_id']!==$id||$number['aliased_at']!==null)){
        $identity=placementIdentityResolve($pdo,$owner,$id,true);
        if(!$identity)throw new RuntimeException('Review the retained placement alias before source refresh.',409);
        $placement=$identity['placement'];
    }
    $canonical=(int)$placement['id'];
    $q=$pdo->prepare('SELECT record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND canonical_record_id=? ORDER BY record_id FOR UPDATE');$q->execute([$owner,$canonical]);
    $family=array_values(array_unique([$canonical,...array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN))]));sort($family);
    // tenant-leak-allow: the accessible placement owner and globally unique target constrain this receipt lookup; no foreign receipt is exposed.
    $q=$pdo->prepare('SELECT id,tenant_id,target_id,meta_json FROM audit_log WHERE event="placement.identity_merged" AND (target_id=? OR JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.result.retired_id"))=?) AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.directory_owner_id"))=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $q->execute([$canonical,$canonical,$owner]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    $merged=count($family)>1;
    if($saved&&!$merged)throw new RuntimeException('The reviewed placement family is incomplete. Review its permanent identities.',409);
    if($merged){
        foreach($family as$physical){
            $resolved=placementIdentityResolve($pdo,$owner,$physical,true);
            if(!$resolved||$resolved['id']!==$canonical)throw new RuntimeException('Review the incomplete placement family before source refresh.',409);
        }
    }
    $marks=implode(',',array_fill(0,count($family),'?'));
    // tenant-leak-allow: globally unique physical IDs in the authorized directory constrain these retained pairs; callers do not expose another workspace's source IDs.
    $q=$pdo->prepare('SELECT m.* FROM external_entity_mappings m JOIN placements p ON p.id=m.internal_entity_id AND p.tenant_id=? WHERE m.source_system="jobdiva" AND m.internal_entity_type="placement" AND p.id IN('.$marks.') ORDER BY m.id FOR UPDATE');
    $q->execute([$owner,...$family]);$links=$q->fetchAll(PDO::FETCH_ASSOC);$primary=null;$evidence=null;
    if($merged){
        $receipt=$saved?json_decode($saved['meta_json'],true,512,JSON_THROW_ON_ERROR):null;
        if(!$receipt||($receipt['instance_id']??null)!==integrationInstanceId()||($receipt['record_type']??null)!=='placements'
            ||($receipt['result']['survivor_id']??null)!==$canonical||($receipt['configuration_placement_id']??null)!==$canonical
            ||($receipt['history_policy']??null)!=='retain_original_rows'||!is_array($receipt['source_options']??null))
            throw new RuntimeException('A current reviewed source-owner receipt is required for this placement family.',409);
        $expected=$receipt['source_options'];$current=[];
        foreach($links as$link)$current[(int)$link['id']]=[(int)$link['tenant_id'],(string)$link['external_id'],(int)$link['internal_entity_id']];
        if(count($current)!==count($expected))throw new RuntimeException('The retained source bindings changed after merge review.',409);
        foreach($expected as$option){
            $mapping=(int)($option['mapping_id']??0);
            if(($current[$mapping]??null)!==[(int)($option['source_workspace_id']??0),(string)($option['external_id']??''),(int)($option['physical_placement_id']??0)]
                ||(int)$saved['tenant_id']!==(int)($option['source_workspace_id']??0))throw new RuntimeException('The retained source binding no longer matches its reviewed owner.',409);
        }
        $chosen=$receipt['primary_jobdiva_mapping_id']??null;
        foreach($links as$link)if((int)$link['id']===$chosen)$primary=$link;
        $chosenOption=array_values(array_filter($expected,static fn($o)=>($o['mapping_id']??null)===$chosen));
        if(($links&&(!$primary||empty($chosenOption[0]['eligible'])))||(!$links&&$chosen!==null))throw new RuntimeException('Review the placement family source owner before refreshing it.',409);
        $evidence=['merge_receipt_id'=>(int)$saved['id'],'primary_mapping_id'=>$chosen,'placement_id'=>$canonical,'family_ids'=>$family];
    }
    return ['placement'=>$placement,'family_ids'=>$family,'merged'=>$merged,'links'=>$links,'primary'=>$primary,'source_owner'=>$evidence];
}

/** Refresh the selected source snapshot while preserving its original physical binding. */
function jobdivaPlacementBindRetained(int $workspace,string $external,int $canonical,array $payload,?int $actor,bool $requiredObservation=false): array
{
    $pdo=getDB();$context=jobdivaPlacementWriteContext($pdo,$workspace);
    $identity=jobdivaPlacementWriteIdentity($pdo,$context,str_starts_with($external,'jd:')?substr($external,3):$external,$canonical);
    $mapping=$identity['mapping'];
    if((int)($identity['placement']['id']??0)!==$canonical||($mapping&&$mapping['external_id']!==$external))
        throw new RuntimeException('The reviewed placement source changed before its snapshot could be saved.',409);
    if(!$identity['source_owner'])return mappingBindStable($workspace,'jobdiva','placement',$external,$canonical,$payload,'pull',$actor,$requiredObservation);
    // All family pairs were locked and checked against the merge receipt. Refresh
    // only this exact pair; every other historical pair in the family is retained.
    $snapshot=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$hash=mappingHash($payload);
    $changed=$hash!==$mapping['content_hash'];
    $q=$pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=?,content_hash=?,direction="pull",sync_status="ok",last_error=NULL,last_seen_at=NOW(),last_synced_at=IF(?=1,NOW(),last_synced_at) WHERE tenant_id=? AND id=? AND external_id=? AND internal_entity_id=?');
    $q->execute([$snapshot,$hash,(int)$changed,$workspace,$mapping['id'],$external,$mapping['internal_entity_id']]);
    if($changed){
        $q=$pdo->prepare('INSERT INTO entity_sync_history(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id,direction,payload_before,payload_after,content_hash_before,content_hash_after,actor_user_id) VALUES(?,"jobdiva","placement",?,?,"pull",?,?,?,?,?)');
        $q->execute([$workspace,$mapping['internal_entity_id'],$external,$mapping['payload_snapshot'],$snapshot,$mapping['content_hash'],$hash,$actor]);
    }
    require_once __DIR__.'/../../../core/integrations/payload_field_index.php';
    integrationPayloadFieldIndexRecord($workspace,'jobdiva','placement',$payload,true);
    return mappingFindInternal($workspace,'jobdiva','placement',$external)+['changed'=>$changed];
}
