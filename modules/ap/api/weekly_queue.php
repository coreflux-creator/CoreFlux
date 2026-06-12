<?php
/**
 * AP API — Weekly Queue.
 *
 *   GET  /api/ap/weekly_queue.php[?lookahead=7]
 *        → { rows, summary, bucketed:{past_due, due_soon} }
 *
 *   POST /api/ap/weekly_queue.php?action=finalize
 *        body: { bill_ids: [N, N, ...] }
 *        → for each bill in 'pending_review' status, run the same logic as
 *          POST /api/ap/bill_approvals?action=submit (resolve workflow,
 *          create per-step rows, transition bill to pending_approval,
 *          send approver one-tap-approve email). Bills that are not in
 *          pending_review are skipped with a reason.
 *
 *   POST /api/ap/weekly_queue.php?action=send_approver_email
 *        body: { bill_id }
 *        → re-mints approver email tokens and re-sends the notification
 *          (useful when a token expired or the approver lost the email).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/email_approval.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/ap.php';
require_once __DIR__ . '/../lib/weekly_queue.php';
require_once __DIR__ . '/../lib/workflow_bridge.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    rbac_legacy_require($user, 'ap.bill.view');
    $look = max(1, min(30, (int) ($_GET['lookahead'] ?? 7)));
    $rows = apWeeklyQueueList($tid, $look);
    $bucketed = apWeeklyQueueBucket($rows);
    $summary  = apWeeklyQueueSummary($rows);
    api_ok([
        'rows'     => $rows,
        'bucketed' => $bucketed,
        'summary'  => $summary,
        'lookahead_days' => $look,
    ]);
}

if ($method === 'POST' && $action === 'finalize') {
    rbac_legacy_require($user, 'ap.bill.create');
    $body = api_json_body();
    $ids  = array_values(array_filter(array_map('intval', (array) ($body['bill_ids'] ?? []))));
    if (empty($ids)) api_error('bill_ids required', 422);

    $results = [];
    foreach ($ids as $billId) {
        try {
            $r = ap_weekly_queue_finalize_one($tid, $billId, $user);
            $results[] = $r + ['bill_id' => $billId];
        } catch (\Throwable $e) {
            $results[] = ['bill_id' => $billId, 'ok' => false, 'reason' => $e->getMessage()];
        }
    }
    api_ok(['results' => $results, 'submitted_count' => count(array_filter($results, fn ($r) => $r['ok'] ?? false))]);
}

if ($method === 'POST' && $action === 'send_approver_email') {
    rbac_legacy_require($user, 'ap.bill.create');
    $body = api_json_body();
    $billId = (int) ($body['bill_id'] ?? 0);
    if ($billId <= 0) api_error('bill_id required', 422);
    try {
        $sent = ap_weekly_queue_notify_current_step($tid, $billId);
    } catch (\Throwable $e) {
        api_error('Could not send: ' . $e->getMessage(), 422);
    }
    api_ok(['sent' => $sent]);
}

api_error('Method/action not allowed', 405);

// ──────────────────────────────────────────────────────────────────────
// Helpers

/**
 * Finalize one bill: resolve workflow rules, create per-step approval rows,
 * transition the bill to `pending_approval`, send approver email with
 * one-tap approve tokens. Returns {ok, reason?, steps?}.
 */
function ap_weekly_queue_finalize_one(int $tenantId, int $billId, array $actor): array {
    $pdo = getDB();
    $bill = scopedFind('SELECT * FROM ap_bills WHERE tenant_id = :tenant_id AND id = :id', ['id' => $billId]);
    if (!$bill) return ['ok' => false, 'reason' => 'Not found'];
    if (!in_array($bill['status'], ['inbox', 'pending_review'], true)) {
        return ['ok' => false, 'reason' => "Bill is '{$bill['status']}', not pending_review"];
    }

    // PWP awaiting AR — refuse to finalize. The approver will be auto-notified
    // when the AR pay-when-paid release fires.
    if (($bill['pwp_status'] ?? '') === 'awaiting_ar') {
        return ['ok' => false, 'reason' => 'Awaiting client payment (PWP) — auto-finalizes when AR clears'];
    }

    try {
        $routing = apWorkflowSubmitBillForApproval(
            $tenantId,
            $bill,
            (int) ($actor['id'] ?? 0) ?: null,
            'weekly_queue_finalize'
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'reason' => $e->getMessage()];
    }

    // Notify the first-step approvers with one-tap approve/reject tokens.
    $notified = 0;
    try {
        $notified = ap_weekly_queue_notify_current_step($tenantId, $billId);
    } catch (\Throwable $e) {
        error_log('[ap_weekly_queue_finalize_one] notify failed for bill ' . $billId . ': ' . $e->getMessage());
    }
    return [
        'ok' => true,
        'steps' => count($routing['approval_ids'] ?? []),
        'workflow_instance_id' => $routing['workflow_instance_id'] ?? null,
        'policy_id' => $routing['policy_id'] ?? null,
        'notified' => $notified,
    ];
}

