<?php
/**
 * AP — Bill Approvals API.
 *
 *   GET  /api/ap/bill_approvals?inbox=1            — current user's pending steps
 *   GET  /api/ap/bill_approvals?bill_id=N          — full approval chain for a bill
 *   POST /api/ap/bill_approvals?action=submit      body { bill_id }
 *        Resolve the matching workflow rules for the bill's amount, create
 *        the per-step ap_bill_approvals rows, set bill.status=pending_approval.
 *   POST /api/ap/bill_approvals?action=approve     body { bill_id, note? }
 *        Approve the current user's pending step through WorkflowEngine.
 *   POST /api/ap/bill_approvals?action=reject      body { bill_id, note? }
 *        Reject through WorkflowEngine; subject sync moves the bill to dispute.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';
require_once __DIR__ . '/../lib/workflow_bridge.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$pdo      = getDB();

$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    rbac_legacy_require($user, 'ap.view');
    if (!empty($_GET['count_pending'])) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM ap_bill_approvals
              WHERE tenant_id = :t AND approver_user_id = :u AND state = 'pending'"
        );
        $stmt->execute(['t' => $tenantId, 'u' => $userId]);
        api_ok(['count' => (int) $stmt->fetchColumn()]);
    }
    if (!empty($_GET['comments_for_bill'])) {
        $billIdQ = (int) $_GET['comments_for_bill'];
        $stmt = $pdo->prepare(
            "SELECT c.id, c.bill_id, c.user_id, c.body, c.created_at,
                    u.name AS user_name, u.email AS user_email
               FROM ap_bill_approval_comments c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.tenant_id = :t AND c.bill_id = :b
              ORDER BY c.created_at ASC LIMIT 500"
        );
        $stmt->execute(['t' => $tenantId, 'b' => $billIdQ]);
        api_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if (!empty($_GET['inbox'])) {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.bill_id, a.step_no, a.created_at,
                    b.vendor_name, b.bill_number, b.total AS amount_total, b.due_date,
                    b.status AS bill_status,
                    (SELECT COUNT(*) FROM ap_bill_approvals a2
                       WHERE a2.tenant_id = a.tenant_id AND a2.bill_id = a.bill_id) AS total_steps
               FROM ap_bill_approvals a
               JOIN ap_bills b ON b.id = a.bill_id AND b.tenant_id = a.tenant_id
              WHERE a.tenant_id        = :t
                AND a.approver_user_id = :u
                AND a.state            = 'pending'
              ORDER BY a.created_at ASC LIMIT 200"
        );
        $stmt->execute(['t' => $tenantId, 'u' => $userId]);
        api_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    $billId = (int) ($_GET['bill_id'] ?? 0);
    if ($billId <= 0) api_error('bill_id required', 422);
    $stmt = $pdo->prepare(
        "SELECT a.*, u.name AS approver_name, u.email AS approver_email
           FROM ap_bill_approvals a
           LEFT JOIN users u ON u.id = a.approver_user_id
          WHERE a.tenant_id = :t AND a.bill_id = :b
          ORDER BY a.step_no ASC"
    );
    $stmt->execute(['t' => $tenantId, 'b' => $billId]);
    api_ok(['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method !== 'POST') api_error('Method not allowed', 405);
$body = api_json_body();
$billId = (int) ($body['bill_id'] ?? 0);
if ($billId <= 0) api_error('bill_id required', 422);

$bill = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1');
$bill->execute(['t' => $tenantId, 'id' => $billId]);
$bill = $bill->fetch(PDO::FETCH_ASSOC);
if (!$bill) api_error('Bill not found', 404);

if ($action === 'comment') {
    rbac_legacy_require($user, 'ap.view');
    $body2 = $body['body'] ?? '';
    $body2 = trim((string) $body2);
    if ($body2 === '') api_error('body required', 422);
    $pdo->prepare(
        'INSERT INTO ap_bill_approval_comments (tenant_id, bill_id, user_id, body, created_at)
         VALUES (:t, :b, :u, :body, NOW())'
    )->execute(['t' => $tenantId, 'b' => $billId, 'u' => $userId, 'body' => $body2]);
    apAudit('ap.bill.approval_comment_added', ['bill_id' => $billId], $billId);
    api_ok(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
}

if ($action === 'submit') {
    rbac_legacy_require($user, 'ap.bill.create');
    try {
        $routing = apWorkflowSubmitBillForApproval($tenantId, $bill, $userId, 'bill_approvals_submit');
    } catch (\Throwable $e) {
        api_error('Could not submit for approval: ' . $e->getMessage(), apWorkflowDecisionHttpStatus($e));
    }

    // Notify the current-step approver(s) by email; router already sent push.
    try {
        $approvers = apBillApprovalCurrentStepApprovers($pdo, $tenantId, $billId);
        if ($approvers) {
            apBillApprovalNotify($pdo, $tenantId, $billId, $bill, $approvers);
        }
    } catch (\Throwable $_) { /* swallow — submit succeeded; notifications are best-effort */ }

    api_ok([
        'ok' => true,
        'workflow_instance_id' => $routing['workflow_instance_id'] ?? null,
        'policy_id' => $routing['policy_id'] ?? null,
        'steps' => count($routing['approval_ids'] ?? []),
    ]);
}

if ($action !== 'approve' && $action !== 'reject') {
    api_error("action must be 'submit' | 'approve' | 'reject'", 422);
}

rbac_legacy_require($user, 'ap.bill.approve');

