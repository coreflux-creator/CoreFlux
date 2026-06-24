<?php
/**
 * Email-approval helper for Staffing timesheets.
 *
 * Mirrors `core/email_approval.php` (AP bills) but for `staffing_timesheet`
 * subjects. Lets an external approver — typically the client manager that
 * oversees the consultant — approve / reject the week's hours in one click
 * from their inbox, without ever logging into CoreFlux.
 *
 * Token TTL: 72h. Each token is bound to one (timesheet_id, approver_email)
 * pair and is single-use for either approve OR reject. Consuming the token
 * calls the same internal approval write-path so cascading time_entries
 * status, accounting event emission, and audit trail are identical to the
 * in-app flow.
 *
 * Tokens are hashed at rest (sha256). Raw token only lives in the email.
 *
 * SPEC: /app/core/approval_tokens.php (generic primitive).
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/approval_tokens.php';
require_once __DIR__ . '/../modules/time/lib/time.php';

/**
 * Mint a pair of single-use approve+reject tokens for an external manager
 * + staffing_timesheet header. Returns the absolute URLs + expiry.
 *
 * Caller is responsible for sending the email (resend / mail driver).
 */
function staffingEmailApprovalMint(
    int $tenantId,
    int $timesheetHeaderId,
    string $approverEmail,
    ?string $approverName = null,
    int $ttlHours = 72
): array {
    $issued = approvalTokenIssue(
        $tenantId,
        'staffing_timesheet',
        $timesheetHeaderId,
        null,                                 // no user_id — this is an external approver
        $approverEmail,
        ['approve', 'reject'],
        $ttlHours,
        ['name' => $approverName]
    );
    $raw  = $issued['token'];
    $base = staffingEmailApprovalBaseUrl();
    return [
        'approve_url' => "{$base}/api/staffing/approve_timesheet_by_email.php?t={$raw}&a=approve",
        'reject_url'  => "{$base}/api/staffing/approve_timesheet_by_email.php?t={$raw}&a=reject",
        'expires_at'  => $issued['expires_at'],
        'token_id'    => $issued['token_id'],
    ];
}

/**
 * Consume a token + apply the matching approve/reject to the
 * staffing_timesheets row. Mirrors `apEmailApprovalConsume` semantics:
 *
 *   { ok:bool, state:'approved'|'rejected'|'already_acted'|'expired'|'invalid',
 *     timesheet_id:int, message:string }
 */
