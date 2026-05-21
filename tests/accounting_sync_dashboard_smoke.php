<?php
/**
 * Accounting Sync Dashboard — smoke.
 *
 * Validates:
 *   - api/admin/accounting_sync_dashboard.php is shaped correctly:
 *     auth guard, RBAC require, calls qbo+zoho helpers, returns the
 *     documented unified shape.
 *   - dashboard/src/pages/AccountingSyncDashboard.jsx renders the
 *     documented testids.
 *   - AdminModule mounts /admin/integrations/accounting-sync.
 *   - IntegrationsHub surfaces a card linking to it.
 *
 * Run via: php -d zend.assertions=1 tests/accounting_sync_dashboard_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- API endpoint
echo "API — api/admin/accounting_sync_dashboard.php\n";
$apiPath = $ROOT . '/api/admin/accounting_sync_dashboard.php';
$api = file_exists($apiPath) ? (string) file_get_contents($apiPath) : '';
$a('file exists',                                $api !== '');
$a('strict types',                               $c($api, 'declare(strict_types=1);'));
$a('requires api_bootstrap',                     $c($api, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('requires qbo client',                        $c($api, "require_once __DIR__ . '/../../core/qbo/client.php'"));
$a('requires zoho_books client',                 $c($api, "require_once __DIR__ . '/../../core/zoho_books/client.php'"));
$a('requires auth',                              $c($api, 'api_require_auth('));
$a('rbac gate on integrations.qbo.view',         $c($api, "rbac_legacy_require(\$user, 'integrations.qbo.view')"));
$a('GET only',                                   $c($api, "api_method() !== 'GET'"));
$a('returns qbo block',                          $c($api, "'qbo' =>"));
$a('returns zoho_books block',                   $c($api, "'zoho_books' =>"));
$a('returns entities array',                     $c($api, "'entities'         => \$entities"));
$a('returns summary block',                      $c($api, "'summary'          => \$summary"));
$a('returns unified_activity array',             $c($api, "'unified_activity' => \$unifiedActivity"));
$a('coverage tracks both/qbo_only/zoho_only/neither',
    $c($api, "'both'") && $c($api, "'qbo_only'") && $c($api, "'zoho_only'") && $c($api, "'neither'"));
$a('drift signals declared',
    $c($api, "'aligned'") && $c($api, "'qbo_ahead'") && $c($api, "'zoho_ahead'") && $c($api, "'one_sided'") && $c($api, "'inactive'"));
$a('reads qbo sync config',                      $c($api, 'qboSyncConfigRead('));
$a('reads zoho sync config',                     $c($api, 'zohoBooksSyncConfigRead('));
$a('queries qbo_sync_audit',                     $c($api, 'FROM qbo_sync_audit'));
$a('queries zoho_books_sync_audit',              $c($api, 'FROM zoho_books_sync_audit'));
$a('caps unified activity to 30',                $c($api, 'array_slice($unifiedActivity, 0, 30)'));

// php -l
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($apiPath) . ' 2>&1', $out, $rc);
$a('php -l api/admin/accounting_sync_dashboard.php', $rc === 0);

// ----------------------------------------------------------------- UI
echo "\nUI — AccountingSyncDashboard.jsx\n";
$uiPath = $ROOT . '/dashboard/src/pages/AccountingSyncDashboard.jsx';
$ui = file_exists($uiPath) ? (string) file_get_contents($uiPath) : '';
$a('file exists',                                $ui !== '');
$a('root testid accounting-sync-dashboard',      $c($ui, 'data-testid="accounting-sync-dashboard"'));
$a('system tiles container testid',              $c($ui, 'data-testid="acct-sync-system-tiles"'));
$a('qbo system tile testid',                     $c($ui, 'testid="acct-sync-tile-qbo"'));
$a('zoho system tile testid',                    $c($ui, 'testid="acct-sync-tile-zoho"'));
$a('coverage scorecard testid',                  $c($ui, 'data-testid="acct-sync-scorecard"'));
$a('scorecard tile both',                        $c($ui, 'data-testid={`acct-sync-score-${t.key}`}'));
$a('drift table testid',                         $c($ui, 'data-testid="acct-sync-drift-table"'));
$a('entity row testid pattern',                  $c($ui, 'data-testid={`acct-sync-row-${entity.key}`}'));
$a('signal testid pattern',                      $c($ui, 'data-testid={`acct-sync-signal-${entity.key}`}'));
$a('activity section testid',                    $c($ui, 'data-testid="acct-sync-activity-section"'));
$a('activity row testid pattern',                $c($ui, 'data-testid={`acct-sync-activity-row-${r.system}-${r.id}`}'));
$a('system badge testid pattern',                $c($ui, 'data-testid={`acct-sync-system-badge-${system}`}'));
$a('fetches /api/admin/accounting_sync_dashboard',$c($ui, '/api/admin/accounting_sync_dashboard.php'));
$a('links to QBO settings',                      $c($ui, 'settingsHref="/admin/integrations/qbo"'));
$a('links to Zoho settings',                     $c($ui, 'settingsHref="/admin/integrations/zoho-books"'));

// ----------------------------------------------------------------- AdminModule wiring
echo "\nUI — AdminModule + IntegrationsHub wiring\n";
$ad = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('imports AccountingSyncDashboard',            $c($ad, "import AccountingSyncDashboard from './AccountingSyncDashboard'"));
$a('mounts /admin/integrations/accounting-sync',
    $c($ad, '<Route path="/integrations/accounting-sync" element={<AccountingSyncDashboard session={session} />} />'));

$hub = (string) file_get_contents($ROOT . '/dashboard/src/pages/IntegrationsHub.jsx');
$a('hub adds accounting-sync card testid',       $c($hub, 'testid="integration-card-accounting-sync"'));
$a('hub links to /admin/integrations/accounting-sync',
    $c($hub, 'href="/admin/integrations/accounting-sync"'));

echo "\n=========================================\n";
echo "Accounting Sync Dashboard smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
