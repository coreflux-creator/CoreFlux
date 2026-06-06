<?php
/**
 * core/accounting/command_service.php
 *
 * Accounting Command Service — provider-neutral entry point for
 * "I want to post X to the accounting backend" from any CoreFlux
 * module (AP, AR, Treasury, JE workflow). Per spec §7 + §16:
 *
 *   CoreFlux UI
 *     → CoreFlux Workflow API
 *     → THIS SERVICE                ← idempotent outbox writer
 *     → Accounting Outbox row       (queued|processing|posted|failed|retrying|dead_letter)
 *     → Worker pulls → adapter dispatch → writes destination link
 *     → Read APIs surface the result back to CoreFlux UI
 *
 * Public surface:
 *   accountingCommandEnqueue(int $tenantId, int $subTenantId, string $commandType,
 *                            array $payload, string $idempotencyKey,
 *                            ?string $sourceEventId = null, ?int $userId = null,
 *                            ?string $provider = null): array
 *
 *     Returns the outbox row (after insert OR existing if idempotent
 *     key already in flight). Never throws on duplicate — returns
 *     same row.
 *
 *   accountingCommandGetStatus(int $tenantId, int $commandId): ?array
 *   accountingCommandApprove(int $tenantId, int $commandId, int $userId): array
 *   accountingCommandExecute(int $tenantId, int $commandId): array
 *     - Pulls the outbox row, resolves the provider adapter, calls the
 *       right adapter method, persists the result + destination link.
 *
 * Idempotency: the `idempotency_key` column has a UNIQUE constraint.
 * Re-enqueueing the same key returns the existing row WITHOUT a second
 * provider call. Callers (AP module, AR module, …) compute keys from
 * stable identifiers like "bill:{id}:v{rev}".
 */
declare(strict_types=1);

require_once __DIR__ . '/provider_adapter.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/sync_config_service.php';

const ACCOUNTING_OUTBOX_BACKOFF_BASE_SECONDS = 60;     // 60s, 120s, 240s, 480s, 960s — capped 16min
const ACCOUNTING_OUTBOX_MAX_ATTEMPTS         = 5;

/**
 * Resolve which provider an entity is currently using. If a connection
 * exists in 'active' or 'pending_diligence' / 'pending' state, use it;
 * otherwise fall back to caller's hint or the spec's 'jaz' default.
 */
function accountingResolveProvider(int $tenantId, int $subTenantId, ?string $hint = null): string
{
    if ($hint !== null) return $hint;
    try {
        $stmt = getDB()->prepare(
            "SELECT provider FROM accounting_provider_connections
              WHERE tenant_id = :t AND sub_tenant_id = :st
                AND connection_status IN ('active','pending')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'st' => $subTenantId]);
        $p = (string) ($stmt->fetchColumn() ?: '');
        return $p !== '' ? $p : 'jaz';
    } catch (\Throwable $e) {
        return 'jaz';
    }
}

function accountingCommandEnqueue(
    int $tenantId,
    int $subTenantId,
    string $commandType,
    array $payload,
    string $idempotencyKey,
    ?string $sourceEventId = null,
    ?int $userId = null,
    ?string $provider = null
): array {
    $idempotencyKey = trim($idempotencyKey);
    if ($idempotencyKey === '') {
        throw new \InvalidArgumentException('idempotency_key required (use a stable per-revision identifier)');
    }
    $provider = accountingResolveProvider($tenantId, $subTenantId, $provider);
    $pdo = getDB();

    // INSERT IGNORE on the UNIQUE idempotency_key — duplicate calls
    // return the existing row WITHOUT a second provider attempt.
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO accounting_outbox_events
            (tenant_id, sub_tenant_id, provider, command_type,
             command_payload, source_event_id, status,
             attempts, max_attempts, idempotency_key, created_by_user_id)
         VALUES (:t, :st, :p, :ct, :pl, :se, "queued",
                 0, :ma, :ik, :uid)'
    );
    $stmt->execute([
        't'   => $tenantId,
        'st'  => $subTenantId,
        'p'   => $provider,
        'ct'  => $commandType,
        'pl'  => json_encode($payload, JSON_UNESCAPED_SLASHES),
        'se'  => $sourceEventId,
        'ma'  => ACCOUNTING_OUTBOX_MAX_ATTEMPTS,
        'ik'  => $idempotencyKey,
        'uid' => $userId,
    ]);
    // Fetch the row (the new one OR the pre-existing one). Always
    // tenant-scope — the tenant-leak sentry correctly insists that
    // even when a column is globally UNIQUE, the SELECT MUST filter
    // by tenant so a forged idempotency_key can't read another
    // tenant's row.
    $sel = $pdo->prepare(
        'SELECT * FROM accounting_outbox_events
          WHERE tenant_id = :t AND idempotency_key = :ik LIMIT 1'
    );
    $sel->execute(['t' => $tenantId, 'ik' => $idempotencyKey]);
    $row = $sel->fetch(\PDO::FETCH_ASSOC);
    return $row ? accountingOutboxRowDecode($row) : [];
}

