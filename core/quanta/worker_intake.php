<?php
/** Reviewed Quanta-to-Core directory intake. Remote reads only; receipts and local changes are atomic. */
declare(strict_types=1);
require_once __DIR__.'/worker_delivery.php';
require_once __DIR__.'/../../modules/people/lib/people.php';

function quantaWorkerIntakeRequest(array $input): array
{
    $mode=$input['mode']??null;
    if($mode==='create'){
        if(array_diff(array_keys($input),['mode','worker_id','first_name','last_name','confirm_distinct_person']))throw new InvalidArgumentException('Only reviewed name fields may be supplied');
        foreach(['first_name','last_name']as$key){
            if(!is_string($input[$key]??null))throw new InvalidArgumentException('Review both name fields');
            $input[$key]=trim($input[$key]);
            if($input[$key]===''||strlen($input[$key])>100||preg_match('/[\x00-\x1f\x7f]/',$input[$key]))throw new InvalidArgumentException('Use printable first and last names within the Core limit');
        }
        if(!is_bool($input['confirm_distinct_person']??false))throw new InvalidArgumentException('Review the same-name identity');
        $input['confirm_distinct_person']=$input['confirm_distinct_person']??false;
        $ids=[$input['worker_id']??null];
    }elseif($mode==='link'){
        if(array_diff(array_keys($input),['mode','links'])||!is_array($input['links']??null)||!array_is_list($input['links'])||!$input['links']||count($input['links'])>100)throw new InvalidArgumentException('Review 1 to 100 worker/person pairs');
        $ids=[];$people=[];
        foreach($input['links']as$link){
            if(!is_array($link)||array_diff(array_keys($link),['worker_id','person_id'])||!is_int($link['person_id']??null)||$link['person_id']<=0)throw new InvalidArgumentException('Choose a Core person');
            $ids[]=$link['worker_id']??null;$people[]=$link['person_id'];
        }
        if(count(array_unique($people))!==count($people))throw new InvalidArgumentException('A person can be linked to only one worker');
    }else throw new InvalidArgumentException('Choose creation or linking');
    foreach($ids as$id)if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id))throw new InvalidArgumentException('Choose a valid Quanta worker');
    if(count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Review each worker once');
    return $input;
}

function quantaWorkerIntakeSources(array $access,array $request): array
{
    $wanted=$request['mode']==='create'?[$request['worker_id']]:array_column($request['links'],'worker_id');
    $rows=[];
    // One worker read avoids requiring unrelated worksite/dimension permissions.
    $catalog=count($wanted)===1?[quantaGet($access['api_key'],'/workers/'.$wanted[0])]:quantaListAll($access['api_key'],'/workers',[],5000);
    foreach($catalog as$worker){
        $id=$worker['id']??null;if(!in_array($id,$wanted,true))continue;
        if(isset($rows[$id]))throw new RuntimeException('Quanta returned an ambiguous worker identity');
        if(!is_string($worker['full_name']??null)||!is_string($worker['email']??null)||!is_bool($worker['active']??null)
            ||!in_array($worker['status']??null,['active','disabled','invited'],true)||$worker['active']!==($worker['status']==='active')
            ||(isset($worker['worker_id'])&&$worker['worker_id']!==$id)||(isset($worker['updated_at'])&&!is_string($worker['updated_at']))
            ||(!is_null($worker['phone']??null)&&!is_string($worker['phone'])))throw new RuntimeException('Quanta returned an incomplete worker observation');
        $rows[$id]=array_intersect_key($worker,array_flip(['id','full_name','email','phone','status','active','updated_at','external_id','employee_id']));
        $rows[$id]['phone']=$worker['phone']??null;
        $rows[$id]['updated_at']=$worker['updated_at']??null;
    }
    if(count($rows)!==count($wanted))throw new RuntimeException('A reviewed worker is no longer available in Quanta');
    quantaWorkspaceAssertCurrent(getDB(),(int)$access['connection']['tenant_id'],$access);
    return $rows;
}

