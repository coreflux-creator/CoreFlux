<?php
/**
 * QuickBooks Online — Slice 4a (COA mirror + Sync health tile) smoke.
 *
 * Validates:
 *   - core/qbo/sync_accounts.php exposes qboSyncAccounts() with proper
 *     three-tier match (mapping → AcctNum → unmapped audit)
 *   - api/qbo.php dispatches `sync_accounts` and `sync_health` with the
 *     documented response shape
 *   - cron/qbo_sync_inbound.php includes the COA pass
 *   - dashboard/src/components/QboSyncHealthTile.jsx renders the
 *     documented testids and is mounted on CFODashboard.jsx
 *   - dashboard/src/pages/QboSettings.jsx exposes the Pull COA button
 *     gated on chart_of_accounts ∈ {pull, two_way}
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- COA driver
echo "core/qbo/sync_accounts.php — public surface\n";
$path = $ROOT . '/core/qbo/sync_accounts.php';
$src = (string) file_get_contents($path);
$a('file exists',                                $src !== '');
$a('strict types',                               $c($src, 'declare(strict_types=1);'));
$a('declares qboSyncAccounts()',                 $c($src, 'function qboSyncAccounts'));
$a('refuses when chart_of_accounts off',         $c($src, "['pull', 'two_way']"));
$a('paginates via STARTPOSITION + MAXRESULTS',   $c($src, 'STARTPOSITION') && $c($src, 'MAXRESULTS'));
$a('match tier 1: existing mapping',             $c($src, 'mappingFindInternal'));
$a('match tier 2: AcctNum → CoreFlux code',      $c($src, 'AcctNum') && $c($src, 'isset($byCode[$acctNum])'));
$a('match tier 3: audits unmapped_qbo_accounts', $c($src, "'unmapped_qbo_accounts'"));
$a('records up to 100 unmapped samples',         $c($src, 'count($unmappedSamples) < 100'));
$a('writes summary audit row sync_accounts',     $c($src, "qboAudit") && $c($src, "'sync_accounts'"));

// ----------------------------------------------------------------- API: sync_accounts + sync_health
echo "\napi/qbo.php — Slice 4a dispatch\n";
$api = (string) file_get_contents($ROOT . '/api/qbo.php');
$a('requires sync_accounts.php',                 $c($api, "require_once __DIR__ . '/../core/qbo/sync_accounts.php'"));
$a('handles sync_accounts',                      $c($api, "case 'sync_accounts'"));
$a('handles sync_health',                        $c($api, "case 'sync_health'"));
$a('sync_health requires view RBAC',             $c($api, "rbac_legacy_require(\$user, 'integrations.qbo.view')"));
$a('sync_health: not_connected branch',          $c($api, "'not_connected'"));
$a('sync_health: counts blocked_jes_7d',         $c($api, "action = 'sync_je_skip'") && $c($api, 'INTERVAL 7 DAY'));
$a('sync_health: counts failed_runs_24h',        $c($api, 'INTERVAL 24 HOUR'));
$a('sync_health: red on > 24h probe stale',
    $c($api, "probe stale > 24h"));
$a('sync_health: yellow on > 2h probe stale',
    $c($api, "probe stale > 2h"));
$a('sync_health: returns status + reasons',      $c($api, "'reasons'"));
$a('shim sync_accounts.php present',             file_exists($ROOT . '/api/qbo/sync_accounts.php'));
$a('shim sync_health.php present',               file_exists($ROOT . '/api/qbo/sync_health.php'));

// ----------------------------------------------------------------- Syntax
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/qbo/sync_accounts.php',
    'api/qbo.php',
    'api/qbo/sync_accounts.php',
    'api/qbo/sync_health.php',
    'cron/qbo_sync_inbound.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- Cron
echo "\ncron/qbo_sync_inbound.php — COA pass\n";
$cron = (string) file_get_contents($ROOT . '/cron/qbo_sync_inbound.php');
$a('requires sync_accounts.php',                 $c($cron, "require_once __DIR__ . '/../core/qbo/sync_accounts.php'"));
$a('runs COA pass per tenant',                   $c($cron, 'qboSyncAccounts') && $c($cron, "in_array(\$cfg['chart_of_accounts'] ?? 'off'"));

// ----------------------------------------------------------------- UI: Sync health tile
echo "\nUI — QboSyncHealthTile.jsx\n";
$tilePath = $ROOT . '/dashboard/src/components/QboSyncHealthTile.jsx';
$tile = (string) file_get_contents($tilePath);
$a('file exists',                                $tile !== '');
$a('tile testid',                                $c($tile, 'data-testid="cfo-qbo-sync-health-tile"'));
$a('summary testid',                             $c($tile, 'data-testid="cfo-qbo-sync-summary"'));
$a('manage link testid',                         $c($tile, 'data-testid="cfo-qbo-sync-manage-link"'));
$a('blocked-jes stat testid',                    $c($tile, 'testid="cfo-qbo-blocked-jes"'));
$a('failed-runs stat testid',                    $c($tile, 'testid="cfo-qbo-failed-runs"'));
$a('probe-age stat testid',                      $c($tile, 'testid="cfo-qbo-probe-age"'));
$a('reasons list testid',                        $c($tile, 'data-testid="cfo-qbo-sync-reasons"'));
$a('hits /api/qbo/sync_health',                  $c($tile, '/api/qbo/sync_health.php?action=sync_health'));
$a('green/yellow/red/not_connected meta',
    $c($tile, 'green') && $c($tile, 'yellow') && $c($tile, 'red') && $c($tile, 'not_connected'));
$a('links Manage to /admin/integrations/qbo',    $c($tile, '/admin/integrations/qbo'));

// ----------------------------------------------------------------- UI: CFO Dashboard wiring
echo "\nUI — CFODashboard.jsx wiring\n";
$cfo = (string) file_get_contents($ROOT . '/dashboard/src/pages/CFODashboard.jsx');
$a('imports QboSyncHealthTile',                  $c($cfo, "import QboSyncHealthTile from '../components/QboSyncHealthTile'"));
$a('mounts <QboSyncHealthTile />',               $c($cfo, '<QboSyncHealthTile />'));

// ----------------------------------------------------------------- UI: QboSettings COA button
echo "\nUI — QboSettings.jsx COA button\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/QboSettings.jsx');
$a('pull accounts button testid',                $c($ui, 'data-testid="qbo-sync-accounts-btn"'));
$a('button conditional on chart_of_accounts dir',
    $c($ui, "['pull', 'two_way'].includes(coaDir)"));

echo "\n=========================================\n";
echo "QBO Slice 4a smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