function accountingCommandGetStatus(int $tenantId, int $commandId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT * FROM accounting_outbox_events
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $commandId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? accountingOutboxRowDecode($row) : null;
}

/**
 * Approve flips status from 'queued' → 'queued' (no state change in
 * Slice 1) but writes an audit row in `provider_result` so we have
 * approver identity once the posting layer (Phase 4) goes live. Real
 * approval policy gating ties into the existing SoD engine in a later
 * slice — kept lightweight here to avoid touching too much surface.
 */
function accountingCommandApprove(int $tenantId, int $commandId, int $userId): array
{
    $row = accountingCommandGetStatus($tenantId, $commandId);
    if (!$row) throw new \InvalidArgumentException('command not found');
    if ($row['status'] !== 'queued') {
        throw new \RuntimeException("cannot approve command in status '{$row['status']}'");
    }
    $approval = ['approved_by_user_id' => $userId, 'approved_at' => date('Y-m-d H:i:s')];
    $existing = is_array($row['provider_result']) ? $row['provider_result'] : [];
    $existing['approval'] = $approval;
    getDB()->prepare(
        'UPDATE accounting_outbox_events
            SET provider_result = :pr
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'pr' => json_encode($existing, JSON_UNESCAPED_SLASHES),
        'id' => $commandId, 't' => $tenantId,
    ]);
    return accountingCommandGetStatus($tenantId, $commandId);
}

/**
 * Execute pulls the row, dispatches to the right adapter method.
 * Wraps every adapter call in error normalisation. On
 * AccountingAdapterNotReadyException, marks 'failed' and bumps the
 * attempt counter; on max_attempts → dead_letter. On success, writes
 * a destination_link row and marks 'posted'.
 */
