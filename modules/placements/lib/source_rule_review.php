<?php
/** Reviewed source replacement. Source slots are not invented provider identities. */
declare(strict_types=1);
require_once __DIR__.'/source_rule_edit.php';
require_once __DIR__.'/../../../core/jobdiva/sync.php';

/** Resolve current policies before taking the parent lock, as ordinary import does. */
function placementSourceRuleLocked(int $workspace,int $placement,callable $work): mixed
{
    $pdo=getDB();
    if($workspace!==(int)currentTenantId())throw new RuntimeException('Placement not found.',404);
    $scope=companyRelationshipScope($pdo,$workspace,'placements');
    return placementWithLockedRecord($scope['owner'],$placement,static function(array $parent,PDO $pdo)use($workspace,$work):mixed{
        $context=jobdivaPlacementWriteContext($pdo,$workspace);
        if($context['placement_owner']!==(int)$parent['tenant_id'])throw new RuntimeException('Placement sharing changed. Load a new review.',409);
        return $work($parent,$pdo,$context);
    });
}

function placementSourceRuleSourceFields(string $kind): array
{
    return array_values(array_diff(placementSourceRuleFields($kind),['vendor_portal_id','contract_storage_object_id','plan_id']));
}

function placementSourceRuleRows(PDO $pdo,int $tenant,int $placement,string $kind): array
{
    $table=placementSourceRuleDefinitions()[$kind]['table']??null;
    if(!$table)throw new InvalidArgumentException('Choose a valid rule set.',422);
    $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');
    $q->execute([$tenant,$placement]);return $q->fetchAll(PDO::FETCH_ASSOC);
}

function placementSourceRuleIdentity(string $kind,array $row): ?string
{
    if($kind==='commission')return !empty($row['user_id'])?'user:'.(int)$row['user_id']:null;
    if($kind==='chain')return !empty($row['company_id'])?'company:'.(int)$row['company_id']:null;
    $field=match($row['referrer_type']??''){'vendor'=>'referrer_company_id','person'=>'referrer_person_id','user'=>'referrer_user_id',default=>null};
    return $field && !empty($row[$field])?$row['referrer_type'].':'.(int)$row[$field]:null;
}

function placementSourceRuleHasLinks(string $kind,array $row): bool
{
    foreach($kind==='chain'?['portal_credentials_ct','contract_storage_object_id','vendor_portal_id']:['plan_id'] as $field)if(!empty($row[$field]))return true;
    return false;
}

