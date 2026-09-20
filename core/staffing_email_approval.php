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
require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';

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
    $note = trim((string) ($note ?? ''));
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($noteLength > 500) {
        return ['ok' => false, 'state' => 'invalid', 'timesheet_id' => 0, 'message' => 'The note must be 500 characters or fewer.'];
    }

    $pdo = getDB();
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $headerId = 0;
    $tenantId = 0;
    $approverEmail = '';

    try {
        $pdo->beginTransaction();

        // Lock the token first. It is consumed only after the timesheet write
        // succeeds, so a missing rate or transient DB failure leaves the link
        // usable after an operator fixes the underlying problem.
        $tokenStmt = $pdo->prepare(
            'SELECT id, tenant_id, subject_type, subject_id, actor_email,
                    actions_json, expires_at, consumed_at
               FROM approval_tokens
              WHERE token_hash = :token_hash
              LIMIT 1 FOR UPDATE'
        );
        $tokenStmt->execute(['token_hash' => hash('sha256', $rawToken)]);
        $row = $tokenStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $tokenReason = null;
        if (!$row) $tokenReason = 'token_not_found';
        elseif (!empty($row['consumed_at'])) $tokenReason = 'already_consumed';
        elseif (strtotime((string) $row['expires_at']) < time()) $tokenReason = 'expired';
        else {
            $allowedActions = json_decode((string) $row['actions_json'], true) ?: [];
            if (!in_array($action, $allowedActions, true)) $tokenReason = 'action_not_permitted';
        }
        if ($tokenReason !== null) {
            $pdo->rollBack();
            $state = match ($tokenReason) {
                'already_consumed' => 'already_acted',
                'expired' => 'expired',
                default => 'invalid',
            };
            return [
                'ok' => false,
                'state' => $state,
                'timesheet_id' => (int) ($row['subject_id'] ?? 0),
                'message' => "Could not use this approval link: {$tokenReason}",
            ];
        }
        if (($row['subject_type'] ?? '') !== 'staffing_timesheet') {
            $pdo->rollBack();
            return ['ok' => false, 'state' => 'invalid', 'timesheet_id' => 0, 'message' => 'Token is not for a staffing_timesheet'];
        }

        $headerId = (int) $row['subject_id'];
        $tenantId = (int) $row['tenant_id'];
        $approverEmail = (string) ($row['actor_email'] ?? '');
        $headerStmt = $pdo->prepare(
            'SELECT * FROM staffing_timesheets
              WHERE tenant_id = :tenant_id AND id = :id
              LIMIT 1 FOR UPDATE'
        );
        $headerStmt->execute(['tenant_id' => $tenantId, 'id' => $headerId]);
        $header = $headerStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$header) {
            $pdo->rollBack();
            return ['ok' => false, 'state' => 'invalid', 'timesheet_id' => $headerId, 'message' => 'Timesheet not found'];
        }
        if (($header['status'] ?? '') !== 'submitted') {
            $pdo->rollBack();
            return [
                'ok' => false,
                'state' => 'already_acted',
                'timesheet_id' => $headerId,
                'message' => "Timesheet is {$header['status']} — someone may have already acted.",
            ];
        }

        if ($newStatus === 'approved') {
            $snapshots = staffingTimesheetApprovalPlan(null, $header, $tenantId);
            staffingTimesheetApplyApproval(null, $headerId, $snapshots, [
                'tenant_id' => $tenantId,
                'header_approved_via' => 'external_email',
                'entry_approved_via' => 'tokenized_client_email',
                'external_approver_email' => $approverEmail,
                'approval_note' => $note !== '' ? $note : null,
                'approval_token_id' => (int) $row['id'],
            ]);
        } else {
            $reason = $note !== '' ? $note : 'Rejected by external approver';
            $headerUpdate = $pdo->prepare(
                "UPDATE staffing_timesheets
                    SET status = 'rejected',
                        approved_at = NULL,
                        approved_by_user_id = NULL,
                        rejected_at = NOW(),
                        approved_via = 'external_email',
                        external_approver_email = :external_email,
                        rejection_reason = :reason
                  WHERE tenant_id = :tenant_id AND id = :id AND status = 'submitted'"
            );
            $headerUpdate->execute([
                'external_email' => $approverEmail,
                'reason' => $reason,
                'tenant_id' => $tenantId,
                'id' => $headerId,
            ]);
            if ($headerUpdate->rowCount() !== 1) {
                throw new \RuntimeException('The timesheet changed while the rejection was being recorded.');
            }
            $entryUpdate = $pdo->prepare(
                "UPDATE time_entries
                    SET status = 'rejected',
                        rejected_reason = :reason,
                        approved_at = NULL,
                        approved_by_user_id = NULL
                  WHERE tenant_id = :tenant_id
                    AND timesheet_id = :timesheet_id
                    AND status = 'pending_review'"
            );
            $entryUpdate->execute([
                'tenant_id' => $tenantId,
                'timesheet_id' => $headerId,
                'reason' => $reason,
            ]);
            if ($entryUpdate->rowCount() < 1) {
                throw new \RuntimeException('This timesheet has no submitted entries to reject.');
            }
            staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.rejected', null, [
                'reason' => $reason,
                'approved_via' => 'external_email',
                'approval_token_id' => (int) $row['id'],
                'external_approver_email' => $approverEmail,
            ], $tenantId);
        }

        $consume = $pdo->prepare(
            'UPDATE approval_tokens
                SET consumed_at = NOW(), consumed_via_action = :action, consumed_ip = :ip
              WHERE tenant_id = :tenant_id AND id = :id AND consumed_at IS NULL'
        );
        $consume->execute([
            'action' => $action,
            'ip' => $ip,
            'tenant_id' => $tenantId,
            'id' => (int) $row['id'],
        ]);
        if ($consume->rowCount() !== 1) {
            throw new \RuntimeException('This approval link was used in another session.');
        }
        $closeSiblings = $pdo->prepare(
            "UPDATE approval_tokens
                SET consumed_at = NOW(), consumed_via_action = 'superseded'
              WHERE tenant_id = :tenant_id
                AND subject_type = 'staffing_timesheet'
                AND subject_id = :subject_id
                AND id != :token_id
                AND consumed_at IS NULL"
        );
        $closeSiblings->execute([
            'tenant_id' => $tenantId,
            'subject_id' => $headerId,
            'token_id' => (int) $row['id'],
        ]);
        staffingTimesheetRecordArtifactEvent($headerId, 'timesheet.approval_token_consumed', null, [
            'approval_token_id' => (int) $row['id'],
            'action' => $action,
            'external_approver_email' => $approverEmail,
            'sibling_tokens_closed' => $closeSiblings->rowCount(),
        ], $tenantId);
        $pdo->commit();
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[staffing-email-approval] database write failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'state' => 'invalid',
            'timesheet_id' => $headerId,
            'message' => 'CoreFlux could not record the decision. The link is still valid; please try again.',
        ];
    } catch (\RuntimeException|\InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [
            'ok' => false,
            'state' => 'needs_attention',
            'timesheet_id' => $headerId,
            'message' => $e->getMessage() . ' The link remains valid after the issue is corrected.',
        ];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[staffing-email-approval] approval failed: ' . $e->getMessage());
        return [
            'ok' => false,
            'state' => 'invalid',
            'timesheet_id' => $headerId,
            'message' => 'CoreFlux could not record the decision. The link is still valid; please try again.',
        ];
    }

    // Best-effort: emit accounting event so the GL gets the labor entry.
    if ($newStatus === 'approved') {
        try {
            staffingEmitWorkerHoursApprovedEvent($tenantId, $headerId);
        } catch (\Throwable $e) {
            error_log("[staffing-email-approval] accounting emit failed: " . $e->getMessage());
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