function accountingCommandExecute(int $tenantId, int $commandId): array
{
    $row = accountingCommandGetStatus($tenantId, $commandId);
    if (!$row) throw new \InvalidArgumentException('command not found');
    if (!in_array($row['status'], ['queued', 'retrying'], true)) {
        return $row; // already terminal — caller can re-read status.
    }

    $pdo = getDB();
    $pdo->prepare(
        'UPDATE accounting_outbox_events SET status = "processing"
          WHERE id = :id AND tenant_id = :t'
    )->execute(['id' => $commandId, 't' => $tenantId]);

    $adapter = accountingProviderAdapterFor((string) $row['provider']);
    $payload = is_array($row['command_payload']) ? $row['command_payload'] : [];
    $subTenantId = (int) $row['sub_tenant_id'];
    $idem = (string) $row['idempotency_key'];

    // Slice 4 — CoreFlux row → provider payload translation. The
    // Slice 3 hook enqueued the raw row under payload.row; here we
    // map it to the shape the adapter actually POSTs. Translation
    // failures (missing vendor link, unbalanced JE, …) raise
    // AccountingAdapterValidationException → outbox row marks
    // 'retrying' with code='provider_validation' so the operator
    // sees the exact field gap.
    $objectType = (string) ($payload['coreflux_object_type'] ?? '');
    $rawRow     = is_array($payload['row'] ?? null) ? $payload['row'] : null;
    $mappedDraft = null;
    if ($rawRow !== null && in_array($objectType, ['bill','invoice','journal'], true)
        && (string) $row['provider'] === 'jaz') {
        try {
            require_once __DIR__ . '/jaz_payload_mapper.php';
            $mappedDraft = mapCorefluxRowToJaz($objectType, $tenantId, $subTenantId, $rawRow);
        } catch (\Throwable $e) {
            return accountingCommandMarkFailure($tenantId, $commandId, $row, $adapter, $e);
        }
    }

    try {
        switch ($row['command_type']) {
            case 'create_draft_bill':
                $res = $adapter->createDraftBill($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem); break;
            case 'create_draft_invoice':
                $res = $adapter->createDraftInvoice($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem); break;
            case 'create_draft_journal':
                $res = $adapter->createDraftJournal($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem); break;
            case 'post_object':
                $res = $adapter->postObject(
                    $tenantId, $subTenantId,
                    (string) ($payload['provider_object_type'] ?? ''),
                    (string) ($payload['provider_object_id'] ?? '')
                );
                break;
            default:
                throw new \InvalidArgumentException("unknown command_type {$row['command_type']}");
        }
    } catch (\Throwable $e) {
        return accountingCommandMarkFailure($tenantId, $commandId, $row, $adapter, $e);
    }

    // Persist success: outbox → posted, destination link row.
    // ── Charter primitive #5: post-push verification ──
    // Re-GET the object we just created and confirm the downstream
    // state matches what we expect. The expected status is per-command:
    //   create_draft_journal → 'active' (journals finalize directly)
    //   create_draft_bill    → 'draft'  (operator reviews in Jaz)
    //   create_draft_invoice → 'draft'  (operator reviews in Jaz)
    //   post_object          → 'active'
    // Verification failures DON'T re-queue (the create succeeded);
    // instead we stamp 'posted_unverified' on the outbox row so the
    // operator sees it in the UI distinct from a clean post.
    static $expectedDownstream = [
        'create_draft_journal' => 'active',
        'create_draft_bill'    => 'draft',
        'create_draft_invoice' => 'draft',
        'post_object'          => 'active',
    ];
    $expected = $expectedDownstream[$row['command_type']] ?? 'active';
    $verify   = null;
    $finalStatus = 'posted';
    if (!empty($res['provider_object_id']) && !empty($res['provider_object_type'])) {
        try {
            $verify = $adapter->verifyCreate(
                $tenantId, $subTenantId,
                (string) $res['provider_object_type'],
                (string) $res['provider_object_id'],
                $expected
            );
            if (!($verify['verified'] ?? false)) {
                $finalStatus = 'posted_unverified';
            }
        } catch (\Throwable $verifyErr) {
            $verify = [
                'verified' => false,
                'downstream_status' => 'verify_threw',
                'expected_status' => $expected,
                'reason' => substr($verifyErr->getMessage(), 0, 180),
                'fetched_at' => date('Y-m-d H:i:s'),
            ];
            $finalStatus = 'posted_unverified';
        }
    }

    $pdo->prepare(
        'UPDATE accounting_outbox_events
            SET status = :s, posted_at = NOW(),
                provider_result = :pr, error_code = NULL,
                error_message = NULL, next_retry_at = NULL
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's'  => $finalStatus,
        'pr' => json_encode(['result' => $res, 'verify' => $verify], JSON_UNESCAPED_SLASHES),
        'id' => $commandId, 't' => $tenantId,
    ]);

    accountingDestinationLinkInsert($tenantId, $subTenantId, (string) $row['provider'], $payload, $res, $idem);
    return accountingCommandGetStatus($tenantId, $commandId);
}

function accountingCommandMarkFailure(int $tenantId, int $commandId, array $row, AccountingProviderAdapter $adapter, \Throwable $e): array
{
    $norm = $adapter->normalizeProviderError($e);
    $attempts = (int) $row['attempts'] + 1;
    $max = (int) $row['max_attempts'];
    $deadLetter = $attempts >= $max;
    $nextRetry = $deadLetter
        ? null
        : date('Y-m-d H:i:s', time() + (int) (ACCOUNTING_OUTBOX_BACKOFF_BASE_SECONDS * (2 ** ($attempts - 1))));
    getDB()->prepare(
        'UPDATE accounting_outbox_events
            SET status = :s, attempts = :a, error_code = :ec,
                error_message = :em, next_retry_at = :nr
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's'  => $deadLetter ? 'dead_letter' : 'retrying',
        'a'  => $attempts,
        'ec' => $norm['code'],
        'em' => $norm['message'],
        'nr' => $nextRetry,
        'id' => $commandId, 't' => $tenantId,
    ]);
    return accountingCommandGetStatus($tenantId, $commandId);
}

