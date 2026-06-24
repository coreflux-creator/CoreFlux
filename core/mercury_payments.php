<?php
/**
 * core/mercury_payments.php — Payment Engine + State Machine (Slice 3).
 *
 * Implements the user-clarified gated workflow:
 *   1. Operator creates a payment_instructions row (Draft).
 *   2. Operator submits for approval (Draft → PendingApproval).
 *   3. A second user (Segregation-of-Duties) approves (→ Approved).
 *   4. mpAdvance() worker picks up Approved → Funding by calling Mercury
 *      to debit the tenant's default_funding_recipient (external_account)
 *      and credit the operating Mercury account.
 *   5. Next worker tick polls Mercury for the funding txn status. ONLY
 *      when it returns `settled` does the row advance to Submitted by
 *      calling Mercury again to debit operating → credit vendor.
 *   6. Subsequent polls move Submitted → Settled (or Failed/Returned).
 *   7. Slice 4 will add Settled → Reconciled by matching mercury_transactions.
 *
 * Every state change writes to payment_instruction_audit + emits a
 * `mercury.payment.transition` event into audit_log.
 *
 * NEVER calls Mercury synchronously from the UI — all adapter calls happen
 * inside mpAdvance() (the worker entry point) so user-facing endpoints stay
 * fast and idempotent.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/RBAC.php';
require_once __DIR__ . '/mercury_adapter.php';
require_once __DIR__ . '/mercury_service.php';
require_once __DIR__ . '/mercury_recipients.php';
require_once __DIR__ . '/approval_policy.php';
require_once __DIR__ . '/integrations/verify_create.php';

// ----------------------------------------------------------------- state machine

/**
 * Allowed transitions matrix. Refuses anything not enumerated.
 * Cancelled is reachable from any pre-Submitted state.
 */
function mpTransitionAllowed(string $from, string $to): bool
{
    $matrix = [
        'Draft'           => ['PendingApproval', 'Cancelled'],
        'PendingApproval' => ['Draft', 'Approved', 'Cancelled'],
        'Approved'        => ['Funding', 'Failed', 'Cancelled'],
        'Funding'         => ['Submitted', 'Failed', 'Returned'],
        'Submitted'       => ['Settled', 'Failed', 'Returned'],
        'Settled'         => ['Reconciled', 'Returned'],   // Slice 4 owns Reconciled
        'Reconciled'      => [],
        // Failed is a soft-terminal state — admin requeue can return
        // it to Approved (the original two-eye approval is still valid
        // because no funds moved). See mpRequeueFailed() for the
        // controlled re-entry path.
        'Failed'          => ['Approved', 'Cancelled'],
        'Returned'        => [],
        'Cancelled'       => [],
    ];
    $allowed = $matrix[$from] ?? [];
    return in_array($to, $allowed, true);
}

/**
 * Persist a state transition with audit. Idempotent on same-state writes
 * (returns false without modifying anything). Throws when the transition
 * is illegal.
 */
function mpTransition(int $tenantId, int $instructionId, string $toState, ?string $reason, ?int $actorUserId, array $patch = [], array $meta = []): bool
{
    $pdo = getDB();
    $cur = $pdo->prepare('SELECT state FROM payment_instructions WHERE tenant_id = :t AND id = :id FOR UPDATE');
    // Wrap in a transaction so the SELECT FOR UPDATE locks the row.
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $cur->execute(['t' => $tenantId, 'id' => $instructionId]);
        $from = $cur->fetchColumn();
        if ($from === false) {
            cf_tx_rollback($pdo, $ownsTxn);
            throw new \RuntimeException('payment_instruction not found');
        }
        if ($from === $toState) {
            cf_tx_rollback($pdo, $ownsTxn);
            return false;
        }
        if (!mpTransitionAllowed((string) $from, $toState)) {
            cf_tx_rollback($pdo, $ownsTxn);
            throw new \RuntimeException("Illegal transition {$from} → {$toState}");
        }

        $sets   = ['state = :st', 'state_reason = :rn', 'state_changed_at = NOW()'];
        $params = ['st' => $toState, 'rn' => $reason, 't' => $tenantId, 'id' => $instructionId];
        foreach ($patch as $col => $val) {
            // simple allowlist to avoid SQL injection via dynamic column
            if (!preg_match('/^[a-z0-9_]+$/', (string) $col)) continue;
            $sets[] = "{$col} = :p_{$col}";
            $params["p_{$col}"] = $val;
        }
        $pdo->prepare(
            'UPDATE payment_instructions SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :t AND id = :id'
        )->execute($params);

        $pdo->prepare(
            'INSERT INTO payment_instruction_audit
                (tenant_id, instruction_id, from_state, to_state, reason, actor_user_id, meta_json)
             VALUES (:t, :id, :fr, :to, :rn, :u, :mt)'
        )->execute([
            't'  => $tenantId,
            'id' => $instructionId,
            'fr' => $from,
            'to' => $toState,
            'rn' => $reason,
            'u'  => $actorUserId,
            'mt' => $meta ? json_encode($meta) : null,
        ]);

        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    // Best-effort cross-module audit event
    try {
        $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
             VALUES (:t, :u, "mercury.payment.transition", :id, :m, NOW())'
        )->execute([
            't'  => $tenantId,
            'u'  => $actorUserId,
            'id' => $instructionId,
            'm'  => json_encode(['from' => $from, 'to' => $toState, 'reason' => $reason] + $meta),
        ]);
    } catch (\Throwable $e) {}
    return true;
}

// ----------------------------------------------------------------- CRUD

