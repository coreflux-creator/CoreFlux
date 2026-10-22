<?php
/** Durable review and single-attempt delivery. Provider adapters own authorization and IO. */
declare(strict_types=1);
require_once __DIR__ . '/../encryption.php';

function integrationDeliveryCanonical(mixed $value): mixed
{
    if (!is_array($value)) {
        if (is_object($value) || is_resource($value) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('Unsupported integration value');
        }
        return $value;
    }
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    return array_map('integrationDeliveryCanonical', $value);
}

function integrationDeliveryJson(array $value): string
{
    return json_encode(integrationDeliveryCanonical($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
}

function integrationDeliveryHash(array $value): string
{
    return hash('sha256', integrationDeliveryJson($value));
}

/** Explicit PDO prevents accidental use of a different environment's database. */
function integrationDeliveryGet(PDO $pdo, int $tenantId, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id');
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('Delivery is not in this workspace');
    return $row;
}

/** Never expose ciphertext, credential fingerprints, or a claim token through an API. */
function integrationDeliverySummary(array $row): array
{
    return array_intersect_key($row, array_flip([
        'id', 'provider', 'entity_type', 'entity_tenant_id', 'entity_id', 'operation',
        'state', 'review_hash', 'remote_id', 'remote_receipt_id', 'error_code', 'created_at', 'expires_at',
        'approved_at', 'attempted_at', 'finished_at',
    ]));
}

/** Repeated previews of identical inputs reuse the durable envelope, including terminal states. */
function integrationDeliveryPreview(PDO $pdo, int $tenantId, int $actorId, array $context, array $payload): array
{
    if ($tenantId <= 0 || $actorId <= 0) throw new InvalidArgumentException('Workspace and actor required');
    $required = ['provider','connection_ref','binding_hash','entity_type','entity_tenant_id','entity_id','operation','source_hash','remote_hash'];
    if (array_diff($required, array_keys($context)) || array_diff(array_keys($context), $required)) {
        throw new InvalidArgumentException('Complete delivery context required');
    }
    if (!in_array($context['provider'], ['quanta','purepay','purepayroll'], true)) throw new InvalidArgumentException('Unsupported delivery provider');
    // Every operation added here needs a reviewed adapter and fixture before it can be queued.
    $operations=['person'=>['worker.create','worker.update','person.link','worker.profile.create','worker.profile.update'],'placement'=>['assignment.create','assignment.update']];
    $quanta=$context['provider']==='quanta' && in_array($context['operation'],$operations[$context['entity_type']]??[],true);
    $purepay=$context['provider']==='purepay' && $context['operation']==='record.link' && in_array($context['entity_type'],['person','placement','client','vendor'],true);
    $payroll=$context['provider']==='purepayroll' && $context['entity_type']==='person' && in_array($context['operation'],['payroll.inputs','people.inputs'],true);
    $assignment=$context['provider']==='purepayroll' && $context['entity_type']==='placement' && $context['operation']==='assignments.inputs';
    if (!$quanta && !$purepay && !$payroll && !$assignment) {
        throw new InvalidArgumentException('This delivery operation is not implemented');
    }
    foreach (['binding_hash','source_hash','remote_hash'] as $key) {
        if (!is_string($context[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $context[$key])) throw new InvalidArgumentException('Invalid delivery revision');
    }
    foreach (['connection_ref'=>128,'entity_type'=>32,'operation'=>64] as $key => $limit) {
        if (!is_string($context[$key]) || strlen($context[$key]) > $limit || !preg_match('/^[a-zA-Z0-9_.:-]+$/D', $context[$key])) throw new InvalidArgumentException('Invalid delivery identity');
    }
    foreach (['entity_tenant_id','entity_id'] as $key) {
        if (!is_int($context[$key]) || $context[$key] <= 0) throw new InvalidArgumentException('Canonical record owner and identity required');
    }
    $json = integrationDeliveryJson($payload);
    if (strlen($json) > ($assignment?210000:65536)) throw new InvalidArgumentException('Delivery payload exceeds the review limit');
    $review = integrationDeliveryHash(['context'=>$context, 'payload'=>$payload]);
    $subject = integrationDeliveryHash(array_intersect_key($context, array_flip(['provider','connection_ref','entity_type','entity_tenant_id','entity_id'])));
    $params = $context + ['t'=>$tenantId, 'u'=>$actorId, 'review'=>$review, 'dedupe'=>$review,
        'subject'=>$subject, 'payload'=>encryptField($json), 'idem'=>bin2hex(random_bytes(32))];
    $pdo->prepare('INSERT IGNORE INTO integration_delivery_subjects (tenant_id,subject_hash) VALUES (:t,:s)')->execute(['t'=>$tenantId,'s'=>$subject]);
    $pdo->prepare('INSERT INTO integration_deliveries
        (tenant_id,provider,connection_ref,binding_hash,entity_type,entity_tenant_id,entity_id,operation,
         source_hash,remote_hash,review_hash,dedupe_hash,subject_hash,payload_ct,idempotency_key,created_by,expires_at)
        VALUES (:t,:provider,:connection_ref,:binding_hash,:entity_type,:entity_tenant_id,:entity_id,:operation,
         :source_hash,:remote_hash,:review,:dedupe,:subject,:payload,:idem,:u,DATE_ADD(NOW(),INTERVAL 30 MINUTE))
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute($params);
    $id=(int)$pdo->lastInsertId();
    // A fresh preview can renew an expired, never-attempted review; it cannot revive an attempt.
    $pdo->prepare('UPDATE integration_deliveries SET state="preview",created_by=:u,reviewed_by=NULL,approved_at=NULL,
        expires_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE)
        WHERE tenant_id=:t AND id=:id AND state IN ("preview","approved") AND attempted_at IS NULL AND expires_at<=NOW()')
        ->execute(['u'=>$actorId,'t'=>$tenantId,'id'=>$id]);
    return integrationDeliveryGet($pdo, $tenantId, $id);
}

function integrationDeliveryApprove(PDO $pdo, int $tenantId, int $id, int $actorId, string $reviewHash): array
{
    if ($actorId <= 0) throw new InvalidArgumentException('Reviewer required');
    $stmt = $pdo->prepare('UPDATE integration_deliveries SET state="approved",reviewed_by=:u,approved_at=NOW()
        WHERE tenant_id=:t AND id=:id AND review_hash=:h AND state="preview" AND expires_at>NOW()');
    $stmt->execute(['u'=>$actorId,'t'=>$tenantId,'id'=>$id,'h'=>$reviewHash]);
    if ($stmt->rowCount() !== 1) throw new RuntimeException('Preview expired or changed; review the current delivery');
    return integrationDeliveryGet($pdo, $tenantId, $id);
}

/**
 * Claim commits BEFORE provider IO. No automatic retry from sending/uncertain/failed.
 * Caller must verify tenant/organization, capability, source and remote snapshots afresh.
 * An attempt is exclusive. An interrupted sending row requires reconciliation; it never expires into a retry.
 */
function integrationDeliveryClaim(PDO $pdo, int $tenantId, int $id, array $current): array
{
    if ($pdo->inTransaction()) throw new LogicException('Delivery claims must commit before provider IO');
    $keys = ['binding_hash','source_hash','remote_hash'];
    if (array_diff($keys,array_keys($current)) || array_diff(array_keys($current),$keys)) throw new InvalidArgumentException('Fresh binding, source and remote revisions required');
    foreach ($current as $hash) if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) throw new InvalidArgumentException('Invalid delivery revision');
    $row = integrationDeliveryGet($pdo, $tenantId, $id);
    if ($row['state'] !== 'approved') throw new RuntimeException('Delivery is not approved for an attempt');
    $mismatch = false;
    foreach ($current as $key=>$hash) if (!hash_equals((string)$row[$key], $hash)) $mismatch = true;
    if ($mismatch) {
        $pdo->prepare('UPDATE integration_deliveries SET state="conflict",error_code="revision_changed",finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state="approved"')
            ->execute(['t'=>$tenantId,'id'=>$id]);
        throw new RuntimeException('Connection, Core record or remote record changed after review');
    }
    // Decrypt before claiming: a missing encryption key must not strand an unattempted envelope.
    $payload = json_decode((string)decryptField($row['payload_ct']), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('Invalid encrypted delivery payload');
    $claim = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $subject=$pdo->prepare('SELECT subject_hash FROM integration_delivery_subjects WHERE tenant_id=:t AND subject_hash=:s FOR UPDATE');
        $subject->execute(['t'=>$tenantId,'s'=>$row['subject_hash']]);
        if (!$subject->fetchColumn()) throw new RuntimeException('Delivery subject is unavailable');
        $pending=$pdo->prepare('SELECT id FROM integration_deliveries WHERE tenant_id=:t AND subject_hash=:s AND state IN ("sending","uncertain") LIMIT 1');
        $pending->execute(['t'=>$tenantId,'s'=>$row['subject_hash']]);
        if ($pending->fetchColumn()) throw new RuntimeException('Reconcile the previous attempt before sending another change for this record');
        if (in_array($row['operation'],['worker.create','worker.profile.create','person.link','assignment.create','record.link'],true)) {
            $created=$pdo->prepare('SELECT id FROM integration_deliveries WHERE tenant_id=:t AND subject_hash=:s AND operation=:o AND state="delivered" LIMIT 1');
            $created->execute(['t'=>$tenantId,'s'=>$row['subject_hash'],'o'=>$row['operation']]);
            if ($created->fetchColumn()) throw new RuntimeException('This record was already published; reconcile its identity before publishing an update');
        }
        $stmt = $pdo->prepare('UPDATE integration_deliveries SET state="sending",claim_hash=:c,attempted_at=NOW()
            WHERE tenant_id=:t AND id=:id AND state="approved" AND expires_at>NOW()');
        $stmt->execute(['c'=>hash('sha256',$claim),'t'=>$tenantId,'id'=>$id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Delivery expired or another attempt already claimed it');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['claim'=>$claim, 'idempotency_key'=>$row['idempotency_key'], 'payload'=>$payload];
}

/** Errors are codes only: provider error text may contain private payloads or credentials. */
function integrationDeliveryFinish(PDO $pdo, int $tenantId, int $id, string $claim, string $state, ?string $remoteId=null, ?string $errorCode=null, ?string $receiptId=null): void
{
    if ($pdo->inTransaction()) throw new LogicException('Delivery results must be durable independently of caller transactions');
    if (!in_array($state,['delivered','uncertain','failed'],true)) throw new InvalidArgumentException('Invalid delivery result');
    if (!preg_match('/^[a-f0-9]{64}$/D',$claim)) throw new InvalidArgumentException('Invalid delivery claim');
    if ($state==='delivered' && ($remoteId===null || $remoteId==='')) throw new InvalidArgumentException('Confirmed remote identity required');
    if ($remoteId!==null && (strlen($remoteId)>128 || preg_match('/[\x00-\x1f\x7f]/',$remoteId))) throw new InvalidArgumentException('Invalid remote identity');
    if ($errorCode!==null && !preg_match('/^[a-z0-9_]{1,64}$/D',$errorCode)) throw new InvalidArgumentException('Use a safe delivery error code');
    if ($receiptId!==null && ($state!=='delivered' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$receiptId))) throw new InvalidArgumentException('Invalid confirmed provider receipt');
    $stmt=$pdo->prepare('UPDATE integration_deliveries SET state=:s,remote_id=:r,remote_receipt_id=:receipt,error_code=:e,finished_at=NOW(),claim_hash=NULL
        WHERE tenant_id=:t AND id=:id AND state="sending" AND claim_hash=:c');
    $stmt->execute(['s'=>$state,'r'=>$remoteId,'receipt'=>$receiptId,'e'=>$errorCode,'t'=>$tenantId,'id'=>$id,'c'=>hash('sha256',$claim)]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('Delivery result does not match the active attempt');
}

function integrationDeliveryCancel(PDO $pdo, int $tenantId, int $id): void
{
    $stmt=$pdo->prepare('UPDATE integration_deliveries SET state="cancelled",finished_at=NOW()
        WHERE tenant_id=:t AND id=:id AND state IN ("preview","approved")');
    $stmt->execute(['t'=>$tenantId,'id'=>$id]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('An attempted delivery must be reconciled, not cancelled');
}
