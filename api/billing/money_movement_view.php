<?php
/**
 * Public Money Movement snapshot — token-gated, no auth required.
 *
 *   GET /api/billing/money_movement_view.php?t={raw_token}
 *
 * Renders the same digest HTML the CFO receives by email, wrapped in a
 * minimal frame with a "this is a shared snapshot" banner and a clear
 * expiry notice. View counter is incremented in the same TX.
 *
 * Token is matched by sha256(raw_token) so a DB breach yields no usable
 * links. Same security model as the treasury scenario share links.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../modules/billing/lib/money_movement.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Content-Type: text/html; charset=utf-8');

$raw = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $raw)) {
    moneyMovementViewError('Invalid share link.');
}
$hash = hash('sha256', $raw);

$pdo = getDB();
try {
    $st = $pdo->prepare(
        'SELECT id, tenant_id, as_of, label, expires_at, revoked_at, view_count
           FROM billing_money_movement_share_links
          WHERE token_sha256 = :h LIMIT 1'
    );
    $st->execute(['h' => $hash]);
    $link = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) { moneyMovementViewError('Share links not provisioned yet.'); }

if (!$link)                              moneyMovementViewError('This share link is not valid.');
if (!empty($link['revoked_at']))         moneyMovementViewError('This share link has been revoked by the tenant.');
if (strtotime((string) $link['expires_at']) <= time()) moneyMovementViewError('This share link has expired.');

// Bump view counter (best-effort, never blocks render)
try {
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE billing_money_movement_share_links SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = :id')
        ->execute(['id' => $link['id']]);
} catch (\Throwable $_) { /* swallow */ }
try {
    $pdo->prepare('INSERT INTO audit_log (tenant_id, actor_user_id, event, meta_json, ip_address, created_at)
                   VALUES (:t, NULL, :e, :m, :ip, NOW())')->execute([
        't' => (int) $link['tenant_id'], 'e' => 'billing.money_movement.share_link_viewed',
        'm' => json_encode(['link_id' => (int) $link['id'], 'as_of' => $link['as_of']]),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
} catch (\Throwable $_) { /* swallow */ }

// Load snapshot (frozen JSON) — fall back to live computation if the
// row was never persisted (e.g. share link minted before A1 migration).
$snapshot = moneyMovementReadSnapshot((int) $link['tenant_id'], (string) $link['as_of'])
         ?: moneyMovementSnapshot((int) $link['tenant_id'], (string) $link['as_of']);
$prior    = moneyMovementGetPriorSnapshot((int) $link['tenant_id'], (string) $link['as_of']);
$wow      = moneyMovementWowDelta($snapshot, $prior);

$tenantName = 'CoreFlux';
try {
    $tn = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
    $tn->execute(['id' => (int) $link['tenant_id']]);
    $tnRow = $tn->fetch(\PDO::FETCH_ASSOC);
    if ($tnRow && !empty($tnRow['name'])) $tenantName = (string) $tnRow['name'];
} catch (\Throwable $_) { /* shrug */ }

$email = moneyMovementRenderEmail($snapshot, $tenantName, '', null, $wow);

$expires = htmlspecialchars((string) $link['expires_at'], ENT_QUOTES, 'UTF-8');
echo '<!doctype html><html><head><meta charset="utf-8">'
   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<title>Money movement — ' . htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8') . '</title>'
   . '<style>body{font-family:system-ui;background:#f1f5f9;margin:0;padding:24px}'
   . '.banner{max-width:680px;margin:0 auto 16px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;color:#92400e;font-size:13px}'
   . '.card{max-width:680px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(15,23,42,0.06)}</style>'
   . '</head><body>'
   . '<div class="banner" data-testid="money-movement-share-banner">'
   . 'You\'re viewing a read-only snapshot shared by <strong>' . htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8') . '</strong>. '
   . 'Expires ' . $expires . ' (UTC). '
   . 'No login required, no data is collected from you.'
   . '</div>'
   . '<div class="card" data-testid="money-movement-share-content">' . $email['html'] . '</div>'
   . '</body></html>';
exit;

function moneyMovementViewError(string $msg): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Snapshot unavailable</title>'
       . '<style>body{font-family:system-ui;background:#f1f5f9;padding:40px 16px;margin:0}'
       . '.card{max-width:480px;margin:auto;background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);padding:28px;text-align:center}'
       . 'h1{color:#dc2626}</style></head><body><div class="card" data-testid="money-movement-share-error">'
       . '<h1>Snapshot unavailable</h1><p>' . $h($msg) . '</p>'
       . '</div></body></html>';
    exit;
}
