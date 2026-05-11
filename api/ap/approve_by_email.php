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
$confirm  = (string) ($_GET['confirm'] ?? '');  // 1 = caller acknowledged note step

if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
    echo cf_email_approval_render('error', null, 'Invalid or missing approval link.');
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo cf_email_approval_render('error', null, "Invalid action: {$action}.");
    exit;
}

// Step 1: GET without ?confirm=1 → show a 1-page "add note?" form so the
// approver can attach a one-line comment before the action is finalised.
// Step 2: GET with ?confirm=1 → consume the token + record the action.
// Rejections ALWAYS pass through the note step (we want a reason on file).
if ($confirm !== '1' && ($action === 'reject' || ($action === 'approve' && $note === null))) {
    echo cf_email_approval_render_note_prompt($rawToken, $action);
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

function cf_email_approval_render_note_prompt(string $rawToken, string $action): string {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    [$title, $color, $btn, $btnBg, $hint] = $action === 'approve'
        ? ['Approve with a note?',     '#16a34a', 'Approve',          '#16a34a', 'Optional. Skip and just approve, or add context for AP / your team.']
        : ['Reject — please add a reason', '#dc2626', 'Reject with reason', '#dc2626', 'Required. AP needs to know what to fix so the bill can be resubmitted.'];
    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . $h($title) . ' — CoreFlux</title>'
         . '<style>body{font-family:system-ui;background:#f8fafc;color:#0f172a;margin:0;padding:40px 16px}'
         . '.card{max-width:480px;margin:auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(15,23,42,.06);padding:28px}'
         . 'h1{color:' . $h($color) . ';margin:0 0 12px;font-size:22px}'
         . '.hint{color:#64748b;font-size:13px;margin:0 0 16px}'
         . 'textarea{width:100%;box-sizing:border-box;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-family:system-ui;font-size:14px;min-height:90px;resize:vertical}'
         . '.row{display:flex;gap:8px;margin-top:16px}'
         . '.btn{display:inline-block;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;border:0;cursor:pointer}'
         . '.btn--primary{background:' . $h($btnBg) . '}'
         . '.btn--ghost{background:#e5e7eb;color:#0f172a}'
         . '</style></head><body><div class="card" data-testid="ap-email-approval-note-prompt">'
         . '<h1>' . $h($title) . '</h1>'
         . '<p class="hint">' . $h($hint) . '</p>'
         . '<form method="get" action="/api/ap/approve_by_email.php">'
         . '<input type="hidden" name="t" value="' . $h($rawToken) . '">'
         . '<input type="hidden" name="a" value="' . $h($action) . '">'
         . '<input type="hidden" name="confirm" value="1">'
         . '<textarea name="note" placeholder="One-line comment…" maxlength="500" '
         . ($action === 'reject' ? 'required' : '') . ' data-testid="ap-email-approval-note-input"></textarea>'
         . '<div class="row">'
         . '<button class="btn btn--primary" type="submit" data-testid="ap-email-approval-note-submit">' . $h($btn) . '</button>'
         . ($action === 'approve'
            ? '<a class="btn btn--ghost" href="/api/ap/approve_by_email.php?t=' . $h($rawToken) . '&a=approve&confirm=1" data-testid="ap-email-approval-skip-note">Skip note &amp; approve</a>'
            : '')
         . '</div></form>'
         . '<p style="margin-top:18px;color:#94a3b8;font-size:11px">Your decision is recorded the moment you submit. Links expire 72h after issue.</p>'
         . '</div></body></html>';
}

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
