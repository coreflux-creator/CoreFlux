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

    try {
        switch ($row['command_type']) {
            case 'create_draft_bill':
                $res = $adapter->createDraftBill($tenantId, $subTenantId, $payload, $idem); break;
            case 'create_draft_invoice':
                $res = $adapter->createDraftInvoice($tenantId, $subTenantId, $payload, $idem); break;
            case 'create_draft_journal':
                $res = $adapter->createDraftJournal($tenantId, $subTenantId, $payload, $idem); break;
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
    $pdo->prepare(
        'UPDATE accounting_outbox_events
            SET status = "posted", posted_at = NOW(),
                provider_result = :pr, error_code = NULL,
                error_message = NULL, next_retry_at = NULL
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'pr' => json_encode($res, JSON_UNESCAPED_SLASHES),
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