function accountingDestinationLinkInsert(int $tenantId, int $subTenantId, string $provider, array $payload, array $providerResult, string $idempotencyKey): void
{
    $providerObjectType = (string) ($providerResult['provider_object_type'] ?? '');
    $providerObjectId   = (string) ($providerResult['provider_object_id']   ?? '');
    $corefluxObjectType = (string) ($payload['coreflux_object_type'] ?? '');
    $corefluxObjectId   = (int)    ($payload['coreflux_object_id']   ?? 0);
    if ($providerObjectId === '' || $corefluxObjectId === 0) return; // nothing to link
    getDB()->prepare(
        'INSERT IGNORE INTO accounting_destination_links
            (tenant_id, sub_tenant_id, provider, provider_org_id,
             coreflux_object_type, coreflux_object_id,
             provider_object_type, provider_object_id,
             source_system, source_object_id, sync_status, idempotency_key)
         VALUES (:t, :st, :p, :org,
                 :cot, :coi, :pot, :poi,
                 :ss, :sid, :status, :ik)'
    )->execute([
        't'   => $tenantId, 'st' => $subTenantId, 'p' => $provider,
        'org' => $payload['provider_org_id'] ?? '',
        'cot' => $corefluxObjectType, 'coi' => $corefluxObjectId,
        'pot' => $providerObjectType, 'poi' => $providerObjectId,
        'ss'  => $payload['source_system']     ?? null,
        'sid' => $payload['source_object_id']  ?? null,
        'status' => ($providerResult['status'] ?? 'pending') === 'posted' ? 'posted' : 'pending',
        'ik'  => $idempotencyKey,
    ]);
}

/** JSON-decode the JSON columns on an outbox row so callers don't have to. */
function accountingOutboxRowDecode(array $row): array
{
    $row['id']            = (int) $row['id'];
    $row['tenant_id']     = (int) $row['tenant_id'];
    $row['sub_tenant_id'] = (int) $row['sub_tenant_id'];
    $row['attempts']      = (int) $row['attempts'];
    $row['max_attempts']  = (int) $row['max_attempts'];
    foreach (['command_payload', 'provider_result'] as $jc) {
        if (!empty($row[$jc])) {
            $d = json_decode((string) $row[$jc], true);
            $row[$jc] = is_array($d) ? $d : null;
        } else {
            $row[$jc] = null;
        }
    }
    return $row;
}

/**
 * accountingTryEnqueueDraft — best-effort hook called by AP/AR/JE
 * module write paths. Resolves the legal entity (sub_tenant_id) from
 * the row itself OR from the tenant's sole active accounting
 * connection; enqueues a draft command; NEVER throws. Designed so
 * existing module code can call it with one line:
 *
 *     accountingTryEnqueueDraft($tid, 'invoice', $row, $user['id'] ?? null);
 *
 * Returns the enqueued outbox row on success, null on skip
 * (no connection / ambiguous entity / disabled / missing id).
 *
 * Idempotency key is stable per (object_type, object_id, version).
 * The `version` comes from `updated_at` so a re-approval after edit
 * enqueues a fresh draft instead of being dedupe'd against the prior
 * one.
 */