/** Local conflicts are rechecked under locks immediately before any mutation. */
function quantaWorkerIntakePlan(PDO $pdo,int $tenant,int $owner,array $request,array $sources,bool $lock=false): array
{
    $suffix=$lock?' FOR UPDATE':'';$rows=[];
    $items=$request['mode']==='create'?[['worker_id'=>$request['worker_id']]]:$request['links'];
    foreach($items as$item){
        $worker=$sources[$item['worker_id']];$person=null;
        if($request['mode']==='create'){
            $email=strtolower(trim($worker['email']));$phone=trim((string)$worker['phone']);
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>255||strlen($phone)>40||preg_match('/[\x00-\x1f\x7f]/',$phone))throw new RuntimeException('The worker email or phone does not fit Core. Review the source before creating a person');
            $s=$pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND LOWER(email_primary)=:e LIMIT 1'.$suffix);
            $s->execute(['t'=>$owner,'e'=>$email]);if($s->fetchColumn())throw new RuntimeException('A Core person already has this email. Review and link that person instead');
            $s=$pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND deleted_at IS NULL AND LOWER(first_name)=LOWER(:f) AND LOWER(last_name)=LOWER(:l) LIMIT 1'.$suffix);
            $s->execute(['t'=>$owner,'f'=>$request['first_name'],'l'=>$request['last_name']]);
            if($s->fetchColumn()&&!$request['confirm_distinct_person'])throw new RuntimeException('Review the existing same-name person or confirm they are different people');
            $target=['first_name'=>$request['first_name'],'last_name'=>$request['last_name'],'email_primary'=>$email,'phone_primary'=>$phone===''?null:$phone,
                'classification'=>'candidate','status'=>$worker['active']?'active':'inactive'];
        }else{
            $s=$pdo->prepare('SELECT id,first_name,last_name,email_primary,phone_primary,status FROM people WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL'.$suffix);
            $s->execute(['t'=>$owner,'p'=>$item['person_id']]);$person=$s->fetch(PDO::FETCH_ASSOC);
            if(!$person)throw new RuntimeException('Choose a person from the authorized Core directory');
            $target=coreRecordNumbersAttach($pdo,$owner,'person',[$person])[0];
            if(empty($target['person_number']))throw new RuntimeException('The Core person number is unavailable');
        }
        $s=$pdo->prepare('SELECT worker_id,person_id FROM quanta_worker_links WHERE tenant_id=:t AND (worker_id=:w'.($person?' OR person_id=:p':'').')'.$suffix);
        $params=['t'=>$tenant,'w'=>$worker['id']];if($person)$params['p']=$person['id'];$s->execute($params);$links=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($links as$link)if(!$person||$link['worker_id']!==$worker['id']||(int)$link['person_id']!==(int)$person['id'])throw new RuntimeException('This worker or person is already linked elsewhere. Refresh and review the existing identity');
        $rows[]=['worker'=>$worker,'target'=>$target];
    }
    return $rows;
}

function quantaWorkerIntakePreview(int $tenant,int $actor,array $input): array
{
    if($actor<=0)throw new InvalidArgumentException('A current reviewer is required');
    $request=quantaWorkerIntakeRequest($input);$access=quantaWorkspaceForDelivery($tenant);
    $owner=effectiveTenantIdForModule('people',$tenant)??$tenant;
    $sources=quantaWorkerIntakeSources($access,$request);$rows=quantaWorkerIntakePlan(getDB(),$tenant,$owner,$request,$sources);
    $review=['kind'=>'quanta_worker_intake_v1','id'=>bin2hex(random_bytes(32)),'tenant'=>$tenant,'actor'=>$actor,'owner'=>$owner,
        'instance'=>$access['instance_id'],'binding_hash'=>$access['binding_hash'],'workspace'=>$access['context']['workspace']['id'],'context'=>$access['context'],
        'expires_at'=>time()+1800,'request'=>$request,'source_hash'=>integrationDeliveryHash($sources),'rows_hash'=>integrationDeliveryHash($rows)];
    return ['review_token'=>base64_encode(encryptField(integrationDeliveryJson($review))),'workspace'=>$access['context']['workspace'],
        'people_tenant_id'=>$owner,'people_directory_name'=>subTenantLookup($owner)['name']??'Core people',
        'mode'=>$request['mode'],'rows'=>$rows,'expires_at'=>gmdate('c',$review['expires_at'])];
}

