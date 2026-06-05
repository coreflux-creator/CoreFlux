<?php
/**
 * core/accounting/post_approval_gates.php
 *
 * P2 hardening (Slice C follow-up) — post-approval gate helpers for
 * AI-drafted journal entries.  Centralises the 6 gating rules so
 * tool_gateway, accounting.php and any future caller stay consistent:
 *
 *   1. accountingComputeDraftHash()
 *        Canonical sha256 of a draft JE's body (entity, posting_date,
 *        currency, sorted lines). Idempotent — same inputs → same hash.
 *
 *   2. accountingApprovalRequestPayloadForJe()
 *        Helper for the workflow node that opens a workflow_approvals
 *        row: returns the *minimal* request_payload structure the gate
 *        will accept later. Snapshotting happens here.
 *
 *   3. accountingCheckPostApprovalGates()
 *        Pure-read pre-flight: takes ($tenantId, $jeId, $approvalRow,
 *        $actorUserId), returns ['ok' => bool, 'code' => string?,
 *        'message' => string?]. Implements rules 1, 3, 4, 6 (binding,
 *        SoD, expiry, mutation guard).  Single-use (rule 2) is enforced
 *        atomically inside accountingPromoteDraftToPosted() via a
 *        conditional UPDATE.  The status='approved' check (rule 0) is
 *        already enforced by aiToolInvoke and is NOT re-checked here.
 *
 * No DB writes happen in this file — write paths live in
 * /modules/accounting/lib/accounting.php so the existing audit + jaz
 * outbox hooks stay in one place.
 */
declare(strict_types=1);

if (!function_exists('getDB')) {
    require_once __DIR__ . '/../db.php';
}

/**
 * Canonical sha256 hash of a draft JE's mutable body. Stable across
 * re-orderings of `lines` (we sort by line_no) so a benign re-save
 * doesn't trip the mutation guard, but ANY change to amounts,
 * accounts, dimensions, memos, or header fields flips the hash.
 *
 * Throws RuntimeException if the JE doesn't exist or isn't tenant-scoped.
 *
 * @param int $tenantId
 * @param int $jeId
 * @return string 64-char hex digest
 */
function accountingComputeDraftHash(int $tenantId, int $jeId): string
{
    if ($jeId <= 0) {
        throw new \InvalidArgumentException('je_id required');
    }
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT id, entity_id, posting_date, currency, memo
           FROM accounting_journal_entries
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $jeId, 't' => $tenantId]);
    $head = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$head) {
        throw new \RuntimeException("JE #{$jeId} not found for tenant #{$tenantId}");
    }

    // tenant-leak-allow: parent JE was fetched tenant-scoped on the line above; lines join by je_id
    $linesStmt = $pdo->prepare(
        'SELECT line_no, account_id, debit, credit, memo, dim_json
           FROM accounting_journal_entry_lines
          WHERE je_id = :je
          ORDER BY line_no ASC'
    );
    $linesStmt->execute(['je' => $jeId]);
    $lineRows = $linesStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Build a deterministic shape — keys sorted, floats rendered with
    // 2-decimal fixed precision to match DECIMAL(14,2) storage.
    $canonical = [
        'entity_id'    => (int)    $head['entity_id'],
        'posting_date' => (string) $head['posting_date'],
        'currency'     => (string) $head['currency'],
        'memo'         => $head['memo'] !== null ? (string) $head['memo'] : null,
        'lines'        => array_map(function (array $r): array {
            $dims = $r['dim_json'] ? (json_decode((string) $r['dim_json'], true) ?: []) : [];
            ksort($dims);
            return [
                'line_no'    => (int) $r['line_no'],
                'account_id' => (int) $r['account_id'],
                'debit'      => number_format((float) $r['debit'],  2, '.', ''),
                'credit'     => number_format((float) $r['credit'], 2, '.', ''),
                'memo'       => $r['memo'] !== null ? (string) $r['memo'] : null,
                'dims'       => $dims,
            ];
        }, $lineRows),
    ];

    $json = json_encode($canonical,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new \RuntimeException('canonical encode failed: ' . json_last_error_msg());
    }
    return hash('sha256', $json);
}

/**
 * Build the minimal `request_payload` the gate will accept for a JE
 * promotion approval. Use this from the workflow node that opens the
 * approval so the snapshot fields are guaranteed to be present.
 *
 * @return array {je_id, draft_hash, snapshot_at}
 */
function accountingApprovalRequestPayloadForJe(int $tenantId, int $jeId): array
{
    return [
        'je_id'       => $jeId,
        'draft_hash'  => accountingComputeDraftHash($tenantId, $jeId),
        'snapshot_at' => date('c'),
    ];
}