function staffingEmailApprovalConsume(string $rawToken, string $action, ?string $note = null, ?string $ip = null): array {
    if (!in_array($action, ['approve', 'reject'], true)) {
        return ['ok' => false, 'state' => 'invalid', 'timesheet_id' => 0, 'message' => "Invalid action {$action}"];
    }

    $res = approvalTokenConsume($rawToken, $action, $ip);
    if (!$res['allowed']) {
        $reason = $res['reason'] ?? 'unknown';
        $state  = match ($reason) {
            'already_consumed'     => 'already_acted',
            'expired'              => 'expired',
            'action_not_permitted' => 'invalid',
            'token_not_found'      => 'invalid',
            default                => 'invalid',
        };
        return [
            'ok' => false, 'state' => $state,
            'timesheet_id' => (int) ($res['row']['subject_id'] ?? 0),
            'message' => "Could not consume token: {$reason}",
        ];
    }

    $row = $res['row'];
    if (($row['subject_type'] ?? '') !== 'staffing_timesheet') {
        return ['ok' => false, 'state' => 'invalid', 'timesheet_id' => 0, 'message' => 'Token is not for a staffing_timesheet'];
    }
    $headerId      = (int) $row['subject_id'];
    $tenantId      = (int) $row['tenant_id'];
    $approverEmail = (string) ($row['actor_email'] ?? '');

    $pdo = getDB();
    $h = $pdo->prepare('SELECT * FROM staffing_timesheets WHERE tenant_id = :t AND id = :id LIMIT 1');
    $h->execute(['t' => $tenantId, 'id' => $headerId]);
    $header = $h->fetch(\PDO::FETCH_ASSOC);
    if (!$header) {
        return ['ok' => false, 'state' => 'invalid', 'timesheet_id' => $headerId, 'message' => 'Timesheet not found'];
    }
    if (($header['status'] ?? '') !== 'submitted') {
        return [
            'ok' => false, 'state' => 'already_acted',
            'timesheet_id' => $headerId,
            'message' => "Timesheet is {$header['status']} — someone may have already acted.",
        ];
    }

    $beforeHeader = timeTimesheetAuditRowForTenant($tenantId, $headerId) ?? $header;
    $beforeEntries = timeEntryAuditRowsForTimesheet($tenantId, $headerId);
    $beforeEntriesById = [];
    foreach ($beforeEntries as $beforeEntry) {
        $beforeEntriesById[(int) ($beforeEntry['id'] ?? 0)] = $beforeEntry;
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $pdo->beginTransaction();
    try {
        if ($newStatus === 'approved') {
            $pdo->prepare(
                "UPDATE staffing_timesheets
                    SET status = 'approved',
                        approved_at = NOW(),
                        approved_via = 'external_email',
                        external_approver_email = :em,
                        approval_note = :n
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['em' => $approverEmail, 'n' => $note, 't' => $tenantId, 'id' => $headerId]);

            $pdo->prepare(
                "UPDATE time_entries
                    SET status = 'approved', approved_at = NOW(), approved_via = 'external_email'
                  WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'pending_review'"
            )->execute(['t' => $tenantId, 'tid' => $headerId]);
        } else {
            $reason = $note ?: 'Rejected by external approver';
            $pdo->prepare(
                "UPDATE staffing_timesheets
                    SET status = 'rejected',
                        rejected_at = NOW(),
                        approved_via = 'external_email',
                        external_approver_email = :em,
                        rejection_reason = :r
                  WHERE tenant_id = :t AND id = :id"
            )->execute(['em' => $approverEmail, 'r' => $reason, 't' => $tenantId, 'id' => $headerId]);

            $pdo->prepare(
                "UPDATE time_entries
                    SET status = 'rejected', rejected_reason = :r
                  WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'pending_review'"
            )->execute(['t' => $tenantId, 'tid' => $headerId, 'r' => $reason]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [
            'ok' => false, 'state' => 'invalid',
            'timesheet_id' => $headerId,
            'message' => 'DB write failed: ' . $e->getMessage(),
        ];
    }

    // Best-effort: emit accounting event so the GL gets the labor entry.
    if ($newStatus === 'approved') {
        try {
            $approvedStmt = $pdo->prepare(
                "SELECT *
                   FROM time_entries
                  WHERE tenant_id = :t AND timesheet_id = :tid AND status = 'approved'"
            );
            $approvedStmt->execute(['t' => $tenantId, 'tid' => $headerId]);
            foreach ($approvedStmt->fetchAll(\PDO::FETCH_ASSOC) as $approved) {
                timeEntryApprovedEmit((int) $approved['id'], $approved, 'external_email', [
                    'tenant_id' => $tenantId,
                    'actor_type' => 'external_approver',
                    'actor_email' => $approverEmail,
                    'before' => $beforeEntriesById[(int) $approved['id']] ?? null,
                    'timesheet_id' => $headerId,
                    'token_id' => (int) ($row['id'] ?? 0),
                    'external_approver_email' => $approverEmail,
                    'ip_address' => $ip,
                ]);
            }
            timeAudit('time.timesheet.approved', [
                'timesheet_id' => $headerId,
                'approved_via' => 'external_email',
                'external_approver_email' => $approverEmail,
                'token_id' => (int) ($row['id'] ?? 0),
            ], $headerId, [
                'tenant_id' => $tenantId,
                'actor_type' => 'external_approver',
                'actor_email' => $approverEmail,
                'before' => [
                    'timesheet' => $beforeHeader,
                    'entries' => $beforeEntries,
                ],
                'after' => [
                    'timesheet' => timeTimesheetAuditRowForTenant($tenantId, $headerId),
                    'entries' => timeEntryAuditRowsForTimesheet($tenantId, $headerId),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("[staffing-email-approval] audit emit failed: " . $e->getMessage());
        }
        try {
            require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';
            staffingEmitWorkerHoursApprovedEvent($tenantId, $headerId);
        } catch (\Throwable $e) {
            error_log("[staffing-email-approval] accounting emit failed: " . $e->getMessage());
        }
    } else {
        try {
            timeAudit('time.timesheet.rejected', [
                'timesheet_id' => $headerId,
                'rejected_via' => 'external_email',
                'external_approver_email' => $approverEmail,
                'token_id' => (int) ($row['id'] ?? 0),
                'reason' => $note ?: 'Rejected by external approver',
            ], $headerId, [
                'tenant_id' => $tenantId,
                'actor_type' => 'external_approver',
                'actor_email' => $approverEmail,
                'before' => [
                    'timesheet' => $beforeHeader,
                    'entries' => $beforeEntries,
                ],
                'after' => [
                    'timesheet' => timeTimesheetAuditRowForTenant($tenantId, $headerId),
                    'entries' => timeEntryAuditRowsForTimesheet($tenantId, $headerId),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log("[staffing-email-approval] rejection audit failed: " . $e->getMessage());
        }
    }

    return [
        'ok' => true, 'state' => $newStatus,
        'timesheet_id' => $headerId,
        'message' => $newStatus === 'approved'
            ? 'Approval recorded. Payroll and billing have been notified.'
            : 'Rejection recorded. The worker has been asked to revise the week.',
    ];
}

function staffingEmailApprovalBaseUrl(): string {
    if (defined('APP_URL') && APP_URL) return rtrim((string) APP_URL, '/');
    $env = getenv('APP_URL');
    if ($env) return rtrim($env, '/');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$proto}://{$host}";
}

/**
 * HTML body for the timesheet-approval notification email. One-tap
 * Approve / Reject buttons plus a sticky link to open the week in the
 * Staffing inbox.
 */
function staffingEmailApprovalBodyHtml(
    array $header,
    string $workerName,
    float $totalHours,
    float $revenue,
    string $approverName,
    string $approveUrl,
    string $rejectUrl,
    string $threadUrl
): string {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $rev = '$' . number_format($revenue, 2);
    $hrs = number_format($totalHours, 2);
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
         . '<p>A timesheet is awaiting your approval:</p>'
         . '<ul style="line-height:1.7">'
         . '<li><strong>Worker:</strong> ' . $h($workerName) . '</li>'
         . '<li><strong>Week:</strong> ' . $h($header['period_start'] ?? '') . ' → ' . $h($header['period_end'] ?? '') . '</li>'
         . '<li><strong>Total hours:</strong> ' . $h($hrs) . '</li>'
         . '<li><strong>Billable amount:</strong> ' . $h($rev) . '</li>'
         . '</ul>'
         . '<div style="margin:24px 0">' . $approveBtn . $rejectBtn . '</div>'
         . '<p style="font-size:12px;color:#64748b">These buttons are personal one-time-use links '
         . 'and expire in 72 hours. They record your decision securely without requiring sign-in.</p>'
         . '<p style="font-size:12px;color:#64748b">Need to add a comment or open the timesheet? '
         . '<a href="' . $h($threadUrl) . '">Open in CoreFlux →</a></p>'
         . '</div>';
}
