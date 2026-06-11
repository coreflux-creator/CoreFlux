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
 *        Approve the current user's pending step. If it was the last step,
 *        flip bill.status='approved'. Otherwise advance step_no.
 *   POST /api/ap/bill_approvals?action=reject      body { bill_id, note? }
 *        Reject. Sets bill.status='disputed' so AP can address.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

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
    $exists = $pdo->prepare('SELECT 1 FROM ap_bill_approvals WHERE tenant_id = :t AND bill_id = :b LIMIT 1');
    $exists->execute(['t' => $tenantId, 'b' => $billId]);
    if ($exists->fetchColumn()) {
        api_error('Bill already has an approval workflow in progress', 409);
    }
    $amt = (float) $bill['total'];

    // Pick default active workflow.
    $wf = $pdo->prepare(
        "SELECT id FROM ap_approval_workflows
          WHERE tenant_id = :t AND is_active = 1
          ORDER BY is_default DESC, id ASC LIMIT 1"
    );
    $wf->execute(['t' => $tenantId]);
    $wfId = (int) $wf->fetchColumn();
    if ($wfId === 0) api_error('No active approval workflow configured', 422);

    // Find rules matching the amount, ordered by step_no.
    $rules = $pdo->prepare(
        "SELECT step_no, approver_user_id, min_amount, max_amount
           FROM ap_approval_workflow_rules
          WHERE tenant_id   = :t
            AND workflow_id = :w
            AND :a1 >= min_amount
            AND (max_amount IS NULL OR :a2 < max_amount)
          ORDER BY step_no ASC"
    );
    $rules->execute(['t' => $tenantId, 'w' => $wfId, 'a1' => $amt, 'a2' => $amt]);
    $rules = $rules->fetchAll(PDO::FETCH_ASSOC);
    if (!$rules) api_error('No rule in workflow brackets the bill amount', 422);

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO ap_bill_approvals
                (tenant_id, bill_id, workflow_id, step_no, approver_user_id, state)
             VALUES (:t, :b, :w, :s, :u, "pending")'
        );
        foreach ($rules as $r) {
            $ins->execute([
                't' => $tenantId, 'b' => $billId, 'w' => $wfId,
                's' => (int) $r['step_no'], 'u' => (int) $r['approver_user_id'],
            ]);
        }
        $pdo->prepare(
            "UPDATE ap_bills SET status = 'pending_approval'
              WHERE tenant_id = :t AND id = :id"
        )->execute(['t' => $tenantId, 'id' => $billId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        api_error('Could not submit for approval: ' . $e->getMessage(), 500);
    }

    // Notify the first-step approver(s) — best-effort.
    try {
        $firstStep = (int) $rules[0]['step_no'];
        $firstApprovers = array_filter(array_map(
            fn($r) => (int) $r['step_no'] === $firstStep ? (int) $r['approver_user_id'] : 0,
            $rules
        ));
        if ($firstApprovers) {
            $place = implode(',', array_fill(0, count($firstApprovers), '?'));
            $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id IN ({$place})");
            $stmt->execute(array_values($firstApprovers));
            $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            apBillApprovalNotify($pdo, $tenantId, $billId, $bill, $approvers);
        }
    } catch (\Throwable $_) { /* swallow — submit succeeded; notifications are best-effort */ }

    apAudit('ap.bill.approval_submitted', ['bill_id' => $billId, 'workflow_id' => $wfId, 'steps' => count($rules)], $billId);
    api_ok(['ok' => true, 'workflow_id' => $wfId, 'steps' => count($rules)]);
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
$workflowDecisionApplied = false;
try {
    $workflowDecisionApplied = apMirrorToWorkflow($tenantId, $billId, $userId, $action, $note, true);
} catch (\Throwable $e) {
    apAudit('ap.bill.approval_blocked', [
        'bill_id' => $billId,
        'step' => (int) $step['step_no'],
        'action' => $action,
        'control' => 'workflow_engine',
        'reason' => $e->getMessage(),
    ], $billId);
    $status = stripos($e->getMessage(), 'not an approver') !== false ? 403 : 409;
    api_error('Workflow control blocked decision: ' . $e->getMessage(), $status);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE ap_bill_approvals
            SET state = :ns, decision_at = NOW(), decision_note = :n
          WHERE tenant_id = :t AND id = :id"
    )->execute(['ns' => $newState, 'n' => $note ?: null, 't' => $tenantId, 'id' => (int) $step['id']]);

    if ($newState === 'rejected') {
        $pdo->prepare("UPDATE ap_bills SET status = 'disputed' WHERE tenant_id = :t AND id = :id")
            ->execute(['t' => $tenantId, 'id' => $billId]);
    } else {
        // === P1.7 — Multi-level approval chain advancement ===========
        // The router previously only materialised step 1 of the chain.
        // When step 1 approvers acted, step 2's rows didn't exist, so
        // the chain silently terminated as if step 1 were the only step.
        // Spec re-audit: "Multi-level approval chain must actually
        // fire." We now read the stored chain_json on each step
        // completion and INSERT the next step's pending rows.
        $stepNo = (int) $step['step_no'];

        // Has THIS step now reached unanimous (every approver acted)?
        $stepPending = $pdo->prepare(
            "SELECT COUNT(*) FROM ap_bill_approvals
              WHERE tenant_id = :t AND bill_id = :b AND step_no = :s AND state = 'pending'"
        );
        $stepPending->execute(['t' => $tenantId, 'b' => $billId, 's' => $stepNo]);
        $stepDone = (int) $stepPending->fetchColumn() === 0;

        if ($stepDone) {
            // Fetch the policy evaluation snapshot — chain_json is the
            // authoritative list of steps + per-step approver ids the
            // router stored at submit time.
            $ev = $pdo->prepare(
                "SELECT chain_json FROM ap_approval_policy_evaluations
                  WHERE tenant_id = :t AND bill_id = :b
                  ORDER BY id DESC LIMIT 1"
            );
            $ev->execute(['t' => $tenantId, 'b' => $billId]);
            $chainJson = (string) ($ev->fetchColumn() ?: '[]');
            $chain     = json_decode($chainJson, true) ?: [];

            // Do we already have a step (stepNo+1) row, or is the
            // chain exhausted? Materialise if neither.
            $nextNo = $stepNo + 1;
            if (isset($chain[$stepNo])) {  // 0-indexed chain[1] === step 2
                $existsNext = $pdo->prepare(
                    "SELECT COUNT(*) FROM ap_bill_approvals
                      WHERE tenant_id = :t AND bill_id = :b AND step_no = :s LIMIT 1"
                );
                $existsNext->execute(['t' => $tenantId, 'b' => $billId, 's' => $nextNo]);
                if ((int) $existsNext->fetchColumn() === 0) {
                    $nextStepDef = $chain[$stepNo];
                    $approverIds = apCurrentWorkflowApproverUserIds($tenantId, $billId)
                        ?: (array) ($nextStepDef['approver_user_ids'] ?? []);
                    $insertNext  = $pdo->prepare(
                        "INSERT INTO ap_bill_approvals
                            (tenant_id, bill_id, approver_user_id, step_no, state, created_at)
                         VALUES (:t, :b, :u, :s, 'pending', NOW())"
                    );
                    foreach ($approverIds as $uid) {
                        try {
                            $insertNext->execute([
                                't' => $tenantId, 'b' => $billId,
                                'u' => (int) $uid, 's' => $nextNo,
                            ]);
                        } catch (\Throwable $_) { /* duplicate / schema drift — non-fatal */ }
                    }
                }
            }
        }
        // ==============================================================

        $pending = $pdo->prepare(
            "SELECT COUNT(*) FROM ap_bill_approvals
              WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'"
        );
        $pending->execute(['t' => $tenantId, 'b' => $billId]);
        if ((int) $pending->fetchColumn() === 0) {
            $pdo->prepare(
                "UPDATE ap_bills SET status = 'approved', approved_at = NOW(), approved_by_user_id = :u
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['u' => $userId, 't' => $tenantId, 'id' => $billId]);
        }
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    api_error('Could not record decision: ' . $e->getMessage(), 500);
}

// On approval-not-final, notify the next step's approvers — best-effort.
if ($newState === 'approved') {
    try {
        $nextStep = $pdo->prepare(
            "SELECT step_no FROM ap_bill_approvals
              WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'
              ORDER BY step_no ASC LIMIT 1"
        );
        $nextStep->execute(['t' => $tenantId, 'b' => $billId]);
        $sn = (int) ($nextStep->fetchColumn() ?: 0);
        if ($sn > 0) {
            $next = $pdo->prepare(
                "SELECT u.id, u.name, u.email FROM ap_bill_approvals a
                   JOIN users u ON u.id = a.approver_user_id
                  WHERE a.tenant_id = :t AND a.bill_id = :b AND a.step_no = :s AND a.state = 'pending'"
            );
            $next->execute(['t' => $tenantId, 'b' => $billId, 's' => $sn]);
            $approvers = $next->fetchAll(PDO::FETCH_ASSOC);
            apBillApprovalNotify($pdo, $tenantId, $billId, null, $approvers);
        }
    } catch (\Throwable $_) { /* best-effort */ }
}

apAudit("ap.bill.approval_{$newState}", ['bill_id' => $billId, 'step' => (int) $step['step_no']], $billId);

// Reverse sync — push the same decision onto the matching workflow_instance
// so the cross-module Inbox + mobile push surfaces stay consistent.
if (!$workflowDecisionApplied) {
    apMirrorToWorkflow($tenantId, $billId, $userId, $action, $note);
}

api_ok(['ok' => true, 'state' => $newState]);

/**
 * Reverse sync (Sprint 6d) — when an approver acts via the legacy AP UI,
 * mirror the same action onto the matching `workflow_instances` row so
 * the bill drops out of the cross-module Workflow Inbox + the mobile
 * app shows the new state. Idempotent: looks up by subject_type/id and
 * silently no-ops if no instance exists (e.g. legacy bills routed
 * before the cutover) or the instance is already terminal.
 *
 * Best-effort by default. When used as a preflight gate, failures are
 * rethrown so WorkflowEngine approver + SoD controls block the AP write.
 */
function apMirrorToWorkflow(int $tenantId, int $billId, ?int $userId, string $action, ?string $note, bool $throwOnFailure = false): bool {
    try {
        require_once __DIR__ . '/../../../core/workflow_engine.php';
        $pdo = getDB();
        if (!$pdo) return false;
        $stmt = $pdo->prepare(
            "SELECT id, status FROM workflow_instances
              WHERE tenant_id = :t AND subject_type = 'ap_bill' AND subject_id = :s
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 's' => $billId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        if ($row['status'] !== 'pending') return false;
        // workflowAct is the common control point: current-step approver
        // assertion, SoD, audit, advancement, and subject sync all live there.
        workflowAct(
            $tenantId,
            (int) $row['id'],
            $userId,
            $action,             // 'approve' or 'reject'
            $note ?: null,
            'app',               // via
            null,                // delegated_to
            null                 // actor_email
        );
        return true;
    } catch (\Throwable $e) {
        if ($throwOnFailure) throw $e;
        // Truly best-effort.
        return false;
    }
}

// ─── helpers ───
function apCurrentWorkflowApproverUserIds(int $tenantId, int $billId): array {
    try {
        require_once __DIR__ . '/../../../core/workflow_engine.php';
        $pdo = getDB();
        if (!$pdo || !function_exists('workflowResolveCurrentStepApprovers')) return [];
        $stmt = $pdo->prepare(
            "SELECT id FROM workflow_instances
              WHERE tenant_id = :t AND subject_type = 'ap_bill' AND subject_id = :s AND status = 'pending'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 's' => $billId]);
        $instanceId = (int) ($stmt->fetchColumn() ?: 0);
        if ($instanceId <= 0) return [];
        return array_values(array_unique(array_filter(array_map(
            'intval',
            workflowResolveCurrentStepApprovers($tenantId, $instanceId)
        ))));
    } catch (\Throwable $_) {
        return [];
    }
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
