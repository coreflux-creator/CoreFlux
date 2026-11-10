<?php
/** Return the original reviewed decision; never rebuild it from current browser values. */
declare(strict_types=1);
require_once __DIR__ . '/profile_review.php';
require_once __DIR__ . '/profile_decision_contract.php';

function quantaProfileReturnAccess(int $tenant): array
{
    $access = quantaWorkspaceForDelivery($tenant);
    quantaProfileChangeRequire($access['api_key'], $access['context']['workspace']['id'], true);
    quantaWorkspaceAssertCurrent(getDB(), $tenant, $access);
    return $access;
}

function quantaProfileReturnKey(int $tenant, array $decision): array
{
    return ['t' => $tenant, 'w' => $decision['source_workspace_id'], 'c' => $decision['change_id']];
}

/** Immutable outbox identity and encryption are checked on every read. */
function quantaProfileReturnEnvelope(PDO $pdo, int $tenant, string $id, array $access, bool $lock = false): array
{
    if (!preg_match('/^cpc_[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('Choose a saved contact decision.');
    $s = $pdo->prepare('SELECT * FROM quanta_profile_decisions WHERE tenant_id=:t AND source_workspace_id=:w AND change_id=:c' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute(['t' => $tenant, 'w' => $access['context']['workspace']['id'], 'c' => $id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('This contact decision is unavailable in this workspace.');
    $proposal = json_decode((string)decryptField($row['proposal_ct']), true, 64, JSON_THROW_ON_ERROR);
    $decision = json_decode((string)decryptField($row['decision_ct']), true, 64, JSON_THROW_ON_ERROR);
    $body = quantaProfileDecisionBody($tenant, $decision, $proposal);
    if (!hash_equals($row['binding_hash'], $access['binding_hash'])
        || $proposal['tenant_id'] !== $row['source_workspace_id']
        || $body['change_id'] !== $row['change_id'] || $body['worker_id'] !== $row['worker_id']
        || $body['actor_id'] !== (int)$row['actor_id'] || $body['review_id'] !== $row['review_id']
        || $body['proposal_hash'] !== $row['proposal_hash'] || $body['decision'] !== $row['decision']
        || ($decision['person_id'] ?? null) !== (int)$row['person_id']
        || $body['person']['directory_tenant_id'] !== (int)$row['people_tenant_id']
        || $body['person']['instance_id'] !== $access['instance_id']) {
        throw new RuntimeException('The saved contact decision does not match the current reviewed connection.');
    }
    return ['row' => $row, 'proposal' => $proposal, 'body' => $body];
}

/** Lock order agrees with review/publication: connection, directory, person, source decision. */
function quantaProfileReturnIdentity(PDO $pdo, int $tenant, array $access, array $envelope, bool $forWrite): array
{
    $row = $envelope['row']; $body = $envelope['body'];
    quantaWorkspaceAssertCurrent($pdo, $tenant, $access, true);
    quantaWorkerDeliveryDirectoryLock($pdo, $tenant, (int)$row['people_tenant_id']);
    $s = $pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL FOR UPDATE');
    $s->execute(['t' => $row['people_tenant_id'], 'p' => $row['person_id']]);
    if (!$s->fetchColumn()) throw new RuntimeException('The Core person is unavailable.');
    $source = quantaWorkerDeliverySource($pdo, $access['instance_id'], (int)$row['people_tenant_id'], (int)$row['person_id']);
    if (quantaProfileDigest($source['reference']) !== quantaProfileDigest($body['person'])
        || quantaProfileLinkedWorker($pdo, $tenant, (int)$row['person_id'], true) !== $body['worker_id']) {
        throw new RuntimeException('The canonical person or Quanta worker link changed.');
    }
    $live = quantaProfileReturnEnvelope($pdo, $tenant, $row['change_id'], $access, true);
    if (quantaProfileDigest($live['body']) !== quantaProfileDigest($body)) throw new RuntimeException('The saved decision changed.');
    if ($forWrite) {
        if ($source['record']['status'] !== 'active') throw new RuntimeException('This person is no longer active. Inspect the receipt without resending.');
        // A phone decision must survive an unrelated Core edit; a name decision uses both names.
        $fields = $envelope['proposal']['changed_fields'];
        if (array_intersect(['first_name', 'last_name'], $fields)) $fields = array_unique(array_merge($fields, ['first_name', 'last_name']));
        $current = quantaProfileFromPerson($source['record']);
        if ($body['decision'] === 'accepted') foreach ($fields as $field) {
            if ($current[$field] !== $body['after'][$field]) throw new RuntimeException('Core changed a reviewed contact field after this decision. Reconcile the newer value before returning it.');
        }
    }
    return $source;
}

function quantaProfileReturnRow(PDO $pdo, int $tenant, array $envelope, bool $lock = false): ?array
{
    $s = $pdo->prepare('SELECT * FROM quanta_profile_returns WHERE tenant_id=:t AND source_workspace_id=:w AND change_id=:c' . ($lock ? ' FOR UPDATE' : ''));
    $s->execute(quantaProfileReturnKey($tenant, $envelope['row']));
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row && !hash_equals($row['body_hash'], quantaProfileDigest($envelope['body']))) throw new RuntimeException('The queued contact decision changed.');
    return $row;
}

function quantaProfileReturnSummary(?array $row, array $envelope): array
{
    $receipt = null;
    if ($row && $row['receipt_ct'] !== null) {
        $receipt = json_decode((string)decryptField($row['receipt_ct']), true, 64, JSON_THROW_ON_ERROR);
        if (!hash_equals((string)$row['receipt_hash'], quantaProfileDigest($receipt))) throw new RuntimeException('The saved return receipt changed.');
        quantaProfileDecisionConfirm($receipt, $envelope['row']['source_workspace_id'], $envelope['body'], $envelope['proposal']);
    }
    if (($row['state'] ?? null) === 'delivered' && ($receipt['state'] ?? null) !== 'complete') throw new RuntimeException('This return has no completed provider receipt.');
    return ['change_id' => $envelope['row']['change_id'], 'state' => $row['state'] ?? 'pending',
        'attempt_count' => (int)($row['attempt_count'] ?? 0), 'decision' => $envelope['body']['decision'],
        'receipt_state' => $receipt['state'] ?? null, 'outcome' => $receipt['outcome'] ?? null,
        'error_code' => $row['error_code'] ?? null];
}

/** Persist a provider observation, including recovery after local receipt persistence failed. */
function quantaProfileReturnRecord(int $tenant, int $actor, array $access, array $envelope, array $record): array
{
    quantaProfileDecisionConfirm($record, $envelope['row']['source_workspace_id'], $envelope['body'], $envelope['proposal']);
    $pdo = getDB(); if ($pdo->inTransaction()) throw new LogicException('Contact receipt requires its own transaction.');
    $pdo->beginTransaction();
    try {
        quantaProfileReturnIdentity($pdo, $tenant, $access, $envelope, false);
        $row = quantaProfileReturnRow($pdo, $tenant, $envelope, true);
        if ($row && $row['state'] === 'delivered') {
            $saved = quantaProfileReturnSummary($row, $envelope);
            // An in-flight read may predate completion. It cannot downgrade a completed receipt.
            if ($record['state'] === 'complete' && !hash_equals($row['receipt_hash'], quantaProfileDigest($record))) {
                throw new RuntimeException('Quanta returned conflicting completed receipts.');
            }
            $pdo->commit(); return $saved;
        }
        $state = $record['state'] === 'complete' ? 'delivered' : 'uncertain';
        $params = quantaProfileReturnKey($tenant, $envelope['row']) + [
            'h' => quantaProfileDigest($envelope['body']), 's' => $state, 'a' => $actor,
            'rh' => quantaProfileDigest($record), 'ct' => encryptField(integrationDeliveryJson($record))];
        $pdo->prepare('INSERT INTO quanta_profile_returns(tenant_id,source_workspace_id,change_id,body_hash,state,last_actor_id,receipt_hash,receipt_ct)
            VALUES(:t,:w,:c,:h,:s,:a,:rh,:ct)
            ON DUPLICATE KEY UPDATE state=VALUES(state),last_actor_id=VALUES(last_actor_id),receipt_hash=VALUES(receipt_hash),
            receipt_ct=VALUES(receipt_ct),claim_hash=NULL,error_code=NULL')->execute($params);
        if ($state === 'delivered') $pdo->prepare('UPDATE quanta_profile_returns SET finished_at=NOW() WHERE tenant_id=:t AND source_workspace_id=:w AND change_id=:c')
            ->execute(quantaProfileReturnKey($tenant, $envelope['row']));
        platformAuditLogWrite($tenant, $actor, 'quanta.profile.return_observed', (int)$envelope['row']['person_id'],
            ['change_id' => $envelope['row']['change_id'], 'state' => $state, 'contact_state' => $record['outcome']['contact_state'] ?? null], ['source' => 'quanta']);
        $result = quantaProfileReturnSummary(quantaProfileReturnRow($pdo, $tenant, $envelope), $envelope);
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Inspect never sends a decision. An explicit resume performs a new read before any PUT. */
function quantaProfileReturnStatus(int $tenant, int $actor, string $id): array
{
    if ($actor <= 0) throw new InvalidArgumentException('A current reviewer is required.');
    $pdo = getDB(); $access = quantaProfileReturnAccess($tenant);
    $envelope = quantaProfileReturnEnvelope($pdo, $tenant, $id, $access);
    $pdo->beginTransaction();
    try {
        quantaProfileReturnIdentity($pdo, $tenant, $access, $envelope, false);
        $saved = quantaProfileReturnSummary(quantaProfileReturnRow($pdo, $tenant, $envelope, true), $envelope);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if ($saved['state'] === 'delivered') return $saved + ['resume_allowed' => false];
    $receipt = quantaProfileDecisionReceipt($access['api_key'], $envelope['row']['source_workspace_id'], $envelope['body'], $envelope['proposal']);
    if ($receipt) $saved = quantaProfileReturnRecord($tenant, $actor, $access, $envelope, $receipt);
    else {
        // A directory/connection may have changed during the provider GET.
        $pdo->beginTransaction();
        try {
            quantaProfileReturnIdentity($pdo, $tenant, $access, $envelope, false);
            $saved = quantaProfileReturnSummary(quantaProfileReturnRow($pdo, $tenant, $envelope, true), $envelope);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    return $saved + ['observed_receipt_state' => $receipt['state'] ?? 'not_found',
        'resume_allowed' => $saved['state'] !== 'pending' && $saved['state'] !== 'delivered' && quantaProfileReturnEnabled($tenant)];
}

function quantaProfileReturnSend(int $tenant, int $actor, string $id, bool $resume = false): array
{
    if ($actor <= 0 || !quantaProfileReturnEnabled($tenant)) throw new RuntimeException('Contact return has not been enabled for this reviewer and company.');
    $pdo = getDB(); $access = quantaProfileReturnAccess($tenant);
    $envelope = quantaProfileReturnEnvelope($pdo, $tenant, $id, $access);
    $local = quantaProfileReturnRow($pdo, $tenant, $envelope);
    // Validate current authorization before exposing even a previously completed outcome.
    $pdo->beginTransaction();
    try {
        quantaProfileReturnIdentity($pdo, $tenant, $access, $envelope, false);
        $local = quantaProfileReturnRow($pdo, $tenant, $envelope, true);
        $saved = quantaProfileReturnSummary($local, $envelope);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if ($saved['state'] === 'delivered') return $saved;
    if ($local && !$resume) throw new RuntimeException('Inspect this decision receipt and explicitly resume the original return.');
    if (!$local && $resume) throw new RuntimeException('This decision has not been sent. Use its initial return action.');
    $receipt = quantaProfileDecisionReceipt($access['api_key'], $envelope['row']['source_workspace_id'], $envelope['body'], $envelope['proposal']);
    if ($receipt && $receipt['state'] === 'complete') return quantaProfileReturnRecord($tenant, $actor, $access, $envelope, $receipt);
    if ($receipt && !$resume) return quantaProfileReturnRecord($tenant, $actor, $access, $envelope, $receipt);
    $link = quantaContractRecord($access['api_key'], $envelope['row']['source_workspace_id'], $envelope['body']['person']);
    if (!$link || $link['worker_id'] !== $envelope['body']['worker_id']) throw new RuntimeException('Reconcile the Quanta person link before returning this decision.');
    $claim = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        quantaProfileReturnIdentity($pdo, $tenant, $access, $envelope, true);
        $row = quantaProfileReturnRow($pdo, $tenant, $envelope, true);
        if (($row['state'] ?? null) === 'delivered') { $saved = quantaProfileReturnSummary($row, $envelope); $pdo->commit(); return $saved; }
        if ($row && !$resume) throw new RuntimeException('Another return already claimed this decision. Inspect its receipt.');
        if (!$row && $resume) throw new RuntimeException('The original return is unavailable.');
        $pdo->prepare('INSERT INTO quanta_profile_returns(tenant_id,source_workspace_id,change_id,body_hash,state,attempt_count,last_actor_id,claim_hash,attempted_at)
            VALUES(:t,:w,:c,:h,"sending",1,:a,:claim,NOW())
            ON DUPLICATE KEY UPDATE state="sending",attempt_count=attempt_count+1,last_actor_id=VALUES(last_actor_id),
            claim_hash=VALUES(claim_hash),attempted_at=NOW(),error_code=NULL')
            ->execute(quantaProfileReturnKey($tenant, $envelope['row']) + ['h' => quantaProfileDigest($envelope['body']), 'a' => $actor, 'claim' => hash('sha256', $claim)]);
        platformAuditLogWrite($tenant, $actor, 'quanta.profile.return_claimed', (int)$envelope['row']['person_id'],
            ['change_id' => $id, 'resume' => $resume], ['source' => 'quanta']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    try {
        $record = quantaWriteProfileDecision($access['api_key'], $envelope['row']['source_workspace_id'], $envelope['body'], $envelope['proposal']);
        return quantaProfileReturnRecord($tenant, $actor, $access, $envelope, $record);
    } catch (Throwable $e) {
        // Only this attempt's claim can mark an error; never overwrite another completion.
        $pdo->prepare('UPDATE quanta_profile_returns SET state=:s,error_code="profile_return_unconfirmed",claim_hash=NULL
            WHERE tenant_id=:t AND source_workspace_id=:w AND change_id=:c AND state="sending" AND claim_hash=:claim')
            ->execute(quantaProfileReturnKey($tenant, $envelope['row']) + ['s' => $e instanceof QuantaContractWriteException && !$e->uncertain ? 'failed' : 'uncertain',
                'claim' => hash('sha256', $claim)]);
        throw $e;
    }
}

/** Paginated local outbox remains available after Quanta stops listing completed requests. */
function quantaProfileReturnPage(int $tenant, ?string $after = null): array
{
    if ($after !== null && !preg_match('/^cpc_[a-f0-9]{64}$/D', $after)) throw new InvalidArgumentException('Invalid return page.');
    $pdo = getDB(); $access = quantaProfileReturnAccess($tenant);
    $owner = effectiveTenantIdForModule('people', $tenant) ?? $tenant;
    $s = $pdo->prepare('SELECT change_id FROM quanta_profile_decisions WHERE tenant_id=:t AND source_workspace_id=:w AND people_tenant_id=:o'
        . ($after === null ? '' : ' AND change_id>:c') . ' ORDER BY change_id LIMIT 26');
    $s->execute(['t' => $tenant, 'w' => $access['context']['workspace']['id'], 'o' => $owner] + ($after === null ? [] : ['c' => $after]));
    $ids = $s->fetchAll(PDO::FETCH_COLUMN); $more = count($ids) > 25; $ids = array_slice($ids, 0, 25);
    $rows = []; $unavailable = 0;
    $pdo->beginTransaction();
    try {
        quantaWorkspaceAssertCurrent($pdo, $tenant, $access, true);
        quantaWorkerDeliveryDirectoryLock($pdo, $tenant, $owner);
        foreach ($ids as $id) {
            try {
                $e = quantaProfileReturnEnvelope($pdo, $tenant, $id, $access);
                $source = quantaProfileReturnIdentity($pdo, $tenant, $access, $e, false);
                $rows[] = quantaProfileReturnSummary(quantaProfileReturnRow($pdo, $tenant, $e), $e)
                    + ['person_number' => $source['reference']['number'], 'person_name' => trim($source['record']['first_name'] . ' ' . $source['record']['last_name'])];
            } catch (InvalidArgumentException|RuntimeException $e) { $unavailable++; }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['rows' => $rows, 'unavailable_count' => $unavailable, 'next_cursor' => $more ? end($ids) : null,
        'delivery_enabled' => quantaProfileReturnEnabled($tenant)];
}

