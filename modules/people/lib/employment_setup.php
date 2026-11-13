<?php
/** Reviewed current employment setup. Payroll execution and placement lifecycle remain separate. */
declare(strict_types=1);
require_once __DIR__.'/employment_links.php';

const PEOPLE_EMPLOYMENT_SETUP_FIELDS=['status','employment_type','flsa_class','hire_date','start_date','job_title','department','location'];

function peopleEmploymentSetupSchema(PDO $pdo): bool
{
    return (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='people_employment_reviews'")->fetchColumn();
}

/** No private tax, banking or identity fields are selected by this workflow. */
function peopleEmploymentSetupSnapshot(PDO $pdo,int $tenant,int $employeeId,bool $forUpdate=false): array
{
    if($employeeId<=0||($forUpdate&&!$pdo->inTransaction()))throw new InvalidArgumentException('Choose an employment record.');
    $owner=peopleEmploymentOwner($tenant,$forUpdate);$lock=$forUpdate?' FOR UPDATE':'';
    $s=$pdo->prepare('SELECT id,tenant_id,employee_number,user_id,legal_first_name,legal_last_name,work_email,status,employment_type,flsa_class,hire_date,start_date,job_title,department,location,terminated_at,updated_at FROM people_employees WHERE tenant_id=? AND id=?'.$lock);
    $s->execute([$owner,$employeeId]);$employee=$s->fetch(PDO::FETCH_ASSOC);
    if(!$employee)throw new RuntimeException('Employment record is unavailable in this directory.');
    if(!peopleEmploymentSchema($pdo))throw new RuntimeException('Employment linking is not installed.');
    $s=$pdo->prepare('SELECT id,person_id,employee_id,review_id FROM people_employment_links WHERE tenant_id=? AND employee_id=?'.$lock);
    $s->execute([$owner,$employeeId]);$link=$s->fetch(PDO::FETCH_ASSOC)?:null;$person=null;
    if($link){
        $s=$pdo->prepare('SELECT id,tenant_id,user_id,first_name,last_name,email_primary,classification,status,employment_type,hire_date,deleted_at FROM people WHERE tenant_id=? AND id=?'.$lock);
        $s->execute([$owner,$link['person_id']]);$person=$s->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$person||$person['deleted_at']!==null)throw new RuntimeException('The linked person is unavailable. Review the employment identity.');
        $s=$pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="person" AND record_id=?'.$lock);
        $s->execute([$owner,$person['id']]);$number=$s->fetch(PDO::FETCH_ASSOC);
        if(!$number||(int)$number['canonical_record_id']!==(int)$person['id'])throw new RuntimeException('The linked person is not the current canonical record.');
        $person['person_number']=$number['record_number'];
        if(!empty($person['user_id'])&&!empty($employee['user_id'])&&(int)$person['user_id']!==(int)$employee['user_id'])throw new RuntimeException('The linked sign-in accounts disagree. Review the employment identity.');
    }
    return ['instance_id'=>integrationInstanceId(),'request_tenant_id'=>$tenant,'owner_tenant_id'=>$owner,'link'=>$link,'person'=>$person,'employee'=>$employee];
}

function peopleEmploymentSetupContext(int $tenant,int $employeeId): array
{
    $pdo=getDB();$snapshot=peopleEmploymentSetupSnapshot($pdo,$tenant,$employeeId);
    return ['person'=>$snapshot['person'],'employee'=>$snapshot['employee'],'linked'=>$snapshot['link']!==null,
        'available'=>peopleEmploymentSetupSchema($pdo),'owner_tenant_id'=>$snapshot['owner_tenant_id']];
}

