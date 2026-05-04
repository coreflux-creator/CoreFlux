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
    if (installerCanExec()) {
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

    // 1b. Sentinel-file deploy-staleness check.
    //     /app/.deploy-version lists the files that MUST exist for the
    //     server to be considered on HEAD. If any are missing, the host
    //     is on an older commit than git — the staleness check below
    //     would be a false ✓ otherwise (because everything's stale
    //     together). We surface this loudly with a red ✗ so the user
    //     immediately sees that Cloudways' git deploy hasn't run.
    $log['steps'][] = _deployVersionCheck($root);

    // 2. Apply pending migrations
    $log['steps'][] = ['name' => 'apply pending migrations', 'ok' => true, 'list' => runMigrationsInProcess()];

    // 2b. Refresh SPA bundle mtimes so freshness check is deterministic.
    //     After `git pull`, source + bundle files all get "now" as their
    //     mtime in an unpredictable order — if git wrote any `.jsx` file
    //     after writing the `index-*.js` bundle, the staleness heuristic
    //     would flag a false positive even though they came from the SAME
    //     commit. `touch()`-ing spa-assets files AFTER migrations guarantees
    //     the bundle mtime is always >= any source file mtime at this point.
    //
    //     We ALSO prune stale bundle siblings: when Vite produces a new
    //     content-hashed `index-XXX.js`, the OLD `index-YYY.js` from the
    //     previous build keeps sitting there. Without pruning, spa.php
    //     could still pick the wrong sibling, and we'd get the
    //     "deploy looks like it did nothing" symptom even after the new
    //     bundle was successfully pulled. The newest .js wins; older .js
    //     siblings are deleted (same for .css).
    $assetsDir = $root . '/spa-assets';
    $pruned = [];

    // Discover the expected bundle from .deploy-version so we can prune ANY
    // bundle that doesn't match (even when there's only one — the partial-
    // deploy failure mode where a stale solo bundle is the only file left).
    $expectedBundles = [];
    $stampFile = $root . '/.deploy-version';
    if (is_file($stampFile)) {
        $stamp = (string) file_get_contents($stampFile);
        if (preg_match('/expected_bundle:\s*\n((?:\s*-[^\n]*\n)+)/', $stamp, $bm)) {
            foreach (preg_split('/\r?\n/', trim($bm[1])) as $line) {
                $rel = trim((string) preg_replace('/^\s*-\s*/', '', $line));
                if ($rel !== '' && str_starts_with($rel, 'spa-assets/')) {
                    $expectedBundles[basename($rel)] = true;
                }
            }
        }
    }

    if (is_dir($assetsDir)) {
        $jsList = $cssList = [];
        foreach (scandir($assetsDir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $assetsDir . '/' . $f;
            @touch($path);
            if (preg_match('/^index-.*\.js$/',  $f)) $jsList[$f]  = filemtime($path);
            if (preg_match('/^index-.*\.css$/', $f)) $cssList[$f] = filemtime($path);
        }

        // Pass 1: if we have an expected bundle list, drop ANY file not in
        // that list — but only when the expected file IS actually present.
        // (If the expected bundle is missing, we keep the stale one rather
        // than leaving the SPA serving a 404 — at least it boots.)
        if ($expectedBundles) {
            $expectedJsPresent  = false;
            $expectedCssPresent = false;
            foreach ($expectedBundles as $name => $_) {
                if (str_ends_with($name, '.js')  && isset($jsList[$name]))  $expectedJsPresent  = true;
                if (str_ends_with($name, '.css') && isset($cssList[$name])) $expectedCssPresent = true;
            }
            if ($expectedJsPresent) {
                foreach ($jsList as $name => $_) {
                    if (!isset($expectedBundles[$name]) && @unlink($assetsDir . '/' . $name)) $pruned[] = $name;
                }
            }
            if ($expectedCssPresent) {
                foreach ($cssList as $name => $_) {
                    if (!isset($expectedBundles[$name]) && @unlink($assetsDir . '/' . $name)) $pruned[] = $name;
                }
            }
            // Refresh lists after deletion for pass 2.
            $jsList  = array_intersect_key($jsList,  array_flip(array_filter(array_keys($jsList),  fn($n) => file_exists($assetsDir . '/' . $n))));
            $cssList = array_intersect_key($cssList, array_flip(array_filter(array_keys($cssList), fn($n) => file_exists($assetsDir . '/' . $n))));
        }

        // Pass 2: classic newest-mtime keep (covers cases where multiple
        // expected/unrelated bundles linger after Vite rebuilds).
        $keepNewest = static function (array $list) use ($assetsDir, &$pruned): void {
            if (count($list) <= 1) return;
            arsort($list);                       // newest mtime first
            $newest = array_key_first($list);
            foreach ($list as $name => $m) {
                if ($name === $newest) continue;
                if (@unlink($assetsDir . '/' . $name)) $pruned[] = $name;
            }
        };
        $keepNewest($jsList);
        $keepNewest($cssList);
    }
    $log['steps'][] = [
        'name'   => 'prune stale spa-assets siblings',
        'ok'     => true,
        'detail' => $pruned ? ('removed: ' . implode(', ', $pruned)) : 'no stale bundle siblings found',
    ];

    // 3. SPA bundle check — the React UI in /spa-assets/ is built ahead of time
    //    and committed via git. If it's missing or older than the latest source
    //    in /modules or /dashboard/src, the browser will keep serving stale UI
    //    even though PHP + DB updated correctly. Surface that mismatch loudly.
    $log['steps'][] = ['name' => 'SPA bundle', 'ok' => true, 'list' => spaBundleStatus($root)];

    // 4. Smoke test
    $localCfg = $root . '/core/config.local.php';
    if (!file_exists($localCfg)) {
        $log['steps'][] = ['name' => 'smoke test', 'ok' => false, 'detail' => 'core/config.local.php missing — run /install.php first'];
        return $log;
    }
    $log['steps'][] = ['name' => 'smoke test', 'ok' => true, 'list' => runSmokeInProcess($localCfg)];

    // 5. Plaid: health check + push the canonical webhook URL to every linked
    //    Item so a domain change (or first-time setup) doesn't require any
    //    manual work in the Plaid Dashboard. Best-effort: never blocks deploy.
    if (file_exists($root . '/core/plaid_service.php')) {
        require_once $root . '/core/plaid_service.php';
        if (function_exists('plaidConfigured') && plaidConfigured()) {
            // 5a. Probe each product against the live Plaid env.
            try {
                $health = plaidProductsHealthCheck(['auth','transactions','identity']);
                $bits = []; $allOk = true;
                foreach ($health['products'] as $product => $info) {
                    if (!empty($info['enabled'])) {
                        $bits[] = $product . '=ENABLED';
                    } else {
                        $allOk = false;
                        $hint  = !empty($info['request_url'])
                            ? ' → request at ' . $info['request_url']
                            : '';
                        $bits[] = $product . '=DISABLED (' . ($info['error'] ?? 'unknown') . ')' . $hint;
                    }
                }
                $log['steps'][] = [
                    'name'   => sprintf('Plaid product health (env=%s)', $health['env']),
                    'ok'     => $allOk,
                    'detail' => implode(' | ', $bits),
                ];
            } catch (\Throwable $e) {
                $log['steps'][] = [
                    'name'   => 'Plaid product health',
                    'ok'     => false,
                    'detail' => 'check failed: ' . $e->getMessage(),
                ];
            }

            // 5b. Push canonical webhook URL to every linked item.
            try {
                $sync = plaidSyncAllItemWebhooks();
                $detail = sprintf(
                    'webhook=%s — checked %d, updated %d, skipped %d, failed %d%s',
                    (string) ($sync['webhook_url'] ?? '?'),
                    (int) $sync['checked'], (int) $sync['updated'],
                    (int) $sync['skipped'], (int) $sync['failed'],
                    !empty($sync['errors']) ? ' [errors: ' . implode('; ', array_slice($sync['errors'], 0, 3)) . ']' : ''
                );
                $log['steps'][] = [
                    'name'   => 'sync Plaid webhook URL to all linked Items',
                    'ok'     => $sync['failed'] === 0,
                    'detail' => $detail,
                ];
            } catch (\Throwable $e) {
                $log['steps'][] = [
                    'name'   => 'sync Plaid webhook URL to all linked Items',
                    'ok'     => true,    // soft-fail (deploy continues)
                    'detail' => 'soft-skip — ' . $e->getMessage(),
                ];
            }
        } else {
            $log['steps'][] = [
                'name'   => 'sync Plaid webhook URL to all linked Items',
                'ok'     => true,
                'detail' => 'skipped — Plaid not configured (PLAID_CLIENT_ID / PLAID_SECRET_* not set)',
            ];
        }
    }

    // 6. Payroll cycle sweep — advance any active cycle whose newest period
    //    has ended on/before today, generating the next pay_period + draft
    //    run automatically. Idempotent + best-effort (never blocks deploy).
    if (file_exists($root . '/modules/payroll/lib/cycles.php')) {
        try {
            require_once $root . '/modules/payroll/lib/cycles.php';
            $sweep = payrollCycleAutoAdvanceAll();
            $advanced = 0; $notDue = 0; $errors = 0;
            foreach ($sweep as $r) {
                if ($r['status'] === 'advanced') $advanced++;
                elseif ($r['status'] === 'error') $errors++;
                else $notDue++;
            }
            $log['steps'][] = [
                'name'   => 'payroll cycles auto-advance',
                'ok'     => $errors === 0,
                'detail' => sprintf('advanced=%d, not_due=%d, errors=%d', $advanced, $notDue, $errors),
            ];
        } catch (\Throwable $e) {
            $log['steps'][] = [
                'name'   => 'payroll cycles auto-advance',
                'ok'     => true,    // soft-fail
                'detail' => 'soft-skip — ' . $e->getMessage(),
            ];
        }
    }

    return $log;
}

/**
 * Read /app/.deploy-version and verify every "sentinel" file exists on
 * disk AND that the expected bundle (the only `.js` shipped at HEAD) is
 * present. Surfaces a red ✗ if anything is missing so the user can't
 * miss a partial Cloudways deploy where the bundle silently failed to
 * transfer (very real failure mode — happened on this app's first deploy).
 */
function _deployVersionCheck(string $root): array
{
    $stampFile = $root . '/.deploy-version';
    if (!is_file($stampFile)) {
        return [
            'name'   => 'deploy version stamp',
            'ok'     => true,
            'detail' => 'no .deploy-version file (older deploy) — skip',
        ];
    }
    $stamp = (string) file_get_contents($stampFile);
    if (!preg_match('/sentinels:\s*\n((?:\s*-[^\n]*\n)+)/', $stamp, $m)) {
        return [
            'name'   => 'deploy version stamp',
            'ok'     => true,
            'detail' => '.deploy-version present but no sentinels block — skip',
        ];
    }
    $missing = [];
    foreach (preg_split('/\r?\n/', trim($m[1])) as $line) {
        $rel = trim((string) preg_replace('/^\s*-\s*/', '', $line));
        if ($rel === '') continue;
        if (!file_exists($root . '/' . $rel)) $missing[] = $rel;
    }
    // Also check for the expected bundle (the JS file specifically — it's
    // the file that's failed to transfer in the past while the .css of the
    // same commit succeeded).
    if (preg_match('/expected_bundle:\s*\n((?:\s*-[^\n]*\n)+)/', $stamp, $bm)) {
        foreach (preg_split('/\r?\n/', trim($bm[1])) as $line) {
            $rel = trim((string) preg_replace('/^\s*-\s*/', '', $line));
            if ($rel === '') continue;
            if (!file_exists($root . '/' . $rel)) $missing[] = $rel . ' (expected bundle file)';
        }
    }
    if (!$missing) {
        return [
            'name'   => 'deploy version stamp',
            'ok'     => true,
            'detail' => 'all sentinel files + expected bundle present — host is on or newer than this stamp',
        ];
    }
    return [
        'name'   => 'deploy version stamp',
        'ok'     => false,
        'detail' => 'STALE OR PARTIAL DEPLOY — Cloudways did not deliver the latest commit cleanly. ' .
                    count($missing) . ' file(s) missing on disk: '
                    . implode(', ', array_slice($missing, 0, 5))
                    . (count($missing) > 5 ? ' …(+' . (count($missing) - 5) . ' more)' : '')
                    . '. Fix: re-run Cloudways → Deployment via Git → Pull Latest. '
                    . 'If the same file fails twice, the bundle likely exceeded a per-file transfer threshold — '
                    . 'upload it manually via SFTP or File Manager.',
    ];
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