function accountingTryEnqueueDraft(int $tenantId, string $objectType, array $row, ?int $userId = null): ?array
{
    static $commandMap = [
        'bill'    => 'create_draft_bill',
        'invoice' => 'create_draft_invoice',
        'journal' => 'create_draft_journal',
    ];
    if (!isset($commandMap[$objectType])) return null;
    $commandType = $commandMap[$objectType];

    $rowId = (int) ($row['id'] ?? 0);
    if ($rowId <= 0) return null;

    // ── Hard skip: consolidation / elimination JEs are CoreFlux-platform-only
    // by user spec — they must NEVER hit the destination accounting system.
    // We detect via the explicit flag (preferred) plus a memo fallback for
    // legacy rows posted before migration 098 added the flag.
    if ($objectType === 'journal') {
        if ((int) ($row['is_consolidation_entry'] ?? 0) === 1) return null;
        $memo = strtolower((string) ($row['memo'] ?? ''));
        if (strpos($memo, 'consolidation') !== false || strpos($memo, 'elimination') !== false) {
            return null;
        }
    }

    // Resolve the sub_tenant. Row.entity_id (JEs) or row.sub_tenant_id
    // wins. Otherwise: tenant must have exactly ONE active connection.
    $subTenantId = (int) ($row['sub_tenant_id'] ?? $row['entity_id'] ?? 0);
    $provider    = '';
    try {
        if ($subTenantId > 0) {
            $chk = getDB()->prepare(
                "SELECT provider FROM accounting_provider_connections
                  WHERE tenant_id = :t AND sub_tenant_id = :st
                    AND connection_status = 'active' LIMIT 1"
            );
            $chk->execute(['t' => $tenantId, 'st' => $subTenantId]);
            $provider = (string) $chk->fetchColumn();
            if ($provider === '') return null;
        } else {
            $stmt = getDB()->prepare(
                "SELECT sub_tenant_id, provider FROM accounting_provider_connections
                  WHERE tenant_id = :t AND connection_status = 'active'
                  LIMIT 2"
            );
            $stmt->execute(['t' => $tenantId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) !== 1) return null; // 0 = nothing wired, 2+ = ambiguous
            $subTenantId = (int) $rows[0]['sub_tenant_id'];
            $provider    = (string) $rows[0]['provider'];
        }
    } catch (\Throwable $e) {
        return null;
    }

    // ── Per-entity sync_config gate. The connection table now carries a
    // sync_config JSON that says push / pull / two_way / off per entity
    // type. We only enqueue when the operator has explicitly opted this
    // entity type in for push. Intercompany JEs go through a distinct
    // 'intercompany' toggle so admins can sync ordinary JEs without
    // intercompany or vice versa.
    if (function_exists('accountingShouldSyncJournalEntry')) {
        if ($objectType === 'journal') {
            if (!accountingShouldSyncJournalEntry($tenantId, $subTenantId, $provider, $row)) return null;
        } elseif ($objectType === 'bill') {
            if (!accountingShouldSync($tenantId, $subTenantId, $provider, 'bills', 'push')) return null;
        } elseif ($objectType === 'invoice') {
            if (!accountingShouldSync($tenantId, $subTenantId, $provider, 'invoices', 'push')) return null;
        }
    }

    // Stable idempotency key. Includes a version derived from
    // updated_at (or status) so a re-approval after edit produces a
    // distinct command instead of being dedupe'd. Falls back to a
    // wall-clock fingerprint when the row has no updated_at.
    $version = (string) ($row['updated_at'] ?? $row['approved_at']
              ?? $row['posting_date'] ?? date('Y-m-d-H:i:s'));
    $idem = sprintf('%s:%d:v=%s', $objectType, $rowId, preg_replace('/[^0-9]/', '', $version) ?: '0');

    // Payload: pass the CoreFlux row through with coreflux_object_*
    // markers. Field-by-field CoreFlux→Jaz translation lives in a
    // later slice; today the worker will hit Jaz with this shape and
    // any missing required fields surface as `provider_validation`
    // on the outbox row (visible in the outbox UI).
    $payload = [
        'coreflux_object_type' => $objectType,
        'coreflux_object_id'   => $rowId,
        'source_system'        => 'manual',
        'source_object_id'     => (string) $rowId,
        'row'                  => $row,
    ];

    try {
        return accountingCommandEnqueue(
            $tenantId, $subTenantId, $commandType,
            $payload, $idem,
            sprintf('%s:%d:approve', $objectType, $rowId),
            $userId
        );
    } catch (\Throwable $e) {
        // Per-spec: the hook is best-effort. Failures here MUST NOT
        // block the originating CoreFlux operation (approving a bill,
        // posting a JE). Log and move on; the operator can re-trigger
        // from the outbox UI when the cause is fixed.
        error_log('[accountingTryEnqueueDraft] ' . $e->getMessage());
        return null;
    }
}
