<?php
/** External weekly review, explicit confirmation, and existing post-commit accounting handoff. */
declare(strict_types=1);
require_once __DIR__.'/staffing_email_review.php';

/** Secret URLs are internal delivery data and must not be returned to the dispatcher. */
function staffingEmailApprovalMint(int $tenantId,int $timesheetHeaderId,string $approverEmail,?string $approverName=null,int $ttlHours = 72,int $issuer=0): array
{
    $issued=staffingEmailReviewIssue($tenantId,$issuer,$timesheetHeaderId,$approverEmail,$approverName,$ttlHours);
    $raw=$issued['token'];unset($issued['token']);$base=staffingEmailApprovalBaseUrl();
    return $issued+[
        'approve_url'=>"{$base}/api/staffing/approve_timesheet_by_email.php?t={$raw}&a=approve",
        'reject_url'=>"{$base}/api/staffing/approve_timesheet_by_email.php?t={$raw}&a=reject",
    ];
}

function staffingEmailApprovalFailure(Throwable $error): array
{
    $safe=!($error instanceof PDOException) && ($error instanceof RuntimeException || $error instanceof InvalidArgumentException);
    $status=$safe?(int)$error->getCode():503;
    if (!in_array($status,[403,404,409,410,422,503],true)) $status=$safe?422:503;
    if (!$safe) error_log('[staffing-email-approval] failed: '.get_class($error));
    return ['ok'=>false,'state'=>$status===410?'expired':'needs_attention','status_code'=>$status,
        'message'=>$safe?$error->getMessage():'The decision could not be recorded. Open the review again to check its current state.'];
}

function staffingEmailApprovalConsume(string $rawToken,string $action,?string $note=null,?string $ip=null,?string $revision=null): array
{
    try {
        // A nested caller cannot guarantee that its outer decision has committed before emission.
        if (getDB()->inTransaction()) throw new RuntimeException('Finish the current transaction before recording this external decision.',409);
        $result=staffingEmailApprovalDecide($rawToken,$action,$revision,(string)$note,$ip);
    } catch(Throwable $error) {return staffingEmailApprovalFailure($error);}
    if ($result['state']==='approved') {
        try {
            $warning=staffingEmitWorkerHoursApprovedEvent($result['workspace_id'],$result['timesheet_id']);
            if ($warning!==null) $result['posting_warning']='Approval recorded; accounting needs review.';
        } catch(Throwable $error) {
            error_log('[staffing-email-approval] accounting emit failed: '.get_class($error));
            $result['posting_warning']='Approval recorded; accounting needs review.';
        }
    }
    return $result;
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
 * HTML body for the timesheet review notification email. Review
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
    $hrs = number_format($totalHours, 2);
    $approveBtn = '<a href="' . $h($approveUrl) . '" '
                . 'style="display:inline-block;padding:10px 22px;background:#16a34a;color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-family:system-ui;font-size:13px;'
                . 'font-weight:600;margin-right:8px">Review approval</a>';
    $rejectBtn = '<a href="' . $h($rejectUrl) . '" '
                . 'style="display:inline-block;padding:10px 22px;background:#dc2626;color:#fff;'
                . 'text-decoration:none;border-radius:6px;font-family:system-ui;font-size:13px;'
                . 'font-weight:600">Review rejection</a>';
    return '<div style="font-family:system-ui;max-width:560px;margin:0 auto;padding:24px;color:#111">'
         . '<p>Hi ' . $h($approverName) . ',</p>'
         . '<p>A timesheet is awaiting your approval:</p>'
         . '<ul style="line-height:1.7">'
         . '<li><strong>Worker:</strong> ' . $h($workerName) . '</li>'
         . '<li><strong>Week:</strong> ' . $h($header['period_start'] ?? '') . ' → ' . $h($header['period_end'] ?? '') . '</li>'
         . '<li><strong>Total hours:</strong> ' . $h($hrs) . '</li>'
         . '</ul>'
         . '<div style="margin:24px 0">' . $approveBtn . $rejectBtn . '</div>'
         . '<p style="font-size:12px;color:#64748b">These buttons are personal one-time-use links '
         . 'and expire in 72 hours. Open the complete week, then confirm your decision. Opening a link does not approve or reject time.</p>'
         . '<p style="font-size:12px;color:#64748b">Need to add a comment or open the timesheet? '
         . '<a href="' . $h($threadUrl) . '">Open in CoreFlux →</a></p>'
         . '</div>';
}