function quantaWorkerIntakeOpen(int $tenant,int $actor,string $token): array
{
    if(strlen($token)>50000)throw new InvalidArgumentException('Invalid worker review');
    try{$bytes=base64_decode($token,true);if($bytes===false)throw new RuntimeException();$review=json_decode((string)decryptField($bytes),true,64,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){throw new InvalidArgumentException('This worker review is invalid. Preview again');}
    if(($review['kind']??null)!=='quanta_worker_intake_v1'||($review['tenant']??null)!==$tenant||($review['actor']??null)!==$actor
        ||!preg_match('/^[a-f0-9]{64}$/D',(string)($review['id']??'')))throw new InvalidArgumentException('This review belongs to another workspace or reviewer');
    $connection=quantaConnection($tenant);$key=quantaApiKey($tenant);$instance=integrationInstanceId();
    if(!$instance)throw new RuntimeException('The Core installation identity is unavailable');
    $context=quantaContractContextValidate($review['context']);
    $access=['connection'=>$connection,'api_key'=>$key,'instance_id'=>$instance,'context'=>$context,
        'binding_hash'=>quantaWorkspaceBindingHash($tenant,(int)$connection['id'],$key,$instance,$context,(int)$connection['binding_revision'])];
    quantaWorkspaceAssertCurrent(getDB(),$tenant,$access);
    if($review['instance']!==$access['instance_id']||$review['workspace']!==$access['context']['workspace']['id']||!hash_equals($review['binding_hash'],$access['binding_hash']))throw new RuntimeException('The Quanta connection changed. Preview again');
    return [$review,$access];
}

function quantaWorkerIntakeReceipt(PDO $pdo,int $tenant,array $review): ?array
{
    $s=$pdo->prepare('SELECT actor_id,people_tenant_id,binding_hash,result_ct FROM quanta_worker_intake_receipts WHERE tenant_id=:t AND review_id=:r');
    $s->execute(['t'=>$tenant,'r'=>$review['id']]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
    if((int)$row['actor_id']!==$review['actor']||(int)$row['people_tenant_id']!==$review['owner']||!hash_equals($row['binding_hash'],$review['binding_hash']))throw new RuntimeException('The intake receipt does not match the review');
    return json_decode((string)decryptField($row['result_ct']),true,64,JSON_THROW_ON_ERROR);
}

function quantaWorkerIntakeStatus(int $tenant,int $actor,string $token): array
{
    [$review,$access]=quantaWorkerIntakeOpen($tenant,$actor,$token);$pdo=getDB();
    if($pdo->inTransaction())throw new LogicException('Intake status requires its own transaction');
    $pdo->beginTransaction();
    try{quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);quantaWorkerDeliveryDirectoryLock($pdo,$tenant,$review['owner']);
        $result=quantaWorkerIntakeReceipt($pdo,$tenant,$review);$pdo->commit();return ['state'=>$result?'applied':'not_applied','result'=>$result];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function quantaWorkerIntakeApply(int $tenant,int $actor,string $token,string $mode): array
{
    [$review,$access]=quantaWorkerIntakeOpen($tenant,$actor,$token);
    if($review['request']['mode']!==$mode)throw new InvalidArgumentException('Use the reviewed intake operation');
    $pdo=getDB();if($pdo->inTransaction())throw new LogicException('Intake requires its own transaction');
    // Receipt replay needs no new worker fetch, but still verifies current workspace/directory access.
    $receipt=quantaWorkerIntakeStatus($tenant,$actor,$token);if($receipt['state']==='applied')return $receipt['result']+['replayed'=>true];
    if($review['expires_at']<time())throw new RuntimeException('This worker review expired. Preview again');
    $access=quantaWorkspaceForDelivery($tenant);
    if(!hash_equals($review['binding_hash'],$access['binding_hash']))throw new RuntimeException('The Quanta workspace changed. Preview again');
    $sources=quantaWorkerIntakeSources($access,$review['request']);
    if(!hash_equals($review['source_hash'],integrationDeliveryHash($sources)))throw new RuntimeException('The Quanta worker changed after review. Preview again');
    $pdo->beginTransaction();
    try{
        quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);quantaWorkerDeliveryDirectoryLock($pdo,$tenant,$review['owner']);
        $existing=quantaWorkerIntakeReceipt($pdo,$tenant,$review);if($existing){$pdo->commit();return $existing+['replayed'=>true];}
        $rows=quantaWorkerIntakePlan($pdo,$tenant,$review['owner'],$review['request'],$sources,true);
        if(!hash_equals($review['rows_hash'],integrationDeliveryHash($rows)))throw new RuntimeException('The Core person changed after review. Preview again');
        $result=['mode'=>$mode,'review_id'=>$review['id'],'people'=>[]];
        foreach($rows as$row){
            $personId=$mode==='create'?peopleCreateExternalCandidate($review['owner'],$row['target'],'quanta',$actor):(int)$row['target']['id'];
            $person=coreRecordNumbersAttach($pdo,$review['owner'],'person',[['id'=>$personId]])[0];
            if(empty($person['person_number']))throw new RuntimeException('The canonical person number was not allocated');
            $s=$pdo->prepare('SELECT person_id FROM quanta_worker_links WHERE tenant_id=:t AND worker_id=:w FOR UPDATE');$s->execute(['t'=>$tenant,'w'=>$row['worker']['id']]);
            if(!$s->fetchColumn())$pdo->prepare('INSERT INTO quanta_worker_links(tenant_id,worker_id,person_id,linked_by_user_id) VALUES(:t,:w,:p,:a)')
                ->execute(['t'=>$tenant,'w'=>$row['worker']['id'],'p'=>$personId,'a'=>$actor]);
            $result['people'][]=['person_id'=>$personId,'person_number'=>$person['person_number'],'worker_id'=>$row['worker']['id']];
            if($mode==='create')platformAuditLogWrite($review['owner'],$actor,'people.created',$personId,
                ['source'=>'quanta','classification'=>'candidate','review_id'=>$review['id']]);
        }
        $pdo->prepare('INSERT INTO quanta_worker_intake_receipts(tenant_id,review_id,actor_id,people_tenant_id,binding_hash,source_workspace_id,source_hash,operation,review_ct,result_ct) VALUES(:t,:r,:a,:o,:b,:w,:s,:m,:v,:c)')
            ->execute(['t'=>$tenant,'r'=>$review['id'],'a'=>$actor,'o'=>$review['owner'],'b'=>$review['binding_hash'],'w'=>$review['workspace'],'s'=>$review['source_hash'],
                'm'=>$mode,'v'=>encryptField(integrationDeliveryJson(['instance_id'=>$review['instance'],'request'=>$review['request'],'rows'=>$rows])),
                'c'=>encryptField(integrationDeliveryJson($result))]);
        platformAuditLogWrite($tenant,$actor,'quanta.worker_intake.applied',null,['review_id'=>$review['id'],'mode'=>$mode,'people_tenant_id'=>$review['owner'],'count'=>count($result['people'])],['source'=>'quanta']);
        $pdo->commit();return $result;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&$e->getCode()==='23000')throw new RuntimeException('A person or worker was added elsewhere. Refresh the directory and review the existing identity');
        throw $e;
    }
}