function peopleEmploymentSetupValues(array $snapshot,array $patch): array
{
    if(!$snapshot['link'])throw new RuntimeException('Review and save the person-to-employment link first.');
    $before=$snapshot['employee'];
    if(!in_array($before['status'],['pending','active'],true)||$before['terminated_at']!==null)throw new RuntimeException('Only pending or active employment can use this setup workflow.');
    if(!$patch||array_diff(array_keys($patch),PEOPLE_EMPLOYMENT_SETUP_FIELDS))throw new InvalidArgumentException('Choose supported employment setup fields.');
    $after=array_intersect_key($before,array_flip(PEOPLE_EMPLOYMENT_SETUP_FIELDS));
    $enums=['status'=>['pending','active'],'employment_type'=>['full_time','part_time','contractor','intern','temp'],'flsa_class'=>['exempt','non_exempt']];
    foreach($patch as$field=>$value){
        if($value!==null&&!is_string($value))throw new InvalidArgumentException('Employment fields must be text or empty.');
        $value=$value===null?null:trim($value);if($value==='')$value=null;
        if(isset($enums[$field])&&$value!==null&&!in_array($value,$enums[$field],true))throw new InvalidArgumentException('Choose a valid '.$field.'.');
        if(in_array($field,['hire_date','start_date'],true)&&$value!==null){
            if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)||(int)$m[1]<1000||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))throw new InvalidArgumentException('Choose a valid calendar date.');
        }
        if(in_array($field,['job_title','department','location'],true)&&$value!==null&&(mb_strlen($value)>($field==='job_title'?150:120)||preg_match('/[\x00-\x1F\x7F]/u',$value)))throw new InvalidArgumentException('Employment text is too long or contains control characters.');
        $after[$field]=$value;
    }
    if($after['status']===null||($before['status']==='active'&&$after['status']!=='active'))throw new InvalidArgumentException('This setup workflow cannot suspend or end employment.');
    if($after['hire_date']!==null&&$after['start_date']!==null&&$after['hire_date']>$after['start_date'])throw new InvalidArgumentException('Hire date must be on or before the employment start date.');
    if($after['status']==='active'){
        foreach(['employment_type','flsa_class','hire_date','start_date']as$field)if($after[$field]===null)throw new InvalidArgumentException('Complete employment type, overtime classification, hire date and start date before activating employment.');
        if($after['employment_type']==='contractor')throw new InvalidArgumentException('A W-2 employment record cannot use contractor employment type. Review the worker classification.');
        if(strtolower((string)$snapshot['person']['classification'])!=='w2'||!in_array($snapshot['person']['status'],['active','bench'],true))throw new RuntimeException('Active employment requires a current W-2 person. Review the person first.');
    }
    if($after===array_intersect_key($before,array_flip(PEOPLE_EMPLOYMENT_SETUP_FIELDS))
        &&$after['employment_type']===$snapshot['person']['employment_type']&&$after['hire_date']===$snapshot['person']['hire_date'])throw new InvalidArgumentException('No employment changes to review.');
    return $after;
}

