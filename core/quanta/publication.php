<?php
/** Reviewable worker publication, grounded in Quanta v1.1.0 and clockin-hub-16 workers.py. */
declare(strict_types=1);
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/workspace.php';
require_once __DIR__ . '/../record_numbers.php';
require_once __DIR__ . '/../integrations/delivery.php';

/** Pure planner. Never infers identity from email or overwrites provider-owned identifiers. */
function quantaWorkerPublicationPlan(array $person, ?array $linkedWorker, array $workers): array
{
    $name=trim(implode(' ',array_filter([$person['first_name']??'', $person['last_name']??''],static fn($v)=>$v!=='')));
    $email=strtolower(trim((string)($person['email_primary']??'')));
    $phone=trim((string)($person['phone_primary']??''));
    $base=['person_id'=>(int)$person['id'],'person_number'=>$person['person_number'],
        'person_name'=>$name,'worker_id'=>$linkedWorker['id']??null,'payload'=>[],
        'operation'=>$linkedWorker?'worker.update':'worker.create'];
    $block=static fn(string $message):array=>$base+['status'=>'blocked','message'=>$message];
    if ($name==='' || preg_match_all('/./us',$name)===false || preg_match_all('/./us',$name)>120 || preg_match('/[\x00-\x1f\x7f]/',$name)) return $block('Name must be 1 to 120 characters.');
    if ($phone!=='' && (preg_match_all('/./us',$phone)===false || preg_match_all('/./us',$phone)>100 || preg_match('/[\x00-\x1f\x7f]/',$phone))) return $block('Review the phone format before publishing.');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) return $block('Add a valid primary email to this person.');
    if (($person['status']??'')!=='active') return $block('Only an active Core person can be prepared for Quanta.');
    foreach ($workers as $worker) {
        if (strtolower(trim((string)($worker['email']??'')))===$email && (string)($worker['id']??'')!==(string)($linkedWorker['id']??'')) {
            return $block('This email already exists in Quanta. Review and link the existing worker first.');
        }
    }
    if ($linkedWorker && strtolower(trim((string)($linkedWorker['email']??'')))!==$email) {
        return $block('The linked worker has a different email. Resolve identity before publishing; Quanta cannot update email through this endpoint.');
    }
    if ($linkedWorker && ($linkedWorker['active']??false)!==true) return $block('The linked worker is inactive in Quanta. Review onboarding and access there first.');
    $payload=[];
    if (!$linkedWorker) {
        // Quanta creates a user without a password. Activation/onboarding remains a separate reviewed step.
        $payload=['email'=>$email,'full_name'=>$name,'active'=>false];
        if ($phone!=='') $payload['phone']=$phone;
    } else {
        if ($name!==($linkedWorker['full_name']??'')) $payload['full_name']=$name;
        // Empty Core phone means no proposal; never silently erase worker self-service data.
        if ($phone!=='' && $phone!==($linkedWorker['phone']??'')) $payload['phone']=$phone;
    }
    $base['payload']=$payload;
    return $base+['status'=>$payload?'preview':'unchanged',
        'message'=>$payload?($linkedWorker?'Review proposed name and phone changes.':'Prepare an inactive worker for onboarding.'):'These fields already agree.'];
}

function quantaPublicationPreview(int $tenantId, int $actorId, array $personIds): array
{
    if (!$personIds || !array_is_list($personIds) || count($personIds)>50) throw new InvalidArgumentException('Choose 1 to 50 people');
    foreach ($personIds as $id) if (!is_int($id) || $id<=0) throw new InvalidArgumentException('Invalid person identity');
    $personIds=array_values(array_unique($personIds));
    $pdo=getDB();
    $verified=quantaWorkspaceForDelivery($tenantId);
    $connection=$verified['connection'];
    $key=$verified['api_key'];
    $workers=quantaListAll($key,'/workers',[],5000);
    $workerIndex=[];
    foreach ($workers as $worker) {
        $id=(string)($worker['id']??'');
        if ($id==='' || isset($workerIndex[$id])) throw new RuntimeException('Quanta worker identities are incomplete or duplicated');
        $workerIndex[$id]=$worker;
    }
    $links=[];
    foreach (quantaWorkerLinks($tenantId) as $link) $links[(int)$link['person_id']]=$link['worker_id'];
    $peopleTenant=effectiveTenantIdForModule('people',$tenantId)??$tenantId;
    $marks=implode(',',array_fill(0,count($personIds),'?'));
    $stmt=$pdo->prepare("SELECT id,first_name,last_name,email_primary,phone_primary,status FROM people WHERE tenant_id=? AND deleted_at IS NULL AND id IN ({$marks})");
    $stmt->execute([$peopleTenant,...$personIds]);
    $people=coreRecordNumbersAttach($pdo,$peopleTenant,'person',$stmt->fetchAll(PDO::FETCH_ASSOC));
    if (count($people)!==count($personIds)) throw new InvalidArgumentException('One or more people are unavailable in this directory');
    $rows=[];
    foreach ($people as $person) {
        $workerId=$links[(int)$person['id']]??null;
        if ($workerId!==null && !isset($workerIndex[$workerId])) {
            $rows[]=['person_id'=>(int)$person['id'],'person_number'=>$person['person_number'],'status'=>'blocked','message'=>'Linked worker is no longer in the connected Quanta catalog.'];
            continue;
        }
        $remote=$workerId!==null?$workerIndex[$workerId]:null;
        $plan=quantaWorkerPublicationPlan($person,$remote,$workers);
        if ($plan['status']==='preview') {
            $row=integrationDeliveryPreview($pdo,$tenantId,$actorId,[
                'provider'=>'quanta','connection_ref'=>(string)$connection['id'],
                'binding_hash'=>$verified['binding_hash'],
                'entity_type'=>'person','entity_tenant_id'=>$peopleTenant,'entity_id'=>(int)$person['id'],
                'operation'=>$plan['operation'],'source_hash'=>integrationDeliveryHash($person),
                'remote_hash'=>integrationDeliveryHash($remote??['absent_email'=>strtolower(trim((string)$person['email_primary']))]),
            ],['worker_id'=>$workerId,'fields'=>$plan['payload'],
                'person'=>integrationRecordReference($verified['instance_id'],$peopleTenant,'person',$person['person_number'])]);
            $plan['delivery']=integrationDeliverySummary($row);
        }
        $rows[]=$plan;
    }
    return ['rows'=>$rows,'delivery_enabled'=>false,
        'hold_reason'=>'Delivery awaits the verified Quanta workspace and shared identity contract. These previews do not send records.'];
}
