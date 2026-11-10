<?php
/** Publish a Core-owned contact snapshot and retain immutable proof for reverse proposals. */
declare(strict_types=1);
require_once __DIR__ . '/profile_baseline_contract.php';
require_once __DIR__ . '/worker_delivery.php';

function quantaProfileReturnEnabled(int $tenant): bool
{
    return integrationDeliveryFlagEnabled('COREFLUX_QUANTA_PROFILE_RETURN') && integrationDeliveryTenantAllowed($tenant);
}

function quantaProfileBaselineAccess(int $tenant): array
{
    $access = quantaWorkspaceForDelivery($tenant);
    quantaProfileChangeRequire($access['api_key'], $access['context']['workspace']['id']);
    quantaWorkspaceAssertCurrent(getDB(), $tenant, $access);
    return $access;
}

function quantaProfileFromPerson(array $person): array
{
    $profile = [];
    foreach (QUANTA_PROFILE_FIELDS as $field) {
        $value = trim((string)($person[$field] ?? ''));
        if ($field === 'email_primary') $value = strtolower($value);
        $profile[$field] = in_array($field, ['email_primary', 'phone_primary'], true) && $value === '' ? null : $value;
    }
    return quantaProfileValuesValidate($profile);
}

function quantaProfileLinkedWorker(PDO $pdo, int $tenant, int $person, bool $lock = false): string
{
    $s = $pdo->prepare('SELECT worker_id FROM quanta_worker_links WHERE tenant_id=:t AND person_id=:p' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute(['t' => $tenant, 'p' => $person]); $workers = $s->fetchAll(PDO::FETCH_COLUMN);
    if (count($workers) !== 1 || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $workers[0])) {
        throw new RuntimeException('Link this person to one reviewed Quanta worker first.');
    }
    return $workers[0];
}