function mpCreate(int $tenantId, array $data, ?int $userId = null): array
{
    foreach (['recipient_id', 'amount_cents'] as $k) {
        if (empty($data[$k])) {
            throw new \InvalidArgumentException("payment.{$k} required");
        }
    }
    $rec = mercuryRecipientGet($tenantId, (int) $data['recipient_id']);
    if (!$rec || !in_array($rec['kind'] ?? '', ['vendor', 'sweep_destination'], true)) {
        // Treasury Sweep go-live (migration 075) added the
        // sweep_destination kind so internal-transfer instructions
        // (account-to-account within the same Mercury org) reuse this
        // pipeline with full approval-policy + state-machine coverage.
        throw new \InvalidArgumentException('recipient must exist with kind=vendor or kind=sweep_destination');
    }
    if ($rec['status'] !== 'active') {
        throw new \InvalidArgumentException('recipient is not active');
    }
    $amount = (int) $data['amount_cents'];
    if ($amount <= 0) throw new \InvalidArgumentException('amount_cents must be > 0');

    // Internal-transfer (treasury sweep) wiring. For sweep_destination
    // recipients we collapse the dual-leg flow: source IS already a
    // Mercury account, so no funding pull is needed. The source account
    // is reused via the existing operating_mercury_account_id column
    // ("the Mercury account I'm debiting"). The flag tells mpAdvance to
    // route through mpOriginateInternalTransfer() instead of
    // mpOriginateFunding() at the Approved-state branch.
    $isInternalTransfer = $rec['kind'] === 'sweep_destination' ? 1 : 0;
    $sourceMercuryAccountId = null;
    if ($isInternalTransfer) {
        $sourceMercuryAccountId = trim((string) ($data['source_mercury_account_id'] ?? ''));
        if ($sourceMercuryAccountId === '') {
            throw new \InvalidArgumentException('source_mercury_account_id required for sweep_destination instructions');
        }
        // Validate the Mercury account belongs to this tenant.
        $chk = getDB()->prepare(
            'SELECT id FROM mercury_accounts WHERE tenant_id = :t AND mercury_account_id = :m LIMIT 1'
        );
        $chk->execute(['t' => $tenantId, 'm' => $sourceMercuryAccountId]);
        if (!$chk->fetchColumn()) {
            throw new \InvalidArgumentException('source_mercury_account_id is not in the tenant\'s synced accounts; run Refresh accounts first');
        }
    }

    $idem = (string) ($data['idempotency_key'] ?? '');
    if ($idem === '') {
        $idem = 'pi_' . date('Ymd-His') . '_' . substr(bin2hex(random_bytes(6)), 0, 10);
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO payment_instructions
            (tenant_id, idempotency_key, state, source_module, source_ref, is_internal_transfer,
             recipient_id, amount_cents, currency, description, notes,
             operating_mercury_account_id, created_by_user_id)
         VALUES (:t, :ik, "Draft", :sm, :sr, :it, :r, :a, :cur, :d, :n, :oma, :u)'
    )->execute([
        't'   => $tenantId,
        'ik'  => $idem,
        'sm'  => (string) ($data['source_module'] ?? 'manual'),
        'sr'  => $data['source_ref'] ?? null,
        'it'  => $isInternalTransfer,
        'r'   => (int) $data['recipient_id'],
        'a'   => $amount,
        'cur' => (string) ($data['currency'] ?? 'USD'),
        'd'   => $data['description'] ?? null,
        'n'   => $data['notes'] ?? null,
        'oma' => $sourceMercuryAccountId,
        'u'   => $userId,
    ]);
    return mpGet($tenantId, (int) $pdo->lastInsertId());
}

function mpGet(int $tenantId, int $id): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM payment_instructions WHERE tenant_id = :t AND id = :id LIMIT 1');
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('payment_instruction not found');
    return $row;
}