function peopleEmploymentSetupPreview(int $tenant,int $actor,int $employeeId,array $patch,string $reason): array
{
    $reason=trim($reason);if($actor<=0||$reason===''||mb_strlen($reason)>500)throw new InvalidArgumentException('Provide a reason of 1–500 characters.');
    $pdo=getDB();if(!peopleEmploymentSetupSchema($pdo))throw new RuntimeException('Employment setup is not installed.');
    if($pdo->inTransaction())throw new LogicException('Employment review requires its own transaction.');
    $pdo->beginTransaction();
    try{$snapshot=peopleEmploymentSetupSnapshot($pdo,$tenant,$employeeId,true);$after=peopleEmploymentSetupValues($snapshot,$patch);$pdo->rollBack();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $review=['purpose'=>'employment_setup','id'=>bin2hex(random_bytes(32)),'expires_at'=>time()+900,'actor_user_id'=>$actor,'reason'=>$reason,'snapshot'=>$snapshot,'after'=>$after];
    return ['review_token'=>base64_encode(encryptField(integrationDeliveryJson($review))),'person_number'=>$snapshot['person']['person_number'],
        'employee_number'=>$snapshot['employee']['employee_number'],'before'=>array_intersect_key($snapshot['employee'],array_flip(PEOPLE_EMPLOYMENT_SETUP_FIELDS)),
        'person_before'=>array_intersect_key($snapshot['person'],array_flip(['employment_type','hire_date'])),
        'after'=>$after,'reason'=>$reason];
}

function peopleEmploymentSetupApply(int $tenant,int $actor,string $token): array
{
    $review=peopleEmploymentDecode($token);$snapshot=$review['snapshot'];$pdo=getDB();
    if(($review['purpose']??null)!=='employment_setup'||($review['actor_user_id']??null)!==$actor||!is_array($review['after']??null)
        ||($snapshot['request_tenant_id']??null)!==$tenant||($snapshot['instance_id']??null)!==integrationInstanceId())throw new RuntimeException('This setup review belongs to another company, operator or installation.');
    if(!peopleEmploymentSetupSchema($pdo))throw new RuntimeException('Employment setup is not installed.');
    if($pdo->inTransaction())throw new LogicException('Employment setup requires its own transaction.');$pdo->beginTransaction();
    try{
        $owner=peopleEmploymentOwner($tenant,true);
        if($snapshot['owner_tenant_id']!==$owner)throw new RuntimeException('The People directory changed. Review employment again.');
        $s=$pdo->prepare('SELECT * FROM people_employment_reviews WHERE tenant_id=? AND review_id=?');$s->execute([$owner,$review['id']]);$prior=$s->fetch(PDO::FETCH_ASSOC);
        if($prior){
            $original=json_decode(decryptField($prior['review_ct']),true,64,JSON_THROW_ON_ERROR);
            // Expiry protects new writes; a committed operation remains recoverable.
            $compare=$review;unset($compare['expires_at']);unset($original['expires_at']);
            if((int)$prior['actor_user_id']!==$actor||integrationDeliveryHash($compare)!==integrationDeliveryHash($original))throw new RuntimeException('Saved setup review differs from this request.');
            $pdo->commit();return ['id'=>(int)$prior['id'],'employee_id'=>(int)$prior['employee_id'],'replayed'=>true];
        }
        if($review['expires_at']<time())throw new RuntimeException('The setup review expired. Review current employment again.');
        $current=peopleEmploymentSetupSnapshot($pdo,$tenant,(int)$snapshot['employee']['id'],true);
        if(integrationDeliveryHash($current)!==integrationDeliveryHash($snapshot))throw new RuntimeException('Person, employment or directory changed. Review employment again.');
        $after=peopleEmploymentSetupValues($current,$review['after']);$employeeId=(int)$current['employee']['id'];
        $set=implode(',',array_map(static fn($f)=>$f.'=?',PEOPLE_EMPLOYMENT_SETUP_FIELDS));
        $pdo->prepare('UPDATE people_employees SET '.$set.',updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([...array_map(static fn($f)=>$after[$f],PEOPLE_EMPLOYMENT_SETUP_FIELDS),$owner,$employeeId]);
        // These two profile fields describe the same employment facts. The
        // review displays both originals and saves their agreed value together.
        if($after['employment_type']!==$current['person']['employment_type']||$after['hire_date']!==$current['person']['hire_date']){
            $pdo->prepare('UPDATE people SET employment_type=?,hire_date=?,updated_at=NOW() WHERE tenant_id=? AND id=?')
                ->execute([$after['employment_type'],$after['hire_date'],$owner,$current['person']['id']]);
        }
        $activating=$current['employee']['status']==='pending'&&$after['status']==='active';$historyId=null;
        if($activating){
            $pdo->prepare('INSERT INTO people_employment_history(tenant_id,employee_id,event_type,effective_date,job_title,department,location,status,reason,created_by) VALUES(?,?,"hire",?,?,?,?,?,?,?)')
                ->execute([$owner,$employeeId,$after['start_date'],$after['job_title'],$after['department'],$after['location'],$after['status'],mb_substr($review['reason'],0,255),$actor]);
            $historyId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO people_employment_reviews(tenant_id,person_id,employee_id,review_id,actor_user_id,kind,reason,review_ct,history_id) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$owner,$current['person']['id'],$employeeId,$review['id'],$actor,$activating?'activation':'setup_update',$review['reason'],encryptField(integrationDeliveryJson($review)),$historyId]);
        $id=(int)$pdo->lastInsertId();
        $changed=array_keys(array_filter($after,static fn($v,$k)=>$v!==$current['employee'][$k],ARRAY_FILTER_USE_BOTH));
        foreach(['employment_type','hire_date']as$field)if($after[$field]!==$current['person'][$field])$changed[]='person.'.$field;
        $pdo->prepare('INSERT INTO people_change_log(tenant_id,user_id,employee_id,entity,entity_id,action,fields_changed) VALUES(?,?,?,"employment",?,"update",?)')
            ->execute([$owner,$actor,$employeeId,$id,json_encode($changed,JSON_THROW_ON_ERROR)]);
        $pdo->commit();return ['id'=>$id,'employee_id'=>$employeeId,'replayed'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function peopleEmploymentSetupHistory(int $tenant,int $employeeId): array
{
    $pdo=getDB();$context=peopleEmploymentSetupSnapshot($pdo,$tenant,$employeeId);if(!peopleEmploymentSetupSchema($pdo))return [];
    $s=$pdo->prepare('SELECT id,kind,reason,actor_user_id,created_at,review_ct FROM people_employment_reviews WHERE tenant_id=? AND employee_id=? ORDER BY id DESC LIMIT 20');
    $s->execute([$context['owner_tenant_id'],$employeeId]);$rows=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){$review=json_decode(decryptField($row['review_ct']),true,64,JSON_THROW_ON_ERROR);unset($row['review_ct']);
        $row['before']=array_intersect_key($review['snapshot']['employee'],array_flip(PEOPLE_EMPLOYMENT_SETUP_FIELDS));
        $row['person_before']=array_intersect_key($review['snapshot']['person'],array_flip(['employment_type','hire_date']));$row['after']=$review['after'];$rows[]=$row;}
    return $rows;
}

/** Older person editors may retain matching values, but cannot split linked employment facts. */
function peopleEmploymentUpdatePerson(int $tenant,int $personId,array $patch): int
{
    $pdo=getDB();
    if(!array_intersect(array_keys($patch),['employment_type','hire_date']))return scopedUpdate('people',$personId,$patch);
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $owner=peopleEmploymentOwner($tenant,true);
        $link=peopleEmploymentLink($pdo,$owner,$personId);
        if($link){
            $current=peopleEmploymentSetupSnapshot($pdo,$tenant,(int)$link['employee_id'],true);
            foreach(['employment_type','hire_date']as$field)if(array_key_exists($field,$patch)){
                $value=$patch[$field]===''?null:$patch[$field];
                if($value!==$current['person'][$field]||$value!==$current['employee'][$field])throw new RuntimeException('Review employment type and hire date in the linked Employment record.');
                unset($patch[$field]);
            }
        }
        coreRecordNumbersRejectWrite($patch);unset($patch['id'],$patch['tenant_id'],$patch['created_at']);$rows=0;
        if($patch){
            $patch['updated_at']=date('Y-m-d H:i:s');
            $set=implode(',',array_map(static fn($field)=>'`'._safeIdent($field).'`=?',array_keys($patch)));
            $s=$pdo->prepare('UPDATE people SET '.$set.' WHERE tenant_id=? AND id=?');$s->execute([...array_values($patch),$owner,$personId]);$rows=$s->rowCount();
        }
        if($ownsTransaction)$pdo->commit();return $rows;
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
