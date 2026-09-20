<?php
/**
 * core/ai/artifacts.php — First-Class Artifact Layer helpers.
 *
 * Spec §2A: every durable AI-generated or workflow-generated output
 * users review, approve, export, file, or rely on operationally must
 * be representable as a first-class artifact object.
 *
 * This file is the canonical surface modules call when they want to
 * promote a domain row (close packet, recon packet, JE draft, cash
 * forecast, etc.) into a first-class artifact. The artifact gets:
 *   - identity (UUIDv4)
 *   - lifecycle (draft → review → approved → final | archived | rejected)
 *   - version (optimistic bump on every write)
 *   - provenance (source_module + source_record_id + actor)
 *   - immutable event log (artifact_events row per transition)
 *   - relationship graph (artifact_relationships edges)
 *
 * The lib intentionally does NOT enforce RBAC — callers are existing
 * RBAC-gated module APIs.  Adding a second perm check here would just
 * double-fire on every write.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../tx_helpers.php';

// ─────────────────────────────────────────────────────────────────
// Lifecycle state machine.
// Each status maps to the set of statuses it can transition to.
// Final and archived are closed. Approved review artifacts may be
// reopened when the owning domain object is explicitly reopened; the
// transition is recorded and the version is bumped, so this does not
// erase the prior approval history.
// ─────────────────────────────────────────────────────────────────
const ARTIFACT_TRANSITIONS = [
    'draft'    => ['review', 'rejected', 'archived'],
    'review'   => ['approved', 'rejected', 'draft', 'archived'],
    'approved' => ['review', 'draft', 'final', 'archived'],
    'final'    => ['archived'],
    'archived' => [],
    'rejected' => ['draft', 'archived'],
];

/**
 * Create an artifact row + its first 'created' event.
 *
 * @param int    $tenantId
 * @param string $artifactType  e.g. 'close_packet', 'reconciliation'
 * @param array  $opts          {
 *     id? (caller-supplied canonical UUID), title?, sub_tenant_id?,
 *     source_module?, source_record_type?,
 *     source_record_id?, payload?, storage_uri?, storage_bytes?,
 *     storage_mime?, created_by_user_id?, created_by_ai_run?,
 *     initial_status? (defaults to 'draft')
 *   }
 * @return array {id, version, status, ...}
 */
function artifactCreate(int $tenantId, string $artifactType, array $opts = []): array
{
    if ($tenantId <= 0)        throw new \InvalidArgumentException('tenantId required');
    if ($artifactType === '')  throw new \InvalidArgumentException('artifactType required');

    $pdo  = getDB();
    $id   = strtolower(trim((string) ($opts['id'] ?? '')));
    if ($id === '') {
        $id = artifactGenerateUuid();
    } elseif (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $id)) {
        throw new \InvalidArgumentException('id must be a valid UUID');
    }
    $status = (string) ($opts['initial_status'] ?? 'draft');
    if (!array_key_exists($status, ARTIFACT_TRANSITIONS)) {
        throw new \InvalidArgumentException("initial_status '{$status}' is not a valid lifecycle state");
    }

    $payloadJson = isset($opts['payload']) && is_array($opts['payload'])
        ? json_encode($opts['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;

    $ownsTx = cf_tx_begin($pdo);
    try {
        $pdo->prepare(
            'INSERT INTO artifact_objects
                (id, tenant_id, sub_tenant_id, artifact_type, title, status, version,
                 source_module, source_record_type, source_record_id,
                 payload_json, storage_uri, storage_bytes, storage_mime,
                 created_by_user_id, created_by_ai_run, created_at, updated_at)
             VALUES
                (:id, :t, :st, :at, :tl, :s, 1,
                 :sm, :srt, :sri,
                 :pl, :su, :sb, :sn,
                 :cu, :car, NOW(), NOW())'
        )->execute([
            'id'  => $id,
            't'   => $tenantId,
            'st'  => $opts['sub_tenant_id']      ?? null,
            'at'  => $artifactType,
            'tl'  => $opts['title']              ?? null,
            's'   => $status,
            'sm'  => $opts['source_module']      ?? null,
            'srt' => $opts['source_record_type'] ?? null,
            'sri' => $opts['source_record_id']   ?? null,
            'pl'  => $payloadJson,
            'su'  => $opts['storage_uri']        ?? null,
            'sb'  => $opts['storage_bytes']      ?? null,
            'sn'  => $opts['storage_mime']       ?? null,
            'cu'  => $opts['created_by_user_id'] ?? null,
            'car' => $opts['created_by_ai_run']  ?? null,
        ]);

        artifactWriteEvent($tenantId, $id, 'created', [
            'prior_status'    => null,
            'new_status'      => $status,
            'actor_user_id'   => $opts['created_by_user_id'] ?? null,
            'actor_ai_run'    => $opts['created_by_ai_run']  ?? null,
            'payload'         => ['artifact_type' => $artifactType, 'title' => $opts['title'] ?? null],
        ]);

        $artifact = artifactGet($tenantId, $id);
        cf_tx_commit($pdo, $ownsTx);
        return $artifact;
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTx);
        throw $e;
    }
}