// Locate THIS user's pending step on this bill.
$step = $pdo->prepare(
    "SELECT * FROM ap_bill_approvals
      WHERE tenant_id = :t AND bill_id = :b AND approver_user_id = :u AND state = 'pending'
      ORDER BY step_no ASC LIMIT 1"
);
$step->execute(['t' => $tenantId, 'b' => $billId, 'u' => $userId]);
$step = $step->fetch(PDO::FETCH_ASSOC);
if (!$step) api_error('No pending approval step for current user on this bill', 404);

// Make sure no earlier step is still pending.
$earlier = $pdo->prepare(
    "SELECT COUNT(*) FROM ap_bill_approvals
      WHERE tenant_id = :t AND bill_id = :b AND step_no < :s AND state = 'pending'"
);
$earlier->execute(['t' => $tenantId, 'b' => $billId, 's' => (int) $step['step_no']]);
if ((int) $earlier->fetchColumn() > 0) {
    api_error('A prior step is still pending; cannot act on this step yet', 409);
}

$note = trim((string) ($body['note'] ?? ''));
$newState = $action === 'approve' ? 'approved' : 'rejected';
try {
    $workflow = apWorkflowActBillApproval($tenantId, $bill, $userId, $action, $note, true);
} catch (\Throwable $e) {
    apAudit('ap.bill.approval_blocked', [
        'bill_id' => $billId,
        'step' => (int) $step['step_no'],
        'action' => $action,
        'control' => 'workflow_engine',
        'via' => 'bill_approvals_api',
        'reason' => $e->getMessage(),
    ], $billId);
    api_error('Workflow control blocked decision: ' . $e->getMessage(), apWorkflowDecisionHttpStatus($e));
}

// WorkflowEngine subject sync owns legacy rows, bill status, chain advancement,
// final approval audit, and accounting draft enqueue.
if ($newState === 'approved') {
    try {
        $approvers = apBillApprovalCurrentStepApprovers($pdo, $tenantId, $billId);
        if ($approvers) {
            apBillApprovalNotify($pdo, $tenantId, $billId, null, $approvers);
        }
    } catch (\Throwable $_) { /* best-effort */ }
}

apAudit("ap.bill.approval_{$newState}", [
    'bill_id' => $billId,
    'step' => (int) $step['step_no'],
    'source' => 'workflow',
    'workflow_instance_id' => $workflow['workflow_instance_id'] ?? null,
    'workflow_status' => $workflow['workflow_status'] ?? null,
], $billId);

api_ok([
    'ok' => true,
    'state' => $newState,
    'workflow_instance_id' => $workflow['workflow_instance_id'] ?? null,
    'workflow_status' => $workflow['workflow_status'] ?? null,
]);

function apBillApprovalCurrentStepApprovers(\PDO $pdo, int $tenantId, int $billId): array
{
    $step = $pdo->prepare(
        "SELECT step_no FROM ap_bill_approvals
          WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'
          ORDER BY step_no ASC LIMIT 1"
    );
    $step->execute(['t' => $tenantId, 'b' => $billId]);
    $stepNo = (int) ($step->fetchColumn() ?: 0);
    if ($stepNo <= 0) return [];

    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email
           FROM ap_bill_approvals a
           JOIN users u ON u.id = a.approver_user_id
          WHERE a.tenant_id = :t
            AND a.bill_id = :b
            AND a.step_no = :s
            AND a.state = 'pending'"
    );
    $stmt->execute(['t' => $tenantId, 'b' => $billId, 's' => $stepNo]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function apBillApprovalNotify(\PDO $pdo, int $tenantId, int $billId, ?array $bill, array $approvers): void
{
    if (!$approvers) return;
    if ($bill === null) {
        $b = $pdo->prepare('SELECT bill_number, vendor_name, total, due_date, payment_terms FROM ap_bills WHERE tenant_id = :t AND id = :id');
        $b->execute(['t' => $tenantId, 'id' => $billId]);
        $bill = $b->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    require_once __DIR__ . '/../../../core/email_approval.php';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $threadUrl = "{$proto}://{$host}/#/modules/ap/bills/{$billId}";

    $logIns = $pdo->prepare(
        'INSERT INTO ap_bill_approval_notifications
            (tenant_id, bill_id, approval_id, approver_user_id, sent_to_email, status, error_text)
         VALUES (:t, :b, :a, :u, :em, :st, :err)'
    );
    foreach ($approvers as $a) {
        $email = (string) ($a['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $sent = false; $err = null;
        try {
            $tokens = apEmailApprovalMint($tenantId, $billId, (int) ($a['id'] ?? 0), $email);
            $html = apEmailApprovalBodyHtml($bill, (string) ($a['name'] ?? ''),
                $tokens['approve_url'], $tokens['reject_url'], $threadUrl);
            if (function_exists('mailerSend')) {
                mailerSend([
                    'to' => $email,
                    'subject' => 'Bill awaiting your approval — ' . ($bill['bill_number'] ?? '') . ' / ' . ($bill['vendor_name'] ?? ''),
                    'body_html' => $html,
                    'module'    => 'ap',
                    'purpose'   => 'ap',
                    'tenant_id' => $tenantId,
                ]);
                $sent = true;
            } else {
                $err = 'mailer not configured';
            }
        } catch (\Throwable $e) { $err = $e->getMessage(); }
        $logIns->execute([
            't'  => $tenantId, 'b' => $billId,
            'a'  => 0, // approval row id is per-step; we just stamp a 0 here for header-level submit nudge
            'u'  => (int) ($a['id'] ?? 0),
            'em' => $email,
            'st' => $sent ? 'sent' : 'failed',
            'err'=> $err ? substr($err, 0, 500) : null,
        ]);
    }
}
