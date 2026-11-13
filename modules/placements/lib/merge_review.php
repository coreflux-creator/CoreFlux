<?php
/** Explicit placement consolidation. Original identities and historical activity stay on their physical rows. */
declare(strict_types=1);
require_once __DIR__.'/identity.php';
require_once __DIR__.'/../../../core/jobdiva/active_status_review.php';
require_once __DIR__.'/../../../core/encryption.php';
require_once __DIR__.'/../../../core/integrations/identity.php';

function placementMergeFields(): array
{
    return ['title','notes','worksite_city','worksite_state','worksite_country','remote_policy',
        'branch','service_line','workers_comp_class','department','cost_center'];
}

function placementMergeActor(PDO $pdo,int $workspace,int $actor): array
{
    $auth=jobdivaActiveRestoreActor($pdo,$workspace,$actor);
    if(!RBAC::hasPermission($auth['user'],'placements.financials.manage'))
        throw new RuntimeException('Placement consolidation requires current placement and financial management access.',403);
    return $auth;
}

/** Exact content hashes let even binary historical evidence participate without exposing it in the review. */
function placementMergeRowsHash(array $rows): string
{
    $hashes=array_map(static fn($row)=>hash('sha256',serialize($row)),$rows);sort($hashes,SORT_STRING);
    return hash('sha256',implode('\n',$hashes));
}