/**
 * Send the one-tap approve+reject email to every currently-pending approver
 * (the lowest pending step_no). Returns the number of emails dispatched.
 */
function ap_weekly_queue_notify_current_step(int $tenantId, int $billId): int {
    $pdo = getDB();
    $bill = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id');
    $bill->execute(['t' => $tenantId, 'id' => $billId]);
    $bill = $bill->fetch(\PDO::FETCH_ASSOC);
    if (!$bill) throw new \RuntimeException("Bill {$billId} not found");

    $next = $pdo->prepare(
        "SELECT u.id, u.name, u.email
           FROM ap_bill_approvals a
           JOIN users u ON u.id = a.approver_user_id
          WHERE a.tenant_id = :t AND a.bill_id = :b AND a.state = 'pending'
          ORDER BY a.step_no ASC"
    );
    $next->execute(['t' => $tenantId, 'b' => $billId]);
    $approvers = $next->fetchAll(\PDO::FETCH_ASSOC);
    if (!$approvers) return 0;
    // Only the lowest pending step_no gets emailed.
    $minStep = null;
    $stepLookup = $pdo->prepare(
        "SELECT step_no FROM ap_bill_approvals
          WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'
          ORDER BY step_no ASC LIMIT 1"
    );
    $stepLookup->execute(['t' => $tenantId, 'b' => $billId]);
    $minStep = (int) ($stepLookup->fetchColumn() ?: 0);

    $sender = function_exists('cf_tenant_mail_sender') ? cf_tenant_mail_sender($tenantId, 'ap') : [];
    $svc = function_exists('cf_mail_bootstrap') ? cf_mail_bootstrap() : null;
    if (!$svc) return 0;

    $base = apEmailApprovalBaseUrl();
    $threadUrl = $base . '/#/modules/ap/bills/' . $billId;
    $sent = 0;
    foreach ($approvers as $a) {
        if (empty($a['email']) || !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) continue;
        try {
            $tokens = apEmailApprovalMint($tenantId, $billId, (int) $a['id'], (string) $a['email']);
            $html = apEmailApprovalBodyHtml($bill, (string) ($a['name'] ?? ''), $tokens['approve_url'], $tokens['reject_url'], $threadUrl);
            $text = sprintf(
                "A bill is awaiting your approval.\n\nVendor: %s\nBill #: %s\nAmount: \$%s\nDue: %s\n\nApprove: %s\nReject:  %s\n\nLinks expire in 72 hours.\n",
                $bill['vendor_name'] ?? '', $bill['bill_number'] ?? '',
                number_format((float) ($bill['total'] ?? 0), 2), $bill['due_date'] ?? '—',
                $tokens['approve_url'], $tokens['reject_url']
            );
            $svc->send($tenantId, 'ap', 'bill_awaiting_approval', [$a['email']],
                'Bill awaiting your approval — ' . ($bill['bill_number'] ?? '') . ' / ' . ($bill['vendor_name'] ?? ''),
                $text, $html, [], [
                    'from' => $sender['from'] ?? null,
                    'from_name' => $sender['from_name'] ?? null,
                    'reply_to' => $sender['reply_to'] ?? null,
                    'idempotency_key' => 'ap-approval-' . $billId . '-' . $a['id'] . '-' . $minStep,
                ]
            );
            $sent++;
        } catch (\Throwable $e) {
            error_log('[ap_weekly_queue_notify_current_step] dispatch failed for ' . $a['email'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}
