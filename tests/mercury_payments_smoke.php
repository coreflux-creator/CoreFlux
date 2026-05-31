<?php
/**
 * Mercury Slice 3 — Payment Engine + State Machine smoke.
 *
 * Coverage:
 *   - Migration 050: payment_instructions + payment_instruction_audit shape,
 *     idempotency UNIQUE, 10-state ENUM, both leg-tracking column families.
 *   - Adapter additions: mercuryCreatePayment / mercuryGetPaymentStatus
 *     (POST + GET URL shape, idempotencyKey required, payload validation).
 *   - State machine: mpTransitionAllowed matrix correctness (allowed +
 *     disallowed transitions).
 *   - Service contract: mpCreate validation, mpApprove SoD enforcement,
 *     mpAdvance dispatches per state, error branches.
 *   - Workflow orchestrator: mpOriginateFunding gates on default config,
 *     gates on external_account mapping, persists txn id+status.
 *     mpVerifyAndOriginatePayout: poll error transient, settled →
 *     originate payout, terminal failure branches. mpPollPayoutStatus:
 *     settled/failed/returned/pending branches.
 *   - API routes: 7 actions, RBAC split, SoD propagation, validation.
 *   - Worker contract.
 *   - UI JSX: all testids, state pill mapping, conditional CTAs, modals.
 *   - TreasuryModule wiring (new tab + route).
 *   - Functional state machine test (in-memory SQLite-like? — no, just
 *     unit-test the pure functions mpTransitionAllowed).
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

// ----------------------------------------------------------------- Migration
echo "Migration 050_mercury_payments.sql\n";
$migPath = __DIR__ . '/../core/migrations/050_mercury_payments.sql';
$a('migration file exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
$a('payment_instructions table',                 $c($mig, 'CREATE TABLE IF NOT EXISTS payment_instructions'));
$a('payment_instruction_audit table',            $c($mig, 'CREATE TABLE IF NOT EXISTS payment_instruction_audit'));
$a('10-state ENUM (Draft → Cancelled)',
    $c($mig, "ENUM('Draft','PendingApproval','Approved','Funding',")
    && $c($mig, "'Submitted','Settled','Reconciled','Failed',")
    && $c($mig, "'Returned','Cancelled')"));
$a('idempotency_key + UNIQUE per tenant',
    $c($mig, 'idempotency_key   VARCHAR(80) NOT NULL')
    && $c($mig, 'UNIQUE KEY uq_pi_idem'));
$a('funding leg columns present',
    $c($mig, 'funding_recipient_id') && $c($mig, 'funding_mercury_txn_id')
    && $c($mig, 'funding_mercury_status') && $c($mig, 'funding_initiated_at')
    && $c($mig, 'funding_settled_at') && $c($mig, 'funding_last_polled_at'));
$a('payout leg columns present',
    $c($mig, 'operating_mercury_account_id') && $c($mig, 'payout_mercury_txn_id')
    && $c($mig, 'payout_mercury_status') && $c($mig, 'payout_initiated_at')
    && $c($mig, 'payout_settled_at') && $c($mig, 'payout_last_polled_at'));
$a('amount_cents BIGINT (signed)',               $c($mig, 'amount_cents      BIGINT NOT NULL'));
$a('source_module column for AP integration',    $c($mig, 'source_module     VARCHAR(40)'));
$a('SoD-friendly approver columns',
    $c($mig, 'created_by_user_id') && $c($mig, 'approved_by_user_id'));
$a('state index for worker walks',               $c($mig, 'ix_pi_state'));
$a('audit table foreign-ish key index',          $c($mig, 'ix_pia_instruction'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',
    $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

// ----------------------------------------------------------------- Adapter
echo "\ncore/mercury_adapter.php — Slice 3 additions\n";
$adv = (string) file_get_contents(__DIR__ . '/../core/mercury_adapter.php');
$a('mercuryCreatePayment() exported',            $c($adv, 'function mercuryCreatePayment'));
$a('mercuryGetPaymentStatus() exported',         $c($adv, 'function mercuryGetPaymentStatus'));
$a('createPayment posts to /account/{id}/transactions',
    $c($adv, "/account/' . rawurlencode(\$accountId) . '/transactions"));
$a('createPayment requires idempotencyKey',
    $c($adv, "['recipientId', 'amount', 'paymentMethod', 'idempotencyKey']"));
$a('getPaymentStatus GETs transaction by id',
    $c($adv, "/transaction/' . rawurlencode(\$txnId)"));

// ----------------------------------------------------------------- Service
echo "\ncore/mercury_payments.php\n";
$svcPath = __DIR__ . '/../core/mercury_payments.php';
$a('service file exists', is_file($svcPath));
$svc = (string) file_get_contents($svcPath);
$a('requires mercury_adapter',                   $c($svc, "require_once __DIR__ . '/mercury_adapter.php'"));
$a('requires mercury_recipients',                $c($svc, "require_once __DIR__ . '/mercury_recipients.php'"));

// CRUD + actions
$a('mpCreate() validates recipient kind=vendor',
    $c($svc, "recipient must exist with kind=vendor"));
$a('mpCreate() rejects amount_cents <= 0',
    $c($svc, "'amount_cents must be > 0'"));
$a('mpCreate() generates idempotency_key when blank',
    $c($svc, "'pi_' . date('Ymd-His')"));
$a('mpSubmitForApproval transitions to PendingApproval',
    $c($svc, "mpTransition(\$tenantId, \$id, 'PendingApproval'"));
$a('mpApprove enforces SoD (creator ≠ approver)',
    $c($svc, 'Segregation of duties: creator cannot approve their own payment'));
$a('mpApprove enforces role-based SoD (treasury.payment.approve)',
    $c($svc, "rbac_legacy_can(\$approverUser, 'treasury.payment.approve')")
    && $c($svc, 'Role separation: approver must hold the treasury.payment.approve permission'));
$a('mpApprove accepts either user array OR int (backward-compat)',
    $c($svc, 'if (is_array($approver))'));
$a('mpApprove triggers CFO notification on success (best-effort)',
    $c($svc, 'mercuryNotifyCfoOfApproval($tenantId, $id, $approverUser)') &&
    $c($svc, '} catch (\Throwable $e) {'));
$a('mercuryNotifyCfoOfApproval() exported',      $c($svc, 'function mercuryNotifyCfoOfApproval'));
$a('CFO lookup by role=cfo',                     $c($svc, "ut.role = \"cfo\""));
$a('falls back to role=master_admin when no CFO tagged',
    $c($svc, "ut.role = \"master_admin\""));
$a('email subject includes [CFO notice] prefix',  $c($svc, '[CFO notice] Mercury payment approved'));
$a('email body warns to cancel if unexpected',   $c($svc, 'cancel the instruction before the worker funds it'));
$a('mailerSend present-check (no hard dep)',     $c($svc, "function_exists('mailerSend')"));
$a('htmlspecialchars on vendor + approver (XSS-safe email body)',
    $c($svc, 'htmlspecialchars($vendor)') && $c($svc, 'htmlspecialchars($approver)'));
$a('CFO notification audit row (mercury.payment.cfo_notified)',
    $c($svc, 'mercury.payment.cfo_notified'));
$a('audit captures sent/failed/mailer_present',
    $c($svc, "'sent'             => \$sent") &&
    $c($svc, "'failed'           => \$failed") &&
    $c($svc, "'mailer_present'   => function_exists('mailerSend')"));
$a('whole notification path is best-effort (never throws)',
    $c($svc, '// Whole function is best-effort'));
$a('mpCancel transitions to Cancelled',          $c($svc, "mpTransition(\$tenantId, \$id, 'Cancelled'"));
$a('mpRejectToDraft route present',              $c($svc, 'function mpRejectToDraft'));

// State machine matrix
$a('Draft → PendingApproval allowed',            $c($svc, "'Draft'           => ['PendingApproval', 'Cancelled']"));
$a('PendingApproval → Approved allowed',         $c($svc, "'PendingApproval' => ['Draft', 'Approved', 'Cancelled']"));
$a('Approved → Funding allowed',                 $c($svc, "'Approved'        => ['Funding', 'Failed', 'Cancelled']"));
$a('Funding → Submitted allowed',                $c($svc, "'Funding'         => ['Submitted', 'Failed', 'Returned']"));
$a('Submitted → Settled allowed',                $c($svc, "'Submitted'       => ['Settled', 'Failed', 'Returned']"));
$a('Settled → Reconciled deferred to Slice 4',
    $c($svc, "'Settled'         => ['Reconciled', 'Returned']")
    && $c($svc, 'Slice 4 owns Reconciled'));
$a('terminal states locked (Failed/Returned/Cancelled/Reconciled)',
    $c($svc, "'Reconciled'      => []")
    && $c($svc, "'Failed'          => []")
    && $c($svc, "'Returned'        => []")
    && $c($svc, "'Cancelled'       => []"));

// mpTransition
$a('mpTransition uses SELECT FOR UPDATE lock',
    $c($svc, 'SELECT state FROM payment_instructions WHERE tenant_id = :t AND id = :id FOR UPDATE'));
$a('mpTransition refuses illegal transitions',   $c($svc, 'Illegal transition'));
$a('mpTransition is idempotent on same-state',   $c($svc, '$from === $toState') && $c($svc, 'return false'));
$a('mpTransition writes audit row',              $c($svc, 'INSERT INTO payment_instruction_audit'));
$a('mpTransition emits mercury.payment.transition audit',
    $c($svc, 'mercury.payment.transition'));
$a('mpTransition patch column allowlist (anti-injection)',
    $c($svc, "preg_match('/^[a-z0-9_]+\$/'"));
$a('mpTransition wrapped in transaction with rollback',
    $c($svc, '$pdo->beginTransaction()') && $c($svc, '$pdo->rollBack()'));

// Workflow orchestrator
$a('mpAdvance() dispatcher exported',            $c($svc, 'function mpAdvance(int $tenantId, int $instructionId): string'));
$a('mpAdvance() requires active Mercury connection',
    $c($svc, "'no active Mercury connection'"));
$a('mpAdvance() routes Approved → originate funding',
    $c($svc, "return mpOriginateFunding(\$tenantId, \$row, \$apiToken, \$defaults);"));
$a('mpAdvance() routes Funding → verify + originate payout',
    $c($svc, "case 'Funding':  return mpVerifyAndOriginatePayout"));
$a('mpAdvance() routes Submitted → poll',
    $c($svc, "case 'Submitted': return mpPollPayoutStatus"));

// Funding originate
$a('mpOriginateFunding fails when default config missing',
    $c($svc, "no default_funding_recipient_id"));
$a('mpOriginateFunding looks up external_account mapping',
    $c($svc, "mercury_kind = \"external_account\""));
$a('mpOriginateFunding fails when no external_account mapping',
    $c($svc, 'has no external_account mapping'));
$a('mpOriginateFunding passes idempotencyKey :funding suffix',
    $c($svc, "':funding'"));
$a('mpOriginateFunding persists txn id + status on success',
    $c($svc, "'funding_mercury_txn_id'") && $c($svc, "'funding_mercury_status'"));
$a('mpOriginateFunding traps MercuryApiException → Failed',
    $c($svc, 'funding originate failed:'));

// Verify + originate payout
$a('mpVerifyAndOriginatePayout polls funding txn',
    $c($svc, 'mercuryGetPaymentStatus($apiToken'));
$a('treats settled/posted/sent as cleared',
    $c($svc, "in_array(\$status, ['settled', 'posted', 'sent'], true)"));
$a('terminal funding failure handled',
    $c($svc, "in_array(\$status, ['failed', 'cancelled'], true)") &&
    $c($svc, "\"funding transfer {\$status}\""));
$a('funding returned → Returned state',          $c($svc, "'funding transfer returned'"));
$a('vendor mapping required before payout',
    $c($svc, "has no Mercury counterparty mapping; click Push to Mercury first"));
$a('payout idempotency key :payout suffix',      $c($svc, "':payout'"));
$a('marks funding_settled_at when funding clears',
    $c($svc, 'funding_settled_at = NOW()'));
$a('transient adapter errors leave state in Funding',
    $c($svc, 'funding_last_polled_at = NOW()') && $c($svc, "return 'Funding';"));

// Payout poll
$a('payout terminal failed/cancelled → Failed',  $c($svc, "\"payout {\$status}\""));
$a('payout returned → Returned',                 $c($svc, "'payout returned'"));
$a('payout settled/posted → Settled',            $c($svc, "in_array(\$status, ['settled', 'posted'], true)"));
$a('payout pending leaves Submitted',            $c($svc, "return 'Submitted'; // still pending"));

// ----------------------------------------------------------------- API
echo "\napi/mercury_payments.php\n";
$apiPath = __DIR__ . '/../api/mercury_payments.php';
$a('API file exists', is_file($apiPath));
$apiF = (string) file_get_contents($apiPath);
$a('GET list/single via useApi',
    $c($apiF, "if (\$method === 'GET')"));
$a('GET single eager-loads audit trail',         $c($apiF, 'FROM payment_instruction_audit'));
$a('RBAC split: bank.view OR bank.manage for reads',
    $c($apiF, "rbac_legacy_can(\$user, 'accounting.bank.view')")
    && $c($apiF, "rbac_legacy_can(\$user, 'accounting.bank.manage')"));
$a('POST default creates payment',               $c($apiF, "mpCreate(\$tenantId, \$body"));
$a('POST ?action=submit',                        $c($apiF, "case 'submit':"));
$a('POST ?action=approve passes full $user (role check)',
    $c($apiF, "mpApprove(\$tenantId, \$id, \$user,"));
$a('POST ?action=reject requires reason',
    $c($apiF, "case 'reject':") && $c($apiF, "'reason required'"));
$a('POST ?action=cancel',                        $c($apiF, "case 'cancel':"));
$a('POST ?action=advance (manual worker trigger)', $c($apiF, "case 'advance':"));
$a('unknown action rejected',                    $c($apiF, 'Unknown action:'));

// ----------------------------------------------------------------- Worker
echo "\ncron/mercury_payment_worker.php\n";
$crPath = __DIR__ . '/../cron/mercury_payment_worker.php';
$a('worker exists', is_file($crPath));
$cr = (string) file_get_contents($crPath);
$a('worker filters actionable states',
    $c($cr, 'state IN ("Approved", "Funding", "Submitted")'));
$a('worker orders by state_changed_at ASC (oldest first)',
    $c($cr, 'ORDER BY tenant_id, state_changed_at ASC'));
$a('worker calls mpAdvance per row',             $c($cr, 'mpAdvance($tid, $id)'));
$a('worker caps per-tenant per-run',             $c($cr, '$MAX_PER_TENANT'));
$a('worker graceful skip when migration missing',
    $c($cr, 'migration 050 not applied yet'));
$a('worker exit reflects error count',           $c($cr, 'exit($errors > 0 ? 1 : 0)'));

// ----------------------------------------------------------------- UI
echo "\nUI — MercuryPayments.jsx\n";
$uiPath = __DIR__ . '/../modules/treasury/ui/MercuryPayments.jsx';
$a('UI exists', is_file($uiPath));
$ui = (string) file_get_contents($uiPath);
$a('panel testid',                               $c($ui, 'data-testid="mercury-payments"'));
$a('state color map covers all 10 states',
    $c($ui, 'Draft:') && $c($ui, 'PendingApproval:') && $c($ui, 'Approved:') &&
    $c($ui, 'Funding:') && $c($ui, 'Submitted:') && $c($ui, 'Settled:') &&
    $c($ui, 'Reconciled:') && $c($ui, 'Failed:') && $c($ui, 'Returned:') && $c($ui, 'Cancelled:'));
$a('reads list via useApi',                      $c($ui, "useApi('/api/mercury_payments.php')"));
$a('vendor recipient picker fetches kind=vendor',
    $c($ui, "useApi('/api/mercury_recipients.php?kind=vendor')"));
$a('create btn testid',                          $c($ui, 'data-testid="mercury-payment-create-btn"'));
$a('per-row state pill testid',
    $c($ui, 'data-testid={`mercury-payment-state-${p.id}`}'));
$a('Draft → submit CTA',                         $c($ui, 'mercury-payment-submit-'));
$a('PendingApproval → approve + reject CTAs',
    $c($ui, 'mercury-payment-approve-') && $c($ui, 'mercury-payment-reject-'));
$a('reject prompts for reason via window.prompt',
    $c($ui, 'window.prompt'));
$a('Approved/Funding/Submitted → Run worker CTA',
    $c($ui, 'mercury-payment-advance-'));
$a('cancellable states cancel CTA',
    $c($ui, "['Draft', 'PendingApproval', 'Approved'].includes(p.state)")
    && $c($ui, 'mercury-payment-cancel-'));
$a('per-row audit detail CTA',                   $c($ui, 'mercury-payment-detail-'));
$a('create modal testid',                        $c($ui, 'data-testid="mercury-payment-create-modal"'));
$a('detail modal testid',                        $c($ui, 'data-testid="mercury-payment-detail-modal"'));
$a('detail modal renders audit table',           $c($ui, 'data-testid="mercury-payment-audit-table"'));
$a('amount-to-cents conversion',                 $c($ui, 'Math.round(Number(form.amount) * 100)'));
$a('amount validation',                          $c($ui, 'Amount must be > 0.'));
$a('explains debit → verify → submit flow in header',
    $c($ui, 'debit external') && $c($ui, 'Verify clearance') && $c($ui, 'Settled'));

// ----------------------------------------------------------------- TreasuryModule wiring
echo "\nUI — TreasuryModule.jsx wiring\n";
$tm = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/TreasuryModule.jsx');
$a('imports MercuryPayments',                    $c($tm, "import MercuryPayments from './MercuryPayments'"));
$a('Mercury Payments tab in nav',                $c($tm, 'TreasuryTab to="mercury-payments"'));
$a('Mercury Payments route mounted',
    $c($tm, '<Route path="mercury-payments" element={<MercuryPayments />} />'));

// ----------------------------------------------------------------- Functional state-machine matrix
echo "\nFunctional — state machine matrix\n";
require_once __DIR__ . '/../core/mercury_adapter.php';
require_once $svcPath;

$a('Draft → PendingApproval allowed (live)',     mpTransitionAllowed('Draft', 'PendingApproval'));
$a('Draft → Cancelled allowed (live)',           mpTransitionAllowed('Draft', 'Cancelled'));
$a('Draft → Approved REFUSED (live)',            !mpTransitionAllowed('Draft', 'Approved'));
$a('PendingApproval → Approved allowed',         mpTransitionAllowed('PendingApproval', 'Approved'));
$a('PendingApproval → Funding REFUSED',          !mpTransitionAllowed('PendingApproval', 'Funding'));
$a('Approved → Funding allowed',                 mpTransitionAllowed('Approved', 'Funding'));
$a('Approved → Submitted REFUSED (must go through Funding)',
    !mpTransitionAllowed('Approved', 'Submitted'));
$a('Funding → Submitted allowed',                mpTransitionAllowed('Funding', 'Submitted'));
$a('Funding → Failed allowed',                   mpTransitionAllowed('Funding', 'Failed'));
$a('Funding → Settled REFUSED (must go through Submitted)',
    !mpTransitionAllowed('Funding', 'Settled'));
$a('Submitted → Settled allowed',                mpTransitionAllowed('Submitted', 'Settled'));
$a('Submitted → Cancelled REFUSED (too late)',
    !mpTransitionAllowed('Submitted', 'Cancelled'));
$a('Settled → Reconciled allowed (Slice 4)',     mpTransitionAllowed('Settled', 'Reconciled'));
$a('Settled → Failed REFUSED',                   !mpTransitionAllowed('Settled', 'Failed'));
$a('Failed terminal',                            !mpTransitionAllowed('Failed', 'Draft'));
$a('Cancelled terminal',                         !mpTransitionAllowed('Cancelled', 'Approved'));
$a('Reconciled terminal',                        !mpTransitionAllowed('Reconciled', 'Failed'));

// ----------------------------------------------------------------- Adapter functional via stub
echo "\nFunctional — createPayment + getPaymentStatus via stub\n";
$captured = [];
$GLOBALS['__mercury_transport'] = function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body');
    if ($method === 'POST' && strpos($url, '/transactions') !== false) {
        return ['status' => 200, 'body' => json_encode([
            'id' => 'tx_fund_001', 'status' => 'pending',
        ])];
    }
    if ($method === 'GET' && strpos($url, '/transaction/tx_fund_001') !== false) {
        return ['status' => 200, 'body' => json_encode([
            'id' => 'tx_fund_001', 'status' => 'settled',
        ])];
    }
    return ['status' => 404, 'body' => '{"error":"unknown stub"}'];
};

$payResp = mercuryCreatePayment('secret-token:abc', 'acc_op_1', [
    'recipientId'    => 'ext_acct_funding',
    'amount'         => '100.00',
    'paymentMethod'  => 'ach',
    'idempotencyKey' => 'pi:test:funding',
]);
$a('createPayment returned txn id',              ($payResp['id'] ?? '') === 'tx_fund_001');
$a('createPayment POSTed to /account/.../transactions',
    !empty($captured) && (string) $captured[0]['method'] === 'POST'
    && strpos((string) $captured[0]['url'], '/account/acc_op_1/transactions') !== false);
$a('createPayment body carries idempotencyKey',
    !empty($captured) && strpos((string) $captured[0]['body'], '"idempotencyKey":"pi:test:funding"') !== false);

$statusResp = mercuryGetPaymentStatus('secret-token:abc', 'acc_op_1', 'tx_fund_001');
$a('getPaymentStatus returned status=settled',
    ($statusResp['status'] ?? '') === 'settled');
$a('getPaymentStatus GETd /transaction/{id}',
    strpos((string) end($captured)['url'], '/account/acc_op_1/transaction/tx_fund_001') !== false);

// Validation paths
$threw = false;
try { mercuryCreatePayment('tok', 'a', ['recipientId' => 'r', 'amount' => '1', 'paymentMethod' => 'ach']); }
catch (MercuryApiException $e) { $threw = $e; }
$a('createPayment rejects missing idempotencyKey', $threw instanceof MercuryApiException && strpos($threw->getMessage(), 'idempotencyKey') !== false);

$threw2 = false;
try { mercuryCreatePayment('tok', '', ['recipientId' => 'r', 'amount' => '1', 'paymentMethod' => 'ach', 'idempotencyKey' => 'k']); }
catch (MercuryApiException $e) { $threw2 = true; }
$a('createPayment rejects empty accountId', $threw2);

$threw3 = false;
try { mercuryGetPaymentStatus('tok', '', ''); } catch (MercuryApiException $e) { $threw3 = true; }
$a('getPaymentStatus rejects empty accountId/txnId', $threw3);

unset($GLOBALS['__mercury_transport']);

// ----------------------------------------------------------------- Syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/mercury_payments.php',
    'core/mercury_adapter.php',
    'api/mercury_payments.php',
    'cron/mercury_payment_worker.php',
] as $rel) {
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg(__DIR__ . '/../' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0);
}

echo "\n=========================================\n";
echo "Mercury Slice 3 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
