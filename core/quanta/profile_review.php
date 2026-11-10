<?php
/** Reviewed Quanta contact changes; native update and immutable decision commit together. */
declare(strict_types=1);
require_once __DIR__ . '/profile_baseline_delivery.php';

/** List only requests whose canonical person resolves in the authorized Core directory. */
function quantaProfileReviewPage(int $tenant, ?string $cursor = null): array
{
    if ($cursor !== null && (!preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $cursor))) throw new InvalidArgumentException('Invalid request page.');
    $pdo = getDB(); $access = quantaProfileBaselineAccess($tenant);
    $owner = effectiveTenantIdForModule('people', $tenant) ?? $tenant;
    $page = quantaGet($access['api_key'], QUANTA_CORE_CONTRACT_PATH . '/profile-changes',
        ['limit' => 25] + ($cursor === null ? [] : ['cursor' => $cursor]));
    [$items, $pagination] = quantaListPage($page, QUANTA_CORE_CONTRACT_PATH . '/profile-changes');
    if (count($items) > 25 || ($pagination['has_more'] && (!is_string($pagination['next_cursor'] ?? null)
        || !preg_match('/^[A-Za-z0-9_-]{1,2048}$/D', $pagination['next_cursor']) || $pagination['next_cursor'] === $cursor))) {
        throw new RuntimeException('The contact request page is incomplete.');
    }
    $rows = []; $unavailable = 0;
    foreach ($items as $item) {
        try {
            quantaProfileExactFields($item, ['proposal', 'outcome']);
            $p = $item['proposal'];
            if (!is_array($p) || !is_array($p['person'] ?? null)) throw new RuntimeException('Invalid source identity.');
            $reference = quantaCoreReferenceValidate($p['person'], 'person');
            if ($reference['instance_id'] !== $access['instance_id'] || $reference['directory_tenant_id'] !== $owner) {
                $unavailable++; continue;
            }
            $s = $pdo->prepare('SELECT record_id FROM core_record_numbers WHERE tenant_id=:t AND entity_type="person" AND record_number=:n');
            $s->execute(['t' => $owner, 'n' => $reference['number']]); $ids = $s->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) { $unavailable++; continue; }
            $personId = (int)$ids[0]; $source = quantaProfileReviewSource($pdo, $tenant, $owner, $personId, $access);
            $p = quantaProfileProposalValidate($p, $access['context']['workspace']['id'], $source['reference'], $source['worker_id'], $p['id']);
            if (!quantaProfileBaselineSaved($pdo, $tenant, $access['binding_hash'], $owner, $personId, $p['baseline'])) {
                $unavailable++; continue;
            }
            $receipt = quantaProfileReviewReceipt($pdo, $tenant, ['workspace' => $p['tenant_id'], 'proposal' => $p,
                'owner' => $owner, 'person_id' => $personId, 'worker_id' => $source['worker_id'],
                'binding_hash' => $access['binding_hash'], 'proposal_hash' => quantaProfileDigest($p)]);
            $rows[] = ['change_id' => $p['id'], 'person_id' => $personId, 'person_number' => $reference['number'],
                'person_name' => trim($source['record']['first_name'] . ' ' . $source['record']['last_name']),
                'changed_fields' => $p['changed_fields'], 'created_at' => $p['created_at'],
                'state' => $receipt['decision'] ?? ($item['outcome'] === null ? 'pending_review' : 'source_decided')];
        } catch (InvalidArgumentException|RuntimeException $e) { $unavailable++; }
    }
    // Recheck uncached directory policy before exposing any personal details from the page.
    if ($pdo->inTransaction()) throw new LogicException('Contact listing requires its own transaction.');
    $pdo->beginTransaction();
    try {
        quantaWorkspaceAssertCurrent($pdo, $tenant, $access, true); quantaWorkerDeliveryDirectoryLock($pdo, $tenant, $owner);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['rows' => $rows, 'unavailable_count' => $unavailable,
        'next_cursor' => $pagination['has_more'] ? $pagination['next_cursor'] : null];
}