/** Discover all physical placement references; none are moved or deleted by consolidation. */
function placementMergeHistory(PDO $pdo,int $owner,array $family): array
{
    if(!$pdo->inTransaction()||!$family)throw new LogicException('Placement history requires the protected merge transaction.');
    $q=$pdo->query('SELECT c.TABLE_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
        ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME="placement_id" AND c.DATA_TYPE IN("bigint","int","mediumint","smallint")
            AND t.TABLE_TYPE="BASE TABLE" ORDER BY c.TABLE_NAME');
    $tables=$q->fetchAll(PDO::FETCH_COLUMN);$result=[];$marks=implode(',',array_fill(0,count($family),'?'));
    foreach($tables as$table){
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('An unsupported history table prevents a complete merge review.',503);
        // tenant-leak-allow: globally unique placement IDs are constrained by their authorized owner; only totals and hashes leave this history reader, and no dependent row is changed
        $q=$pdo->prepare('SELECT child.* FROM `'.$table.'` child JOIN placements p ON p.id=child.placement_id
            WHERE p.tenant_id=? AND p.id IN('.$marks.') FOR UPDATE');$q->execute([$owner,...$family]);
        $hashes=[];$counts=[];
        while($row=$q->fetch(PDO::FETCH_ASSOC)){$hashes[]=hash('sha256',serialize($row));$id=(int)$row['placement_id'];$counts[$id]=($counts[$id]??0)+1;}
        sort($hashes,SORT_STRING);ksort($counts,SORT_NUMERIC);
        $result[$table]=['count'=>count($hashes),'by_placement'=>$counts,'hash'=>hash('sha256',implode('\n',$hashes))];
    }
    return $result;
}

function placementMergeSnapshot(int $workspace,int $actor,int $survivor,int $retired): array
{
    if(!JobdivaStoredReviewTransaction::active())throw new LogicException('Placement consolidation needs its protected review transaction.');
    if($survivor<=0||$retired<=0||$survivor===$retired)throw new InvalidArgumentException('Select two distinct current placements.',422);
    $pdo=getDB();$auth=placementMergeActor($pdo,$workspace,$actor);$scope=jobdivaPlacementWriteContext($pdo,$workspace);$owner=$scope['placement_owner'];
    // Authentication refreshes activity timestamps between preview and apply.
    // Preserve every access-control value, without making ordinary session use stale a review.
    if($auth['membership'])$auth['membership']=array_diff_key($auth['membership'],array_flip(['last_active_at','created_at','updated_at']));
    $auth['grants']=array_map(static fn($grant)=>array_diff_key($grant,array_flip(['created_at','updated_at'])),$auth['grants']);
    $instance=integrationInstanceId();if(!$instance)throw new RuntimeException('A permanent Core installation identity is required before consolidation.',409);
    $ids=[$survivor,$retired];sort($ids,SORT_NUMERIC);$records=[];$identities=[];
    foreach($ids as$id){
        $identity=placementIdentityResolve($pdo,$owner,$id,true);
        if(!$identity||$identity['merged'])throw new RuntimeException('Choose two current placements with permanent numbers in this directory.',409);
        placementOwnershipFields($identity['placement']['coreflux_overridden_fields']??null);
        $records[$id]=$identity['placement'];$identities[$id]=$identity;
    }
    if((int)$records[$survivor]['person_id']!==(int)$records[$retired]['person_id'])
        throw new RuntimeException('Review the worker identities first. Placements for different people cannot be consolidated by matching names.',409);
    $q=$pdo->prepare('SELECT id,first_name,last_name,status,deleted_at,external_id FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$scope['people_owner'],$records[$survivor]['person_id']]);$person=$q->fetch(PDO::FETCH_ASSOC);
    if(!$person||$person['deleted_at']!==null)throw new RuntimeException('The placed worker is unavailable in the current People directory.',409);
    $q=$pdo->prepare('SELECT * FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND canonical_record_id IN(?,?) ORDER BY record_id FOR UPDATE');
    $q->execute([$owner,$survivor,$retired]);$numbers=$q->fetchAll(PDO::FETCH_ASSOC);$family=array_map('intval',array_column($numbers,'record_id'));
    foreach($family as$id)if(!in_array($id,$ids,true)){
        $alias=placementIdentityResolve($pdo,$owner,$id,true);
        if(!$alias||!in_array($alias['id'],$ids,true))throw new RuntimeException('Review the incomplete existing placement aliases before consolidation.',409);
    }
    $marks=implode(',',array_fill(0,count($family),'?'));
    // tenant-leak-allow: inspect only bindings of accessible, globally unique placement IDs; foreign-workspace source ownership blocks consolidation before any source identifiers are exposed
    $q=$pdo->prepare('SELECT * FROM external_entity_mappings WHERE internal_entity_type="placement" AND internal_entity_id IN('.$marks.') ORDER BY id FOR UPDATE');
    $q->execute($family);$mappings=$q->fetchAll(PDO::FETCH_ASSOC);$sourceOptions=[];
    foreach($mappings as$m){
        if((int)$m['tenant_id']!==$workspace)throw new RuntimeException('A placement has a source binding in another workspace. Review its ownership before consolidation.',409);
        if($m['source_system']==='jobdiva'){
            $external=jobdivaAssignmentIdentityNormaliseId($m['external_id']);$valid=false;
            try{$payload=json_decode((string)$m['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
                $valid=is_array($payload)&&!array_is_list($payload)&&preg_match('/^[0-9]+$/D',$external)
                    &&!empty(jobdivaAssignmentValidate(jobdivaAssignmentSanitisePayload($payload,$external),$external)['valid'])&&$m['sync_status']==='ok';
            }catch(Throwable){}
            $sourceOptions[]=['mapping_id'=>(int)$m['id'],'source_workspace_id'=>(int)$m['tenant_id'],
                'external_id'=>$m['external_id'],'physical_placement_id'=>(int)$m['internal_entity_id'],'eligible'=>(bool)$valid];
        }
    }
    $q=$pdo->prepare('SELECT * FROM integration_deliveries WHERE entity_type="placement" AND entity_tenant_id=? AND entity_id IN('.$marks.') ORDER BY id FOR UPDATE');
    $q->execute([$owner,...$family]);$deliveries=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($deliveries as$d)if(in_array($d['state'],['sending','uncertain'],true))
        throw new RuntimeException('Reconcile the in-flight placement delivery before consolidating its identity.',409);
    $history=placementMergeHistory($pdo,$owner,$family);
    $q=$pdo->prepare('SELECT f.*,v.record_id AS value_record_id,v.value AS stored_value FROM custom_fields f LEFT JOIN custom_values v
        ON v.field_id=f.id AND v.tenant_id=f.tenant_id AND v.record_id IN('.$marks.')
        WHERE f.tenant_id=? AND f.module="placements" ORDER BY f.id,v.record_id FOR UPDATE');$q->execute([...$family,$owner]);$custom=$q->fetchAll(PDO::FETCH_ASSOC);
    $fields=[];foreach(placementMergeFields()as$field)$fields[$field]=[
        'survivor'=>$records[$survivor][$field]??null,'retired'=>$records[$retired][$field]??null,
        'differs'=>($records[$survivor][$field]??null)!==($records[$retired][$field]??null)];
    $configuration=[];foreach(['status','start_date','end_date','actual_end_date','engagement_type','accounting_entity_id','client_id','end_client_company_id','staffing_job_id',
        'client_approver_name','client_approver_email','billing_operating_cycle_id','ap_operating_cycle_id','payroll_operating_cycle_id']as$field)
        $configuration[$field]=['survivor'=>$records[$survivor][$field]??null,'retired'=>$records[$retired][$field]??null];
    $snapshot=['instance'=>$instance,'workspace'=>$workspace,'actor'=>$actor,'auth'=>$auth,'scope'=>$scope,'records'=>$records,'person'=>$person,
        'numbers'=>$numbers,'mappings'=>$mappings,'deliveries_hash'=>placementMergeRowsHash($deliveries),'history'=>$history,'custom_hash'=>placementMergeRowsHash($custom)];
    return ['hash'=>hash('sha256',serialize($snapshot)),'scope'=>$scope,'records'=>$records,'numbers'=>$numbers,'family'=>$family,'source_options'=>$sourceOptions,
        'history'=>$history,'public'=>['workspace_id'=>$workspace,'placement_owner_id'=>$owner,'survivor_id'=>$survivor,'retired_id'=>$retired,
            'survivor_number'=>$identities[$survivor]['placement_number'],'retired_number'=>$identities[$retired]['placement_number'],
            'person'=>['id'=>(int)$person['id'],'name'=>trim($person['first_name'].' '.$person['last_name'])],
            'fields'=>$fields,'current_configuration'=>$configuration,'source_options'=>$sourceOptions,
            'retained_history'=>array_map(static fn($h)=>array_diff_key($h,['hash'=>true]),$history),
            'permanent_alias_count'=>count($family),'provider_pairs_retained'=>count($mappings),'custom_values_retained'=>count(array_filter($custom,static fn($r)=>$r['value_record_id']!==null))]];
}

function placementMergeReview(int $workspace,int $actor,int $survivor,int $retired): array
{
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$survivor,$retired):array{
        $snapshot=placementMergeSnapshot($workspace,$actor,$survivor,$retired);
        return $snapshot['public']+['review_token'=>$snapshot['hash'],'dry_run'=>true,
            'history_policy'=>'retain_original_rows','configuration_policy'=>'survivor_remains_current'];
    },true);
}

function placementMergeApply(int $workspace,int $actor,array $body): array
{
    $survivor=$body['survivor_id']??null;$retired=$body['retired_id']??null;$token=$body['review_token']??null;
    $request=$body['request_id']??null;$reason=$body['reason']??null;$choices=$body['fields']??null;$primary=$body['primary_jobdiva_mapping_id']??null;
    if(!is_int($survivor)||!is_int($retired)||$survivor<=0||$retired<=0||$survivor===$retired
        ||($body['confirm']??null)!=='MERGE_REVIEWED_PLACEMENTS'||($body['configuration_placement_id']??null)!==$survivor
        ||($body['history_policy']??null)!=='retain_original_rows'||!is_array($choices)
        ||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!is_string($request)
        ||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di',$request)
        ||!is_string($reason)||strlen(trim($reason))<3||strlen($reason)>1000||!preg_match('//u',$reason)||preg_match('/[\x00-\x1f\x7f]/',$reason)
        ||($primary!==null&&(!is_int($primary)||$primary<=0)))throw new InvalidArgumentException('Confirm the exact placements, survivor configuration, retained history, field choices, source owner and review.',422);
    $keys=array_keys($choices);sort($keys);$expected=placementMergeFields();sort($expected);
    if($keys!==$expected||array_filter($choices,static fn($c)=>!in_array($c,['survivor','retired'],true)))
        throw new InvalidArgumentException('Choose the survivor or retired value for every descriptive field.',422);
    ksort($choices);$request=strtolower($request);$reason=trim($reason);
    $hash=jobdivaAssignmentRepairFingerprint([$workspace,$actor,$survivor,$retired,$token,$request,$reason,$choices,$primary]);
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$survivor,$retired,$token,$request,$reason,$choices,$primary,$hash):array{
        $pdo=getDB();placementMergeActor($pdo,$workspace,$actor);$currentScope=jobdivaPlacementWriteContext($pdo,$workspace);
        $q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND event="placement.identity_merged" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=? FOR UPDATE');
        $q->execute([$workspace,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){
            $receipt=json_decode($prior['meta_json'],true,512,JSON_THROW_ON_ERROR);
            if((int)$prior['actor_user_id']!==$actor||($receipt['request_hash']??null)!==$hash
                ||($receipt['directory_owner_id']??null)!==$currentScope['placement_owner']||($receipt['instance_id']??null)!==integrationInstanceId())
                throw new RuntimeException('This request differs from its saved placement merge or current authorized directory.',409);
            return array_replace($receipt['result'],['replayed'=>true]);
        }
        $snapshot=placementMergeSnapshot($workspace,$actor,$survivor,$retired);$owner=$snapshot['scope']['placement_owner'];
        if(!hash_equals($token,$snapshot['hash']))throw new RuntimeException('The placements, source bindings, permissions or retained activity changed. Review again.',409);
        $sourceIds=array_column(array_filter($snapshot['source_options'],static fn($s)=>$s['eligible']),'mapping_id');
        if(($snapshot['source_options']&&!in_array($primary,$sourceIds,true))||(!$snapshot['source_options']&&$primary!==null))throw new InvalidArgumentException('Choose an eligible exact retained JobDiva binding that may own future source updates.',422);
        $values=[];foreach($choices as$field=>$choice)$values[$field]=$snapshot['records'][$choice==='survivor'?$survivor:$retired][$field]??null;
        if(!is_string($values['title'])||trim($values['title'])==='')throw new InvalidArgumentException('Choose a nonempty placement title.',422);
        if($values['worksite_country']!==null&&integrationSourceCountry($values['worksite_country'])!==$values['worksite_country'])throw new InvalidArgumentException('Review the selected worksite country before consolidation.',422);
        if($values['remote_policy']!==null&&!in_array($values['remote_policy'],PLACEMENTS_ALLOWED_REMOTE,true))throw new InvalidArgumentException('Review the selected remote-work policy.',422);
        $owned=placementOwnershipFields($snapshot['records'][$survivor]['coreflux_overridden_fields']??null);
        // Explicitly chosen descriptive values become Core-owned; the source cannot immediately undo the review.
        $values['coreflux_overridden_fields']=json_encode(array_values(array_unique([...$owned,...array_keys($choices)])),JSON_THROW_ON_ERROR);
        $sets=array_map(static fn($field)=>'`'.$field.'`=?',array_keys($values));
        $pdo->prepare('UPDATE placements SET '.implode(',',$sets).',updated_at=NOW() WHERE tenant_id=? AND id=? AND deleted_at IS NULL')
            ->execute([...array_values($values),$owner,$survivor]);
        $q=$pdo->prepare('UPDATE placements SET deleted_at=NOW(),updated_at=NOW() WHERE tenant_id=? AND id=? AND deleted_at IS NULL');$q->execute([$owner,$retired]);
        if($q->rowCount()!==1)throw new RuntimeException('The retired placement changed before consolidation.',409);
        coreRecordNumbersMerge($pdo,$owner,'placement',$survivor,$retired,$actor);
        $result=['survivor_id'=>$survivor,'retired_id'=>$retired,'survivor_number'=>$snapshot['public']['survivor_number'],
            'retained_number'=>$snapshot['public']['retired_number'],'provider_pairs_retained'=>$snapshot['public']['provider_pairs_retained'],
            'history_rows_retained'=>array_sum(array_column($snapshot['history'],'count')),'replayed'=>false];
        companySourceAudit($pdo,$workspace,$owner,'placements',$survivor,'placement.identity_merged',array_keys($choices),$actor,[
            'request_id'=>$request,'request_hash'=>$hash,'review_token'=>$token,'reason'=>$reason,'result'=>$result,'instance_id'=>integrationInstanceId(),
            'primary_jobdiva_mapping_id'=>$primary,'source_options'=>$snapshot['source_options'],'configuration_placement_id'=>$survivor,
            'history_policy'=>'retain_original_rows','field_choices'=>$choices,'before_rows_ct'=>base64_encode(encryptField(json_encode($snapshot['records'],JSON_THROW_ON_ERROR))),
            'history_fingerprints'=>$snapshot['history'],
        ]);
        return $result;
    });
}
