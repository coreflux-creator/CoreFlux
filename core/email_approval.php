<?php
/**
 * Email-approval helper for AP bills.
 *
 * Wraps the generic `core/approval_tokens.php` primitive specifically for
 * `ap_bill` subjects. Lets an approver tap "Approve" / "Reject" inside their
 * email client without logging in.
 *
 * Token TTL: 72h. Each token is bound to a specific (approver_user_id,
 * bill_id) pair and one of {approve, reject}. Consuming the token runs the
 * existing `bill_approvals.php?action=approve|reject` logic by reusing
 * the user record from the token row — no session needed.
 *
 * Tokens are hashed at rest; the raw token only lives in the email body
 * (and in the recipient's inbox).
 *
 * SPEC: /app/core/approval_tokens.php
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/approval_tokens.php';

/**
 * Mint a pair of single-use approve+reject tokens for a given approver +
 * bill. Returns ['approve_url' => ..., 'reject_url' => ..., 'expires_at' => ...].
 *
 * Both tokens share the same row (actions = ['approve','reject']) so
 * consuming one effectively burns the other — you cannot approve then
 * reject by clicking both links.
 */
function apEmailApprovalMint(int $tenantId, int $billId, int $approverUserId, string $approverEmail, int $ttlHours = 72): array {
    $issued = approvalTokenIssue(
        $tenantId,
        'ap_bill',
        $billId,
        $approverUserId,
        $approverEmail,
        ['approve', 'reject'],
        $ttlHours,
        []
    );
    $raw = $issued['token'];
    $base = apEmailApprovalBaseUrl();
    return [
        'approve_url' => "{$base}/api/ap/approve_by_email.php?t={$raw}&a=approve",
        'reject_url'  => "{$base}/api/ap/approve_by_email.php?t={$raw}&a=reject",
        'expires_at'  => $issued['expires_at'],
        'token_id'    => $issued['token_id'],
    ];
}

/**
 * Consume a token + apply the matching approve/reject. Returns:
 *   ['ok' => bool, 'state' => 'approved'|'rejected'|'already_acted'|'expired'|'invalid',
 *    'bill_id' => N, 'message' => string].
 *
 * Wraps the legacy `ap_bill_approvals` write path so all auditing and
 * downstream workflow mirror behaviour stays identical to the in-app path.
 */
function apEmailApprovalConsume(string $rawToken, string $action, ?string $note = null, ?string $ip = null): array {
    if (!in_array($action, ['approve', 'reject'], true)) {
        return ['ok' => false, 'state' => 'invalid', 'bill_id' => 0, 'message' => "Invalid action {$action}"];
    }

    $res = approvalTokenConsume($rawToken, $action, $ip);
    if (!$res['allowed']) {
        $reason = $res['reason'] ?? 'unknown';
        $state  = match ($reason) {
            'already_consumed'    => 'already_acted',
            'expired'             => 'expired',
            'action_not_permitted'=> 'invalid',
            'token_not_found'     => 'invalid',
            default               => 'invalid',
        };
        return [
            'ok' => false, 'state' => $state,
            'bill_id' => (int) ($res['row']['subject_id'] ?? 0),
            'message' => "Could not consume token: {$reason}",
        ];
    }

    $row = $res['row'];
    if (($row['subject_type'] ?? '') !== 'ap_bill') {
        return ['ok' => false, 'state' => 'invalid', 'bill_id' => 0, 'message' => 'Token is not for an ap_bill'];
    }
    $billId   = (int) $row['subject_id'];
    $tenantId = (int) $row['tenant_id'];
    $userId   = (int) ($row['actor_user_id'] ?? 0);
    if ($userId === 0) {
        return ['ok' => false, 'state' => 'invalid', 'bill_id' => $billId, 'message' => 'Token has no associated approver'];
    }

    $pdo = getDB();
    $bill = $pdo->prepare('SELECT * FROM ap_bills WHERE tenant_id = :t AND id = :id LIMIT 1');
    $bill->execute(['t' => $tenantId, 'id' => $billId]);
    $bill = $bill->fetch(\PDO::FETCH_ASSOC);
    if (!$bill) {
        return ['ok' => false, 'state' => 'invalid', 'bill_id' => $billId, 'message' => 'Bill not found'];
    }

    // Locate THIS approver's pending step. Must mirror /api/ap/bill_approvals.php
    // logic so any audit/state-change path stays consistent.
    $step = $pdo->prepare(
        "SELECT * FROM ap_bill_approvals
          WHERE tenant_id = :t AND bill_id = :b AND approver_user_id = :u AND state = 'pending'
          ORDER BY step_no ASC LIMIT 1"
    );
    $step->execute(['t' => $tenantId, 'b' => $billId, 'u' => $userId]);
    $step = $step->fetch(\PDO::FETCH_ASSOC);
    if (!$step) {
        return ['ok' => false, 'state' => 'already_acted', 'bill_id' => $billId, 'message' => 'No pending step for this approver — someone may have already acted.'];
    }
    // Block earlier-step bypass (mirrors in-app guard).
    $earlier = $pdo->prepare(
        "SELECT COUNT(*) FROM ap_bill_approvals
          WHERE tenant_id = :t AND bill_id = :b AND step_no < :s AND state = 'pending'"
    );
    $earlier->execute(['t' => $tenantId, 'b' => $billId, 's' => (int) $step['step_no']]);
    if ((int) $earlier->fetchColumn() > 0) {
        return ['ok' => false, 'state' => 'invalid', 'bill_id' => $billId, 'message' => 'A prior approval step is still pending.'];
    }

    $newState = $action === 'approve' ? 'approved' : 'rejected';
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE ap_bill_approvals
                SET state = :ns, decision_at = NOW(), decision_note = :n
              WHERE tenant_id = :t AND id = :id"
        )->execute(['ns' => $newState, 'n' => $note, 't' => $tenantId, 'id' => (int) $step['id']]);

        if ($newState === 'rejected') {
            $pdo->prepare("UPDATE ap_bills SET status = 'disputed' WHERE tenant_id = :t AND id = :id")
                ->execute(['t' => $tenantId, 'id' => $billId]);
        } else {
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'state' => 'invalid', 'bill_id' => $billId, 'message' => 'DB write failed: ' . $e->getMessage()];
    }

    // Audit log via apAudit() if available (loaded by AP API entry points).
    @require_once __DIR__ . '/../modules/ap/lib/ap.php';
    if (function_exists('apAudit')) {
        try {
            apAudit("ap.bill.approval_{$newState}", [
                'bill_id' => $billId,
                'step' => (int) $step['step_no'],
                'via' => 'email_approval',
                'token_id' => (int) ($row['id'] ?? 0),
            ], $billId);
        } catch (\Throwable $_) { /* non-fatal */ }
    }

    return [
        'ok' => true, 'state' => $newState, 'bill_id' => $billId,
        'message' => $newState === 'approved'
            ? 'Approval recorded. Thank you!'
            : 'Rejection recorded. AP has been notified.',
    ];
}