/** Exact saved assignment, checked against the existing person and employment model. */
function placementSourceRuleStoredSource(PDO $pdo,array $parent,array $context): array
{
    $tenant=$context['workspace'];$placement=(int)$parent['id'];
    $identity=placementJobdivaSourceIdentity($pdo,$parent);
    if(!$identity)throw new RuntimeException('Review and link this placement to its exact JobDiva Start before returning its rules.',409);
    if($identity['source_workspace_id']!==null&&$identity['source_workspace_id']!==$tenant)throw new RuntimeException('Open the workspace containing this placement\'s JobDiva connection to review its source rules.',409);
    $start=$identity['start_id'];$row=null;
    // The existing planner bounds memory; walk its ID pages to find this exact
    // assignment rather than assuming it is among the latest 5,000 records.
    for($offset=0;;$offset+=5000){
        $plan=jobdivaStoredAssignmentProjectionPlan($tenant,5000,[$start],true,$offset,$context);
        foreach($plan['rows'] as $candidate)if((string)$candidate['start_id']===$start){$row=$candidate;break;}
        if($row || $offset+5000>=(int)$plan['pagination']['total'])break;
    }
    if(!$row || (int)($row['placement_id']??0)!==$placement)throw new RuntimeException('No saved source assignment matches this placement. Refresh its source evidence first.',409);
    if(empty($row['selectable']))throw new RuntimeException('The saved assignment needs review: '.implode(' ',array_slice($row['errors']??[],0,3)),409);
    if(($row['source']['engagement_type']??'')!==$parent['engagement_type'])throw new RuntimeException('Resolve the source employment model before returning these rules.',409);
    $candidateId=(string)($row['source']['candidate_id']??'');
    $personOwner=$context['people_owner'];
    $q=$pdo->prepare('SELECT external_id FROM people WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$personOwner,$parent['person_id']]);$external=$q->fetchColumn();
    $q=$pdo->prepare('SELECT id,internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND internal_entity_type="person" AND external_id IN(?,?) ORDER BY id FOR UPDATE');
    $q->execute([$tenant,$candidateId,'jd:'.$candidateId]);$links=$q->fetchAll(PDO::FETCH_ASSOC);$mapping=$links[0]??null;
    $q=$pdo->prepare('SELECT id FROM external_entity_mappings WHERE source_system="jobdiva" AND internal_entity_type="person" AND internal_entity_id=? AND id<>? LIMIT 1 FOR UPDATE');
    $q->execute([$parent['person_id'],$mapping['id']??0]);$competing=$q->fetchColumn();
    $display=trim((string)$external);
    if($external===false || count($links)>1 || $competing
        || ($mapping && (int)$mapping['internal_entity_id']!==(int)$parent['person_id'])
        || (str_starts_with($display,'jd:')&&substr($display,3)!==$candidateId)
        || (!$mapping&&jobdivaAssignmentIdentityNormaliseId($display)!==$candidateId))throw new RuntimeException('Review the exact JobDiva person link before returning rules.',409);
    if(!empty($parent['jobdiva_job_id']) && (string)$parent['jobdiva_job_id']!==(string)($row['source']['job_id']??''))throw new RuntimeException('The source job differs from this placement. Review its identity first.',409);
    $policy=$pdo->prepare('SELECT tenant_id,module_key,scope_mode FROM tenant_module_scope WHERE tenant_id IN(?,?) AND module_key IN("people","placements","companies","staffing","accounting") ORDER BY tenant_id,module_key FOR UPDATE');
    $policy->execute([$tenant,$context['placement_owner']]);
    $row['__source_identity']=$identity;$row['__directory_context']=$context;$row['__policies']=$policy->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}

/** Same default and configured source writers, restricted to one rule table. */
function placementSourceRuleProject(array $parent,string $kind,array $source): void
{
    $tenant=(int)$parent['tenant_id'];$id=(int)$parent['id'];$payload=$source['__payload'];
    $context=jobdivaPlacementWriteContext(getDB(),$source['__directory_context']['workspace']);
    if($context!==$source['__directory_context']||$context['placement_owner']!==$tenant)throw new RuntimeException('Assignment sharing changed. Load a new review.',409);
    $workspace=$context['workspace'];
    $start=(string)$parent['start_date'];
    match($kind){
        'chain'=>jobdivaSyncUpsertPlacementChain($tenant,$id,!empty($parent['end_client_company_id'])?(int)$parent['end_client_company_id']:null,(string)($parent['end_client_name']??''),$payload,null,$workspace),
        'commission'=>jobdivaSyncUpsertPlacementCommissions($tenant,$id,$start,$payload,$workspace),
        'referral'=>jobdivaSyncUpsertPlacementReferral($tenant,$id,$start,$payload,null,$workspace),
    };
    $mapped=jobdivaApplyPlacementFieldMappings($workspace,$id,(int)$parent['person_id'],
        !empty($parent['end_client_company_id'])?(int)$parent['end_client_company_id']:null,(int)($parent['staffing_job_id']??0),$payload,0,
        ['only_tables'=>[placementSourceRuleDefinitions()[$kind]['table']],'reconcile'=>false,'source_owned_context'=>$context]);
    if($mapped['errors'])throw new RuntimeException('Source rule mappings need review before these rules can be returned.',409);
}

function placementSourceRuleSemanticRows(string $kind,array $rows): array
{
    $out=[];
    foreach($rows as $row){$values=array_intersect_key($row,array_flip(placementSourceRuleSourceFields($kind)));ksort($values);$out[]=$values;}
    usort($out,static fn($a,$b)=>strcmp(serialize($a),serialize($b)));
    return $out;
}

/** Preview writes run only inside a protected rollback scope, never against a provider. */
function placementSourceRuleReplacementPlan(PDO $pdo,array $parent,string $kind,array $context): array
{
    $definition=placementSourceRuleDefinitions()[$kind]??null;
    if(!$definition)throw new InvalidArgumentException('Choose a valid rule set.',422);
    $tenant=(int)$parent['tenant_id'];$placement=(int)$parent['id'];$owned=placementOwnershipFields($parent['coreflux_overridden_fields']??null);
    if(!in_array($definition['field'],$owned,true))throw new RuntimeException('This rule set already follows JobDiva.',409);
    $source=placementSourceRuleStoredSource($pdo,$parent,$context);
    $current=placementSourceRuleRows($pdo,$tenant,$placement,$kind);
    $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_type=? ORDER BY id FOR UPDATE');$q->execute([$tenant,$placement,$kind]);$parties=$q->fetchAll(PDO::FETCH_ASSOC);
    $proposed=JobdivaStoredReviewTransaction::run(static function()use($pdo,$parent,$kind,$definition,$tenant,$placement,$owned,$source):array{
        $pdo->prepare('DELETE FROM '.$definition['table'].' WHERE tenant_id=? AND placement_id=?')->execute([$tenant,$placement]);
        $remaining=array_values(array_diff($owned,[$definition['field']]));
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE tenant_id=? AND id=?')->execute([$remaining?json_encode($remaining,JSON_THROW_ON_ERROR):null,$tenant,$placement]);
        placementSourceRuleProject($parent,$kind,$source);
        $first=placementSourceRuleRows($pdo,$tenant,$placement,$kind);
        placementSourceRuleProject($parent,$kind,$source);
        $second=placementSourceRuleRows($pdo,$tenant,$placement,$kind);
        if(placementSourceRuleSemanticRows($kind,$first)!==placementSourceRuleSemanticRows($kind,$second))throw new RuntimeException('Repeated source projection changes these rules. Resolve the source mapping conflict first.',409);
        foreach($first as $row)foreach(['vendor_portal_id','contract_storage_object_id','plan_id']as$link)if(!empty($row[$link]))throw new RuntimeException('A source mapping attempts to replace a linked portal, document or plan. Review that mapping first.',409);
        return placementSourceRuleSemanticRows($kind,$first);
    },true);
    $issues=[];$references=[];
    foreach($parties as $party)if(empty($party['source_managed']))$issues[]='A participant is managed separately in Core. Review that participant before returning its source rules.';
    foreach($proposed as $index=>$values){
        try {
            placementSourceRuleInput($kind,$values);
            placementSourceRuleValues($kind,$values);
        } catch (InvalidArgumentException $e) { $issues[]='Source values need review: '.$e->getMessage(); }
        if($kind==='commission' && ($issue=placementCommissionTermsIssue($values))) $issues[]=$issue;
        // References created only during simulation are not committed identities.
        $company=$values[$kind==='chain'?'company_id':'referrer_company_id']??null;
        $user=$values[$kind==='commission'?'user_id':'referrer_user_id']??null;
        if($company){$q=$pdo->prepare('SELECT id,name FROM companies WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$context['company_owner'],$company]);$ref=$q->fetch(PDO::FETCH_ASSOC);if(!$ref)$issues[]='Create and review the source company in the company directory before returning these rules.';else$references['company:'.$company]=$ref;}
        if($user){try{placementSourceRuleMember($pdo,(int)currentTenantId(),$tenant,$user);$q=$pdo->prepare('SELECT id,name FROM users WHERE id=?');$q->execute([$user]);$references['user:'.$user]=$q->fetch(PDO::FETCH_ASSOC);}catch(InvalidArgumentException $e){$issues[]=$e->getMessage();}}
        if($kind==='referral' && ($values['referrer_type']??'')==='person') {
            $owner=$context['people_owner'];
            $q=$pdo->prepare('SELECT id,CONCAT_WS(" ",first_name,last_name) AS name FROM people WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$owner,$values['referrer_person_id']??0]);$ref=$q->fetch(PDO::FETCH_ASSOC);
            if(!$ref)$issues[]='Choose a person in the placement directory.';
            else $references['person:'.$ref['id']]=$ref;
        }
        if(!placementSourceRuleIdentity($kind,$values))$issues[]='A source recipient has no resolved Core identity. Resolve that recipient before returning these rules.';
        $proposed[$index]=['key'=>'proposal-'.($index+1),'values'=>$values,'identity'=>placementSourceRuleIdentity($kind,$values)];
    }
    $byRef=array_column($parties,null,'source_ref');$publicCurrent=[];
    foreach($current as $row){$party=$byRef[$kind.':'.$row['id']]??[];$flags=[];$retained=[];
        foreach(['payment_terms_overridden','pwp_overridden','cycle_overridden']as$flag)if(!empty($party[$flag]))$flags[]=$flag;
        foreach(['payment_terms_overridden'=>'payment_terms','pwp_overridden'=>'pwp_enabled','cycle_overridden'=>'operating_cycle_id']as$flag=>$field)if(!empty($party[$flag]))$retained[$field]=$party[$field]??null;
        $publicCurrent[]=['id'=>(int)$row['id'],'values'=>array_intersect_key($row,array_flip(placementSourceRuleSourceFields($kind))),
            'identity'=>placementSourceRuleIdentity($kind,$row),'has_linked_records'=>placementSourceRuleHasLinks($kind,$row)||($kind==='commission'&&placementCommissionHasObligations($pdo,$tenant,$placement,(int)$row['id'])),'retained_participant_settings'=>$flags,'retained_participant_values'=>$retained];
    }
    $token=hash('sha256',serialize(['v'=>2,'parent'=>$parent,'kind'=>$kind,'source'=>$source['__row_token'],
        'source_identity'=>$source['__source_identity'],'directory_context'=>$context,'policies'=>$source['__policies'],
        'current'=>$current,'parties'=>$parties,'proposed'=>$proposed,'references'=>$references,'issues'=>$issues]));
    return ['placement_id'=>$placement,'kind'=>$kind,'label'=>$definition['label'],'source_start_id'=>$source['start_id'],'source_workspace_id'=>$context['workspace'],
        'source_updated_at'=>$source['source_updated_at'],'review_token'=>$token,'current'=>$publicCurrent,'proposed'=>$proposed,
        'references'=>$references,'issues'=>array_values(array_unique($issues)),'selectable'=>!$issues,
        '__raw_current'=>$current,'__source'=>$source,'__parties'=>$parties];
}

function placementSourceRulePublicReview(array $plan): array
{
    unset($plan['__raw_current'],$plan['__source'],$plan['__parties']);return $plan;
}

function placementSourceRuleReview(int $tenant,int $placement,string $kind): array
{
    return JobdivaStoredReviewTransaction::run(static fn()=>placementSourceRuleLocked($tenant,$placement,
        static fn(array $parent,PDO $pdo,array $context)=>placementSourceRulePublicReview(placementSourceRuleReplacementPlan($pdo,$parent,$kind,$context))),true);
}

/** Every proposal needs an explicit new-row or compatible reuse decision. */
function placementSourceRuleMatchPlan(array $plan,array $matches): array
{
    if(!$plan['selectable'])throw new RuntimeException(implode(' ',$plan['issues']),409);
    $keys=array_column($plan['proposed'],'key');$given=array_keys($matches);sort($keys);sort($given);
    if($keys!==$given)throw new InvalidArgumentException('Choose an existing rule or a new rule for every source proposal.',422);
    $current=array_column($plan['current'],null,'id');$used=[];$out=[];
    foreach($plan['proposed']as$proposal){$id=$matches[$proposal['key']];
        if(!is_int($id)||$id<0)throw new InvalidArgumentException('Choose a valid rule match.',422);
        if($id && (!isset($current[$id])||isset($used[$id])||!$proposal['identity']||$current[$id]['identity']!==$proposal['identity']))throw new RuntimeException('A reused rule must have the same resolved recipient and can be selected only once.',409);
        if($id)$used[$id]=true;$out[$proposal['key']]=$id;
    }
    foreach($current as $id=>$row)if(!isset($used[$id])&&($row['has_linked_records']||!empty($row['retained_participant_settings'])))throw new RuntimeException('A rule selected for removal has linked records or separately reviewed participant settings. Preserve it with the same recipient or resolve those links first.',409);
    return $out;
}

function placementSourceRuleReplace(int $tenant,int $placement,string $kind,array $body,array $user): array
{
    $token=$body['review_token']??'';$request=$body['request_id']??'';$matches=$body['matches']??null;
    if(($body['confirm']??'')!=='REPLACE_REVIEWED_SOURCE_RULES'||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)
        ||!is_string($request)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$request)||!is_array($matches))throw new InvalidArgumentException('Review and confirm the source replacement before applying it.',422);
    $definition=placementSourceRuleDefinitions()[$kind]??null;if(!$definition)throw new InvalidArgumentException('Choose a valid rule set.',422);
    ksort($matches);$fingerprint=hash('sha256',serialize([$kind,$token,$matches]));
    return JobdivaStoredReviewTransaction::run(static fn()=>placementSourceRuleLocked($tenant,$placement,
        static function(array $parent,PDO $pdo,array $context)use($placement,$kind,$definition,$token,$request,$matches,$fingerprint,$user):array{
        $tenant=(int)$parent['tenant_id'];
        $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event="placement.source_rules.replaced" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$q->execute([$context['workspace'],$placement,$request]);$prior=$q->fetchColumn();
        if($prior!==false){$receipt=json_decode($prior,true,512,JSON_THROW_ON_ERROR);if(!hash_equals($receipt['request_fingerprint']??'',$fingerprint))throw new RuntimeException('This request already belongs to a different replacement.',409);return $receipt['result']+['replayed'=>true,'currently_follows_source'=>!in_array($definition['field'],placementOwnershipFields($parent['coreflux_overridden_fields']??null),true)];}
        $plan=placementSourceRuleReplacementPlan($pdo,$parent,$kind,$context);
        if(!hash_equals($plan['review_token'],$token))throw new RuntimeException('The source, rule values, identities or linked records changed. Load a new review.',409);
        $selected=placementSourceRuleMatchPlan($plan,$matches);$table=$definition['table'];$current=array_column($plan['__raw_current'],null,'id');
        $reuse=array_values(array_filter($selected));$retire=array_values(array_diff(array_keys($current),$reuse));
        // Clear occupied chain positions while preserving row IDs and attached
        // records. The final values and audit commit together or all roll back.
        if($kind==='chain'){
            $max=0;foreach($current as$row)$max=max($max,(int)$row['position']);foreach($plan['proposed']as$p)$max=max($max,(int)$p['values']['position']);
            if($max+count($current)+1>2147483647)throw new RuntimeException('Chain positions need review before replacement.',409);
            foreach($current as$id=>$row)$pdo->prepare('UPDATE '.$table.' SET position=? WHERE tenant_id=? AND placement_id=? AND id=?')->execute([++$max,$tenant,$placement,$id]);
        }
        foreach($retire as$id)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=? AND placement_id=? AND id=?')->execute([$tenant,$placement,$id]);
        $applied=[];
        foreach($plan['proposed']as$proposal){$values=$proposal['values'];$id=$selected[$proposal['key']];
            if($kind==='commission' && $id)placementCommissionProtectConsumed($pdo,$tenant,$placement,$id,$values);
            if($id){$pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn($field)=>$field.'=?',array_keys($values))).' WHERE tenant_id=? AND placement_id=? AND id=?')->execute(array_merge(array_values($values),[$tenant,$placement,$id]));}
            else{$pdo->prepare('INSERT INTO '.$table.' (tenant_id,placement_id,'.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values)+2,'?')).')')->execute(array_merge([$tenant,$placement],array_values($values)));$id=(int)$pdo->lastInsertId();}
            $applied[$proposal['key']]=$id;
        }
        $owned=placementOwnershipFields($parent['coreflux_overridden_fields']??null);$remaining=array_values(array_diff($owned,[$definition['field']]));
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE tenant_id=? AND id=?')->execute([$remaining?json_encode($remaining,JSON_THROW_ON_ERROR):null,$tenant,$placement]);
        $beforeReplay=placementSourceRuleRows($pdo,$tenant,$placement,$kind);
        placementSourceRuleProject($parent,$kind,$plan['__source']);
        $afterReplay=placementSourceRuleRows($pdo,$tenant,$placement,$kind);
        if(array_column($beforeReplay,'id')!==array_column($afterReplay,'id')||placementSourceRuleSemanticRows($kind,$beforeReplay)!==placementSourceRuleSemanticRows($kind,$afterReplay))throw new RuntimeException('Source replay would change the reviewed replacement. No replacement was saved.',409);
        $graph=placementEconomicsReconcile($tenant,$placement);
        if(empty($graph['available'])||$graph['errors'])throw new RuntimeException('The replacement could not be reconciled to the contract. No replacement was saved.',503);
        $result=['placement_id'=>$placement,'kind'=>$kind,'applied'=>$applied,'retired'=>$retire,'source_control_released'=>true];
        placementRequiredAudit('placement.source_rules.replaced',['request_id'=>$request,'request_fingerprint'=>$fingerprint,
            'review'=>placementSourceRulePublicReview($plan),'matches'=>$selected,'result'=>$result,'owned_fields_before'=>$owned,'owned_fields_after'=>$remaining,
            'actor_user_id'=>$user['id']??null,'approved_rates_changed'=>false,'financial_execution'=>false],$placement);
        return $result;
    }));
}
