<?php
/** Availability is an explicit Core decision, not an inference from an ended assignment. */
declare(strict_types=1);
require_once __DIR__.'/stored_review.php';
require_once __DIR__.'/placement_write_context.php';
require_once __DIR__.'/../../modules/people/lib/company_source_ownership.php';
require_once __DIR__.'/../record_numbers.php';

function jobdivaPeopleLifecycleScope(PDO $pdo,int $workspace): array
{
    if(!JobdivaStoredReviewTransaction::active())throw new LogicException('People lifecycle review requires its protected transaction.');
    $people=companyRelationshipScope($pdo,$workspace,'people');
    $placements=companyRelationshipScope($pdo,$workspace,'placements');
    if($people['owner']!==$placements['owner'])throw new RuntimeException('Review People and Placements sharing before reviewing availability.',409);
    return ['workspace'=>$workspace,'owner'=>$people['owner']];
}

/** Internal snapshot; public output excludes provider payloads and private person data. */
function jobdivaPeopleLifecycleSnapshot(int $workspace,int $actor,int $limit): array
{
    $pdo=getDB();$scope=jobdivaPeopleLifecycleScope($pdo,$workspace);$owner=$scope['owner'];
    $limit=max(1,min(5000,$limit));
    $q=$pdo->prepare('SELECT tenant_id,module_key,scope_mode FROM tenant_module_scope WHERE tenant_id IN(?,?) ORDER BY tenant_id,module_key FOR UPDATE');$q->execute([$workspace,$owner]);$policies=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT COUNT(*) FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='person'");$q->execute([$workspace]);$total=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT id,tenant_id,external_id,internal_entity_id,content_hash,sync_status,last_error,last_seen_at FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='person' ORDER BY id LIMIT {$limit} FOR UPDATE");$q->execute([$workspace]);$mappings=$q->fetchAll(PDO::FETCH_ASSOC);
    $evidence=[];$rows=[];$warnings=[];
    foreach($mappings as$mapping){
        $id=(int)$mapping['internal_entity_id'];$external=jobdivaAssignmentIdentityNormaliseId($mapping['external_id']);
        $q=$pdo->prepare('SELECT id,tenant_id,external_id,first_name,last_name,status,classification,termination_date,updated_at,deleted_at FROM people WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$owner,$id]);$person=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $item=['mapping'=>$mapping,'person'=>$person];
        if(!$person){$evidence[]=$item;$warnings[]=['mapping_id'=>(int)$mapping['id'],'reason'=>'Person unavailable in the current directory'];continue;}
        $q=$pdo->prepare("SELECT m.id FROM external_entity_mappings m JOIN people p ON p.id=m.internal_entity_id AND p.tenant_id=? WHERE p.id=? AND m.source_system='jobdiva' AND m.internal_entity_type='person' AND m.id<>? FOR UPDATE");$q->execute([$owner,$id,$mapping['id']]);$item['competing_links']=$q->fetchAll(PDO::FETCH_COLUMN);
        $q=$pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="person" AND record_id=? FOR UPDATE');$q->execute([$owner,$id]);$number=$q->fetch(PDO::FETCH_ASSOC)?:null;$item['number']=$number;
        // All placements referencing this accessible person matter, including
        // historical references held by another operating directory.
        $q=$pdo->prepare('SELECT pl.id,pl.tenant_id,pl.status,pl.start_date,pl.end_date,pl.actual_end_date,pl.deleted_at,pl.updated_at FROM placements pl JOIN people p ON p.id=pl.person_id AND p.tenant_id=? WHERE p.id=? ORDER BY pl.id FOR UPDATE');$q->execute([$owner,$id]);$history=$q->fetchAll(PDO::FETCH_ASSOC);$item['placements']=$history;
        $evidence[]=$item;
        if(!preg_match('/^[0-9]+$/D',$external)||$item['competing_links']||!$number||(int)$number['canonical_record_id']!==$id
            ||(str_starts_with((string)$person['external_id'],'jd:')&&substr($person['external_id'],3)!==$external)){
            $warnings[]=['mapping_id'=>(int)$mapping['id'],'reason'=>'Review the exact candidate identity and retained person number'];continue;
        }
        if($mapping['sync_status']!=='ok'){$warnings[]=['mapping_id'=>(int)$mapping['id'],'reason'=>'The candidate source link needs review'];continue;}
        if($person['deleted_at']!==null||!in_array($person['status'],['active','bench'],true)||!$history)continue;
        $live=array_filter($history,static fn(array $p):bool=>(empty($p['deleted_at'])||$p['deleted_at']==='0000-00-00 00:00:00')&&in_array($p['status'],['draft','pending_start','active','on_hold'],true));
        if($live)continue;
        $ends=array_filter(array_map(static fn(array $p):?string=>$p['actual_end_date']?:$p['end_date'],$history));sort($ends);
        $rows[]=['id'=>$id,'person_number'=>$number['record_number'],'name'=>trim($person['first_name'].' '.$person['last_name']),
            'status'=>$person['status'],'historical_placement_count'=>count($history),'last_placement_end_date'=>$ends?end($ends):null];
    }
    return ['scope'=>$scope,'actor'=>$actor,'limit'=>$limit,'total'=>$total,'policies'=>$policies,'evidence'=>$evidence,'rows'=>$rows,'warnings'=>$warnings];
}

function jobdivaPeopleLifecycleToken(array $snapshot): string
{
    return hash('sha256',json_encode(mappingCanonicalise($snapshot),JSON_THROW_ON_ERROR));
}