/**
 * Return the one artifact that represents a durable domain record.
 */
function artifactFindBySource(
    int $tenantId,
    string $artifactType,
    string $sourceModule,
    string $sourceRecordType,
    int $sourceRecordId,
    bool $forUpdate = false
): ?array {
    $stmt = getDB()->prepare(
        'SELECT id
           FROM artifact_objects
          WHERE tenant_id = :t
            AND artifact_type = :at
            AND source_module = :sm
            AND source_record_type = :srt
            AND source_record_id = :sri
          ORDER BY created_at ASC, id ASC
          LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $stmt->execute([
        't' => $tenantId,
        'at' => $artifactType,
        'sm' => $sourceModule,
        'srt' => $sourceRecordType,
        'sri' => $sourceRecordId,
    ]);
    $id = $stmt->fetchColumn();
    return $id ? artifactGet($tenantId, (string) $id) : null;
}

/**
 * Idempotently create the artifact for a durable domain record.
 *
 * The database unique key is the concurrency guard. If two workers race,
 * the loser re-reads and returns the winner instead of creating a sibling.
 */
function artifactEnsureForSource(
    int $tenantId,
    string $artifactType,
    string $sourceModule,
    string $sourceRecordType,
    int $sourceRecordId,
    array $opts = []
): array {
    if ($sourceRecordId <= 0) throw new \InvalidArgumentException('sourceRecordId required');

    $existing = artifactFindBySource(
        $tenantId,
        $artifactType,
        $sourceModule,
        $sourceRecordType,
        $sourceRecordId
    );
    if ($existing) return $existing;

    $opts['source_module'] = $sourceModule;
    $opts['source_record_type'] = $sourceRecordType;
    $opts['source_record_id'] = $sourceRecordId;

    try {
        return artifactCreate($tenantId, $artifactType, $opts);
    } catch (\PDOException $e) {
        if ((string) $e->getCode() !== '23000') throw $e;
        $existing = artifactFindBySource(
            $tenantId,
            $artifactType,
            $sourceModule,
            $sourceRecordType,
            $sourceRecordId,
            true
        );
        if (!$existing) throw $e;
        return $existing;
    }
}

/**
 * Move an artifact through the shortest legal lifecycle path.
 */
function artifactTransitionTo(
    int $tenantId,
    string $artifactId,
    string $targetStatus,
    ?int $actorUserId = null,
    ?string $actorAiRun = null,
    array $payload = []
): array {
    $pdo = getDB();
    $ownsTx = cf_tx_begin($pdo);
    try {
        $artifact = artifactGet($tenantId, $artifactId);
        if (!$artifact) throw new \RuntimeException("artifact {$artifactId} not found");
        if ((string) $artifact['status'] === $targetStatus) {
            cf_tx_commit($pdo, $ownsTx);
            return $artifact;
        }
        if (!array_key_exists($targetStatus, ARTIFACT_TRANSITIONS)) {
            throw new \InvalidArgumentException("Unknown artifact status '{$targetStatus}'");
        }

        $start = (string) $artifact['status'];
        $queue = [[$start, []]];
        $visited = [$start => true];
        $path = null;
        while ($queue) {
            [$status, $steps] = array_shift($queue);
            foreach (ARTIFACT_TRANSITIONS[$status] ?? [] as $next) {
                if (isset($visited[$next])) continue;
                $nextSteps = array_merge($steps, [$next]);
                if ($next === $targetStatus) {
                    $path = $nextSteps;
                    break 2;
                }
                $visited[$next] = true;
                $queue[] = [$next, $nextSteps];
            }
        }
        if ($path === null) {
            throw new \RuntimeException("No legal artifact lifecycle path from '{$start}' to '{$targetStatus}'");
        }
        foreach ($path as $status) {
            $artifact = artifactTransition(
                $tenantId,
                $artifactId,
                $status,
                $actorUserId,
                $actorAiRun,
                $payload
            );
        }
        cf_tx_commit($pdo, $ownsTx);
        return $artifact;
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTx);
        throw $e;
    }
}

