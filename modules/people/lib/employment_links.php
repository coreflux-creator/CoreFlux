<?php
/** Stable person/employment identity. Names and contacts never establish a link. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/tenant_scope.php';
require_once __DIR__.'/../../../core/sub_tenants.php';
require_once __DIR__.'/../../../core/integrations/delivery.php';
require_once __DIR__.'/../../../core/integrations/identity.php';

function peopleEmploymentOwner(int $requestTenant): int
{
    if ($requestTenant<=0 || $requestTenant!==(int)currentTenantId()) throw new InvalidArgumentException('Choose a person in the current company.');
    // Review/apply must see current ownership, not subTenantLookup's process cache.
    $pdo=getDB();$lock=$pdo->inTransaction()?' FOR UPDATE':'';
    $s=$pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=?'.$lock);
    $s->execute([$requestTenant]);$tenant=$s->fetch(PDO::FETCH_ASSOC);
    if (!$tenant || !(int)$tenant['is_active']) throw new RuntimeException('People directory is unavailable.');
    $owner=$requestTenant;
    if ($tenant['tenant_type']==='sub' && !empty($tenant['parent_id'])) {
        $s=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=? AND module_key="people"'.$lock);
        $s->execute([$requestTenant]);$mode=$s->fetchColumn()?:SUBTENANT_MODULE_SCOPE_DEFAULTS['people'];
        if ($mode==='shared') $owner=(int)$tenant['parent_id'];
    }
    if ($owner!==$requestTenant) {
        $s=$pdo->prepare('SELECT id FROM tenants WHERE id=? AND is_active=1'.$lock);$s->execute([$owner]);
        if (!$s->fetchColumn()) throw new RuntimeException('People directory is unavailable.');
    }
    return $owner;
}

function peopleEmploymentSchema(PDO $pdo): bool
{
    return (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='people_employment_links'")->fetchColumn();
}

function peopleEmploymentLink(PDO $pdo,int $owner,int $personId): ?array
{
    if (!peopleEmploymentSchema($pdo)) return null;
    $s=$pdo->prepare('SELECT id,tenant_id,person_id,employee_id,review_id,actor_user_id,origin,reason,created_at FROM people_employment_links WHERE tenant_id=? AND person_id=?');
    $s->execute([$owner,$personId]); return $s->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Caller already authorized the directory. This read neither creates nor adopts an employee. */
function peopleEmploymentContext(int $tenant,int $personId): array
{
    $pdo=getDB();$owner=peopleEmploymentOwner($tenant);
    $s=$pdo->prepare('SELECT id,tenant_id,first_name,last_name,email_primary,classification,status,deleted_at FROM people WHERE tenant_id=? AND id=?');
    $s->execute([$owner,$personId]);$person=$s->fetch(PDO::FETCH_ASSOC);
    if (!$person || $person['deleted_at']!==null) throw new RuntimeException('Person is unavailable.');
    $person=coreRecordNumbersAttach($pdo,$owner,'person',[$person])[0];
    $link=peopleEmploymentLink($pdo,$owner,$personId);
    $employee=null;
    if ($link) {
        $s=$pdo->prepare('SELECT id,employee_number,legal_first_name,legal_last_name,work_email,status FROM people_employees WHERE tenant_id=? AND id=?');
        $s->execute([$owner,$link['employee_id']]);$employee=$s->fetch(PDO::FETCH_ASSOC)?:null;
        if (!$employee) throw new RuntimeException('The saved employment record is missing; review its history.');
    }
    return ['person'=>$person,'link'=>$link,'employee'=>$employee,'available'=>peopleEmploymentSchema($pdo)];
}

