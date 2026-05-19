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
        'Failed'          => [],
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
    $pdo->beginTransaction();
    try {
        $cur->execute(['t' => $tenantId, 'id' => $instructionId]);
        $from = $cur->fetchColumn();
        if ($from === false) {
            $pdo->rollBack();
            throw new \RuntimeException('payment_instruction not found');
        }
        if ($from === $toState) {
            $pdo->rollBack();
            return false;
        }
        if (!mpTransitionAllowed((string) $from, $toState)) {
            $pdo->rollBack();
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

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    if (!$rec || $rec['kind'] !== 'vendor') {
        throw new \InvalidArgumentException('recipient must exist with kind=vendor');
    }
    if ($rec['status'] !== 'active') {
        throw new \InvalidArgumentException('recipient is not active');
    }
    $amount = (int) $data['amount_cents'];
    if ($amount <= 0) throw new \InvalidArgumentException('amount_cents must be > 0');

    $idem = (string) ($data['idempotency_key'] ?? '');
    if ($idem === '') {
        $idem = 'pi_' . date('Ymd-His') . '_' . substr(bin2hex(random_bytes(6)), 0, 10);
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO payment_instructions
            (tenant_id, idempotency_key, state, source_module, source_ref,
             recipient_id, amount_cents, currency, description, notes, created_by_user_id)
         VALUES (:t, :ik, "Draft", :sm, :sr, :r, :a, :cur, :d, :n, :u)'
    )->execute([
        't'   => $tenantId,
        'ik'  => $idem,
        'sm'  => (string) ($data['source_module'] ?? 'manual'),
        'sr'  => $data['source_ref'] ?? null,
        'r'   => (int) $data['recipient_id'],
        'a'   => $amount,
        'cur' => (string) ($data['currency'] ?? 'USD'),
        'd'   => $data['description'] ?? null,
        'n'   => $data['notes'] ?? null,
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
        $sql = 'SELECT pi.*, r.name AS recipient_name
                  FROM payment_instructions pi
                  LEFT JOIN mercury_recipients r ON r.id = pi.recipient_id AND r.tenant_id = pi.tenant_id
                 WHERE pi.tenant_id = :t';
        $params = ['t' => $tenantId];
        if (!empty($opts['state'])) { $sql .= ' AND pi.state = :st'; $params['st'] = $opts['state']; }
        $sql .= ' ORDER BY pi.created_at DESC, pi.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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
function mpApprove(int $tenantId, int $id, $approver, ?string $note = null): bool
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
    if ($approverUser !== null && !RBAC::hasPermission($approverUser, 'treasury.payment.approve')) {
        throw new \RuntimeException(
            'Role separation: approver must hold the treasury.payment.approve permission'
        );
    }

    $row = mpGet($tenantId, $id);
    if ((int) ($row['created_by_user_id'] ?? 0) === (int) ($approverId ?? -1)) {
        throw new \RuntimeException('Segregation of duties: creator cannot approve their own payment');
    }
    $ok = mpTransition($tenantId, $id, 'Approved', $note ?: 'approver_ok', $approverId, [
        'approved_by_user_id' => $approverId,
        'approved_at'         => date('Y-m-d H:i:s'),
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
        case 'Approved': return mpOriginateFunding($tenantId, $row, $apiToken, $defaults);
        case 'Funding':  return mpVerifyAndOriginatePayout($tenantId, $row, $apiToken, $defaults);
        case 'Submitted': return mpPollPayoutStatus($tenantId, $row, $apiToken);
        default: return (string) $row['state'];
    }
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
            null, [], ['stage' => 'originate_funding', 'http_status' => $e->httpStatus]);
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
    ], ['mercury_txn_id' => $txnId, 'status' => $status]);
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
            null, [], ['stage' => 'originate_payout', 'http_status' => $e->httpStatus]);
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
    ], ['mercury_txn_id' => $txnId, 'status' => $status]);
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