function jobdivaPeopleLifecycleReview(int $workspace,int $actor,int $limit=5000): array
{
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$limit):array{
        $snapshot=jobdivaPeopleLifecycleSnapshot($workspace,$actor,$limit);
        return ['review_token'=>jobdivaPeopleLifecycleToken($snapshot),'rows'=>$snapshot['rows'],'warnings'=>$snapshot['warnings'],
            'limit'=>$snapshot['limit'],'checked'=>count($snapshot['evidence']),'review_required'=>count($snapshot['rows']),
            'total'=>$snapshot['total'],'truncated'=>$snapshot['total']>$snapshot['limit'],
            'inactivated'=>0,'updated'=>0,'failed'=>0,'dry_run'=>true];
    },true);
}

function jobdivaPeopleLifecycleApply(int $workspace,int $actor,array $body): array
{
    $token=$body['review_token']??null;$request=$body['request_id']??null;$decisions=$body['decisions']??null;$reason=$body['reason']??null;$limit=$body['limit']??5000;
    if($actor<=0||($body['confirm']??null)!=='APPLY_REVIEWED_PEOPLE_AVAILABILITY'
        ||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)
        ||!is_string($request)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$request)
        ||!is_array($decisions)||!$decisions||count($decisions)>500
        ||!is_string($reason)||strlen(trim($reason))<3||strlen($reason)>1000||!preg_match('//u',$reason)
        ||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$reason)||!is_int($limit)||$limit<1||$limit>5000){
        throw new InvalidArgumentException('Choose reviewed people, an availability decision and a reason before saving.',422);
    }
    foreach($decisions as$id=>$status)if(!preg_match('/^[1-9][0-9]*$/D',(string)$id)||!in_array($status,['bench','inactive'],true))throw new InvalidArgumentException('Choose Bench or Inactive for each selected person.',422);
    $request=strtolower($request);$reason=trim($reason);ksort($decisions);
    $hash=hash('sha256',json_encode([$workspace,$actor,$token,$request,$decisions,$reason,$limit],JSON_THROW_ON_ERROR));
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$actor,$token,$request,$decisions,$reason,$limit,$hash):array{
        $pdo=getDB();$scope=jobdivaPeopleLifecycleScope($pdo,$workspace);$owner=$scope['owner'];
        // The workspace/policy locks serialize receipt lookup and write. A
        // committed receipt remains recoverable after a later native edit.
        $q=$pdo->prepare('SELECT actor_user_id,meta_json FROM audit_log WHERE tenant_id=? AND event="jobdiva.people_availability_applied" AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=? FOR UPDATE');$q->execute([$workspace,$request]);$prior=$q->fetch(PDO::FETCH_ASSOC);
        if($prior){
            $receipt=json_decode($prior['meta_json'],true,512,JSON_THROW_ON_ERROR);
            if((int)$prior['actor_user_id']!==$actor||$receipt['request_hash']!==$hash||$receipt['directory_owner_id']!==$owner)throw new RuntimeException('This availability request differs from its saved receipt.',409);
            return array_replace($receipt['result'],['replayed'=>true]);
        }
        $snapshot=jobdivaPeopleLifecycleSnapshot($workspace,$actor,$limit);
        if(!hash_equals($token,jobdivaPeopleLifecycleToken($snapshot)))throw new RuntimeException('People, placements or source links changed. Refresh the availability review.',409);
        $rows=array_column($snapshot['rows'],null,'id');$byPerson=[];
        foreach($snapshot['evidence']as$item)if($item['person'])$byPerson[(int)$item['person']['id']]=$item;
        foreach($decisions as$id=>$status)if(!isset($rows[$id]))throw new RuntimeException('A selected person is no longer eligible for this availability review.',409);
        $result=['updated'=>0,'inactivated'=>0,'benched'=>0,'unchanged'=>0,'replayed'=>false,'people'=>[]];
        foreach($decisions as$id=>$status){
            $item=$byPerson[$id];$before=$item['person']['status'];
            if($before===$status){$result['unchanged']++;continue;}
            $pdo->prepare('UPDATE people SET status=?,updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$status,$owner,$id]);
            companySourceAudit($pdo,$workspace,$owner,'people',(int)$id,'people.availability_reviewed',['status'],$actor,[
                'request_id'=>$request,'reason'=>$reason,'source_system'=>'jobdiva','source_mapping_id'=>(int)$item['mapping']['id'],
                'source_external_id'=>$item['mapping']['external_id'],'person_number'=>$item['number']['record_number'],
                'before'=>['status'=>$before],'after'=>['status'=>$status],
            ]);
            $result['updated']++;$result[$status==='inactive'?'inactivated':'benched']++;
            $result['people'][]=['id'=>(int)$id,'person_number'=>$item['number']['record_number'],'status'=>$status];
        }
        companySourceAudit($pdo,$workspace,$owner,'people',0,'jobdiva.people_availability_applied',['status'],$actor,[
            'request_id'=>$request,'request_hash'=>$hash,'review_token'=>$token,'reason'=>$reason,'decisions'=>$decisions,'result'=>$result,
        ]);
        return$result;
    });
}

/** Legacy callers may discover review candidates, never infer an inactive status. */
function jobdivaMappingRepairSourcePeopleLifecycle(int $tenantId,?int $userId=null,int $limit=500,bool $dryRun=true): array
{
    if(!$dryRun)throw new InvalidArgumentException('Review each person availability decision before applying People lifecycle changes.',422);
    return jobdivaPeopleLifecycleReview($tenantId,(int)$userId,$limit);
}

function _jobdivaMappingStaleSourcePeopleRows(PDO $pdo,int $tenantId,int $limit=500): array
{
    if($pdo!==getDB())throw new LogicException('Use the active application connection.');
    return jobdivaPeopleLifecycleReview($tenantId,0,$limit)['rows'];
}
