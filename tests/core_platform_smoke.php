<?php
/**
 * Smoke test for core platform primitives.
 * Run: php /app/tests/core_platform_smoke.php
 */

// Require assertions to be enabled (CLI: -d zend.assertions=1)
if ((int) ini_get('zend.assertions') < 1) {
    fwrite(STDERR, "Run with: php -d zend.assertions=1 -d assert.exception=1 " . __FILE__ . "\n");
    exit(2);
}
ini_set('assert.exception', '1');
error_reporting(E_ALL & ~E_WARNING); // suppress PDO-driver-missing noise in CLI
ob_start();                          // let api_bootstrap set headers without warnings

// Start the session BEFORE populating it; otherwise session_start() inside
// helpers will overwrite our preset $_SESSION.
session_start();
$_SESSION['user']      = ['id' => 1, 'email' => 'k@c.app', 'role' => 'admin',
                          'tenants' => [['id' => 7, 'name' => 'Acme']]];
$_SESSION['tenant_id'] = 7;
$_SESSION['modules']   = [];

require_once __DIR__ . '/../core/tenant_scope.php';

$tid = currentTenantId();
assert($tid === 7, "currentTenantId should return 7, got: " . var_export($tid, true));
echo "[ok] currentTenantId() = $tid\n";

// _safeIdent must reject bad names
$rejected = false;
try { _safeIdent('drop;--'); } catch (InvalidArgumentException $e) { $rejected = true; }
assert($rejected === true, "_safeIdent did not reject injection");
echo "[ok] _safeIdent rejects injection\n";
assert(_safeIdent('payroll_employees') === 'payroll_employees');
echo "[ok] _safeIdent accepts valid name\n";

// api_bootstrap helpers registered
require_once __DIR__ . '/../core/api_bootstrap.php';
foreach (['api_ok','api_error','api_method','api_json_body','api_require_auth',
          'api_require_role','api_require_fields','api_query'] as $fn) {
    assert(function_exists($fn), "missing helper: $fn");
}
echo "[ok] api_bootstrap helpers registered\n";

// Manifest template shape
$manifest = require __DIR__ . '/../modules/_template/manifest.php';
foreach (['id','name','icon','description','actions','permissions','default_roles'] as $k) {
    assert(array_key_exists($k, $manifest), "manifest missing key: $k");
}
assert(is_array($manifest['actions']) && count($manifest['actions']) > 0);
echo "[ok] template manifest shape valid\n";

ob_end_clean();
echo "\nAll smoke checks passed.\n";
