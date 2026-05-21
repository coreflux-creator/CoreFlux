<?php
/**
 * /api/staffing/timesheet_email_approver — Send a one-tap approval email to
 * an external manager (typically the client-side approver) for a submitted
 * staffing timesheet.
 *
 *   POST { timesheet_id, approver_email, approver_name? }
 *     → mints a 72h single-use approve+reject token pair, formats the email
 *       body, sends via `mailerSend` if configured, returns the dispatch
 *       receipt.
 *
 * Auth: in-app session (the dispatcher must be a CoreFlux user). The
 * recipient (the approver) does NOT need a CoreFlux account.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/staffing_email_approval.php';
require_once __DIR__ . '/../lib/timesheets.php';

$ctx    = api_require_auth();
$method = api_method();
if ($method !== 'POST') api_error('Method not allowed', 405);

$body          = api_json_body();
$tsId          = (int) ($body['timesheet_id'] ?? 0);
$approverEmail = trim((string) ($body['approver_email'] ?? ''));
$approverName  = trim((string) ($body['approver_name']  ?? ''));

if ($tsId <= 0) api_error('timesheet_id required', 422);
if ($approverEmail === '' || !filter_var($approverEmail, FILTER_VALIDATE_EMAIL)) {
    api_error('approver_email must be a valid email', 422);
}

$tenantId = currentTenantId();
$pdo      = getDB();

// Load header + verify it's submitted (only submitted timesheets can be
// emailed for external approval).
$h = $pdo->prepare(
    "SELECT t.*,
            COALESCE(NULLIF(CONCAT_WS(' ', p.first_name, p.last_name), ' '), p.email_primary) AS worker_name
       FROM staffing_timesheets t
       LEFT JOIN people p ON p.id = t.person_id AND p.tenant_id = t.tenant_id
      WHERE t.tenant_id = :t AND t.id = :id LIMIT 1"
);
$h->execute(['t' => $tenantId, 'id' => $tsId]);
$header = $h->fetch(\PDO::FETCH_ASSOC);
if (!$header) api_error('Timesheet not found', 404);
if (($header['status'] ?? '') !== 'submitted') {
    api_error("Timesheet is {$header['status']} — only submitted timesheets can be sent for external approval", 409);
}

// Pull total hours + revenue snapshot so the email shows what the approver
// is being asked to sign off on.
$totals = $pdo->prepare(
    "SELECT COALESCE(SUM(te.hours), 0)                              AS hours,
            COALESCE(SUM(te.hours * COALESCE(pr.bill_rate, 0)), 0)  AS revenue
       FROM time_entries te
       LEFT JOIN placement_rates pr ON pr.id = te.rate_snapshot_id
      WHERE te.tenant_id = :t AND te.timesheet_id = :id AND te.status != 'superseded'"
);
$totals->execute(['t' => $tenantId, 'id' => $tsId]);
$totRow = $totals->fetch(\PDO::FETCH_ASSOC) ?: ['hours' => 0, 'revenue' => 0];

try {
    $tokens = staffingEmailApprovalMint($tenantId, $tsId, $approverEmail, $approverName ?: null);
} catch (\Throwable $e) {
    api_error('Could not mint approval token: ' . $e->getMessage(), 500);
}

$base = staffingEmailApprovalBaseUrl();
$threadUrl = "{$base}/#/modules/staffing/approvals?focus={$tsId}";

$html = staffingEmailApprovalBodyHtml(
    $header,
    (string) ($header['worker_name'] ?? 'Worker'),
    (float)  $totRow['hours'],
    (float)  $totRow['revenue'],
    $approverName ?: $approverEmail,
    $tokens['approve_url'],
    $tokens['reject_url'],
    $threadUrl
);

$subject = sprintf(
    'Timesheet awaiting approval — %s, week of %s',
    (string) ($header['worker_name'] ?? 'Worker'),
    (string) ($header['period_start'] ?? '')
);

$sent = false; $err = null;
try {
    if (function_exists('mailerSend')) {
        mailerSend([
            'to'        => $approverEmail,
            'subject'   => $subject,
            'body_html' => $html,
            'module'    => 'staffing',
            'purpose'   => 'timesheets',
            'tenant_id' => $tenantId,
        ]);
        $sent = true;
    } else {
        $err = 'mailer not configured';
    }
} catch (\Throwable $e) {
    $err = $e->getMessage();
}

api_ok([
    'sent'           => $sent,
    'error'          => $err ? substr($err, 0, 500) : null,
    'approver_email' => $approverEmail,
    'token_id'       => (int) $tokens['token_id'],
    'expires_at'     => $tokens['expires_at'],
    // Surface the URLs so the dispatcher (or QA) can verify the link in the
    // common case where the mailer isn't wired up locally.
    'approve_url'    => $tokens['approve_url'],
    'reject_url'     => $tokens['reject_url'],
]);
