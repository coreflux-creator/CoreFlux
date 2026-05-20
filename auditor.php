<?php
/**
 * /auditor.php — External Auditor token entry point.
 *
 *   /auditor.php?token=XXX
 *
 * Validates the token, seeds an auditor session, then bounces into the SPA
 * (defaulting to the CFO Dashboard which is the most useful auditor surface).
 * Every page view is recorded in `auditor_access_log` for forensics.
 *
 * Invalid / revoked / expired tokens render a friendly error page rather
 * than redirecting back to the regular login (so the auditor doesn't think
 * they need a CoreFlux account).
 */
declare(strict_types=1);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/auditor.php';

initSession();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$token = trim($token);

if ($token === '') {
    http_response_code(400);
    echo _auditorErrorPage('Token is required', 'No access token was supplied. The link you received should include a <code>?token=…</code> parameter.');
    exit;
}

$ok = auditorRedeemAndStart($token);
if (!$ok) {
    http_response_code(403);
    echo _auditorErrorPage(
        'Access link is no longer valid',
        'This auditor access link is invalid, has been revoked, or has expired. Please ask the issuer to send you a new one.'
    );
    exit;
}

// Default landing is the CFO dashboard — the headline read-only surface.
// Auditors can navigate into reports/accounting/etc from the sidebar.
$next = '/spa.php#/cfo';
header('Location: ' . $next);
exit;

function _auditorErrorPage(string $title, string $body): string {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $b = $body; // body may contain trusted HTML (e.g. <code>)
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>External Auditor — {$t} | CoreFlux</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
           background: #f7f8fb; color: #1f2937; margin: 0;
           display: flex; min-height: 100vh; align-items: center; justify-content: center; }
    .card { max-width: 480px; padding: 36px; background: #fff;
            border-radius: 14px; box-shadow: 0 4px 24px rgba(0,0,0,0.06); text-align: center; }
    .icon { font-size: 48px; line-height: 1; margin-bottom: 16px; }
    h1 { font-size: 20px; font-weight: 700; margin: 0 0 12px; }
    p  { font-size: 14px; color: #6b7280; line-height: 1.6; margin: 0; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  </style>
</head>
<body>
  <div class="card" data-testid="auditor-error-card">
    <div class="icon">🔒</div>
    <h1>{$t}</h1>
    <p>{$b}</p>
  </div>
</body>
</html>
HTML;
}
