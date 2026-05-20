<?php
/**
 * RBAC B4 bridge-health smoke — verifies the disagreement audit pipeline:
 *   - migration 056 (rbac_bridge_audit) shape
 *   - bridge legacy_map.php records disagreements via rbac_bridge_record_disagreement()
 *   - /api/admin/rbac_bridge_health.php contract
 *   - React panel wiring (AdminOverview + RbacMembershipsAdmin)
 *
 *   php -d zend.assertions=1 /app/tests/rbac_b4_bridge_health_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration 056
echo "Migration 056 — rbac_bridge_audit\n";
$migPath = $ROOT . '/core/migrations/056_rbac_bridge_audit.sql';
$mig = (string) file_get_contents($migPath);
$a('file present',                                $mig !== '');
$a('CREATE TABLE IF NOT EXISTS rbac_bridge_audit',$c($mig, 'CREATE TABLE IF NOT EXISTS rbac_bridge_audit'));
$a('tenant_id column',                            $c($mig, 'tenant_id     INT UNSIGNED NULL'));
$a('user_id column',                              $c($mig, 'user_id       INT UNSIGNED NULL'));
$a('perm column',                                 $c($mig, 'perm          VARCHAR(120)'));
$a('module_key column',                           $c($mig, 'module_key    VARCHAR(60)'));
$a('action column',                               $c($mig, 'action        VARCHAR(20)'));
$a('legacy_ok + new_ok flags',
    $c($mig, 'legacy_ok     TINYINT(1)') && $c($mig, 'new_ok        TINYINT(1)'));
$a('occurred_at default CURRENT_TIMESTAMP',       $c($mig, 'DEFAULT CURRENT_TIMESTAMP'));
$a('index on occurred_at',                        $c($mig, 'KEY ix_rba_occurred'));
$a('index on perm+occurred_at',                   $c($mig, 'KEY ix_rba_perm     (perm, occurred_at)'));
$a('index on tenant_id+occurred_at',              $c($mig, 'KEY ix_rba_tenant   (tenant_id, occurred_at)'));

// ----------------------------------------------------------------- legacy_map writer
echo "\nlegacy_map.php disagreement writer\n";
$bridge = (string) file_get_contents($ROOT . '/core/rbac/legacy_map.php');
$a('defines rbac_bridge_record_disagreement()',   $c($bridge, 'function rbac_bridge_record_disagreement('));
$a('only invoked when legacy != new',             $c($bridge, '$legacyOk !== $newOk'));
$a('writer INSERTs into rbac_bridge_audit',       $c($bridge, 'INSERT INTO rbac_bridge_audit'));
$a('writer never throws (try/catch + silent)',    $c($bridge, '// intentional: never bubble an audit failure'));
$a('writer guarded on getDB() existence',         $c($bridge, "function_exists('getDB')"));
$a('writer captures user_id from $user array',    $c($bridge, "isset(\$user['id']) ? (int) \$user['id']"));

// ----------------------------------------------------------------- /api/admin/rbac_bridge_health.php
echo "\n/api/admin/rbac_bridge_health.php contract\n";
$epPath = $ROOT . '/api/admin/rbac_bridge_health.php';
$ep = (string) file_get_contents($epPath);
$a('file present',                                $ep !== '');
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($epPath) . ' 2>&1', $o, $rc);
$a('php -l clean',                                $rc === 0);
$a('requires api_bootstrap',                      $c($ep, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('GET-only',                                    $c($ep, "api_method() !== 'GET'"));
$a('admin gate',                                  $c($ep, "in_array(\$role, ['master_admin', 'tenant_admin']") && $c($ep, '$isGlobalAdmin'));
$a('handles missing migration (configured:false)',$c($ep, "'configured'          => false"));
$a('window clamped 1..168 hours',                 $c($ep, 'max(1, min(168,'));
$a('returns total_disagreements',                 $c($ep, "'total_disagreements'"));
$a('returns legacy_only_grants',                  $c($ep, "'legacy_only_grants'"));
$a('returns new_only_grants',                     $c($ep, "'new_only_grants'"));
$a('top_perms aggregated by perm+verdict',
    $c($ep, 'GROUP BY perm, module_key, action, legacy_ok, new_ok'));
$a('top_perms limited to 10',                     $c($ep, 'LIMIT 10'));
$a('recent samples limited to 20',                $c($ep, 'LIMIT 20'));
$a('uses INTERVAL :h HOUR',                       $c($ep, 'INTERVAL :h HOUR'));

// ----------------------------------------------------------------- React panel
echo "\nRbacBridgeHealthPanel.jsx\n";
$panelPath = $ROOT . '/dashboard/src/pages/RbacBridgeHealthPanel.jsx';
$panel = (string) file_get_contents($panelPath);
$a('file present',                                $panel !== '');
$a('calls /api/admin/rbac_bridge_health.php',     $c($panel, '/api/admin/rbac_bridge_health.php'));
$a('passes window_hours query param',             $c($panel, 'window_hours='));
$a('renders total disagreements',                 $c($panel, 'data-testid="rbac-bridge-health-total"'));
$a('renders legacy-only counter',                 $c($panel, 'data-testid="rbac-bridge-legacy-only"'));
$a('renders new-only counter',                    $c($panel, 'data-testid="rbac-bridge-new-only"'));
$a('renders green-light banner when healthy',     $c($panel, 'data-testid="rbac-bridge-health-green"'));
$a('renders top-perms table when not healthy',    $c($panel, 'data-testid="rbac-bridge-top-perms"'));
$a('handles unconfigured (no migration)',         $c($panel, 'data-testid="rbac-bridge-health-unconfigured"'));
$a('error state surfaced',                        $c($panel, 'data-testid="rbac-bridge-health-error"'));
$a('refresh button has testid',                   $c($panel, 'data-testid="rbac-bridge-health-refresh"'));

// ----------------------------------------------------------------- wiring
echo "\nAdminModule + MembershipsAdmin wiring\n";
$admin = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$mems  = (string) file_get_contents($ROOT . '/dashboard/src/pages/RbacMembershipsAdmin.jsx');
$a('AdminModule imports RbacBridgeHealthPanel',   $c($admin, "import RbacBridgeHealthPanel from './RbacBridgeHealthPanel'"));
$a('AdminOverview embeds bridge health panel',    $c($admin, '<RbacBridgeHealthPanel'));
$a('MembershipsAdmin imports the panel',          $c($mems,  "import RbacBridgeHealthPanel from './RbacBridgeHealthPanel'"));
$a('MembershipsAdmin embeds the panel',           $c($mems,  '<RbacBridgeHealthPanel'));

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "RBAC B4 bridge-health smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