function apEmailApprovalBaseUrl(): string {
    if (defined('APP_URL') && APP_URL) return rtrim((string) APP_URL, '/');
    $env = getenv('APP_URL');
    if ($env) return rtrim($env, '/');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$proto}://{$host}";
}

/**
 * Build the HTML used inside the approval-notification email. Includes
 * one-tap Approve / Reject buttons + a link to the in-app discussion thread.
 */
function apEmailApprovalBodyHtml(array $bill, string $approverName, string $approveUrl, string $rejectUrl, string $threadUrl): string {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $amt = '$' . number_format((float) ($bill['total'] ?? 0), 2);
    $approveBtn = '<a href="' . $h($approveUrl) . '" '
                . 'style="display:inline-block;padding:10px 22px;background:#16a34a;color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-family:system-ui;font-size:13px;'
                . 'font-weight:600;margin-right:8px">Approve in one click</a>';
    $rejectBtn = '<a href="' . $h($rejectUrl) . '" '
                . 'style="display:inline-block;padding:10px 22px;background:#dc2626;color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-family:system-ui;font-size:13px;'
                . 'font-weight:600">Reject</a>';
    return '<div style="font-family:system-ui;max-width:560px;margin:0 auto;padding:24px;color:#111">'
         . '<p>Hi ' . $h($approverName) . ',</p>'
         . '<p>A bill is awaiting your approval:</p>'
         . '<ul style="line-height:1.7">'
         . '<li><strong>Vendor:</strong> ' . $h($bill['vendor_name'] ?? '') . '</li>'
         . '<li><strong>Bill #:</strong> ' . $h($bill['bill_number'] ?? '') . '</li>'
         . '<li><strong>Amount:</strong> ' . $h($amt) . '</li>'
         . '<li><strong>Due:</strong> ' . $h($bill['due_date'] ?? '—') . '</li>'
         . ($bill['payment_terms'] ? '<li><strong>Terms:</strong> ' . $h($bill['payment_terms']) . '</li>' : '')
         . '</ul>'
         . '<div style="margin:24px 0">' . $approveBtn . $rejectBtn . '</div>'
         . '<p style="font-size:12px;color:#64748b">These buttons are personal one-time-use links '
         . 'and expire in 72 hours. They will record your decision securely without requiring sign-in.</p>'
         . '<p style="font-size:12px;color:#64748b">Need to add a comment or see the discussion? '
         . '<a href="' . $h($threadUrl) . '">Open the approval thread in CoreFlux →</a></p>'
         . '</div>';
}