/** Exact contact/account matches are suggestions for review, never identity authority. */
function peopleEmploymentCandidates(PDO $pdo,int $owner,array $person): array
{
    $s=$pdo->prepare('SELECT id,employee_number,legal_first_name,legal_last_name,work_email,status,user_id
        FROM people_employees WHERE tenant_id=? AND
        ((?<>"" AND (LOWER(TRIM(work_email))=? OR LOWER(TRIM(personal_email))=?)) OR (?>0 AND user_id=?)) ORDER BY id');
    $email=strtolower(trim((string)($person['email_primary']??'')));$user=(int)($person['user_id']??0);
    $s->execute([$owner,$email,$email,$email,$user,$user]);return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Lock one owner before absent-link checks so competing creates cannot split an identity. */
function peopleEmploymentSnapshot(PDO $pdo,int $tenant,int $actor,int $personId,?int $employeeId): array
{
    if (!$pdo->inTransaction() || $actor<=0 || $personId<=0 || ($employeeId!==null&&$employeeId<=0)) throw new InvalidArgumentException('Choose a valid person and employment record.');
    $owner=peopleEmploymentOwner($tenant);
    if (!peopleEmploymentSchema($pdo)) throw new RuntimeException('Employment linking is not installed.');
    $s=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$s->execute([$owner]);
    if (!$s->fetchColumn()) throw new RuntimeException('People directory is unavailable.');
    $s=$pdo->prepare('SELECT id,tenant_id,user_id,first_name,last_name,email_primary,classification,status,deleted_at FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $s->execute([$owner,$personId]);$person=$s->fetch(PDO::FETCH_ASSOC);
    if (!$person||$person['deleted_at']!==null||strtolower($person['classification'])!=='w2') throw new RuntimeException('Choose a current W-2 person record.');
    $s=$pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=? AND entity_type="person" AND record_id=? FOR UPDATE');
    $s->execute([$owner,$personId]);$number=$s->fetch(PDO::FETCH_ASSOC);
    if (!$number || (int)$number['canonical_record_id']!==$personId) throw new RuntimeException('Choose the current canonical person record.');
    $person['person_number']=$number['record_number'];
    if (peopleEmploymentLink($pdo,$owner,$personId)) throw new RuntimeException('This person already has a saved employment link.');
    $employee=null;
    if ($employeeId!==null) {
        $s=$pdo->prepare('SELECT id,tenant_id,employee_number,user_id,legal_first_name,legal_last_name,work_email,personal_email,status FROM people_employees WHERE tenant_id=? AND id=? FOR UPDATE');
        $s->execute([$owner,$employeeId]);$employee=$s->fetch(PDO::FETCH_ASSOC);
        if (!$employee) throw new RuntimeException('Employment record is unavailable in this directory.');
        $s=$pdo->prepare('SELECT id FROM people_employment_links WHERE tenant_id=? AND employee_id=? FOR UPDATE');$s->execute([$owner,$employeeId]);
        if ($s->fetchColumn()) throw new RuntimeException('This employment record already belongs to another person.');
        if (!empty($person['user_id'])&&!empty($employee['user_id'])&&(int)$person['user_id']!==(int)$employee['user_id']) throw new RuntimeException('The sign-in accounts disagree. Resolve those identities before linking.');
    } else {
        if (peopleEmploymentCandidates($pdo,$owner,$person)) throw new RuntimeException('An employment record shares this contact or account. Review the existing record before creating another.');
        foreach (['first_name','last_name'] as $field) if (trim((string)$person[$field])===''||mb_strlen($person[$field])>80) throw new RuntimeException('Employment names must contain between 1 and 80 characters.');
    }
    return ['instance_id'=>integrationInstanceId(),'request_tenant_id'=>$tenant,'owner_tenant_id'=>$owner,'actor_user_id'=>$actor,'person'=>$person,'employee'=>$employee];
}

function peopleEmploymentPreview(int $tenant,int $actor,int $personId,?int $employeeId,string $reason): array
{
    $reason=trim($reason);if ($reason===''||mb_strlen($reason)>500) throw new InvalidArgumentException('Provide a reason of 1–500 characters.');
    $pdo=getDB();if ($pdo->inTransaction()) throw new LogicException('Employment review requires its own transaction.');
    $pdo->beginTransaction();
    try {$snapshot=peopleEmploymentSnapshot($pdo,$tenant,$actor,$personId,$employeeId);$pdo->rollBack();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $review=['id'=>bin2hex(random_bytes(32)),'expires_at'=>time()+900,'reason'=>$reason,'snapshot'=>$snapshot];
    return ['review_token'=>base64_encode(encryptField(integrationDeliveryJson($review))),'person'=>$snapshot['person'],'employee'=>$snapshot['employee'],'creates_employee'=>$employeeId===null,'reason'=>$reason];
}

function peopleEmploymentDecode(string $token): array
{
    if (strlen($token)>30000 || ($raw=base64_decode($token,true))===false) throw new InvalidArgumentException('Invalid employment review.');
    try {$review=json_decode(decryptField($raw),true,64,JSON_THROW_ON_ERROR);}
    catch(Throwable $e){throw new InvalidArgumentException('Invalid employment review.');}
    if (!is_array($review)||!preg_match('/^[a-f0-9]{64}$/D',(string)($review['id']??''))||!is_array($review['snapshot']??null)||!is_int($review['expires_at']??null)||!is_string($review['reason']??null)) throw new InvalidArgumentException('Invalid employment review.');
    return $review;
}

function peopleEmploymentInsert(PDO $pdo,array $snapshot,string $reviewId,string $reason,string $origin): array
{
    $owner=(int)$snapshot['owner_tenant_id'];$person=$snapshot['person'];$employeeId=$snapshot['employee']['id']??null;
    if ($employeeId===null) {
        // A canonical person-based number is retry-safe and does not consume an existing legacy number.
        $number='E-'.$owner.'-P'.$person['id'];
        $s=$pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,legal_first_name,legal_last_name,work_email,user_id,status,employment_type,flsa_class) VALUES(?,?,?,?,?,?,"pending",NULL,NULL)');
        $s->execute([$owner,$number,$person['first_name'],$person['last_name'],$person['email_primary']?:null,$person['user_id']?:null]);$employeeId=(int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO people_employment_links(tenant_id,person_id,employee_id,review_id,actor_user_id,origin,reason,review_ct) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$owner,$person['id'],$employeeId,$reviewId,$snapshot['actor_user_id'],$origin,$reason,encryptField(integrationDeliveryJson($snapshot))]);
    return peopleEmploymentLink($pdo,$owner,(int)$person['id']);
}

function peopleEmploymentApply(int $tenant,int $actor,string $token): array
{
    $review=peopleEmploymentDecode($token);$saved=$review['snapshot'];$owner=peopleEmploymentOwner($tenant);
    if (($saved['request_tenant_id']??null)!==$tenant||($saved['owner_tenant_id']??null)!==$owner||($saved['actor_user_id']??null)!==$actor||($saved['instance_id']??null)!==integrationInstanceId()) throw new RuntimeException('This review belongs to another company, operator or installation.');
    $pdo=getDB();if($pdo->inTransaction())throw new LogicException('Employment link requires its own transaction.');$pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT id FROM tenants WHERE id=? FOR UPDATE');$s->execute([$owner]);$s->fetchColumn();
        $s=$pdo->prepare('SELECT * FROM people_employment_links WHERE tenant_id=? AND review_id=?');$s->execute([$owner,$review['id']]);$prior=$s->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            if((int)$prior['actor_user_id']!==$actor||$prior['reason']!==$review['reason']||integrationDeliveryHash(json_decode(decryptField($prior['review_ct']),true,64,JSON_THROW_ON_ERROR))!==integrationDeliveryHash($saved))throw new RuntimeException('Saved review differs from this request.');
            $result=peopleEmploymentLink($pdo,$owner,(int)$prior['person_id']);$pdo->commit();return ['link'=>$result,'replayed'=>true];
        }
        if ($review['expires_at']<time()) throw new RuntimeException('The review expired. Review the current records again.');
        $snapshot=peopleEmploymentSnapshot($pdo,$tenant,$actor,(int)$saved['person']['id'],isset($saved['employee']['id'])?(int)$saved['employee']['id']:null);
        if (integrationDeliveryHash($snapshot)!==integrationDeliveryHash($saved)) throw new RuntimeException('A person, employment record or directory changed. Review the link again.');
        $link=peopleEmploymentInsert($pdo,$snapshot,$review['id'],$review['reason'],'reviewed');$pdo->commit();return ['link'=>$link,'replayed'=>false];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Called only with the ID returned by an explicit new-person creation. Never scans legacy names. */
function peopleEmploymentForNewPerson(int $personId,int $actor): array
{
    $tenant=(int)currentTenantId();$owner=peopleEmploymentOwner($tenant);$pdo=getDB();
    if($existing=peopleEmploymentLink($pdo,$owner,$personId))return $existing;
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try {
        $snapshot=peopleEmploymentSnapshot($pdo,$tenant,$actor,$personId,null);
        $link=peopleEmploymentInsert($pdo,$snapshot,bin2hex(random_bytes(32)),'Employment created with the new Core person.','new_person');
        if($owns)$pdo->commit();return $link;
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
