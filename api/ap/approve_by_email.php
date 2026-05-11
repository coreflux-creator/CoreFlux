<?php
/**
 * Public one-tap approve/reject endpoint for AP bills.
 *
 * Reached from email links minted by `core/email_approval.php`. Does NOT
 * require a session — the secret in `?t=…` is the auth credential.
 *
 *   GET /api/ap/approve_by_email.php?t=RAW&a=approve
 *   GET /api/ap/approve_by_email.php?t=RAW&a=reject
 *
 * Renders an HTML receipt page. Posts a comment when `?note=…` is supplied
 * (useful for "approve with caveat" or "reject because…" flows from the
 * email link footer).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/email_approval.php';

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$rawToken = (string) ($_GET['t'] ?? '');
$action   = (string) ($_GET['a'] ?? '');
$note     = isset($_GET['note']) ? trim((string) $_GET['note']) : null;

if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
    echo cf_email_approval_render('error', null, 'Invalid or missing approval link.');
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo cf_email_approval_render('error', null, "Invalid action: {$action}.");
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
try {
    $res = apEmailApprovalConsume($rawToken, $action, $note, $ip);
} catch (\Throwable $e) {
    error_log('[approve_by_email] consume failed: ' . $e->getMessage());
    echo cf_email_approval_render('error', null, 'Server error — please retry from the in-app approvals inbox.');
    exit;
}

echo cf_email_approval_render($res['state'], $res, $res['message']);
exit;

function cf_email_approval_render(string $state, ?array $res, string $message): string {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $billId = (int) ($res['bill_id'] ?? 0);
    [$emoji, $title, $color] = match ($state) {
        'approved'      => ['✅', 'Approved',                '#16a34a'],
        'rejected'      => ['🛑', 'Rejected',                '#dc2626'],
        'already_acted' => ['ℹ️', 'Already actioned',        '#0891b2'],
        'expired'       => ['⏰', 'Link expired',            '#a16207'],
        default         => ['⚠️', 'Could not complete',     '#7c2d12'],
    };
    $base = apEmailApprovalBaseUrl();
    $inboxUrl = "{$base}/#/modules/ap/approvals";
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . $h($title) . ' — CoreFlux</title>'
         . '<style>body{font-family:system-ui;background:#f8fafc;color:#0f172a;margin:0;padding:40px 16px}'
         . '.card{max-width:480px;margin:auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(15,23,42,.06);padding:32px;text-align:center}'
         . '.icon{font-size:48px;line-height:1;margin-bottom:8px}'
         . 'h1{color:' . $h($color) . ';margin:0 0 12px;font-size:22px}'
         . '.message{color:#475569;line-height:1.6;font-size:14px;margin:0 0 24px}'
         . '.btn{display:inline-block;background:#0f172a;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-weight:600;font-size:13px}'
         . '.meta{margin-top:24px;color:#94a3b8;font-size:12px}'
         . '</style></head><body><div class="card" data-testid="ap-email-approval-receipt">'
         . '<div class="icon">' . $emoji . '</div>'
         . '<h1>' . $h($title) . '</h1>'
         . '<p class="message">' . $h($message) . '</p>'
         . ($billId > 0
             ? '<a class="btn" href="' . $h($inboxUrl) . '">Open Approvals inbox →</a>'
             : '')
         . '<p class="meta">CoreFlux — secure one-tap approval</p>'
         . '</div></body></html>';
}