/**
 * Update an artifact's mutable fields (payload, title, storage_*).
 * Bumps version. Writes an `updated` event.  NOT for status changes —
 * use artifactTransition() for that.
 */
function artifactUpdate(int $tenantId, string $artifactId, array $patch, ?int $actorUserId = null): array
{
    $existing = artifactGet($tenantId, $artifactId);
    if (!$existing) throw new \RuntimeException("artifact {$artifactId} not found");

    $sets   = [];
    $changedFields = [];
    $params = [
        'id' => $artifactId,
        't' => $tenantId,
        'prior_version' => (int) $existing['version'],
    ];
    if (array_key_exists('title', $patch) && $patch['title'] !== $existing['title']) {
        $sets[] = 'title = :tl';  $params['tl'] = $patch['title'];
        $changedFields[] = 'title';
    }
    if (array_key_exists('payload', $patch)
        && artifactComparableJson($patch['payload']) !== artifactComparableJson($existing['payload'])) {
        $sets[] = 'payload_json = :pl';
        $params['pl'] = $patch['payload'] === null
            ? null
            : json_encode($patch['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $changedFields[] = 'payload';
    }
    foreach (['storage_uri', 'storage_bytes', 'storage_mime'] as $k) {
        if (array_key_exists($k, $patch) && !artifactFieldMatches($existing, $k, $patch[$k])) {
            $sets[] = "{$k} = :{$k}";  $params[$k] = $patch[$k];
            $changedFields[] = $k;
        }
    }
    if (!$sets) return $existing;

    $sets[] = 'version = version + 1';
    $sets[] = 'updated_at = NOW()';

    $pdo = getDB();
    $ownsTx = cf_tx_begin($pdo);
    try {
        $update = $pdo->prepare(
            'UPDATE artifact_objects SET ' . implode(', ', $sets) .
            ' WHERE id = :id AND tenant_id = :t AND version = :prior_version LIMIT 1'
        );
        $update->execute($params);
        if ($update->rowCount() !== 1) {
            $fresh = artifactGet($tenantId, $artifactId);
            if ($fresh && artifactPatchMatches($fresh, $patch)) {
                cf_tx_commit($pdo, $ownsTx);
                return $fresh;
            }
            $actualVersion = $fresh ? (int) $fresh['version'] : 0;
            throw new \RuntimeException(
                "Artifact {$artifactId} changed concurrently; expected version {$existing['version']}, found {$actualVersion}"
            );
        }

        artifactWriteEvent($tenantId, $artifactId, 'updated', [
            'prior_status'  => $existing['status'],
            'new_status'    => $existing['status'],
            'actor_user_id' => $actorUserId,
            'payload'       => ['changed_fields' => $changedFields],
        ]);

        $artifact = artifactGet($tenantId, $artifactId);
        cf_tx_commit($pdo, $ownsTx);
        return $artifact;
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTx);
        throw $e;
    }
}

/** @internal Stable comparison for artifact payloads. */
function artifactComparableJson(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return $json === false ? 'null' : $json;
}

/** @internal Whether a concurrent writer already applied this exact patch. */
function artifactPatchMatches(array $artifact, array $patch): bool
{
    foreach ($patch as $key => $value) {
        if ($key === 'payload') {
            if (artifactComparableJson($value) !== artifactComparableJson($artifact['payload'] ?? null)) return false;
            continue;
        }
        if (in_array($key, ['title', 'storage_uri', 'storage_bytes', 'storage_mime'], true)
            && !artifactFieldMatches($artifact, $key, $value)) return false;
    }
    return true;
}

/** @internal Compare database scalar types without treating numeric strings as changes. */
function artifactFieldMatches(array $artifact, string $key, mixed $value): bool
{
    $current = $artifact[$key] ?? null;
    if ($key === 'storage_bytes') {
        return $value === null
            ? $current === null
            : $current !== null && (int) $value === (int) $current;
    }
    return $value === $current;
}

/**
 * Transition an artifact's lifecycle status. Refuses illegal moves.
 * Writes a `transitioned` event with prior/new status.
 */
function artifactTransition(
    int $tenantId, string $artifactId, string $newStatus,
    ?int $actorUserId = null, ?string $actorAiRun = null, array $payload = []
): array {
    $existing = artifactGet($tenantId, $artifactId);
    if (!$existing) throw new \RuntimeException("artifact {$artifactId} not found");

    $current = (string) $existing['status'];
    if ($current === $newStatus) return $existing; // idempotent no-op.

    $allowed = ARTIFACT_TRANSITIONS[$current] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        throw new \RuntimeException(
            "Illegal transition '{$current}' → '{$newStatus}'. "
            . 'Allowed from ' . $current . ': ' . (count($allowed) ? implode(', ', $allowed) : '(none — closed state)')
        );
    }

    $pdo = getDB();
    $ownsTx = cf_tx_begin($pdo);
    try {
        $update = $pdo->prepare(
            'UPDATE artifact_objects
                SET status = :s, version = version + 1, updated_at = NOW(),
                    archived_at = CASE WHEN :s2 = "archived" THEN NOW() ELSE archived_at END
              WHERE id = :id AND tenant_id = :t AND status = :prior LIMIT 1'
        );
        $update->execute([
            's' => $newStatus,
            's2' => $newStatus,
            'id' => $artifactId,
            't' => $tenantId,
            'prior' => $current,
        ]);
        if ($update->rowCount() !== 1) {
            $fresh = artifactGet($tenantId, $artifactId);
            if ($fresh && (string) $fresh['status'] === $newStatus) {
                cf_tx_commit($pdo, $ownsTx);
                return $fresh;
            }
            $actual = $fresh ? (string) $fresh['status'] : 'missing';
            throw new \RuntimeException(
                "Artifact {$artifactId} changed concurrently; expected '{$current}', found '{$actual}'"
            );
        }

        artifactWriteEvent($tenantId, $artifactId, 'transitioned', [
            'prior_status'  => $current,
            'new_status'    => $newStatus,
            'actor_user_id' => $actorUserId,
            'actor_ai_run'  => $actorAiRun,
            'payload'       => $payload ?: null,
        ]);

        $artifact = artifactGet($tenantId, $artifactId);
        cf_tx_commit($pdo, $ownsTx);
        return $artifact;
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTx);
        throw $e;
    }
}