function mpList(int $tenantId, array $opts = []): array
{
    try {
        $pdo = getDB();
        // Use a correlated subquery for ack count so the list endpoint can
        // surface an inline approval progress indicator (N/M) on the table
        // row without forcing the UI to round-trip per payment.
        $sql = 'SELECT pi.*, r.name AS recipient_name,
                       (SELECT COUNT(*) FROM payment_instruction_approvals a
                          WHERE a.tenant_id = pi.tenant_id AND a.instruction_id = pi.id) AS acks_collected
                  FROM payment_instructions pi
                  LEFT JOIN mercury_recipients r ON r.id = pi.recipient_id AND r.tenant_id = pi.tenant_id
                 WHERE pi.tenant_id = :t';
        $params = ['t' => $tenantId];
        if (!empty($opts['state'])) { $sql .= ' AND pi.state = :st'; $params['st'] = $opts['state']; }
        $sql .= ' ORDER BY pi.created_at DESC, pi.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Resolve acks_required for PendingApproval rows only (bounded loop;
        // mpList already caps at 200). Other states show no inline badge.
        if ($rows) {
            require_once __DIR__ . '/approval_policy.php';
            foreach ($rows as &$r) {
                $r['acks_collected'] = (int) ($r['acks_collected'] ?? 0);
                $r['acks_required']  = 1;
                if (($r['state'] ?? '') === 'PendingApproval') {
                    try {
                        $policy = approvalPolicyResolve(
                            $tenantId,
                            (int) $r['amount_cents'],
                            (int) $r['recipient_id'],
                            (string) ($r['operating_mercury_account_id'] ?? '') ?: null,
                            'mercury'
                        );
                        $r['acks_required'] = $policy
                            ? max(1, (int) $policy['min_approvers'])
                            : 1;
                    } catch (\Throwable $e) {
                        // Fall back to single-approver policy on resolver fail.
                    }
                }
            }
            unset($r);
        }
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

// ----------------------------------------------------------------- user actions

function mpSubmitForApproval(int $tenantId, int $id, ?int $userId): bool
{
    return mpTransition($tenantId, $id, 'PendingApproval', 'submitted_for_approval', $userId, [
        'submitted_for_approval_at' => date('Y-m-d H:i:s'),
    ]);
}

/** Two-eye approval: refuses self-approval + requires a distinct approver role. */
function mpApprove(int $tenantId, int $id, $approver, ?string $note = null, array $opts = []): bool
{
    // Accept either a full user array (preferred — enables permission check) or
    // just an int user id (legacy callers). Wrap so the existing $approverId
    // code below keeps working.
    if (is_array($approver)) {
        $approverId   = (int) ($approver['id']   ?? 0) ?: null;
        $approverUser = $approver;
    } else {
        $approverId   = $approver !== null ? (int) $approver : null;
        $approverUser = null;
    }

    // Role-based SoD enforcement (Slice 3.6 hardening). The approver MUST hold
    // `treasury.payment.approve` — a permission deliberately distinct from the
    // `accounting.bank.manage` perm AP clerks use to create instructions.
    // Without this check, two clerks with the same role could cover for each
    // other. Service-layer enforcement so curl-bypass attempts also fail.
    if ($approverUser !== null && !rbac_legacy_can($approverUser, 'treasury.payment.approve')) {
        throw new \RuntimeException(
            'Role separation: approver must hold the treasury.payment.approve permission'
        );
    }

    $row = mpGet($tenantId, $id);
    if ((int) ($row['created_by_user_id'] ?? 0) === (int) ($approverId ?? -1)) {
        throw new \RuntimeException('Segregation of duties: creator cannot approve their own payment');
    }

    // -----------------------------------------------------------------
    // Approval-policy engine (migration 072). Tenants can encode amount-
    // banded rules that demand a specific approver role, N-of-M co-
    // approvers, and an enforced cool-off window before the worker may
    // advance the row. Absent any matching policy, the legacy single-
    // approver default (creator ≠ approver + treasury.payment.approve
    // permission) still gates the transition.
    //
    // Policy is resolved by amount + recipient + source account; the
    // most-specific match wins. See approvalPolicyResolve().
    // -----------------------------------------------------------------
    $policy = approvalPolicyResolve(
        $tenantId,
        (int) $row['amount_cents'],
        (int) $row['recipient_id'],
        // operating_mercury_account_id is only set after Funding; before
        // that we can match by the tenant's default funding account.
        (string) ($row['operating_mercury_account_id'] ?? '') ?: null,
        'mercury'
    );
    $minApprovers = 1;
    $coolOffMinutes = 0;
    if ($policy !== null) {
        $minApprovers   = max(1, (int) ($policy['min_approvers']    ?? 1));
        $coolOffMinutes = max(0, (int) ($policy['cool_off_minutes'] ?? 0));
        $requiredRole   = $policy['required_approver_role'] ?? null;
        if ($requiredRole !== null && $requiredRole !== '' && $approverUser !== null) {
            // Use the role on the authenticated user object (already
            // tenant-scoped by api_require_auth). Avoids a direct
            // user_tenants read so the RBAC sentry stays happy — that
            // table is being phased out in favour of tenant_memberships.
            $hasRole = (string) ($approverUser['role'] ?? '') === $requiredRole;
            if (!$hasRole) {
                throw new \RuntimeException(sprintf(
                    'Approval policy "%s" requires role "%s"; approver does not hold it.',
                    $policy['name'], $requiredRole
                ));
            }
        }
    }

    // Record this user's ack on the co-approver chain. If we still need
    // more approvals, STAY in PendingApproval and short-circuit. The
    // next distinct approver flips the row to Approved.
    $ackCount = $approverId !== null
        ? approvalRecordAck($tenantId, $id, $approverId, $policy['id'] ?? null, $note)
        : 1;
    if ($ackCount < $minApprovers) {
        try {
            getDB()->prepare(
                'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                 VALUES (:t, :u, "mercury.payment.coapproval_recorded", :id, :m, NOW())'
            )->execute([
                't'  => $tenantId, 'u' => $approverId, 'id' => $id,
                'm'  => json_encode([
                    'acks_collected'  => $ackCount,
                    'acks_required'   => $minApprovers,
                    'policy_id'       => $policy['id'] ?? null,
                    'policy_name'     => $policy['name'] ?? null,
                ]),
            ]);
        } catch (\Throwable $_) {}
        return false; // not enough approvals yet
    }

    $patch = [
        'approved_by_user_id' => $approverId,
        'approved_at'         => date('Y-m-d H:i:s'),
    ];
    if ($coolOffMinutes > 0) {
        $patch['cool_off_until'] = date('Y-m-d H:i:s', time() + $coolOffMinutes * 60);
    }
    $ok = mpTransition($tenantId, $id, 'Approved', $note ?: 'approver_ok', $approverId, $patch, [
        'policy_id'       => $policy['id'] ?? null,
        'policy_name'     => $policy['name'] ?? null,
        'acks_collected'  => $ackCount,
        'cool_off_until'  => $patch['cool_off_until'] ?? null,
    ]);

    // Out-of-band CFO notification (Slice 3.6 hardening). Best-effort — never
    // throws, so a flaky mailer can't roll back the approval. Audit log
    // captures success/failure either way.
    if ($ok) {
        try {
            mercuryNotifyCfoOfApproval($tenantId, $id, $approverUser);
        } catch (\Throwable $e) {
            error_log('[mercury.payment.cfo_notify] failed: ' . $e->getMessage());
        }
    }

    // Dual-leg auto-trigger (2026-02 — current fork). The product win the
    // user explicitly called out: "the approval within the platform
    // actually triggers two transactions — transfer in to Mercury from
    // funding account, transfer out to vendor." We honour that by
    // immediately driving the row from Approved → Funding (originate leg
    // 1: pull from external funding account into the operating Mercury
    // account). The poll-and-payout step (leg 2) still happens on the
    // next worker tick (or via the "Continue" button) because it
    // requires Mercury to confirm the funding transfer cleared first.
    //
    // Best-effort by design: if Mercury is unreachable or the connection
    // is mid-rotation, the row stays in Approved and the cron worker
    // picks it up on its next pass. The approval transition itself has
    // already committed and audited — it is NEVER rolled back by an
    // adapter failure here.
    //
    // Opt out via mpApprove(..., ['trigger_now' => false]) — exercised
    // by smoke tests that want to assert the pure approval transition
    // without invoking the Mercury HTTP layer.
    if ($ok && ($opts['trigger_now'] ?? true) !== false) {
        // Cool-off window enforcement (policy.cool_off_minutes). When set,
        // we defer the funding-leg origination to the cron worker so the
        // configured delay genuinely elapses before any money moves. The
        // worker SELECT in /app/cron/mercury_payment_worker.php must also
        // honour cool_off_until (added in migration 072).
        $coolOff = $patch['cool_off_until'] ?? null;
        if ($coolOff !== null && strtotime($coolOff) > time()) {
            try {
                getDB()->prepare(
                    'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                     VALUES (:t, :u, "mercury.payment.cool_off_deferred", :id, :m, NOW())'
                )->execute([
                    't'  => $tenantId, 'u' => $approverId, 'id' => $id,
                    'm'  => json_encode([
                        'cool_off_until' => $coolOff,
                        'policy_id'      => $policy['id'] ?? null,
                    ]),
                ]);
            } catch (\Throwable $_) {}
            return $ok;
        }
        try {
            mpAdvance($tenantId, $id);
        } catch (\Throwable $e) {
            error_log('[mercury.payment.auto_advance] approval-time advance failed (will retry next worker tick): ' . $e->getMessage());
            try {
                getDB()->prepare(
                    'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                     VALUES (:t, :u, "mercury.payment.auto_advance_failed", :id, :m, NOW())'
                )->execute([
                    't'  => $tenantId,
                    'u'  => $approverId,
                    'id' => $id,
                    'm'  => json_encode(['error' => substr($e->getMessage(), 0, 400)]),
                ]);
            } catch (\Throwable $_) {}
        }
    }

    return $ok;
}

/**
 * Send an out-of-band approval notice to CFO users in this tenant so a
 * compromised approval is visible to the C-suite within minutes instead of
 * waiting for the next reconciliation review. Falls back to master_admin
 * recipients when no `role=cfo` user exists. Best-effort — every failure
 * mode is swallowed so the caller stays atomic.
 */
function mercuryNotifyCfoOfApproval(int $tenantId, int $instructionId, ?array $approverUser): void
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT u.email, u.name
               FROM users u
               JOIN user_tenants ut ON ut.user_id = u.id
              WHERE ut.tenant_id = :t AND ut.role = "cfo"
              ORDER BY u.id ASC LIMIT 10'
        );
        $stmt->execute(['t' => $tenantId]);
        $recipients = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (!$recipients) {
            // No CFO tagged → fall back to master_admin recipients so the
            // notice still lands somewhere a human will see. Tenants without
            // either tagged role: the email is skipped (audit logs the gap).
            $stmt = $pdo->prepare(
                'SELECT u.email, u.name
                   FROM users u
                   JOIN user_tenants ut ON ut.user_id = u.id
                  WHERE ut.tenant_id = :t AND ut.role = "master_admin"
                  ORDER BY u.id ASC LIMIT 5'
            );
            $stmt->execute(['t' => $tenantId]);
            $recipients = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        if (!$recipients) return;

        $row = mpGet($tenantId, $instructionId);
        $vendor = '';
        try {
            $v = $pdo->prepare('SELECT name FROM mercury_recipients WHERE tenant_id = :t AND id = :id LIMIT 1');
            $v->execute(['t' => $tenantId, 'id' => (int) $row['recipient_id']]);
            $vendor = (string) ($v->fetchColumn() ?: '');
        } catch (\Throwable $e) {}

        $amount   = number_format(((int) $row['amount_cents']) / 100, 2);
        $approver = trim((string) (($approverUser['name'] ?? '') ?: ($approverUser['email'] ?? 'unknown approver')));
        $subj = "[CFO notice] Mercury payment approved: \${$amount} → {$vendor}";
        $html =
              "<p>A Mercury payment instruction was just approved for outbound funding.</p>"
            . "<table style='font-size:13px;border-collapse:collapse'>"
            . "<tr><td><b>Instruction #</b></td><td>{$instructionId}</td></tr>"
            . "<tr><td><b>Vendor</b></td><td>" . htmlspecialchars($vendor) . "</td></tr>"
            . "<tr><td><b>Amount</b></td><td>\${$amount} " . htmlspecialchars((string) $row['currency']) . "</td></tr>"
            . "<tr><td><b>Approved by</b></td><td>" . htmlspecialchars($approver) . "</td></tr>"
            . "<tr><td><b>When</b></td><td>" . date('Y-m-d H:i:s') . " UTC</td></tr>"
            . "</table>"
            . "<p style='font-size:12px;color:#64748b'>This is a CFO-only out-of-band notice. "
            . "If you did NOT expect this approval, sign in to CoreFlux → Treasury → Mercury Payments and cancel the instruction before the worker funds it.</p>";

        $sent = 0; $failed = 0;
        if (function_exists('mailerSend')) {
            foreach ($recipients as $r) {
                try {
                    mailerSend([
                        'to'        => $r['email'],
                        'subject'   => $subj,
                        'body_html' => $html,
                        'module'    => 'treasury',
                        'purpose'   => 'payments',
                        'tenant_id' => $tenantId,
                    ]);
                    $sent++;
                } catch (\Throwable $e) { $failed++; }
            }
        }
        // Audit the notification attempt regardless of send outcome so the
        // CFO can later verify "did I get pinged on instruction #X?".
        try {
            $pdo->prepare(
                'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
                 VALUES (:t, :u, "mercury.payment.cfo_notified", :id, :m, NOW())'
            )->execute([
                't'  => $tenantId,
                'u'  => $approverUser['id'] ?? null,
                'id' => $instructionId,
                'm'  => json_encode([
                    'recipients_count' => count($recipients),
                    'sent'             => $sent,
                    'failed'           => $failed,
                    'mailer_present'   => function_exists('mailerSend'),
                ]),
            ]);
        } catch (\Throwable $e) {}
    } catch (\Throwable $e) {
        // Whole function is best-effort — the approval transition itself
        // already succeeded, no need to escalate notification failures.
    }
}

