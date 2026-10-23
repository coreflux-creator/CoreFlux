<?php
/** Employer-reviewed cases. Dependencies are explicit so reads and tests cannot send mail or payroll. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/record_numbers.php';

class OnboardingConflict extends RuntimeException {}

function onboardingUuid(): string {
    $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128);
    $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function onboardingId($v): string {
    if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v))throw new InvalidArgumentException('A valid request or case identifier is required.');
    return $v;
}
function onboardingJson(array $v): string { return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function onboardingHash(array $v): string {
    $sort=static function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map($sort,$v);};
    return hash('sha256',onboardingJson($sort($v)));
}
function onboardingText($v,int $max): string {
    if(!is_string($v)||trim($v)===''||strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Use a short, single-line description.');
    return trim($v);
}
function onboardingDate($v): ?string {
    if($v===null||$v==='')return null;
    if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))throw new InvalidArgumentException('Use a date in YYYY-MM-DD format.');
    return $v;
}
function onboardingScope(array $scope,string $permission='view'): void {
    foreach(['tenant_id','people_tenant_id','placements_tenant_id','actor_user_id'] as $k)if(!is_int($scope[$k]??null)||$scope[$k]<=0)throw new InvalidArgumentException('An authorized workspace and actor are required.');
    if(($scope['permissions'][$permission]??false)!==true)throw new RuntimeException('Onboarding permission required.');
}
function onboardingSource(PDO $pdo,array $scope,int $personId,int $placementId,bool $lock=false): array {
    onboardingScope($scope);
    if($personId<=0||$placementId<=0)throw new InvalidArgumentException('Choose an existing person and placement.');
    $st=$pdo->prepare('SELECT id,person_id,status,engagement_type,start_date,end_date,accounting_entity_id,branch,workers_comp_class,worksite_state,worksite_country FROM placements WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $st->execute(['t'=>$scope['placements_tenant_id'],'id'=>$placementId]);$placement=$st->fetch(PDO::FETCH_ASSOC);
    if(!$placement||(int)$placement['person_id']!==$personId)throw new OnboardingConflict('The placement and person do not match in this workspace.');
    $st=$pdo->prepare('SELECT id,status FROM people WHERE tenant_id=:t AND id=:id AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $st->execute(['t'=>$scope['people_tenant_id'],'id'=>$personId]);$person=$st->fetch(PDO::FETCH_ASSOC);
    if(!$person)throw new OnboardingConflict('The person is unavailable in this workspace.');
    $source=['person'=>$person,'placement'=>$placement];
    return ['source_revision'=>onboardingHash($source),'source_context'=>$placement,
        'person_number'=>coreRecordNumbersAttach($pdo,$scope['people_tenant_id'],'person',[$person])[0]['person_number'],
        'placement_number'=>coreRecordNumbersAttach($pdo,$scope['placements_tenant_id'],'placement',[$placement])[0]['placement_number']];
}
function onboardingCase(PDO $pdo,array $scope,string $id,bool $lock=false): array {
    onboardingScope($scope);onboardingId($id);
    $st=$pdo->prepare('SELECT * FROM staffing_onboarding_cases WHERE tenant_id=:t AND people_tenant_id=:pt AND placements_tenant_id=:lt AND id=:id'.($lock?' FOR UPDATE':''));
    $st->execute(['t'=>$scope['tenant_id'],'pt'=>$scope['people_tenant_id'],'lt'=>$scope['placements_tenant_id'],'id'=>$id]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new OnboardingConflict('Onboarding case is unavailable in this workspace.');
    $row+=onboardingSource($pdo,$scope,(int)$row['person_id'],(int)$row['placement_id'],$lock);
    foreach(['tenant_id','people_tenant_id','placements_tenant_id','person_id','placement_id','version','plan_version']as$k)$row[$k]=(int)$row[$k];
    $row['confirmed_plan_version']=$row['confirmed_plan_version']===null?null:(int)$row['confirmed_plan_version'];
    $row['tasks']=json_decode($row['tasks_json'],true,64,JSON_THROW_ON_ERROR);unset($row['tasks_json']);return $row;
}

/** The evidence revision covers both the owned document metadata and actual stored object version/ETag. */
function onboardingDocument(PDO $pdo,array $case,int $documentId,callable $head,bool $lock=false): array {
    $st=$pdo->prepare('SELECT d.id,d.doc_type,d.storage_object_id,d.signed,d.signed_at,d.expires_at,
        s.s3_key,s.module,s.entity_type,s.entity_id FROM people_documents d
        JOIN storage_objects s ON s.tenant_id=d.tenant_id AND s.id=d.storage_object_id
        WHERE d.tenant_id=:t AND d.person_id=:p AND d.id=:id AND d.deleted_at IS NULL'.($lock?' FOR UPDATE':''));
    $st->execute(['t'=>$case['people_tenant_id'],'p'=>$case['person_id'],'id'=>$documentId]);$doc=$st->fetch(PDO::FETCH_ASSOC);
    if(!$doc||$doc['module']!=='people'||$doc['entity_type']!=='document'||(string)$doc['entity_id']!==(string)$case['person_id']
        ||!str_starts_with($doc['s3_key'],'people/'.$case['people_tenant_id'].'/document/'.$case['person_id'].'/'))throw new OnboardingConflict('Document is unavailable or belongs to another person.');
    $blob=$head($doc['s3_key']);
    if(!is_array($blob)||!is_string($blob['etag']??null)||$blob['etag']===''||!isset($blob['size_bytes'])||(int)$blob['size_bytes']<0)throw new OnboardingConflict('The stored document could not be verified.');
    $stamp=['etag'=>$blob['etag'],'version_id'=>$blob['version_id']??null,'size_bytes'=>(int)$blob['size_bytes'],'last_modified'=>$blob['last_modified']??null];
    return ['document_id'=>$documentId,'revision'=>onboardingHash(['document'=>$doc,'object'=>$stamp]),
        'expires_at'=>empty($doc['expires_at'])?null:onboardingDate(substr($doc['expires_at'],0,10))];
}
function onboardingTaskDefinition(array $body): array {
    if(!in_array($body['assignee']??null,['worker','staff'],true)||!in_array($body['evidence_kind']??null,['attestation','document'],true)||!is_bool($body['required']??null))throw new InvalidArgumentException('Choose a responsible person, evidence type and required status.');
    return ['title'=>onboardingText($body['title']??null,160),'instructions'=>onboardingText($body['instructions']??null,600),
        'assignee'=>$body['assignee'],'evidence_kind'=>$body['evidence_kind'],'required'=>$body['required']];
}
/** A failed evidence lookup cannot preserve an approved/completed status. Read never changes history. */
function onboardingView(PDO $pdo,array $scope,string $id,callable $head,bool $lock=false): array {
    $case=onboardingCase($pdo,$scope,$id,$lock);$today=gmdate('Y-m-d');$required=0;$satisfied=0;
    foreach($case['tasks']as&$task){
        $task['effective_state']=$task['state'];
        if($task['state']!=='retired'){
            if(in_array($task['state'],['approved','waived'],true)&&($task['review']['source_revision']??null)!==$case['source_revision'])$task['effective_state']='needs_review';
            $expiry=$task['review']['expires_at']??null;
            if($task['state']!=='waived'&&isset($task['evidence']['document_id'])){
                try{$doc=onboardingDocument($pdo,$case,$task['evidence']['document_id'],$head,$lock);
                    if(!hash_equals($task['evidence']['revision'],$doc['revision']))$task['effective_state']='needs_review';
                    if($doc['expires_at']!==null&&($expiry===null||$doc['expires_at']<$expiry))$expiry=$doc['expires_at'];
                }catch(Throwable $e){$task['effective_state']='evidence_unavailable';}
            }
            if($expiry!==null&&$expiry<$today)$task['effective_state']='expired';
            if($task['required']){$required++;if(in_array($task['effective_state'],['approved','waived'],true))$satisfied++;}
        }
    }unset($task);
    $case['source_changed']=$case['confirmed_source_hash']!==$case['source_revision'];
    $case['readiness']=$case['confirmed_plan_version']!==$case['plan_version']||$case['source_changed']||$required===0?'requirements_unconfirmed':($required===$satisfied?'complete':'incomplete');
    $case['required_count']=$required;$case['satisfied_count']=$satisfied;$case['observed_at']=gmdate('Y-m-d\TH:i:s\Z');
    $case['payroll_authorization']='not_evaluated';$case['portal_delivery']='not_checked';
    $st=$pdo->prepare('SELECT case_version,actor_user_id,action,task_id,details_json,created_at FROM staffing_onboarding_events WHERE tenant_id=:t AND case_id=:id ORDER BY case_version DESC LIMIT 101');
    $st->execute(['t'=>$scope['tenant_id'],'id'=>$id]);$events=$st->fetchAll(PDO::FETCH_ASSOC);
    $case['history_has_more']=count($events)>100;$case['history']=array_map(static function($e){$e['details']=json_decode($e['details_json'],true,32,JSON_THROW_ON_ERROR);unset($e['details_json']);return $e;},array_slice($events,0,100));
    return $case;
}
function onboardingReceipt(PDO $pdo,array $scope,string $requestId,string $hash,bool $lock=false): ?array {
    $st=$pdo->prepare('SELECT request_hash,receipt_json FROM staffing_onboarding_events WHERE tenant_id=:t AND request_id=:r'.($lock?' FOR UPDATE':''));
    $st->execute(['t'=>$scope['tenant_id'],'r'=>$requestId]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;if(!hash_equals($row['request_hash'],$hash))throw new OnboardingConflict('This request identifier was already used for a different change.');
    return json_decode($row['receipt_json'],true,32,JSON_THROW_ON_ERROR)+['replayed'=>true];
}
/** One conditional command, state revision, append-only event and replay receipt per transaction. */
function onboardingCommand(PDO $pdo,array $scope,array $body,callable $head): array {
    $action=$body['action']??'';
    $keys=['create'=>['person_id','placement_id'],'add_task'=>['case_id','expected_version','title','instructions','assignee','evidence_kind','required'],
        'revise_task'=>['case_id','expected_version','task_id','title','instructions','assignee','evidence_kind','required'],
        'retire_task'=>['case_id','expected_version','task_id','reason'],'confirm_plan'=>['case_id','expected_version'],
        'submit'=>['case_id','expected_version','task_id','document_id','acknowledged'],
        'review'=>['case_id','expected_version','task_id','decision','reason','expires_at'],
        'reopen'=>['case_id','expected_version','task_id','reason']];
    if(!isset($keys[$action])||array_diff(array_keys($body),array_merge(['action','request_id'],$keys[$action])))throw new InvalidArgumentException('Unsupported onboarding change or field.');
    onboardingScope($scope,in_array($action,['review','confirm_plan'],true)?'review':'manage');
    $rid=onboardingId($body['request_id']??null);$hash=onboardingHash(['actor'=>$scope['actor_user_id'],'people_owner'=>$scope['people_tenant_id'],'placements_owner'=>$scope['placements_tenant_id'],'body'=>$body]);
    if($pdo->inTransaction())throw new LogicException('Onboarding owns its transaction.');
    $pdo->beginTransaction();
    try{
        if($receipt=onboardingReceipt($pdo,$scope,$rid,$hash)){
            onboardingCase($pdo,$scope,$receipt['case_id']);$pdo->commit();return $receipt;
        }
        $taskId=null;$details=[];
        if($action==='create'){
            if(!is_int($body['person_id']??null)||!is_int($body['placement_id']??null))throw new InvalidArgumentException('Person and placement identifiers are required.');
            onboardingSource($pdo,$scope,$body['person_id'],$body['placement_id'],true);
            $case=['id'=>onboardingUuid(),'tenant_id'=>$scope['tenant_id'],'people_tenant_id'=>$scope['people_tenant_id'],'person_id'=>$body['person_id'],
                'placements_tenant_id'=>$scope['placements_tenant_id'],'placement_id'=>$body['placement_id'],'version'=>1,'plan_version'=>1,
                'confirmed_plan_version'=>null,'confirmed_by_user_id'=>null,'confirmed_at'=>null,'tasks'=>[]];
            $st=$pdo->prepare('INSERT INTO staffing_onboarding_cases (id,tenant_id,people_tenant_id,person_id,placements_tenant_id,placement_id,tasks_json,created_by_user_id) VALUES (:id,:t,:pt,:p,:lt,:l,:tasks,:u)');
            $st->execute(['id'=>$case['id'],'t'=>$scope['tenant_id'],'pt'=>$scope['people_tenant_id'],'p'=>$body['person_id'],'lt'=>$scope['placements_tenant_id'],'l'=>$body['placement_id'],'tasks'=>'[]','u'=>$scope['actor_user_id']]);
        }else{
            $case=onboardingCase($pdo,$scope,onboardingId($body['case_id']??null),true);
            // Recheck after the row lock: a simultaneous retry may have committed while we waited.
            // A locking read sees a receipt committed while we waited, including
            // under MySQL's default REPEATABLE READ transaction isolation.
            if($receipt=onboardingReceipt($pdo,$scope,$rid,$hash,true)){$pdo->commit();return $receipt;}
            if(!is_int($body['expected_version']??null)||$body['expected_version']!==$case['version'])throw new OnboardingConflict('This case changed. Reload and review the latest version.');
            if($action==='add_task'){
                if(count($case['tasks'])>=25)throw new InvalidArgumentException('A case can contain at most 25 tasks, including retired tasks.');
                $taskId=onboardingUuid();$case['tasks'][]=['id'=>$taskId,'version'=>1]+onboardingTaskDefinition($body)+['state'=>'requested','evidence'=>null,'review'=>null];
                $details=['task'=>$case['tasks'][array_key_last($case['tasks'])]];
            }elseif($action==='confirm_plan'){
                $required=array_filter($case['tasks'],static fn($t)=>$t['required']&&$t['state']!=='retired');
                if(!$required)throw new InvalidArgumentException('Add at least one required task before confirming the requirement list.');
                $case['confirmed_plan_version']=$case['plan_version'];$case['confirmed_by_user_id']=$scope['actor_user_id'];$case['confirmed_at']=gmdate('Y-m-d H:i:s');$case['confirmed_source_hash']=$case['source_revision'];
                $details=['plan_version'=>$case['plan_version'],'source_revision'=>$case['source_revision']];
            }else{
                $taskId=onboardingId($body['task_id']??null);$index=null;
                foreach($case['tasks']as$i=>$t)if($t['id']===$taskId)$index=$i;
                if($index===null)throw new OnboardingConflict('Task is not part of this case.');
                $task=&$case['tasks'][$index];
                if($task['state']==='retired')throw new OnboardingConflict('Retired tasks cannot be changed. Add a new requirement.');
                $details=['previous_state'=>$task['state']];
                if($action==='revise_task'){
                    $task=array_replace($task,onboardingTaskDefinition($body),['state'=>'requested','evidence'=>null,'review'=>null]);$details['definition']=onboardingTaskDefinition($body);
                }elseif($action==='retire_task'||$action==='reopen'){
                    $details['reason']=onboardingText($body['reason']??null,500);$task['state']=$action==='retire_task'?'retired':'requested';$task['review']=null;$task['evidence']=null;
                }elseif($action==='submit'){
                    if(($body['acknowledged']??null)!==true)throw new InvalidArgumentException('Confirm that you checked the task instructions and evidence.');
                    $evidence=['source'=>'core_staff','actor_user_id'=>$scope['actor_user_id'],'submitted_at'=>gmdate('Y-m-d\TH:i:s\Z'),'source_revision'=>$case['source_revision']];
                    if($task['evidence_kind']==='document'){
                        if(!is_int($body['document_id']??null)||$body['document_id']<=0)throw new InvalidArgumentException('Choose a document from this person’s document vault.');
                        $evidence+=onboardingDocument($pdo,$case,$body['document_id'],$head,true);
                        if($evidence['expires_at']!==null&&$evidence['expires_at']<gmdate('Y-m-d'))throw new OnboardingConflict('This document has expired.');
                    }elseif(isset($body['document_id']))throw new InvalidArgumentException('An attestation does not accept a document reference.');
                    $task['state']='submitted';$task['evidence']=$evidence;$task['review']=null;$details['evidence']=$evidence;
                }elseif($action==='review'){
                    $decision=$body['decision']??'';
                    if(!in_array($decision,['approve','reject','waive'],true))throw new InvalidArgumentException('Choose approve, reject or waive.');
                    if($decision!=='waive'&&$task['state']!=='submitted')throw new OnboardingConflict('Only submitted evidence can be approved or rejected.');
                    if($decision==='approve'&&($task['evidence']['source_revision']??null)!==$case['source_revision'])throw new OnboardingConflict('Placement setup changed after submission. Resubmit the evidence for review.');
                    $expiry=onboardingDate($body['expires_at']??null);
                    if($expiry!==null&&$expiry<gmdate('Y-m-d'))throw new InvalidArgumentException('The review expiry cannot be in the past.');
                    if($decision==='approve'&&$task['evidence_kind']==='document'){
                        $doc=onboardingDocument($pdo,$case,$task['evidence']['document_id'],$head,true);
                        if(!hash_equals($task['evidence']['revision'],$doc['revision'])||($doc['expires_at']!==null&&$doc['expires_at']<gmdate('Y-m-d')))throw new OnboardingConflict('Document changed or expired after submission. Resubmit it for review.');
                    }
                    $task['state']=['approve'=>'approved','reject'=>'rejected','waive'=>'waived'][$decision];
                    $task['review']=['decision'=>$decision,'reason'=>onboardingText($body['reason']??null,500),'actor_user_id'=>$scope['actor_user_id'],'reviewed_at'=>gmdate('Y-m-d\TH:i:s\Z'),'expires_at'=>$expiry,'source_revision'=>$case['source_revision']];
                    $details['review']=$task['review'];
                }
                $task['version']++;$details['state']=$task['state'];unset($task);
            }
            if(in_array($action,['add_task','revise_task','retire_task'],true)){$case['plan_version']++;$case['confirmed_plan_version']=null;$case['confirmed_by_user_id']=null;$case['confirmed_at']=null;$case['confirmed_source_hash']=null;}
            $case['version']++;
            $st=$pdo->prepare('UPDATE staffing_onboarding_cases SET version=:v,plan_version=:pv,confirmed_plan_version=:cv,confirmed_by_user_id=:u,confirmed_at=:at,confirmed_source_hash=:sh,tasks_json=:tasks,updated_at=UTC_TIMESTAMP() WHERE tenant_id=:t AND id=:id');
            $st->execute(['v'=>$case['version'],'pv'=>$case['plan_version'],'cv'=>$case['confirmed_plan_version'],'u'=>$case['confirmed_by_user_id'],'at'=>$case['confirmed_at'],'sh'=>$case['confirmed_source_hash'],'tasks'=>onboardingJson($case['tasks']),'t'=>$scope['tenant_id'],'id'=>$case['id']]);
        }
        $receipt=['case_id'=>$case['id'],'case_version'=>$case['version'],'task_id'=>$taskId,'action'=>$action];
        $st=$pdo->prepare('INSERT INTO staffing_onboarding_events (tenant_id,case_id,case_version,request_id,request_hash,actor_user_id,action,task_id,details_json,receipt_json) VALUES (:t,:c,:v,:r,:h,:u,:a,:task,:d,:receipt)');
        $st->execute(['t'=>$scope['tenant_id'],'c'=>$case['id'],'v'=>$case['version'],'r'=>$rid,'h'=>$hash,'u'=>$scope['actor_user_id'],'a'=>$action,'task'=>$taskId,'d'=>onboardingJson($details),'receipt'=>onboardingJson($receipt)]);
        $pdo->commit();return $receipt+['replayed'=>false];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&($e->errorInfo[1]??null)===1062){
            if($receipt=onboardingReceipt($pdo,$scope,$rid,$hash)){onboardingCase($pdo,$scope,$receipt['case_id']);return $receipt;}
            throw new OnboardingConflict('An onboarding case already exists for this placement. Reload the list.');
        }
        throw $e;
    }
}