/**
 * Add an edge to the artifact network.  Target is EITHER another
 * artifact OR a plain CoreFlux table row (e.g. a journal_entry).
 * Exactly one of {$targetArtifactId} or {$targetTable+$targetRecordId}
 * must be set.
 */
function artifactLink(
    int $tenantId, string $sourceArtifactId,
    string $relationshipType,
    ?string $targetArtifactId = null,
    ?string $targetTable = null,
    ?int $targetRecordId = null,
    array $metadata = [],
    ?int $actorUserId = null,
    ?string $actorAiRun = null
): array {
    $hasArt = $targetArtifactId !== null && $targetArtifactId !== '';
    $hasRow = $targetTable !== null && $targetTable !== '' && $targetRecordId !== null;
    if ($hasArt === $hasRow) {
        throw new \InvalidArgumentException(
            'Provide EXACTLY one of (targetArtifactId) or (targetTable + targetRecordId)'
        );
    }
    if ($relationshipType === '') throw new \InvalidArgumentException('relationshipType required');

    $pdo = getDB();
    if (!artifactGet($tenantId, $sourceArtifactId)) {
        throw new \RuntimeException("source artifact {$sourceArtifactId} not found in tenant {$tenantId}");
    }
    if ($hasArt && !artifactGet($tenantId, (string) $targetArtifactId)) {
        throw new \RuntimeException("target artifact {$targetArtifactId} not found in tenant {$tenantId}");
    }
    $where = $hasArt
        ? 'target_artifact_id = :ta'
        : 'target_table = :tt AND target_record_id = :tr';
    $lookupSql = 'SELECT id FROM artifact_relationships
          WHERE tenant_id = :t AND source_artifact_id = :sa
            AND relationship_type = :rt AND ' . $where . '
          LIMIT 1';
    $existing = $pdo->prepare($lookupSql);
    $lookup = ['t' => $tenantId, 'sa' => $sourceArtifactId, 'rt' => $relationshipType];
    if ($hasArt) {
        $lookup['ta'] = $targetArtifactId;
    } else {
        $lookup['tt'] = $targetTable;
        $lookup['tr'] = $targetRecordId;
    }
    $existing->execute($lookup);
    $edgeId = $existing->fetchColumn();
    if ($edgeId) {
        return ['ok' => true, 'edge_id' => (int) $edgeId, 'relationship_type' => $relationshipType, 'existing' => true];
    }

    $insert = $pdo->prepare(
        'INSERT INTO artifact_relationships
            (tenant_id, source_artifact_id, target_artifact_id,
             target_table, target_record_id, relationship_type,
             metadata, created_by_user_id, created_by_ai_run, created_at)
         VALUES
            (:t, :sa, :ta, :tt, :tr, :rt, :m, :cu, :car, NOW())'
    );
    try {
        $insert->execute([
            't'   => $tenantId,
            'sa'  => $sourceArtifactId,
            'ta'  => $targetArtifactId,
            'tt'  => $targetTable,
            'tr'  => $targetRecordId,
            'rt'  => $relationshipType,
            'm'   => $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'cu'  => $actorUserId,
            'car' => $actorAiRun,
        ]);
    } catch (\PDOException $e) {
        if ((string) $e->getCode() !== '23000') throw $e;
        // A locking read sees the committed winner even under REPEATABLE READ.
        $winner = $pdo->prepare($lookupSql . ' FOR UPDATE');
        $winner->execute($lookup);
        $edgeId = $winner->fetchColumn();
        if (!$edgeId) throw $e;
        return ['ok' => true, 'edge_id' => (int) $edgeId, 'relationship_type' => $relationshipType, 'existing' => true];
    }

    return ['ok' => true, 'edge_id' => (int) $pdo->lastInsertId(), 'relationship_type' => $relationshipType];
}

