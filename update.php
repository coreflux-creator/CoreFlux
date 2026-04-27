<?php
/**
 * CoreFlux Web Updater (one-click routine deploys)
 *
 * Visit: https://<your-app>/update.php
 *
 *   - Logged-in master admin only
 *   - Click "Update now" → server runs `git pull`, applies pending migrations,
 *     runs the smoke test, shows results.
 *
 * Requires that the web server's user can `git pull` (a configured PAT or SSH
 * key on the host). If the pull fails, the page shows the git error and stops
 * — no migrations are applied.
 */

declare(strict_types=1);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/admin_gate.php';
require_once __DIR__ . '/core/installer_helpers.php';

$user = requireAdminForOps('update');

initSession();

$user = $user ?? null;
// Already authed by requireAdminForOps('update') above.

$result = null;
$error  = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    try {
        $result = runUpdate();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function runUpdate(): array {
    $root = __DIR__;
    $log = ['steps' => []];

    // 1. git pull — only if shell exec is available on this host. Cloudways and
    //    most managed PHP hosts disable exec()/shell_exec() via disable_functions.
    //    In that case the user is expected to deploy code via the host's UI
    //    (e.g. Cloudways → Application → Deploy via Git) BEFORE clicking Update.
    if (_canExec()) {
        $cmd = sprintf('cd %s && git pull --ff-only origin main 2>&1', escapeshellarg($root));
        $out = []; $rc = 1;
        @exec($cmd, $out, $rc);
        $log['steps'][] = [
            'name'   => 'git pull origin main',
            'ok'     => $rc === 0,
            'output' => implode("\n", $out),
        ];
        if ($rc !== 0) return $log;
    } else {
        $log['steps'][] = [
            'name'   => 'git pull origin main',
            'ok'     => true,
            'detail' => 'skipped — exec() disabled on this host. Pull the latest code from your host control panel (e.g. Cloudways → Application → Deploy via Git) before clicking Update.',
        ];
    }

    // 2. Apply pending migrations
    $log['steps'][] = ['name' => 'apply pending migrations', 'ok' => true, 'list' => runMigrationsInProcess()];

    // 3. Smoke test
    $localCfg = $root . '/core/config.local.php';
    if (!file_exists($localCfg)) {
        $log['steps'][] = ['name' => 'smoke test', 'ok' => false, 'detail' => 'core/config.local.php missing — run /install.php first'];
        return $log;
    }
    $log['steps'][] = ['name' => 'smoke test', 'ok' => true, 'list' => runSmokeInProcess($localCfg)];

    return $log;
}

/**
 * True only if exec() is callable on this host. Cloudways, many shared hosts,
 * and some managed PHP environments disable shell exec via php.ini's
 * `disable_functions` for security.
 */
function _canExec(): bool {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function uh(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CoreFlux — Update</title>
<style>
  * { box-sizing: border-box; }
  body { font: 14px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Inter, system-ui, sans-serif;
         color: #1c1f26; background: #f6f7fb; margin: 0; padding: 40px 20px; }
  .wrap { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #e3e6ec;
          border-radius: 12px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,.04); }
  h1 { margin: 0 0 4px 0; font-size: 22px; }
  .muted { color: #6b7280; font-size: 13px; }
  .btn { display: inline-block; padding: 10px 18px; border-radius: 6px; border: 0;
         background: #5a6cff; color: #fff; font-weight: 600; cursor: pointer; font: inherit; }
  .btn:hover { background: #4859d6; }
  .btn--ghost { background: #fff; color: #1c1f26; border: 1px solid #d2d6de; }
  .alert { padding: 10px 14px; border-radius: 6px; margin: 16px 0; font-size: 13px; }
  .alert--err { background: #fdf2f2; color: #9a2727; border: 1px solid #f3c0c0; }
  .alert--ok  { background: #f1faf3; color: #2c6e4a; border: 1px solid #b6d8c2; }
  pre { background: #0f111a; color: #e6e7ee; padding: 12px; border-radius: 6px;
        font-size: 12px; overflow-x: auto; max-height: 280px; }
  .row { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 13px; }
  .tick { width: 18px; }
  .row.ok .tick { color: #2ea46a; } .row.no .tick { color: #c94040; }
  hr { border: 0; border-top: 1px solid #eef0f4; margin: 24px 0; }
</style>
</head>
<body>
<div class="wrap">
  <h1>CoreFlux Update</h1>
  <p class="muted">Logged in as <strong><?= uh($user['email'] ?? 'admin') ?></strong></p>

  <?php if ($result): ?>
    <?php $allOk = !array_filter($result['steps'], fn($s) => empty($s['ok'])); ?>
    <div class="alert <?= $allOk ? 'alert--ok' : 'alert--err' ?>">
      <?= $allOk ? 'Update complete.' : 'Update finished with errors — see below.' ?>
    </div>

    <?php foreach ($result['steps'] as $s): ?>
      <div class="row <?= $s['ok'] ? 'ok' : 'no' ?>">
        <span class="tick"><?= $s['ok'] ? '✓' : '✗' ?></span>
        <strong><?= uh($s['name']) ?></strong>
        <?php if (!empty($s['detail'])): ?> — <span class="muted"><?= uh($s['detail']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($s['output'])): ?>
        <pre><?= uh($s['output']) ?></pre>
      <?php endif; ?>
      <?php if (!empty($s['list'])): ?>
        <ul class="muted">
          <?php foreach ($s['list'] as $item): ?>
            <li>
              <?php if (isset($item['file'])): ?>
                <code><?= uh($item['file']) ?></code> — <?= uh($item['status']) ?>
              <?php elseif (isset($item['check'])): ?>
                <strong><?= uh($item['check']) ?></strong> — <?= !empty($item['ok']) ? '✓' : '✗' ?>
                <?php if (!empty($item['detail'])): ?> · <?= uh($item['detail']) ?><?php endif; ?>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endforeach; ?>

    <hr>
    <p>
      <a class="btn" href="/spa.php">Open the app</a>
      <a class="btn btn--ghost" href="/update.php">Run another update</a>
      <a class="btn btn--ghost" href="/diagnostics.php">Diagnostics</a>
    </p>

  <?php else: ?>
    <p>One click pulls the latest code, applies any new migrations, and verifies everything is healthy.</p>

    <?php if ($error): ?><div class="alert alert--err"><?= uh($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="action" value="update">
      <p style="margin-top:24px;">
        <button class="btn" type="submit" data-testid="update-submit">Update now</button>
        <a class="btn btn--ghost" href="/spa.php">Cancel</a>
      </p>
    </form>

    <p class="muted" style="margin-top:24px;">
      The update runs as the web server's user. If <code>git pull</code> fails with an authentication error,
      configure a GitHub Personal Access Token on this host once and the button will work after.
    </p>
  <?php endif; ?>
</div>
</body>
</html>