function quantaProfileBaselineSaved(PDO $pdo, int $tenant, string $binding, int $owner, int $personId, array $baseline): bool
{
    $s = $pdo->prepare('SELECT * FROM quanta_profile_baselines WHERE tenant_id=:t AND binding_hash=:b AND baseline_id=:id AND baseline_version=:v');
    $s->execute(['t' => $tenant, 'b' => $binding, 'id' => $baseline['id'], 'v' => $baseline['version']]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $saved = json_decode((string)decryptField($row['baseline_ct']), true, 64, JSON_THROW_ON_ERROR);
    if ((int)$row['people_tenant_id'] !== $owner || (int)$row['person_id'] !== $personId
        || $row['source_workspace_id'] !== $baseline['tenant_id'] || $row['worker_id'] !== $baseline['worker_id']
        || !hash_equals($row['baseline_hash'], quantaProfileDigest($baseline))
        || quantaProfileDigest($saved) !== quantaProfileDigest($baseline)) {
        throw new RuntimeException('The source baseline differs from Core’s saved publication evidence.');
    }
    $delivery = integrationDeliveryGet($pdo, $tenant, (int)$row['delivery_id']);
    $payload = json_decode((string)decryptField($delivery['payload_ct']), true, 64, JSON_THROW_ON_ERROR);
    if ($delivery['state'] !== 'delivered' || $delivery['provider'] !== 'quanta'
        || $delivery['operation'] !== 'profile.baseline.publish' || $delivery['entity_type'] !== 'person'
        || (int)$delivery['entity_tenant_id'] !== $owner || (int)$delivery['entity_id'] !== $personId
        || !hash_equals($delivery['binding_hash'], $binding) || $delivery['remote_receipt_id'] !== $row['provider_receipt_id']
        || $delivery['remote_id'] !== $baseline['id'] || ($payload['contract_version'] ?? null) !== QUANTA_PROFILE_CHANGE_VERSION
        || ($payload['workspace_id'] ?? null) !== $baseline['tenant_id']
        || ($payload['body']['core_snapshot_hash'] ?? null) !== $delivery['source_hash']) {
        throw new RuntimeException('The baseline has no matching completed Core delivery.');
    }
    quantaProfileBaselineConfirm(['baseline' => $baseline, 'delivery_id' => $row['provider_receipt_id']],
        $row['source_workspace_id'], $payload['body'], $payload['body']['expected_version'] === 0 ? 201 : 200);
    return true;
}

function quantaProfileBaselineSnapshot(int $tenant, int $personId): array
{
    $pdo = getDB(); $access = quantaProfileBaselineAccess($tenant); $workspace = $access['context']['workspace']['id'];
    $owner = effectiveTenantIdForModule('people', $tenant) ?? $tenant;
    $source = quantaWorkerDeliverySource($pdo, $access['instance_id'], $owner, $personId);
    if ($source['record']['status'] !== 'active') throw new RuntimeException('Only an active Core person can publish contact details.');
    $worker = quantaProfileLinkedWorker($pdo, $tenant, $personId);
    $link = quantaContractRecord($access['api_key'], $workspace, $source['reference']);
    if (!$link || $link['worker_id'] !== $worker) throw new RuntimeException('Reconcile the shared person identity before publishing contact details.');
    $remote = quantaProfileBaselineRead($access['api_key'], $workspace, $source['reference'], $worker);
    $body = quantaProfileBaselineBodyValidate(['person' => $source['reference'], 'worker_id' => $worker,
        'expected_version' => $remote['version'] ?? 0, 'core_snapshot_hash' => integrationDeliveryHash($source),
        'profile' => quantaProfileFromPerson($source['record'])]);
    $known = $remote && quantaProfileBaselineSaved($pdo, $tenant, $access['binding_hash'], $owner, $personId, $remote);
    $same = $known && $remote['core_snapshot_hash'] === $body['core_snapshot_hash']
        && quantaProfileDigest($remote['profile']) === quantaProfileDigest($body['profile']);
    quantaWorkspaceAssertCurrent($pdo, $tenant, $access);
    $context = ['provider' => 'quanta', 'connection_ref' => (string)$access['connection']['id'], 'binding_hash' => $access['binding_hash'],
        'entity_type' => 'person', 'entity_tenant_id' => $owner, 'entity_id' => $personId, 'operation' => 'profile.baseline.publish',
        'source_hash' => integrationDeliveryHash($source), 'remote_hash' => integrationDeliveryHash(['worker_id' => $worker, 'baseline' => $remote])];
    return ['access' => $access, 'source' => $source, 'context' => $context, 'status' => $same ? 'unchanged' : 'preview',
        'payload' => ['contract_version' => QUANTA_PROFILE_CHANGE_VERSION, 'workspace_id' => $workspace, 'body' => $body]];
}

function quantaProfileBaselinePreview(int $tenant, int $actor, int $personId): array
{
    $snapshot = quantaProfileBaselineSnapshot($tenant, $personId);
    $result = ['status' => $snapshot['status'], 'person_id' => $personId, 'reference' => $snapshot['source']['reference'],
        'profile' => $snapshot['payload']['body']['profile'], 'delivery_enabled' => quantaProfileReturnEnabled($tenant)];
    if ($snapshot['status'] === 'preview') $result['delivery'] = integrationDeliverySummary(
        integrationDeliveryPreview(getDB(), $tenant, $actor, $snapshot['context'], $snapshot['payload']));
    return $result;
}

function quantaProfileBaselineEnvelope(int $tenant, int $id, string $reviewHash): array
{
    $row = integrationDeliveryGet(getDB(), $tenant, $id);
    if ($row['provider'] !== 'quanta' || $row['entity_type'] !== 'person' || $row['operation'] !== 'profile.baseline.publish'
        || !hash_equals($row['review_hash'], $reviewHash)) throw new InvalidArgumentException('Choose the reviewed contact baseline delivery.');
    $payload = json_decode((string)decryptField($row['payload_ct']), true, 64, JSON_THROW_ON_ERROR);
    if (($payload['contract_version'] ?? null) !== QUANTA_PROFILE_CHANGE_VERSION || !is_array($payload['body'] ?? null)) {
        throw new RuntimeException('This is not a current contact baseline review.');
    }
    $body = quantaProfileBaselineBodyValidate($payload['body']);
    $owner = effectiveTenantIdForModule('people', $tenant) ?? $tenant; $instance = integrationInstanceId();
    if (!$instance || (int)$row['entity_tenant_id'] !== $owner || $body['person']['directory_tenant_id'] !== $owner
        || $body['person']['instance_id'] !== $instance || $body['core_snapshot_hash'] !== $row['source_hash']) {
        throw new RuntimeException('The Core directory or reviewed snapshot changed.');
    }
    $source = quantaWorkerDeliverySource(getDB(), $instance, $owner, (int)$row['entity_id']);
    if (quantaProfileDigest($source['reference']) !== quantaProfileDigest($body['person'])) throw new RuntimeException('The canonical person identity changed.');
    return [$row, $payload];
}

/** Receipt and saved baseline commit together; later Core edits do not rewrite this history. */
function quantaProfileBaselineComplete(int $tenant, int $actor, array $row, array $payload, array $access, array $record, ?string $claim): array
{
    quantaProfileBaselineConfirm($record, $payload['workspace_id'], $payload['body'], $payload['body']['expected_version'] === 0 ? 201 : 200);
    if (!hash_equals($row['binding_hash'], $access['binding_hash']) || $payload['workspace_id'] !== $access['context']['workspace']['id']) {
        throw new RuntimeException('Restore the original reviewed Quanta connection.');
    }
    $pdo = getDB(); if ($pdo->inTransaction()) throw new LogicException('Baseline completion requires its own transaction.');
    $baseline = $record['baseline']; $pdo->beginTransaction();
    try {
        quantaWorkspaceAssertCurrent($pdo, $tenant, $access, true);
        quantaWorkerDeliveryDirectoryLock($pdo, $tenant, (int)$row['entity_tenant_id']);
        $s = $pdo->prepare('SELECT subject_hash FROM integration_delivery_subjects WHERE tenant_id=:t AND subject_hash=:s FOR UPDATE');
        $s->execute(['t' => $tenant, 's' => $row['subject_hash']]); if (!$s->fetchColumn()) throw new RuntimeException('Delivery subject unavailable.');
        $s = $pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=:t AND id=:id FOR UPDATE');
        $s->execute(['t' => $tenant, 'id' => $row['id']]); $live = $s->fetch(PDO::FETCH_ASSOC);
        if (!$live || !hash_equals($live['review_hash'], $row['review_hash'])) throw new RuntimeException('The reviewed delivery changed.');
        if ($live['state'] === 'delivered') { $pdo->commit(); return integrationDeliverySummary($live); }
        if (!in_array($live['state'], ['sending', 'uncertain'], true)
            || ($claim !== null && ($live['state'] !== 'sending' || !hash_equals((string)$live['claim_hash'], hash('sha256', $claim))))) {
            throw new RuntimeException('This baseline is not awaiting a receipt.');
        }
        $s = $pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL FOR UPDATE');
        $s->execute(['t' => $row['entity_tenant_id'], 'p' => $row['entity_id']]); if (!$s->fetchColumn()) throw new RuntimeException('The Core person is unavailable.');
        $source = quantaWorkerDeliverySource($pdo, $access['instance_id'], (int)$row['entity_tenant_id'], (int)$row['entity_id']);
        if (quantaProfileDigest($source['reference']) !== quantaProfileDigest($baseline['person'])
            || quantaProfileLinkedWorker($pdo, $tenant, (int)$row['entity_id'], true) !== $baseline['worker_id']) {
            throw new RuntimeException('The worker identity changed before receipt completion.');
        }
        $s = $pdo->prepare('SELECT baseline_hash FROM quanta_profile_baselines WHERE tenant_id=:t AND binding_hash=:b AND baseline_id=:id AND baseline_version=:v FOR UPDATE');
        $params = ['t' => $tenant, 'b' => $row['binding_hash'], 'id' => $baseline['id'], 'v' => $baseline['version']];
        $s->execute($params); $old = $s->fetchColumn(); $hash = quantaProfileDigest($baseline);
        if ($old !== false && !hash_equals($old, $hash)) throw new RuntimeException('This baseline version already has different Core evidence.');
        if ($old === false) $pdo->prepare('INSERT INTO quanta_profile_baselines
            (tenant_id,binding_hash,baseline_id,baseline_version,people_tenant_id,person_id,source_workspace_id,worker_id,baseline_hash,baseline_ct,delivery_id,provider_receipt_id)
            VALUES(:t,:b,:id,:v,:o,:p,:w,:worker,:h,:ct,:d,:r)')->execute($params + ['o' => $row['entity_tenant_id'], 'p' => $row['entity_id'],
                'w' => $payload['workspace_id'], 'worker' => $baseline['worker_id'], 'h' => $hash, 'ct' => encryptField(integrationDeliveryJson($baseline)),
                'd' => $row['id'], 'r' => $record['delivery_id']]);
        $pdo->prepare('UPDATE integration_deliveries SET state="delivered",remote_id=:b,remote_receipt_id=:r,error_code=NULL,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id')
            ->execute(['b' => $baseline['id'], 'r' => $record['delivery_id'], 't' => $tenant, 'id' => $row['id']]);
        platformAuditLogWrite($tenant, $actor, 'quanta.profile_baseline.delivered', (int)$row['entity_id'],
            ['delivery_id' => (int)$row['id'], 'baseline_version' => $baseline['version'], 'provider_receipt' => $record['delivery_id']], ['source' => 'quanta']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return integrationDeliverySummary(integrationDeliveryGet($pdo, $tenant, (int)$row['id']));
}

function quantaProfileBaselineSend(int $tenant, int $actor, int $id, string $reviewHash): array
{
    if (!quantaProfileReturnEnabled($tenant)) throw new RuntimeException('Profile return is not enabled for this company.');
    [$row, $payload] = quantaProfileBaselineEnvelope($tenant, $id, $reviewHash);
    if ($row['state'] === 'delivered') return integrationDeliverySummary($row);
    if (!in_array($row['state'], ['preview', 'approved'], true)) throw new RuntimeException('Check the original baseline receipt before another attempt.');
    $snapshot = quantaProfileBaselineSnapshot($tenant, (int)$row['entity_id']);
    if (integrationDeliveryHash($payload) !== integrationDeliveryHash($snapshot['payload'])) throw new RuntimeException('Core or Quanta contacts changed after review. Preview again.');
    $pdo = getDB(); if ($row['state'] === 'preview') integrationDeliveryApprove($pdo, $tenant, $id, $actor, $reviewHash);
    $claim = integrationDeliveryClaim($pdo, $tenant, $id, array_intersect_key($snapshot['context'], array_flip(['binding_hash', 'source_hash', 'remote_hash'])),
        static function () use ($pdo, $tenant, $row, $payload, $snapshot): void {
            quantaWorkspaceAssertCurrent($pdo, $tenant, $snapshot['access'], true);
            quantaWorkerDeliveryDirectoryLock($pdo, $tenant, (int)$row['entity_tenant_id']);
            $s = $pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL FOR UPDATE');
            $s->execute(['t' => $row['entity_tenant_id'], 'p' => $row['entity_id']]);
            if (!$s->fetchColumn()) throw new RuntimeException('The Core person is unavailable.');
            $source = quantaWorkerDeliverySource($pdo, $snapshot['access']['instance_id'], (int)$row['entity_tenant_id'], (int)$row['entity_id']);
            if (integrationDeliveryHash($source) !== $row['source_hash']
                || quantaProfileLinkedWorker($pdo, $tenant, (int)$row['entity_id'], true) !== $payload['body']['worker_id']) {
                throw new RuntimeException('The Core person changed before the delivery was claimed.');
            }
        });
    try {
        $record = quantaWriteProfileBaseline($snapshot['access']['api_key'], $payload['workspace_id'], $payload['body'], $claim['idempotency_key']);
        return quantaProfileBaselineComplete($tenant, $actor, $row, $payload, $snapshot['access'], $record, $claim['claim']);
    } catch (Throwable $e) {
        if (integrationDeliveryGet($pdo, $tenant, $id)['state'] === 'sending') integrationDeliveryFinish($pdo, $tenant, $id, $claim['claim'],
            $e instanceof QuantaContractWriteException && !$e->uncertain ? 'failed' : 'uncertain', null, 'profile_baseline_unconfirmed');
        throw $e;
    }
}

function quantaProfileBaselineStatus(int $tenant, int $actor, int $id, string $reviewHash): array
{
    [$row, $payload] = quantaProfileBaselineEnvelope($tenant, $id, $reviewHash);
    if (!in_array($row['state'], ['sending', 'uncertain'], true)) return ['delivery' => integrationDeliverySummary($row), 'receipt_state' => $row['state'] === 'delivered' ? 'confirmed' : 'not_pending'];
    $access = quantaProfileBaselineAccess($tenant);
    if (!hash_equals($row['binding_hash'], $access['binding_hash']) || $payload['workspace_id'] !== $access['context']['workspace']['id']) {
        throw new RuntimeException('Restore the original reviewed connection before checking the receipt.');
    }
    $receipt = quantaProfileBaselineReceipt($access['api_key'], $payload['workspace_id'], $payload['body'], $row['idempotency_key']);
    if (!$receipt || $receipt['state'] !== 'complete') return ['delivery' => integrationDeliverySummary($row), 'receipt_state' => $receipt['state'] ?? 'not_found'];
    if (in_array($receipt['status_code'] ?? null, [200, 201], true)) return ['receipt_state' => 'confirmed',
        'delivery' => quantaProfileBaselineComplete($tenant, $actor, $row, $payload, $access, $receipt['response'], null)];
    if (!in_array($receipt['status_code'] ?? null, [400, 401, 403, 404, 409, 422], true)) {
        throw new RuntimeException('The provider has not confirmed success or rejection.');
    }
    $pdo = getDB(); if ($pdo->inTransaction()) throw new LogicException('Receipt rejection requires its own transaction.');
    $pdo->beginTransaction();
    try {
        quantaWorkspaceAssertCurrent($pdo, $tenant, $access, true);
        quantaWorkerDeliveryDirectoryLock($pdo, $tenant, (int)$row['entity_tenant_id']);
        $pdo->prepare('UPDATE integration_deliveries SET state="failed",error_code="provider_rejected_baseline",remote_receipt_id=:r,claim_hash=NULL,finished_at=NOW() WHERE tenant_id=:t AND id=:id AND state IN ("sending","uncertain")')
            ->execute(['r' => $receipt['id'], 't' => $tenant, 'id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['delivery' => integrationDeliverySummary(integrationDeliveryGet($pdo, $tenant, $id)), 'receipt_state' => 'rejected'];
}