function artifactGet(int $tenantId, string $artifactId): ?array
{
    $st = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, artifact_type, title, status, version,
                source_module, source_record_type, source_record_id, payload_json,
                storage_uri, storage_bytes, storage_mime,
                created_by_user_id, created_by_ai_run,
                created_at, updated_at, archived_at
           FROM artifact_objects
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $st->execute(['id' => $artifactId, 't' => $tenantId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if ($row && $row['payload_json']) {
        $row['payload'] = json_decode((string) $row['payload_json'], true);
        unset($row['payload_json']);
    } elseif ($row) {
        $row['payload'] = null;
        unset($row['payload_json']);
    }
    return $row;
}

function artifactList(int $tenantId, array $filters = []): array
{
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['artifact_type'])) {
        $where[] = 'artifact_type = :at'; $params['at'] = (string) $filters['artifact_type'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'status = :s'; $params['s'] = (string) $filters['status'];
    }
    if (!empty($filters['source_module'])) {
        $where[] = 'source_module = :sm'; $params['sm'] = (string) $filters['source_module'];
    }
    if (!empty($filters['since'])) {
        $where[] = 'created_at >= :sn'; $params['sn'] = (string) $filters['since'];
    }
    $limit  = max(1, min(500, (int) ($filters['limit']  ?? 100)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $st = getDB()->prepare(
        'SELECT id, artifact_type, title, status, version,
                source_module, source_record_type, source_record_id,
                created_by_user_id, created_by_ai_run,
                created_at, updated_at
           FROM artifact_objects
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY created_at DESC
          LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $st->execute($params);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fetch the full lineage of an artifact:
 *   - outgoing edges (artifacts this one derives from / includes / references)
 *   - incoming edges (artifacts that derive from / include / reference this one)
 *   - event history (transitions, updates, custom events)
 */
function artifactLineage(int $tenantId, string $artifactId): array
{
    $pdo = getDB();
    $stOut = $pdo->prepare(
        'SELECT id AS edge_id, target_artifact_id, target_table, target_record_id,
                relationship_type, metadata, created_at
           FROM artifact_relationships
          WHERE tenant_id = :t AND source_artifact_id = :a
          ORDER BY created_at DESC'
    );
    $stOut->execute(['t' => $tenantId, 'a' => $artifactId]);
    $outgoing = $stOut->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $stIn = $pdo->prepare(
        'SELECT id AS edge_id, source_artifact_id,
                relationship_type, metadata, created_at
           FROM artifact_relationships
          WHERE tenant_id = :t AND target_artifact_id = :a
          ORDER BY created_at DESC'
    );
    $stIn->execute(['t' => $tenantId, 'a' => $artifactId]);
    $incoming = $stIn->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $stEv = $pdo->prepare(
        'SELECT id, event_type, prior_status, new_status,
                actor_user_id, actor_ai_run, actor_worker_id,
                payload, created_at
           FROM artifact_events
          WHERE tenant_id = :t AND artifact_id = :a
          ORDER BY created_at DESC, id DESC
          LIMIT 200'
    );
    $stEv->execute(['t' => $tenantId, 'a' => $artifactId]);
    $events = $stEv->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Decode JSON columns for the API consumer.
    foreach ($outgoing as &$e) {
        $e['metadata'] = $e['metadata'] ? json_decode((string) $e['metadata'], true) : null;
    }
    foreach ($incoming as &$e) {
        $e['metadata'] = $e['metadata'] ? json_decode((string) $e['metadata'], true) : null;
    }
    foreach ($events as &$e) {
        $e['payload'] = $e['payload'] ? json_decode((string) $e['payload'], true) : null;
    }

    return [
        'artifact_id'   => $artifactId,
        'outgoing'      => $outgoing,
        'incoming'      => $incoming,
        'event_history' => $events,
    ];
}

/**
 * Internal — append a row to artifact_events.
 *
 * @param array $evt {prior_status, new_status, actor_user_id, actor_ai_run, actor_worker_id, payload}
 */
function artifactWriteEvent(int $tenantId, string $artifactId, string $eventType, array $evt): void
{
    getDB()->prepare(
        'INSERT INTO artifact_events
            (tenant_id, artifact_id, event_type, prior_status, new_status,
             actor_user_id, actor_ai_run, actor_worker_id, payload, created_at)
         VALUES
            (:t, :a, :e, :ps, :ns, :au, :ar, :aw, :pl, NOW())'
    )->execute([
        't'   => $tenantId,
        'a'   => $artifactId,
        'e'   => $eventType,
        'ps'  => $evt['prior_status']    ?? null,
        'ns'  => $evt['new_status']      ?? null,
        'au'  => $evt['actor_user_id']   ?? null,
        'ar'  => $evt['actor_ai_run']    ?? null,
        'aw'  => $evt['actor_worker_id'] ?? null,
        'pl'  => isset($evt['payload']) && $evt['payload'] !== null
                    ? json_encode($evt['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : null,
    ]);
}

/**
 * Generate a UUIDv4. Matches the pattern used by ai_runs (mig 090).
 */
function artifactGenerateUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