function mpRejectToDraft(int $tenantId, int $id, ?int $userId, string $reason): bool
{
    return mpTransition($tenantId, $id, 'Draft', $reason, $userId);
}

function mpCancel(int $tenantId, int $id, ?int $userId, ?string $reason = null): bool
{
    return mpTransition($tenantId, $id, 'Cancelled', $reason ?: 'cancelled_by_user', $userId);
}

/**
 * Admin requeue from Failed. Used when Mercury rejected the originate
 * (insufficient funds cleared, rate-limit elapsed, recipient detail
 * corrected) but the original two-eye approval is still valid.
 *
 * Resets stage-specific txn refs so the next mpAdvance call originates
 * fresh, and audits the requeue with the supplied reason + caller id.
 *
 * SoD note: this does NOT re-trigger approval — the assumption is that
 * the underlying business decision to pay the recipient didn't change.
 * If it did, the admin should Cancel + create a new PI instead. The
 * endpoint that calls this is RBAC-gated to master_admin / tenant_admin.
 */
function mpRequeueFailed(int $tenantId, int $id, int $userId, string $reason): bool
{
    $row = mpGet($tenantId, $id);
    if ($row['state'] !== 'Failed') {
        throw new \RuntimeException("mpRequeueFailed: PI {$id} is in state '{$row['state']}', expected 'Failed'");
    }
    // Reset stage-specific refs so mpAdvance originates fresh against
    // Mercury. Keep funding_settled_at intact for internal-transfer
    // flows that already cleared the funding leg.
    $patch = [
        'funding_mercury_txn_id'  => null,
        'funding_mercury_status'  => null,
        'payout_mercury_txn_id'   => null,
        'payout_mercury_status'   => null,
        'payout_initiated_at'     => null,
    ];
    return mpTransition($tenantId, $id, 'Approved',
        'requeue_from_failed: ' . substr($reason, 0, 200),
        $userId, $patch, ['action' => 'requeue_from_failed', 'requeued_by' => $userId]);
}