/**
 * Pre-flight gate checks. Returns ['ok' => true] on success or
 * ['ok' => false, 'code' => '...', 'message' => '...'] on the first
 * failing rule.
 *
 * Caller (tool_gateway risk-4 path) has already verified:
 *   - approval row exists, tenant-scoped, status='approved'
 *
 * @param int    $tenantId
 * @param int    $jeId             je_id arg passed to the promote tool
 * @param array  $approvalRow      workflow_approvals row (must include
 *                                 request_payload, decided_by_user_id,
 *                                 expires_at, consumed_at)
 * @param ?int   $actorUserId      user invoking the post tool
 */
function accountingCheckPostApprovalGates(
    int $tenantId,
    int $jeId,
    array $approvalRow,
    ?int $actorUserId
): array {
    if ($jeId <= 0) {
        return ['ok' => false, 'code' => 'bad_args', 'message' => 'je_id required'];
    }

    // Rule 2 (single-use, pre-flight portion) — atomic enforcement
    // happens via conditional UPDATE in accountingPromoteDraftToPosted,
    // but bail early to give the caller a clean error code.
    if (!empty($approvalRow['consumed_at'])) {
        return [
            'ok'      => false,
            'code'    => 'approval_already_consumed',
            'message' => "approval #{$approvalRow['id']} already consumed by JE #"
                       . ((int) ($approvalRow['consumed_by_je_id'] ?? 0))
                       . "; approvals are single-use",
        ];
    }

    // Rule 4 — honor expires_at.
    if (!empty($approvalRow['expires_at'])) {
        $expiresTs = strtotime((string) $approvalRow['expires_at']);
        if ($expiresTs !== false && $expiresTs < time()) {
            return [
                'ok'      => false,
                'code'    => 'approval_expired',
                'message' => "approval #{$approvalRow['id']} expired at {$approvalRow['expires_at']}",
            ];
        }
    }

    // Decode the request payload once.
    $rawPayload = $approvalRow['request_payload'] ?? null;
    $payload    = is_string($rawPayload) ? (json_decode($rawPayload, true) ?: [])
                                          : (is_array($rawPayload) ? $rawPayload : []);

    // Rule 1 — Approval ↔ JE binding. The workflow node that opened
    // the approval MUST stamp request_payload.je_id.
    if (!array_key_exists('je_id', $payload)) {
        return [
            'ok'      => false,
            'code'    => 'approval_missing_binding',
            'message' => "approval #{$approvalRow['id']} request_payload lacks je_id binding; "
                       . "use accountingApprovalRequestPayloadForJe() when opening the approval",
        ];
    }
    if ((int) $payload['je_id'] !== $jeId) {
        return [
            'ok'      => false,
            'code'    => 'approval_je_mismatch',
            'message' => "approval #{$approvalRow['id']} authorizes JE #"
                       . (int) $payload['je_id']
                       . ", not JE #{$jeId}",
        ];
    }

    // Rule 6 — Draft-mutation guard. The snapshot hash MUST be present
    // (we just inserted the workflow node helper for this) and MUST
    // still match the current draft body.
    if (empty($payload['draft_hash'])) {
        return [
            'ok'      => false,
            'code'    => 'approval_missing_hash',
            'message' => "approval #{$approvalRow['id']} request_payload lacks draft_hash; "
                       . "use accountingApprovalRequestPayloadForJe() when opening the approval",
        ];
    }
    try {
        $currentHash = accountingComputeDraftHash($tenantId, $jeId);
    } catch (\Throwable $e) {
        return [
            'ok'      => false,
            'code'    => 'draft_not_found',
            'message' => "could not hash draft JE #{$jeId}: " . $e->getMessage(),
        ];
    }
    if (!hash_equals((string) $payload['draft_hash'], $currentHash)) {
        return [
            'ok'      => false,
            'code'    => 'draft_mutated',
            'message' => "draft JE #{$jeId} was modified after approval #{$approvalRow['id']} "
                       . "was granted; request a fresh approval",
        ];
    }

    // Rule 3 — Segregation of Duties. Approver cannot be the drafter.
    // Fetch the JE's created_by_user_id from the row.
    $stmt = getDB()->prepare(
        'SELECT created_by_user_id FROM accounting_journal_entries
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $jeId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row && $row['created_by_user_id'] !== null) {
        $drafterUid  = (int) $row['created_by_user_id'];
        $approverUid = (int) ($approvalRow['decided_by_user_id'] ?? 0);
        if ($drafterUid > 0 && $approverUid > 0 && $drafterUid === $approverUid) {
            return [
                'ok'      => false,
                'code'    => 'sod_self_approval',
                'message' => "SoD violation: user #{$drafterUid} drafted AND approved JE #{$jeId}",
            ];
        }
        // Belt-and-suspenders — the human invoking the post tool should
        // typically be the approver. If they ARE the drafter we also
        // refuse (the AI / agent has no user_id so $actorUserId may be
        // null, in which case we skip this leg).
        if ($actorUserId !== null && $actorUserId > 0 && $drafterUid === $actorUserId) {
            return [
                'ok'      => false,
                'code'    => 'sod_self_approval',
                'message' => "SoD violation: drafter (user #{$drafterUid}) cannot invoke the post tool",
            ];
        }
    }

    return ['ok' => true];
}
