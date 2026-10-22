<?php
/** Reviewed candidate links. Provider reads only; no profile edits or invitations. */
declare(strict_types=1);
require_once __DIR__.'/sync_placements.php';
require_once __DIR__.'/person_directory.php';
require_once __DIR__.'/../record_numbers.php';
require_once __DIR__.'/../integrations/identity.php';

function jobdivaPersonReviewBinding(PDO $pdo, int $tenant, bool $lock=false): string
{
    $s=$pdo->prepare('SELECT id,client_id,username,password_enc,status FROM jobdiva_connections WHERE tenant_id=:t'.($lock?' FOR UPDATE':''));
    $s->execute(['t'=>$tenant]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row || !in_array($row['status'],['connected','degraded'],true)) throw new RuntimeException('Connect JobDiva before reviewing a candidate');
    unset($row['status']); // Auth refresh and health changes do not change the account.
    $instance=integrationInstanceId();
    if (!$instance) throw new RuntimeException('The permanent Core installation identity is unavailable');
    return mappingHash(['instance'=>$instance,'connection'=>$row]);
}

function jobdivaPersonReviewRequest(array $input): array
{
    if (array_diff(array_keys($input),['candidate_id','person_number'])
        || !is_string($input['candidate_id']??null) || !preg_match('/^[1-9][0-9]{0,17}$/D',$input['candidate_id'])
        || !is_string($input['person_number']??null) || !preg_match('/^P-[1-9][0-9]{0,17}$/D',$input['person_number'])) {
        throw new InvalidArgumentException('Enter the JobDiva candidate ID and the existing Core person number (P-…)');
    }
    return $input;
}

/** Optional reader is an internal test dependency, never accepted from an API request. */
function jobdivaPersonReviewSource(int $tenant, string $candidate, ?callable $reader=null): array
{
    $response=$reader ? $reader($tenant,$candidate) : jobdivaCall($tenant,'POST','/apiv2/jobdiva/searchCandidate',['candidateId'=>$candidate]);
    $rows=jobdivaRowsFromResponse($response);
    if (count($rows)!==1 || !is_array($rows[0])) throw new RuntimeException('JobDiva did not return exactly one candidate. Review the source ID');
    $row=$rows[0]; $ids=[];
    foreach (['id','candidateId','candidate_id','candidate id','candidateID'] as $key) {
        if (isset($row[$key])) {
            if (!is_int($row[$key]) && !is_string($row[$key])) throw new RuntimeException('JobDiva returned an invalid candidate ID');
            $ids[]=(string)$row[$key];
        }
    }
    if (!$ids || array_values(array_unique($ids))!==[$candidate]) throw new RuntimeException('JobDiva returned a different or ambiguous candidate ID');
    $first=jobdivaPluckField($row,['firstName','first_name','candidateFirstName']);
    $last=jobdivaPluckField($row,['lastName','last_name','candidateLastName']);
    $name=trim($first.' '.$last) ?: jobdivaPluckField($row,['candidateName','fullName','name']);
    if ($name==='') throw new RuntimeException('JobDiva returned no candidate name to review');
    return ['candidate_id'=>$candidate,'name'=>$name,
        'email'=>jobdivaPluckField($row,['email','emailAddress','candidateEmail','email_primary']),
        'phone'=>jobdivaPluckField($row,['phone','phoneNumber','candidatePhone','phone_primary'])];
}

function jobdivaPersonReviewTarget(PDO $pdo,int $tenant,array $request,bool $lock=false): array
{
    $suffix=$lock?' FOR UPDATE':'';
    $s=$pdo->prepare('SELECT p.id,p.first_name,p.last_name,p.email_primary,p.phone_primary,p.external_id,p.status,n.record_number AS person_number FROM people p JOIN core_record_numbers n ON n.tenant_id=p.tenant_id AND n.entity_type="person" AND n.record_id=p.id AND n.canonical_record_id=p.id WHERE p.tenant_id=:t AND n.record_number=:n AND p.deleted_at IS NULL'.$suffix);
    $s->execute(['t'=>$tenant,'n'=>$request['person_number']]); $target=$s->fetch(PDO::FETCH_ASSOC);
    if (!$target) throw new RuntimeException('Choose a current person from this Core workspace');
    if (jobdivaPersonExternalIdConflicts((string)$target['external_id'],$request['candidate_id'])) throw new RuntimeException('This person carries another JobDiva candidate ID. Review the existing identity before linking');
    $s=$pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND external_id=:e AND id<>:p'.$suffix);
    $s->execute(['t'=>$tenant,'e'=>'jd:'.$request['candidate_id'],'p'=>$target['id']]);
    if ($s->fetchColumn()) throw new RuntimeException('Another Core person already carries this JobDiva candidate ID');
    $s=$pdo->prepare('SELECT external_id,internal_entity_id FROM external_entity_mappings WHERE tenant_id=:t AND source_system="jobdiva" AND internal_entity_type="person" AND (external_id=:e OR internal_entity_id=:p)'.$suffix);
    $s->execute(['t'=>$tenant,'e'=>$request['candidate_id'],'p'=>$target['id']]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $link) {
        if ($link['external_id']!==$request['candidate_id'] || (int)$link['internal_entity_id']!==(int)$target['id']) throw new RuntimeException('This candidate or person is already linked elsewhere. Existing links cannot be replaced here');
    }
    return $target;
}