/**
 * Slice 3.5 — AP integration. Create a Draft payment_instruction from an
 * existing ap_payments row. Used by the AP PaymentsList "Send via Mercury"
 * per-row button.
 *
 * Looks up the matching mercury_recipients (kind=vendor) by name. Refuses
 * if no match (operator must add the vendor to the recipient vault first).
 * Refuses if a live (non-Cancelled/Failed) instruction already exists for
 * this ap_payment.
 *
 * SoD is preserved because the resulting instruction starts in `Draft` —
 * treasury ops still has to Submit + Approve before money moves.
 */
function mpCreateFromApPayment(int $tenantId, int $apPaymentId, ?int $userId = null): array
{
    if ($apPaymentId <= 0) throw new \InvalidArgumentException('ap_payment_id required');
    $pdo = getDB();

    // Re-use scopedFind / scopedQuery so we follow the codebase convention,
    // but fall back to raw PDO for portability with the rest of this file.
    $ap = $pdo->prepare(
        'SELECT id, vendor_name, amount, status, method, rail_external_ref
           FROM ap_payments WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $ap->execute(['t' => $tenantId, 'id' => $apPaymentId]);
    $row = $ap->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('ap_payment not found');
    if ($row['status'] !== 'sent') throw new \RuntimeException('ap_payment must be in status=sent');
    if (!empty($row['rail_external_ref'])) {
        throw new \RuntimeException('ap_payment is already attached to rail ' . $row['rail_external_ref']);
    }

    // Refuse duplicate instructions per ap_payment unless prior one was terminal.
    $dup = $pdo->prepare(
        'SELECT id, state FROM payment_instructions
          WHERE tenant_id = :t AND source_module = "ap" AND source_ref = :r
            AND state NOT IN ("Cancelled","Failed","Returned")
          LIMIT 1'
    );
    $dup->execute(['t' => $tenantId, 'r' => (string) $apPaymentId]);
    if ($e = $dup->fetch(\PDO::FETCH_ASSOC)) {
        throw new \RuntimeException("payment_instruction #{$e['id']} already exists for this ap_payment ({$e['state']})");
    }

    // Find the mercury_recipient by vendor name (case-insensitive).
    $rec = $pdo->prepare(
        'SELECT id FROM mercury_recipients
          WHERE tenant_id = :t AND kind = "vendor" AND status = "active"
            AND deleted_at IS NULL
            AND LOWER(name) = LOWER(:n) LIMIT 1'
    );
    $rec->execute(['t' => $tenantId, 'n' => (string) $row['vendor_name']]);
    $recipientId = (int) ($rec->fetchColumn() ?: 0);
    if ($recipientId === 0) {
        throw new \RuntimeException("no Mercury recipient found for vendor '{$row['vendor_name']}'; add them under Treasury → Pay-out Rails → Recipients first");
    }

    $amountCents = (int) round(((float) $row['amount']) * 100);
    $instruction = mpCreate($tenantId, [
        'recipient_id'   => $recipientId,
        'amount_cents'   => $amountCents,
        'currency'       => 'USD',
        'description'    => 'AP #' . $apPaymentId . ' / ' . substr((string) $row['vendor_name'], 0, 35),
        'notes'          => 'Auto-created from ap_payments #' . $apPaymentId,
        'source_module'  => 'ap',
        'source_ref'     => (string) $apPaymentId,
        'idempotency_key' => 'ap:' . $apPaymentId . ':' . substr(bin2hex(random_bytes(3)), 0, 6),
    ], $userId);

    // Stamp the ap_payment so the UI hides the "Send via Mercury" button
    // and the reverse link is discoverable.
    $pdo->prepare(
        'UPDATE ap_payments
            SET rail_external_ref = :ref, disbursement_rail = "mercury"
          WHERE tenant_id = :t AND id = :id'
    )->execute([
        'ref' => 'pi:' . $instruction['id'],
        't'   => $tenantId,
        'id'  => $apPaymentId,
    ]);

    return $instruction;
}

// ----------------------------------------------------------------- workflow orchestrator

/**
 * The worker entry point. Drives ONE payment_instruction one step forward:
 *
 *   Approved   → Funding   (originate Mercury funding pull)
 *   Funding    → Submitted (verify funding cleared → originate payout)
 *   Submitted  → Settled / Returned / Failed (poll payout status)
 *
 * Idempotent — calling on a non-actionable state is a no-op. NEVER calls
 * Mercury more than once per advance() invocation for the same payment.
 * The cron worker loops over actionable states and calls this per row.
 *
 * Returns the new state (or the unchanged one if no transition happened).
 */
function mpAdvance(int $tenantId, int $instructionId): string
{
    $row  = mpGet($tenantId, $instructionId);
    $conn = mercuryGetConnection($tenantId);
    if (!$conn || ($conn['status'] ?? '') !== 'active') {
        mpTransition($tenantId, $instructionId, 'Failed',
            'no active Mercury connection', null, [], ['stage' => 'pre-funding']);
        return 'Failed';
    }
    $apiToken = $conn['api_token'];
    $defaults = mercuryRecipientGetFundingDefault($tenantId);

    switch ($row['state']) {
        case 'Approved':
            // Internal transfers (treasury sweep) collapse to a single
            // Submitted leg — source is already a Mercury account so no
            // funding pull is needed. Vendor payments still go through
            // the full dual-leg pull + payout flow.
            if ((int) ($row['is_internal_transfer'] ?? 0) === 1) {
                return mpOriginateInternalTransfer($tenantId, $row, $apiToken);
            }
            return mpOriginateFunding($tenantId, $row, $apiToken, $defaults);
        case 'Funding':  return mpVerifyAndOriginatePayout($tenantId, $row, $apiToken, $defaults);
        case 'Submitted': return mpPollPayoutStatus($tenantId, $row, $apiToken);
        default: return (string) $row['state'];
    }
}

/**
 * Single-leg internal transfer for sweep_destination instructions.
 *
 * The destination's Mercury counterparty id was pasted into
 * mercury_recipient_mappings via mercurySweepDestinationSetCounterparty().
 * The source Mercury account was stamped onto operating_mercury_account_id
 * at mpCreate() time. Approved → Submitted in one hop, then the
 * standard mpPollPayoutStatus path tracks settlement.
 */
function mpOriginateInternalTransfer(int $tenantId, array $row, string $apiToken): string
{
    $sourceAcctId = (string) ($row['operating_mercury_account_id'] ?? '');
    if ($sourceAcctId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'internal transfer missing source Mercury account', null, [], ['stage' => 'originate_internal_transfer']);
        return 'Failed';
    }

    $destMapping = getDB()->prepare(
        'SELECT mercury_id FROM mercury_recipient_mappings
          WHERE tenant_id = :t AND recipient_id = :r AND mercury_kind = "counterparty" LIMIT 1'
    );
    $destMapping->execute(['t' => $tenantId, 'r' => (int) $row['recipient_id']]);
    $destCounterpartyId = (string) ($destMapping->fetchColumn() ?: '');
    if ($destCounterpartyId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'sweep_destination has no Mercury counterparty mapping; paste the counterparty id via "Set Mercury counterparty" first',
            null, [], ['stage' => 'originate_internal_transfer']);
        return 'Failed';
    }

    $idemTransfer = 'pi:' . $row['idempotency_key'] . ':transfer';
    try {
        $resp = mercuryCreatePayment($apiToken, $sourceAcctId, [
            'recipientId'    => $destCounterpartyId,
            'amount'         => number_format($row['amount_cents'] / 100, 2, '.', ''),
            'paymentMethod'  => 'ach',
            'idempotencyKey' => $idemTransfer,
            'note'           => substr((string) ($row['description'] ?? ('CoreFlux sweep #' . $row['id'])), 0, 50),
        ]);
    } catch (MercuryApiException $e) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'internal transfer originate failed: ' . substr($e->getMessage(), 0, 180),
            null, [], [
                'stage' => 'originate_internal_transfer',
                'http_status' => $e->httpStatus,
                // Charter primitive #6 — full vendor error surface.
                'vendor_error_code' => $e->errorCode,
                'vendor_raw'        => $e->raw,
            ]);
        return 'Failed';
    }

    $txnId  = (string) ($resp['id'] ?? '');
    $status = (string) ($resp['status'] ?? 'pending');
    if ($txnId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'Mercury did not return a transaction id for internal transfer',
            null, [], ['stage' => 'originate_internal_transfer']);
        return 'Failed';
    }

    // Skip the Funding state — no funding pull happened. Stamp both
    // funding_settled_at and funding_initiated_at to NOW() so reporting
    // and reconciliation queries that join on funding timestamps don't
    // mistake an internal transfer for a stalled vendor payment. The
    // payout_* columns then track the single Mercury leg.
    // Charter primitive #5 — post-push verification.
    $verify = mercuryVerifyCreate($apiToken, $sourceAcctId, $txnId, 'pending');
    mpTransition($tenantId, (int) $row['id'], 'Submitted', 'internal transfer originated', null, [
        'funding_initiated_at'   => date('Y-m-d H:i:s'),
        'funding_settled_at'     => date('Y-m-d H:i:s'),
        'funding_mercury_status' => 'internal_transfer_skip',
        'payout_mercury_txn_id'  => $txnId,
        'payout_mercury_status'  => $status,
        'payout_initiated_at'    => date('Y-m-d H:i:s'),
    ], ['mercury_txn_id' => $txnId, 'status' => $status, 'verify' => $verify]);
    return 'Submitted';
}

