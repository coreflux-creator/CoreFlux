<?php
/** Locked identities for reviewed JobDiva CSV updates; never match people or companies by name. */
declare(strict_types=1);

function jobdivaReconciliationFetchExactPlacement(PDO $pdo,array $context,string $startId): array
{
    try{
        $identity=jobdivaPlacementWriteIdentity($pdo,$context,$startId);
        $row=$identity['placement'];
        return ['status'=>$row?(empty($row['deleted_at'])?'matched':'archived'):'missing',
            'ids'=>$row?[(int)$row['id']]:[],'mapping_ids'=>$identity['mapping']?[(int)$identity['mapping']['id']]:[],
            'mapping'=>$identity['mapping'],'row'=>$row,'owned_fields'=>$identity['owned_fields'],'source_owner'=>$identity['source_owner'],'error'=>null];
    }catch(RuntimeException $e){
        if(!in_array($e->getCode(),[409,422],true))throw$e;
        return ['status'=>'conflict','ids'=>[],'mapping_ids'=>[],'mapping'=>null,'row'=>null,'owned_fields'=>[],'error'=>$e->getMessage()];
    }
}

function jobdivaReconciliationResolvePerson(PDO $pdo,array $context,array $source,?array $placement,array $owned): array
{
    $result=['id'=>null,'label'=>null,'email'=>null,'matched_by'=>null,'error'=>null,'evidence'=>[]];
    $candidate=(string)($source['candidate_id']??'');$held=in_array('person_id',$owned,true);
    try{
        $link=$candidate!==''&&!$held?jobdivaPlacementSourceLink($pdo,$context['workspace'],'person',$candidate):null;
        $q=$pdo->prepare('SELECT id,external_id,first_name,last_name,email_primary,status,deleted_at,updated_at FROM people WHERE tenant_id=? AND (id IN(?,?) OR external_id=?) ORDER BY id FOR UPDATE');
        $q->execute([$context['people_owner'],$link['internal_entity_id']??0,$placement['person_id']??0,$candidate!==''?'jd:'.$candidate:'']);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$result['evidence']=['link'=>$link,'people'=>$rows];
        $indexed=array_column($rows,null,'id');$exact=$candidate===''?[]:array_values(array_filter($rows,static fn($p)=>$p['external_id']==='jd:'.$candidate));
        if($held||$candidate===''){
            $id=(int)($placement['person_id']??0);$person=$indexed[$id]??null;
            $result['matched_by']=$held?'core_owned':'existing_placement';
        }else{
            if(count($exact)>1)throw new RuntimeException('Multiple people carry this candidate ID. Review the person link.',409);
            $id=(int)($link['internal_entity_id']??($exact[0]['id']??0));$person=$indexed[$id]??null;
            if($link&&$exact&&(int)$exact[0]['id']!==$id)throw new RuntimeException('The saved candidate link and displayed candidate ID disagree.',409);
            if($person&&str_starts_with((string)$person['external_id'],'jd:')&&substr((string)$person['external_id'],3)!==$candidate)throw new RuntimeException('The person number conflicts with its retained candidate link.',409);
            if($placement&&$id>0&&(int)$placement['person_id']!==$id)throw new RuntimeException('Review the existing assignment person link before changing its candidate.',409);
            if($person){
                $q=$pdo->prepare('SELECT m.id FROM external_entity_mappings m JOIN people p ON p.id=m.internal_entity_id AND p.tenant_id=? WHERE m.source_system="jobdiva" AND m.internal_entity_type="person" AND p.id=? AND m.id<>? FOR UPDATE');
                $q->execute([$context['people_owner'],$id,$link['id']??0]);
                if($q->fetchColumn())throw new RuntimeException('This person already has another JobDiva source link. Review that identity first.',409);
            }
            $result['matched_by']='candidate_id';
        }
        if(!$person)throw new RuntimeException('Link the exact candidate to an existing person in this workspace before importing. Names and email addresses do not establish identity.',409);
        if($person['deleted_at']!==null||$person['status']!=='active')throw new RuntimeException('Review and reactivate the linked Core person before importing this assignment.',409);
        $result['id']=(int)$person['id'];$result['label']=trim($person['first_name'].' '.$person['last_name']);$result['email']=$person['email_primary'];
    }catch(RuntimeException $e){if(!in_array($e->getCode(),[409,422],true))throw$e;$result['error']=$e->getMessage();}
    return$result;
}

/** Read-only company proposal. Source IDs are external IDs, never Core company IDs. */
function jobdivaReconciliationCompanyProposal(PDO $pdo,array $context,array $source,?array $placement,array $owned): array
{
    $external=(string)($source['end_client_source_id']??'');$name=trim((string)($source['end_client_name']??''));
    $held=(bool)array_intersect($owned,['end_client_name','end_client_company_id']);
    $result=['requested'=>$external!==''||$name!=='','held'=>$held,'id'=>$placement['end_client_company_id']??null,'name'=>$placement['end_client_name']??null,'external_id'=>$external,'create'=>false,'error'=>null,'evidence'=>[]];
    if(!$result['requested'])return$result;
    try{
        if($held)return$result;
        if($external===''){
            if(empty($placement['end_client_company_id'])||strcasecmp($name,trim((string)$placement['end_client_name']))!==0)throw new RuntimeException('Provide the exact JobDiva end-client company ID or review the existing company link. A name alone cannot create or change that identity.',409);
            $identity=companyIdentityResolve($pdo,$context['company_owner'],(int)$placement['end_client_company_id'],true);
            if(!$identity)throw new RuntimeException('The linked company is unavailable in the current directory.',409);
            $result['id']=$identity['id'];$result['evidence']['identity']=$identity;
        }else{
            $q=$pdo->prepare('SELECT id,tenant_id,external_id,internal_entity_id,content_hash FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="company" AND external_id=? FOR UPDATE');
            $q->execute([$context['workspace'],$external]);$link=$q->fetch(PDO::FETCH_ASSOC)?:null;$result['evidence']['mapping']=$link;
            if($link){
                $identity=companyIdentityResolve($pdo,$context['company_owner'],(int)$link['internal_entity_id'],true);
                if(!$identity)throw new RuntimeException('Review the exact company source link in its current directory.',409);
                $result['id']=$identity['id'];$result['evidence']['identity']=$identity;
            }else{
                if($name==='')throw new RuntimeException('A new source company requires its company name.',409);
                $q=$pdo->prepare('SELECT id FROM companies WHERE tenant_id=? AND name=? ORDER BY id FOR UPDATE');$q->execute([$context['company_owner'],$name]);
                if($q->fetchColumn())throw new RuntimeException('A Core company already uses this name. Review and link its exact source ID before importing.',409);
                $result['id']=null;$result['name']=$name;$result['create']=true;return$result;
            }
        }
        $q=$pdo->prepare('SELECT id,name,deleted_at,coreflux_source_state FROM companies WHERE tenant_id=? AND id=? FOR UPDATE');
        $q->execute([$context['company_owner'],$result['id']]);$company=$q->fetch(PDO::FETCH_ASSOC);
        if(!$company||$company['deleted_at']!==null)throw new RuntimeException('Review the current company before linking it.',409);
        $result['name']=$company['name'];$result['evidence']['company']=$company;
    }catch(RuntimeException $e){if(!in_array($e->getCode(),[409,422],true))throw$e;$result['error']=$e->getMessage();}
    return$result;
}