function jobdivaPersonReviewPreview(int $tenant,int $actor,array $input,?callable $reader=null): array
{
    if ($actor<=0) throw new InvalidArgumentException('A current reviewer is required');
    $request=jobdivaPersonReviewRequest($input); $pdo=getDB();
    jobdivaPersonDirectoryCheck($pdo,$tenant); $binding=jobdivaPersonReviewBinding($pdo,$tenant);
    $source=jobdivaPersonReviewSource($tenant,$request['candidate_id'],$reader);
    if (!hash_equals($binding,jobdivaPersonReviewBinding($pdo,$tenant))) throw new RuntimeException('The JobDiva connection changed. Review again');
    jobdivaPersonDirectoryCheck($pdo,$tenant); $target=jobdivaPersonReviewTarget($pdo,$tenant,$request);
    $review=['kind'=>'jobdiva_person_link_v1','id'=>bin2hex(random_bytes(32)),'tenant'=>$tenant,'actor'=>$actor,
        'expires_at'=>time()+1800,'binding'=>$binding,'request'=>$request,'source'=>$source,'target'=>$target];
    return ['review_token'=>base64_encode(encryptField(json_encode($review,JSON_THROW_ON_ERROR))),
        'source'=>$source,'target'=>$target,'expires_at'=>gmdate('c',$review['expires_at'])];
}

function jobdivaPersonReviewOpen(int $tenant,int $actor,string $token): array
{
    if (strlen($token)>50000) throw new InvalidArgumentException('Invalid candidate review');
    try {$bytes=base64_decode($token,true); if ($bytes===false) throw new RuntimeException();
        $review=json_decode((string)decryptField($bytes),true,64,JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {throw new InvalidArgumentException('This review is invalid. Preview again');}
    if (($review['kind']??null)!=='jobdiva_person_link_v1' || ($review['tenant']??null)!==$tenant || ($review['actor']??null)!==$actor
        || !preg_match('/^[a-f0-9]{64}$/D',(string)($review['id']??''))) throw new InvalidArgumentException('This review belongs to another workspace or reviewer');
    return $review;
}

function jobdivaPersonReviewReceipt(PDO $pdo,int $tenant,array $review): ?array
{
    $s=$pdo->prepare('SELECT actor_id,binding_hash,result_ct FROM jobdiva_person_link_receipts WHERE tenant_id=:t AND review_id=:r');
    $s->execute(['t'=>$tenant,'r'=>$review['id']]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ((int)$row['actor_id']!==$review['actor'] || !hash_equals($row['binding_hash'],$review['binding'])) throw new RuntimeException('The receipt does not match this review');
    return json_decode((string)decryptField($row['result_ct']),true,64,JSON_THROW_ON_ERROR);
}

function jobdivaPersonReviewStatus(int $tenant,int $actor,string $token): array
{
    $review=jobdivaPersonReviewOpen($tenant,$actor,$token); $pdo=getDB();
    if ($pdo->inTransaction()) throw new LogicException('Identity review needs its own transaction');
    $pdo->beginTransaction();
    try {
        jobdivaPersonDirectoryCheck($pdo,$tenant,true);
        if (!hash_equals($review['binding'],jobdivaPersonReviewBinding($pdo,$tenant,true))) throw new RuntimeException('The JobDiva connection changed. Review again');
        $result=jobdivaPersonReviewReceipt($pdo,$tenant,$review); $pdo->commit();
        return ['state'=>$result?'applied':'not_applied','result'=>$result];
    } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack(); throw $e;}
}

function jobdivaPersonReviewApply(int $tenant,int $actor,string $token,?callable $reader=null): array
{
    $review=jobdivaPersonReviewOpen($tenant,$actor,$token);
    $receipt=jobdivaPersonReviewStatus($tenant,$actor,$token);
    if ($receipt['state']==='applied') return $receipt['result']+['replayed'=>true];
    if ($review['expires_at']<time()) throw new RuntimeException('This candidate review expired. Preview again');
    $source=jobdivaPersonReviewSource($tenant,$review['request']['candidate_id'],$reader);
    if (mappingHash($source)!==mappingHash($review['source'])) throw new RuntimeException('The JobDiva candidate changed after review. Preview again');
    $pdo=getDB(); $pdo->beginTransaction();
    try {
        jobdivaPersonDirectoryCheck($pdo,$tenant,true);
        if (!hash_equals($review['binding'],jobdivaPersonReviewBinding($pdo,$tenant,true))) throw new RuntimeException('The JobDiva connection changed. Review again');
        $receipt=jobdivaPersonReviewReceipt($pdo,$tenant,$review);
        if ($receipt) {$pdo->commit(); return $receipt+['replayed'=>true];}
        $target=jobdivaPersonReviewTarget($pdo,$tenant,$review['request'],true);
        if (mappingHash($target)!==mappingHash($review['target'])) throw new RuntimeException('The Core person changed after review. Preview again');
        mappingUpsert($tenant,'jobdiva','person',$source['candidate_id'],(int)$target['id'],['candidateId'=>$source['candidate_id'],'_coreflux_identity_review'=>$source],'pull',$actor);
        $result=['candidate_id'=>$source['candidate_id'],'person_id'=>(int)$target['id'],'person_number'=>$target['person_number'],'review_id'=>$review['id']];
        $pdo->prepare('INSERT INTO jobdiva_person_link_receipts(tenant_id,review_id,actor_id,binding_hash,review_ct,result_ct) VALUES(:t,:r,:a,:b,:v,:c)')
            ->execute(['t'=>$tenant,'r'=>$review['id'],'a'=>$actor,'b'=>$review['binding'],'v'=>encryptField(json_encode($review,JSON_THROW_ON_ERROR)),'c'=>encryptField(json_encode($result,JSON_THROW_ON_ERROR))]);
        $pdo->commit(); return $result;
    } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack(); throw $e;}
}