/** Stage 1: originate the funding pull (debit external, credit Mercury op). */
function mpOriginateFunding(int $tenantId, array $row, string $apiToken, ?array $defaults): string
{
    if (!$defaults || empty($defaults['recipient_id']) || empty($defaults['mercury_account_id'])) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'no default_funding_recipient_id / default_mercury_account_id configured',
            null, [], ['stage' => 'originate_funding']);
        return 'Failed';
    }
    // The funding-side recipient must be mapped to a Mercury external_account.
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT mercury_id FROM mercury_recipient_mappings
          WHERE tenant_id = :t AND recipient_id = :r AND mercury_kind = "external_account" LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'r' => (int) $defaults['recipient_id']]);
    $extAcctId = (string) ($stmt->fetchColumn() ?: '');
    if ($extAcctId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'funding_source recipient has no external_account mapping; paste the Mercury external_account id first',
            null, [], ['stage' => 'originate_funding']);
        return 'Failed';
    }

    $idemFunding = 'pi:' . $row['idempotency_key'] . ':funding';
    try {
        $resp = mercuryCreatePayment($apiToken, (string) $defaults['mercury_account_id'], [
            'recipientId'    => $extAcctId,
            'amount'         => number_format($row['amount_cents'] / 100, 2, '.', ''),
            'paymentMethod'  => 'ach',
            'idempotencyKey' => $idemFunding,
            'note'           => 'CoreFlux funding pull for instruction #' . $row['id'],
        ]);
    } catch (MercuryApiException $e) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'funding originate failed: ' . substr($e->getMessage(), 0, 180),
            null, [], [
                'stage' => 'originate_funding',
                'http_status' => $e->httpStatus,
                // Charter primitive #6 — full vendor error surface.
                'vendor_error_code' => $e->errorCode,
                'vendor_raw'        => $e->raw,
            ]);
        return 'Failed';
    }

    $txnId  = (string) ($resp['id'] ?? '');
    $status = (string) ($resp['status'] ?? 'pending');
    if ($txnId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'Mercury did not return a transaction id', null, [], ['stage' => 'originate_funding']);
        return 'Failed';
    }

    mpTransition($tenantId, (int) $row['id'], 'Funding', 'funding originated', null, [
        'funding_recipient_id'         => (int) $defaults['recipient_id'],
        'operating_mercury_account_id' => (string) $defaults['mercury_account_id'],
        'funding_mercury_txn_id'       => $txnId,
        'funding_mercury_status'       => $status,
        'funding_initiated_at'         => date('Y-m-d H:i:s'),
    ], ['mercury_txn_id' => $txnId, 'status' => $status, 'verify' => mercuryVerifyCreate($apiToken, (string) $defaults['mercury_account_id'], $txnId, 'pending')]);
    return 'Funding';
}