function quantaProfileProposalRead(array $access, array $person, string $worker, string $id): array
{
    if (!preg_match('/^cpc_[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('Choose a Quanta contact request.');
    $record = quantaGet($access['api_key'], QUANTA_CORE_CONTRACT_PATH . '/profile-changes/' . $id);
    quantaProfileExactFields($record, ['proposal', 'outcome']);
    if (!is_array($record['proposal']) || $record['outcome'] !== null) {
        throw new RuntimeException('This source request is no longer awaiting a Core decision.');
    }
    return quantaProfileProposalValidate($record['proposal'], $access['context']['workspace']['id'], $person, $worker, $id);
}

function quantaProfileReviewSource(PDO $pdo, int $tenant, int $owner, int $personId, array $access, bool $lock = false): array
{
    if ($lock) {
        $s = $pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL FOR UPDATE');
        $s->execute(['t' => $owner, 'p' => $personId]);
        if (!$s->fetchColumn()) throw new RuntimeException('This Core person is unavailable.');
    }
    $source = quantaWorkerDeliverySource($pdo, $access['instance_id'], $owner, $personId);
    if ($source['record']['status'] !== 'active') throw new RuntimeException('This Core person is no longer active.');
    $source['worker_id'] = quantaProfileLinkedWorker($pdo, $tenant, $personId, $lock);
    // Validate even when only rejecting; a review must display the actual current fields.
    quantaProfileFromPerson($source['record']);
    return $source;
}

function quantaProfileReviewPreview(int $tenant, int $actor, int $personId, string $changeId): array
{
    if ($actor <= 0) throw new InvalidArgumentException('A current reviewer is required.');
    $pdo = getDB(); $access = quantaProfileBaselineAccess($tenant);
    $owner = effectiveTenantIdForModule('people', $tenant) ?? $tenant;
    $source = quantaProfileReviewSource($pdo, $tenant, $owner, $personId, $access);
    $proposal = quantaProfileProposalRead($access, $source['reference'], $source['worker_id'], $changeId);
    if (!quantaProfileBaselineSaved($pdo, $tenant, $access['binding_hash'], $owner, $personId, $proposal['baseline'])) {
        throw new RuntimeException('Core has no verified publication for this contact request.');
    }
    quantaWorkspaceAssertCurrent($pdo, $tenant, $access);
    $review = ['kind' => 'quanta_profile_review_v1', 'id' => bin2hex(random_bytes(32)), 'tenant' => $tenant, 'actor' => $actor,
        'owner' => $owner, 'person_id' => $personId, 'worker_id' => $source['worker_id'], 'instance' => $access['instance_id'],
        'binding_hash' => $access['binding_hash'], 'workspace' => $access['context']['workspace']['id'], 'context' => $access['context'],
        'expires_at' => time() + 1800, 'source_hash' => integrationDeliveryHash($source),
        'proposal_hash' => quantaProfileDigest($proposal), 'proposal' => $proposal];
    $current = quantaProfileFromPerson($source['record']);
    return ['review_token' => base64_encode(encryptField(integrationDeliveryJson($review))),
        'person_id' => $personId, 'reference' => $source['reference'], 'proposal' => $proposal, 'current' => $current,
        'comparison' => quantaProfileChangeCompare($proposal['baseline']['profile'], $proposal['proposed'], $current),
        'apply_enabled' => quantaProfileReturnEnabled($tenant), 'expires_at' => gmdate('c', $review['expires_at'])];
}

function quantaProfileReviewOpen(int $tenant, int $actor, string $token): array
{
    if ($actor <= 0 || strlen($token) > 60000) throw new InvalidArgumentException('Invalid contact review.');
    try {
        $bytes = base64_decode($token, true); if ($bytes === false) throw new RuntimeException();
        $review = json_decode((string)decryptField($bytes), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) { throw new InvalidArgumentException('This contact review is invalid. Preview again.'); }
    if (($review['kind'] ?? null) !== 'quanta_profile_review_v1' || ($review['tenant'] ?? null) !== $tenant
        || ($review['actor'] ?? null) !== $actor || !preg_match('/^[a-f0-9]{64}$/D', (string)($review['id'] ?? ''))) {
        throw new InvalidArgumentException('This review belongs to another workspace or reviewer.');
    }
    $connection = quantaConnection($tenant); $key = quantaApiKey($tenant); $instance = integrationInstanceId();
    if (!$instance || !$connection) throw new RuntimeException('The reviewed connection is unavailable.');
    $context = quantaContractContextValidate($review['context']);
    $access = ['connection' => $connection, 'api_key' => $key, 'instance_id' => $instance, 'context' => $context,
        'binding_hash' => quantaWorkspaceBindingHash($tenant, (int)$connection['id'], $key, $instance, $context, (int)$connection['binding_revision'])];
    quantaWorkspaceAssertCurrent(getDB(), $tenant, $access);
    if ($instance !== $review['instance'] || !hash_equals($review['binding_hash'], $access['binding_hash'])
        || $review['workspace'] !== $context['workspace']['id']) throw new RuntimeException('The Quanta connection changed. Preview again.');
    $proposal = quantaProfileProposalValidate($review['proposal'], $review['workspace'], $review['proposal']['person'],
        $review['worker_id'], $review['proposal']['id']);
    if ($proposal['person']['directory_tenant_id'] !== $review['owner'] || $proposal['person']['instance_id'] !== $instance
        || !hash_equals($review['proposal_hash'], quantaProfileDigest($proposal))) throw new RuntimeException('Invalid reviewed source identity.');
    return [$review, $access];
}

/** The receipt is the mandatory transactional audit; it contains encrypted before/after values. */
function quantaProfileReviewReceipt(PDO $pdo, int $tenant, array $review): ?array
{
    $s = $pdo->prepare('SELECT * FROM quanta_profile_decisions WHERE tenant_id=:t AND source_workspace_id=:w AND change_id=:c');
    $s->execute(['t' => $tenant, 'w' => $review['workspace'], 'c' => $review['proposal']['id']]);
    $row = $s->fetch(PDO::FETCH_ASSOC); if (!$row) return null;
    $proposal = json_decode((string)decryptField($row['proposal_ct']), true, 64, JSON_THROW_ON_ERROR);
    $result = json_decode((string)decryptField($row['decision_ct']), true, 64, JSON_THROW_ON_ERROR);
    if ((int)$row['people_tenant_id'] !== $review['owner'] || (int)$row['person_id'] !== $review['person_id']
        || $row['worker_id'] !== $review['worker_id'] || !hash_equals($row['binding_hash'], $review['binding_hash'])
        || !hash_equals($row['proposal_hash'], $review['proposal_hash'])
        || quantaProfileDigest($proposal) !== $review['proposal_hash']
        || ($result['change_id'] ?? null) !== $row['change_id'] || ($result['decision'] ?? null) !== $row['decision']
        || ($result['actor_id'] ?? null) !== (int)$row['actor_id'] || ($result['review_id'] ?? null) !== $row['review_id']) {
        throw new RuntimeException('The saved contact decision does not match this request.');
    }
    return $result;
}

function quantaProfileReviewLockedIdentity(PDO $pdo, int $tenant, array $review, array $access): array
{
    quantaWorkspaceAssertCurrent($pdo, $tenant, $access, true);
    quantaWorkerDeliveryDirectoryLock($pdo, $tenant, $review['owner']);
    $source = quantaProfileReviewSource($pdo, $tenant, $review['owner'], $review['person_id'], $access, true);
    if ($source['worker_id'] !== $review['worker_id']
        || quantaProfileDigest($source['reference']) !== quantaProfileDigest($review['proposal']['person'])) {
        throw new RuntimeException('The worker or canonical person identity changed.');
    }
    return $source;
}

function quantaProfileReviewStatus(int $tenant, int $actor, string $token): array
{
    [$review, $access] = quantaProfileReviewOpen($tenant, $actor, $token); $pdo = getDB();
    if ($pdo->inTransaction()) throw new LogicException('Contact status requires its own transaction.');
    $pdo->beginTransaction();
    try {
        quantaProfileReviewLockedIdentity($pdo, $tenant, $review, $access);
        $result = quantaProfileReviewReceipt($pdo, $tenant, $review); $pdo->commit();
        return ['state' => $result ? 'decided' : 'not_decided', 'result' => $result];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function quantaProfileReviewApply(int $tenant, int $actor, string $token, string $decision, string $reason): array
{
    if (!in_array($decision, ['accepted', 'rejected'], true) || strlen($reason) > 1000
        || $reason !== trim($reason) || preg_match('/[\x00-\x1f\x7f]/', $reason) || preg_match_all('/./us', $reason) === false
        || ($decision === 'rejected' && $reason === '')) throw new InvalidArgumentException('Choose a decision and include a reason for rejection.');
    [$review, $access] = quantaProfileReviewOpen($tenant, $actor, $token); $pdo = getDB();
    $replay = static function (array $result) use ($decision, $reason): array {
        if ($result['decision'] !== $decision || $result['reason'] !== $reason) throw new RuntimeException('This request already has a different decision.');
        return $result + ['replayed' => true];
    };
    // A committed result is recoverable without another provider request or unexpired token.
    $status = quantaProfileReviewStatus($tenant, $actor, $token);
    if ($status['result']) return $replay($status['result']);
    if (!quantaProfileReturnEnabled($tenant)) throw new RuntimeException('Contact review has not been enabled for this pilot.');
    if ($review['expires_at'] < time()) throw new RuntimeException('This contact review expired. Preview again.');
    $fresh = quantaProfileBaselineAccess($tenant);
    if (!hash_equals($review['binding_hash'], $fresh['binding_hash'])) throw new RuntimeException('The Quanta workspace changed. Preview again.');
    $proposal = quantaProfileProposalRead($fresh, $review['proposal']['person'], $review['worker_id'], $review['proposal']['id']);
    if (!hash_equals($review['proposal_hash'], quantaProfileDigest($proposal))) throw new RuntimeException('The source request changed after review.');
    if ($pdo->inTransaction()) throw new LogicException('Contact decisions require their own transaction.');
    $pdo->beginTransaction();
    try {
        $source = quantaProfileReviewLockedIdentity($pdo, $tenant, $review, $fresh);
        $existing = quantaProfileReviewReceipt($pdo, $tenant, $review);
        if ($existing) { $result = $replay($existing); $pdo->commit(); return $result; }
        if (!hash_equals($review['source_hash'], integrationDeliveryHash($source))) throw new RuntimeException('Core contact details changed after review. Preview again.');
        if (!quantaProfileBaselineSaved($pdo, $tenant, $review['binding_hash'], $review['owner'], $review['person_id'], $proposal['baseline'])) {
            throw new RuntimeException('The original Core publication is unavailable.');
        }
        $before = quantaProfileFromPerson($source['record']);
        $comparison = quantaProfileChangeCompare($proposal['baseline']['profile'], $proposal['proposed'], $before);
        if ($decision === 'accepted' && $comparison['state'] === 'conflict') throw new RuntimeException('Core and Quanta changed the same fields. Resolve this conflict before approval.');
        $changes = $decision === 'accepted' ? $comparison['changes'] : [];
        if (isset($changes['email_primary'])) {
            $s = $pdo->prepare('SELECT id FROM people WHERE tenant_id=:t AND LOWER(email_primary)=:e AND id<>:p AND deleted_at IS NULL FOR UPDATE');
            $s->execute(['t' => $review['owner'], 'e' => $changes['email_primary'], 'p' => $review['person_id']]);
            if ($s->fetchColumn()) throw new RuntimeException('That contact email already belongs to another Core person.');
        }
        if ($changes) {
            $sets = []; $params = ['t' => $review['owner'], 'p' => $review['person_id']];
            // Only the validated contract fields may enter this SQL; IDs, status and login never do.
            foreach ($changes as $field => $value) { $sets[] = '`' . $field . '`=:' . $field; $params[$field] = $value; }
            $pdo->prepare('UPDATE people SET ' . implode(',', $sets) . ',updated_at=NOW() WHERE tenant_id=:t AND id=:p AND deleted_at IS NULL')->execute($params);
        }
        $result = ['contract_version' => QUANTA_PROFILE_CHANGE_VERSION, 'change_id' => $proposal['id'],
            'proposal_hash' => $review['proposal_hash'], 'review_id' => $review['id'], 'decision' => $decision, 'reason' => $reason,
            'actor_id' => $actor, 'person_id' => $review['person_id'], 'person' => $proposal['person'], 'worker_id' => $review['worker_id'],
            'before' => $before, 'after' => array_replace($before, $changes), 'changed_fields' => array_keys($changes),
            'decided_at' => gmdate('Y-m-d\TH:i:s\Z'), 'quanta_return_state' => 'pending'];
        $pdo->prepare('INSERT INTO quanta_profile_decisions(tenant_id,source_workspace_id,change_id,binding_hash,people_tenant_id,person_id,worker_id,actor_id,review_id,proposal_hash,decision,decision_ct,proposal_ct) VALUES(:t,:w,:c,:b,:o,:p,:k,:a,:r,:h,:d,:result,:proposal)')
            ->execute(['t' => $tenant, 'w' => $review['workspace'], 'c' => $proposal['id'], 'b' => $review['binding_hash'],
                'o' => $review['owner'], 'p' => $review['person_id'], 'k' => $review['worker_id'], 'a' => $actor,
                'r' => $review['id'], 'h' => $review['proposal_hash'], 'd' => $decision,
                'result' => encryptField(integrationDeliveryJson($result)), 'proposal' => encryptField(integrationDeliveryJson($proposal))]);
        platformAuditLogWrite($tenant, $actor, 'quanta.profile.decided', $review['person_id'],
            ['change_id' => $proposal['id'], 'decision' => $decision, 'people_tenant_id' => $review['owner'], 'fields' => array_keys($changes)], ['source' => 'quanta']);
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
