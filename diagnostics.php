<?php
/**
 * CoreFlux Diagnostics — master-admin health dashboard.
 *
 * Visit: https://<your-app>/diagnostics.php
 *
 *   - Re-runs the smoke test (encryption, OpenAI, SMTP, data key)
 *   - Shows last 20 ai_interactions rows
 *   - Shows last 20 people_emails_sent rows
 *   - Quick stat tiles (last 24h: AI calls + email sends, %success)
 *
 * Read-only — never modifies anything. Safe to bookmark.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/admin_gate.php';
require_once __DIR__ . '/core/installer_helpers.php';

$user = requireAdminForOps('diagnostics');

initSession();

$localCfg = __DIR__ . '/core/config.local.php';
$smoke = file_exists($localCfg) ? runSmokeInProcess($localCfg) : null;

// DB stats + recent rows
$pdo = null;
$err = null;
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    $err = $e->getMessage();
}

function safeQuery(?PDO $pdo, string $sql): array {
    if (!$pdo) return [];
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) { return []; }
}

$aiRecent    = safeQuery($pdo,
    "SELECT id, tenant_id, user_id, feature_key, kind, status, model, http_status, latency_ms, created_at
     FROM ai_interactions
     ORDER BY id DESC
     LIMIT 20");

$ai24h       = safeQuery($pdo,
    "SELECT status, COUNT(*) AS n, AVG(latency_ms) AS avg_ms
     FROM ai_interactions
     WHERE created_at >= NOW() - INTERVAL 1 DAY
     GROUP BY status");

$emailRecent = safeQuery($pdo,
    "SELECT id, tenant_id, kind, to_email, subject, status, smtp_message_id, error, created_at
     FROM people_emails_sent
     ORDER BY id DESC
     LIMIT 20");

$email24h    = safeQuery($pdo,
    "SELECT status, COUNT(*) AS n
     FROM people_emails_sent
     WHERE created_at >= NOW() - INTERVAL 1 DAY
     GROUP BY status");

function dh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function statusPill(?string $s): string {
    $s = $s ?: 'unknown';
    $class = ($s === 'ok' || $s === 'sent') ? 'ok' : (($s === 'error' || $s === 'failed') ? 'no' : 'mute');
    return "<span class='pill pill--$class'>" . dh($s) . "</span>";
}
function tally(array $rows, string $okKey): array {
    $ok = 0; $bad = 0;
    foreach ($rows as $r) ($r['status'] === $okKey) ? $ok += (int)$r['n'] : $bad += (int)$r['n'];
    $total = $ok + $bad;
    $pct = $total ? round(100 * $ok / $total) : null;
    return ['ok' => $ok, 'bad' => $bad, 'total' => $total, 'pct' => $pct];
}
$aiStats    = tally($ai24h, 'ok');
$emailStats = tally($email24h, 'sent');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CoreFlux — Diagnostics</title>
<style>
  * { box-sizing: border-box; }
  body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, system-ui, sans-serif;
         color: #1c1f26; background: #f6f7fb; margin: 0; padding: 32px 20px; }
  .wrap { max-width: 1080px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid #e3e6ec; border-radius: 12px;
          padding: 22px 24px; margin-bottom: 20px; box-shadow: 0 4px 16px rgba(0,0,0,.03); }
  h1 { margin: 0 0 4px 0; font-size: 22px; }
  h2 { margin: 0 0 14px 0; font-size: 15px; letter-spacing: .04em; text-transform: uppercase; color: #6b7280; }
  .muted { color: #6b7280; font-size: 13px; }
  .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
  .btn { display: inline-block; padding: 8px 14px; border-radius: 6px; border: 0; background: #5a6cff;
         color: #fff; font-weight: 600; cursor: pointer; font: inherit; text-decoration: none; }
  .btn:hover { background: #4859d6; }
  .btn--ghost { background: #fff; color: #1c1f26; border: 1px solid #d2d6de; }

  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 18px; }
  .tile { background: #fff; border: 1px solid #e3e6ec; border-radius: 10px; padding: 16px 18px; }
  .tile__label { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
  .tile__value { font-size: 22px; font-weight: 700; margin-top: 4px; }
  .tile__sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
  .tile--ok    .tile__value { color: #2ea46a; }
  .tile--mixed .tile__value { color: #b87b14; }
  .tile--bad   .tile__value { color: #c94040; }

  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eef0f4; }
  th { font-weight: 600; color: #4a5562; background: #fafbff; position: sticky; top: 0; }
  tbody tr:hover { background: #fafbff; }
  td.num { font-variant-numeric: tabular-nums; text-align: right; color: #6b7280; }
  code { background: #eef0f4; padding: 1px 6px; border-radius: 4px; font-size: 12px; }

  .row { display: flex; align-items: center; gap: 8px; padding: 6px 0; }
  .row .tick { width: 18px; }
  .row.ok .tick { color: #2ea46a; } .row.no .tick { color: #c94040; }

  .pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600;
          letter-spacing: .03em; text-transform: uppercase; }
  .pill--ok   { background: #e6f7ee; color: #2c6e4a; }
  .pill--no   { background: #fdecec; color: #9a2727; }
  .pill--mute { background: #eef0f4; color: #4a5562; }

  .empty { padding: 16px; color: #6b7280; font-style: italic; }
  .scroll { max-height: 420px; overflow: auto; border: 1px solid #eef0f4; border-radius: 6px; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar">
    <div>
      <h1>CoreFlux Diagnostics</h1>
      <p class="muted">Logged in as <strong><?= dh($user['email'] ?? 'admin') ?></strong> · server time <?= dh(date('Y-m-d H:i:s T')) ?></p>
    </div>
    <div>
      <a class="btn btn--ghost" href="/diagnostics.php">Refresh</a>
      <a class="btn btn--ghost" href="/spa.php">Open the app</a>
      <a class="btn" href="/update.php">Run an update</a>
    </div>
  </div>

  <!-- Stat tiles -->
  <div class="tiles">
    <div class="tile <?= $aiStats['total']    ? ($aiStats['bad']    ? 'tile--mixed' : 'tile--ok') : '' ?>">
      <div class="tile__label">AI calls (24h)</div>
      <div class="tile__value"><?= $aiStats['total'] ?: '—' ?></div>
      <div class="tile__sub"><?= $aiStats['pct']  !== null ? $aiStats['pct'] . '% ok'   : 'no data' ?> · <?= $aiStats['bad']    ?> errors</div>
    </div>
    <div class="tile <?= $emailStats['total'] ? ($emailStats['bad'] ? 'tile--mixed' : 'tile--ok') : '' ?>">
      <div class="tile__label">Emails sent (24h)</div>
      <div class="tile__value"><?= $emailStats['total'] ?: '—' ?></div>
      <div class="tile__sub"><?= $emailStats['pct'] !== null ? $emailStats['pct'] . '% sent' : 'no data' ?> · <?= $emailStats['bad'] ?> failed</div>
    </div>
    <div class="tile">
      <div class="tile__label">Database</div>
      <div class="tile__value" style="color:<?= $pdo ? '#2ea46a' : '#c94040' ?>;"><?= $pdo ? 'connected' : 'down' ?></div>
      <div class="tile__sub"><?= dh(DB_NAME) ?></div>
    </div>
  </div>

  <!-- Smoke test -->
  <div class="card">
    <h2>Smoke test</h2>
    <?php if ($smoke === null): ?>
      <div class="empty">No <code>core/config.local.php</code> on this server. Run <a href="/install.php">/install.php</a> first.</div>
    <?php else: foreach ($smoke as $c): ?>
      <div class="row <?= !empty($c['ok']) ? 'ok' : 'no' ?>">
        <span class="tick"><?= !empty($c['ok']) ? '✓' : '✗' ?></span>
        <strong><?= dh($c['check']) ?></strong>
        <?php if (!empty($c['detail'])): ?> — <span class="muted"><?= dh($c['detail']) ?></span><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <?php if ($err): ?>
    <div class="card">
      <h2>Database error</h2>
      <p class="muted"><?= dh($err) ?></p>
    </div>
  <?php endif; ?>

  <!-- AI interactions -->
  <div class="card">
    <h2>Recent AI interactions <span class="muted">(latest 20)</span></h2>
    <?php if (!$aiRecent): ?>
      <div class="empty">No AI interactions yet.</div>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr>
          <th>id</th><th>when</th><th>tenant</th><th>user</th><th>feature_key</th><th>kind</th>
          <th>status</th><th>model</th><th class="num">http</th><th class="num">latency</th>
        </tr></thead>
        <tbody>
        <?php foreach ($aiRecent as $r): ?>
          <tr>
            <td><?= dh((string)$r['id']) ?></td>
            <td class="muted"><?= dh($r['created_at'] ?? '') ?></td>
            <td><?= dh((string)($r['tenant_id'] ?? '')) ?></td>
            <td><?= dh((string)($r['user_id']   ?? '')) ?></td>
            <td><code><?= dh($r['feature_key']) ?></code></td>
            <td><?= dh($r['kind']) ?></td>
            <td><?= statusPill($r['status']) ?></td>
            <td><?= dh($r['model'] ?? '—') ?></td>
            <td class="num"><?= dh((string)($r['http_status'] ?? '')) ?></td>
            <td class="num"><?= dh($r['latency_ms'] !== null ? $r['latency_ms'] . ' ms' : '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Emails sent -->
  <div class="card">
    <h2>Recent emails <span class="muted">(latest 20)</span></h2>
    <?php if (!$emailRecent): ?>
      <div class="empty">No emails sent yet.</div>
    <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr>
          <th>id</th><th>when</th><th>tenant</th><th>kind</th><th>to</th><th>subject</th>
          <th>status</th><th>smtp id</th>
        </tr></thead>
        <tbody>
        <?php foreach ($emailRecent as $r): ?>
          <tr>
            <td><?= dh((string)$r['id']) ?></td>
            <td class="muted"><?= dh($r['created_at'] ?? '') ?></td>
            <td><?= dh((string)($r['tenant_id'] ?? '')) ?></td>
            <td><?= dh($r['kind']) ?></td>
            <td><?= dh($r['to_email']) ?></td>
            <td title="<?= dh($r['subject']) ?>"><?= dh(mb_strimwidth($r['subject'] ?? '', 0, 40, '…')) ?></td>
            <td><?= statusPill($r['status']) ?>
              <?php if (!empty($r['error'])): ?><div class="muted"><?= dh(mb_strimwidth($r['error'], 0, 80, '…')) ?></div><?php endif; ?>
            </td>
            <td class="muted"><?= dh(mb_strimwidth($r['smtp_message_id'] ?? '', 0, 24, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <p class="muted" style="text-align:center;">CoreFlux diagnostics · read-only · auto-refresh: never (manual)</p>
</div>
</body>
</html>