/** Stage 2: poll the funding txn; if cleared, originate the vendor payout. */
function mpVerifyAndOriginatePayout(int $tenantId, array $row, string $apiToken, ?array $defaults): string
{
    if (empty($row['funding_mercury_txn_id']) || empty($row['operating_mercury_account_id'])) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'funding state without funding_mercury_txn_id — inconsistent', null);
        return 'Failed';
    }

    // Poll Mercury for the funding transaction's latest status.
    try {
        $resp = mercuryGetPaymentStatus($apiToken,
            (string) $row['operating_mercury_account_id'],
            (string) $row['funding_mercury_txn_id']);
    } catch (MercuryApiException $e) {
        // Transient — leave row in Funding and try next tick. Update poll timestamp.
        getDB()->prepare(
            'UPDATE payment_instructions SET funding_last_polled_at = NOW() WHERE tenant_id = :t AND id = :id'
        )->execute(['t' => $tenantId, 'id' => $row['id']]);
        return 'Funding';
    }
    $status = strtolower((string) ($resp['status'] ?? ''));

    getDB()->prepare(
        'UPDATE payment_instructions
            SET funding_mercury_status = :s, funding_last_polled_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['s' => $status, 't' => $tenantId, 'id' => $row['id']]);

    if (in_array($status, ['failed', 'cancelled'], true)) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            "funding transfer {$status}", null, [], ['mercury_status' => $status]);
        return 'Failed';
    }
    if ($status === 'returned') {
        mpTransition($tenantId, (int) $row['id'], 'Returned',
            'funding transfer returned', null, [], ['mercury_status' => $status]);
        return 'Returned';
    }
    if (!in_array($status, ['settled', 'posted', 'sent'], true)) {
        // Still pending — wait for next poll tick.
        return 'Funding';
    }

    // Funding cleared. Originate the vendor payout from the same Mercury account.
    $vendorMapping = getDB()->prepare(
        'SELECT mercury_id FROM mercury_recipient_mappings
          WHERE tenant_id = :t AND recipient_id = :r AND mercury_kind = "counterparty" LIMIT 1'
    );
    $vendorMapping->execute(['t' => $tenantId, 'r' => (int) $row['recipient_id']]);
    $vendorMercuryId = (string) ($vendorMapping->fetchColumn() ?: '');
    if ($vendorMercuryId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'vendor recipient has no Mercury counterparty mapping; click Push to Mercury first',
            null, [], ['stage' => 'originate_payout']);
        return 'Failed';
    }

    // Mark funding_settled_at now that we know it cleared.
    getDB()->prepare(
        'UPDATE payment_instructions SET funding_settled_at = NOW() WHERE tenant_id = :t AND id = :id AND funding_settled_at IS NULL'
    )->execute(['t' => $tenantId, 'id' => $row['id']]);

    $idemPayout = 'pi:' . $row['idempotency_key'] . ':payout';
    try {
        $resp = mercuryCreatePayment($apiToken, (string) $row['operating_mercury_account_id'], [
            'recipientId'    => $vendorMercuryId,
            'amount'         => number_format($row['amount_cents'] / 100, 2, '.', ''),
            'paymentMethod'  => 'ach',
            'idempotencyKey' => $idemPayout,
            'note'           => substr((string) ($row['description'] ?? ('CoreFlux #' . $row['id'])), 0, 50),
        ]);
    } catch (MercuryApiException $e) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'payout originate failed: ' . substr($e->getMessage(), 0, 180),
            null, [], [
                'stage' => 'originate_payout',
                'http_status' => $e->httpStatus,
                // Charter primitive #6 — full vendor error surface.
                'vendor_error_code' => $e->errorCode,
                'vendor_raw'        => $e->raw,
            ]);
        return 'Failed';
    }

    $txnId  = (string) ($resp['id'] ?? '');
    $status = (string) ($resp['status'] ?? 'pending');
    if ($txnId === '') {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            'Mercury did not return a payout transaction id', null, [], ['stage' => 'originate_payout']);
        return 'Failed';
    }

    mpTransition($tenantId, (int) $row['id'], 'Submitted', 'payout originated', null, [
        'payout_mercury_txn_id'  => $txnId,
        'payout_mercury_status'  => $status,
        'payout_initiated_at'    => date('Y-m-d H:i:s'),
    ], ['mercury_txn_id' => $txnId, 'status' => $status, 'verify' => mercuryVerifyCreate($apiToken, (string) $row['operating_mercury_account_id'], $txnId, 'pending')]);
    return 'Submitted';
}

/** Stage 3: poll the payout txn. */
function mpPollPayoutStatus(int $tenantId, array $row, string $apiToken): string
{
    if (empty($row['payout_mercury_txn_id']) || empty($row['operating_mercury_account_id'])) {
        return (string) $row['state'];
    }
    try {
        $resp = mercuryGetPaymentStatus($apiToken,
            (string) $row['operating_mercury_account_id'],
            (string) $row['payout_mercury_txn_id']);
    } catch (MercuryApiException $e) {
        getDB()->prepare(
            'UPDATE payment_instructions SET payout_last_polled_at = NOW() WHERE tenant_id = :t AND id = :id'
        )->execute(['t' => $tenantId, 'id' => $row['id']]);
        return 'Submitted';
    }
    $status = strtolower((string) ($resp['status'] ?? ''));

    getDB()->prepare(
        'UPDATE payment_instructions
            SET payout_mercury_status = :s, payout_last_polled_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['s' => $status, 't' => $tenantId, 'id' => $row['id']]);

    if (in_array($status, ['failed', 'cancelled'], true)) {
        mpTransition($tenantId, (int) $row['id'], 'Failed',
            "payout {$status}", null, [], ['mercury_status' => $status]);
        return 'Failed';
    }
    if ($status === 'returned') {
        mpTransition($tenantId, (int) $row['id'], 'Returned',
            'payout returned', null, [], ['mercury_status' => $status]);
        return 'Returned';
    }
    if (in_array($status, ['settled', 'posted'], true)) {
        mpTransition($tenantId, (int) $row['id'], 'Settled', 'payout cleared', null, [
            'payout_settled_at' => date('Y-m-d H:i:s'),
        ], ['mercury_status' => $status]);
        return 'Settled';
    }
    return 'Submitted'; // still pending
}

/**
 * mpGetApprovalProgress — surface the dual-leg approval state for a
 * single payment instruction, so the operator-facing UI can render:
 *   • who has already ack'd (with names)
 *   • how many acks are still needed
 *   • cool-off countdown (seconds remaining; 0 if elapsed or no policy)
 *   • required approver role (if a policy demands one)
 *   • whether the current viewer is eligible to ack
 *
 * Pure-read; never throws. Returns a stable shape even when no policy
 * matches (legacy default: 1 distinct non-creator approver).
 *
 *   ['policy_id', 'policy_name', 'required_approver_role',
 *    'min_approvers', 'cool_off_minutes',
 *    'acks_collected' => N, 'acks_required' => N, 'acks_remaining' => N,
 *    'acks' => [['user_id','user_name','user_email','note','created_at'], ...],
 *    'cool_off_until', 'cool_off_seconds_remaining',
 *    'creator_user_id', 'creator_name',
 *    'can_approve' => bool, 'can_approve_reason' => string]
 */
function mpGetApprovalProgress(int $tenantId, int $instructionId, ?array $viewer = null): array
{
    require_once __DIR__ . '/approval_policy.php';

    $pdo = getDB();
    // tenant-leak-allow: caller already validated tenant ownership via mpGet/list
    $rowStmt = $pdo->prepare(
        'SELECT pi.id, pi.amount_cents, pi.recipient_id, pi.state,
                pi.created_by_user_id, pi.cool_off_until,
                pi.operating_mercury_account_id,
                cu.name AS creator_name
           FROM payment_instructions pi
      LEFT JOIN users cu ON cu.id = pi.created_by_user_id
          WHERE pi.tenant_id = :t AND pi.id = :i'
    );
    $rowStmt->execute(['t' => $tenantId, 'i' => $instructionId]);
    $pi = $rowStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$pi) return [];

    $policy = approvalPolicyResolve(
        $tenantId,
        (int) $pi['amount_cents'],
        (int) $pi['recipient_id'],
        (string) ($pi['operating_mercury_account_id'] ?? '') ?: null,
        'mercury'
    );
    $minApprovers   = $policy ? max(1, (int) $policy['min_approvers'])    : 1;
    $coolOffMin     = $policy ? max(0, (int) $policy['cool_off_minutes']) : 0;
    $requiredRole   = $policy['required_approver_role'] ?? null;

    // Acks collected — JOIN to users for display.
    $ackStmt = $pdo->prepare(
        'SELECT a.id, a.user_id, a.note, a.created_at,
                u.name AS user_name, u.email AS user_email
           FROM payment_instruction_approvals a
      LEFT JOIN users u ON u.id = a.user_id
          WHERE a.tenant_id = :t AND a.instruction_id = :i
          ORDER BY a.created_at ASC, a.id ASC'
    );
    $ackStmt->execute(['t' => $tenantId, 'i' => $instructionId]);
    $acks = $ackStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $ackCount = count($acks);
    $remaining = max(0, $minApprovers - $ackCount);

    $coolOffSecs = 0;
    if (!empty($pi['cool_off_until'])) {
        $coolOffSecs = max(0, strtotime((string) $pi['cool_off_until']) - time());
    }

    // Viewer eligibility — used by the UI to enable/disable the
    // Approve button without round-tripping mpApprove.
    $canApprove = true; $canApproveReason = '';
    $viewerId   = $viewer !== null ? (int) ($viewer['id'] ?? 0) : 0;
    $viewerRole = (string) ($viewer['role'] ?? '');
    if ($viewerId === 0) {
        $canApprove = false; $canApproveReason = 'no-viewer';
    } elseif ((int) $pi['created_by_user_id'] === $viewerId) {
        $canApprove = false; $canApproveReason = 'creator-cannot-approve';
    } elseif ($requiredRole !== null && $requiredRole !== '' && $viewerRole !== $requiredRole) {
        $canApprove = false; $canApproveReason = 'role-mismatch:' . $requiredRole;
    } else {
        foreach ($acks as $a) {
            if ((int) $a['user_id'] === $viewerId) {
                $canApprove = false; $canApproveReason = 'already-acked';
                break;
            }
        }
        if ($canApprove && $remaining === 0 && (string) $pi['state'] !== 'PendingApproval') {
            $canApprove = false; $canApproveReason = 'state-' . $pi['state'];
        }
    }

    return [
        'instruction_id'             => (int) $pi['id'],
        'state'                      => (string) $pi['state'],
        'policy_id'                  => $policy['id']    ?? null,
        'policy_name'                => $policy['name']  ?? null,
        'required_approver_role'     => $requiredRole,
        'min_approvers'              => $minApprovers,
        'cool_off_minutes'           => $coolOffMin,
        'acks_collected'             => $ackCount,
        'acks_required'              => $minApprovers,
        'acks_remaining'             => $remaining,
        'acks'                       => array_map(static fn(array $a) => [
            'id'         => (int) $a['id'],
            'user_id'    => (int) $a['user_id'],
            'user_name'  => $a['user_name']  ?? null,
            'user_email' => $a['user_email'] ?? null,
            'note'       => $a['note'],
            'created_at' => $a['created_at'],
        ], $acks),
        'cool_off_until'             => $pi['cool_off_until'],
        'cool_off_seconds_remaining' => $coolOffSecs,
        'creator_user_id'            => (int) $pi['created_by_user_id'],
        'creator_name'               => $pi['creator_name'],
        'can_approve'                => $canApprove,
        'can_approve_reason'         => $canApproveReason,
    ];
}
