<?php
/**
 * RBAC B2 smoke — verifies the new RBACResolver class, its method
 * surface, the api_bootstrap wiring, the auth.php persona helpers, and
 * the absence of a class-name collision against the legacy /core/RBAC.php.
 *
 * No live DB is required: the resolver's DB calls are wrapped in
 * try/catch returning null/false, so we exercise the no-DB paths.
 *
 *   php -d zend.assertions=1 /app/tests/rbac_b2_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- file presence
echo "RBACResolver file + class\n";
$permPath = $ROOT . '/core/rbac/permissions.php';
$legacyPath = $ROOT . '/core/RBAC.php';
$permSrc   = (string) file_get_contents($permPath);
$legacySrc = (string) file_get_contents($legacyPath);

$a('permissions.php exists',                              $permSrc !== '');
$a('legacy RBAC.php still present',                       $legacySrc !== '');
$a('new file declares final class RBACResolver',          $c($permSrc, 'final class RBACResolver'));
$a('new file does NOT redeclare class RBAC',              !preg_match('/\bclass\s+RBAC\b\s*\{/', $permSrc));
$a('legacy file still declares class RBAC',               $c($legacySrc, 'class RBAC {') || preg_match('/\bclass\s+RBAC\b/', $legacySrc) === 1);

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity\n";
foreach ([
    '/core/rbac/permissions.php',
    '/core/RBAC.php',
    '/core/api_bootstrap.php',
    '/core/auth.php',
] as $rel) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . $rel) . ' 2>&1', $o, $rc);
    $a("php -l {$rel}", $rc === 0);
}

// ----------------------------------------------------------------- load both classes side-by-side
echo "\nDual-load (no redeclaration fatal)\n";
require_once $legacyPath;
require_once $permPath;
$a('class RBAC exists after load',                        class_exists('RBAC'));
$a('class RBACResolver exists after load',                class_exists('RBACResolver'));
$a('legacy RBAC has hasPermission()',                     method_exists('RBAC', 'hasPermission'));
$a('legacy RBAC has requirePermission()',                 method_exists('RBAC', 'requirePermission'));

// ----------------------------------------------------------------- resolver method surface
echo "\nRBACResolver method surface (B2 contract)\n";
$a('RBACResolver::can()',                                 method_exists('RBACResolver', 'can'));
$a('RBACResolver::personaTypeOf()',                       method_exists('RBACResolver', 'personaTypeOf'));
$a('RBACResolver::memberships()',                         method_exists('RBACResolver', 'memberships'));
$a('RBACResolver::activeMembership()',                    method_exists('RBACResolver', 'activeMembership'));
$a('RBACResolver::moduleAccessFor()',                     method_exists('RBACResolver', 'moduleAccessFor'));
$a('RBACResolver::grantModule()',                         method_exists('RBACResolver', 'grantModule'));
$a('RBACResolver::revokeModule()',                        method_exists('RBACResolver', 'revokeModule'));
$a('RBACResolver::copyPermissions()',                     method_exists('RBACResolver', 'copyPermissions'));
$a('RBACResolver::isGlobalAdmin()',                       method_exists('RBACResolver', 'isGlobalAdmin'));
$a('RBACResolver::legacyRole()',                          method_exists('RBACResolver', 'legacyRole'));
$a('RBACResolver::resetCache()',                          method_exists('RBACResolver', 'resetCache'));

// ----------------------------------------------------------------- can() guards (no DB needed)
echo "\nRBACResolver::can() input guards\n";
RBACResolver::resetCache();
$a('returns false on userId=0',                           RBACResolver::can(0, 1, 'people', 'read') === false);
$a('returns false on invalid action',                     RBACResolver::can(1, 1, 'people', 'bogus') === false);
$a('returns false on userId=0 via array input',           RBACResolver::can(['id' => 0], 1, 'people', 'read') === false);

// ----------------------------------------------------------------- legacy fall-through when DB absent
echo "\nDB-absent fall-throughs\n";
// db.php silently returns null when MySQL is unreachable, so every PDO
// call inside the resolver hits the catch branch.  These calls must NOT
// throw and must return safe defaults.
try {
    $role = RBACResolver::legacyRole(1, 1);
    $a('legacyRole() returns "employee" when DB unreachable', $role === 'employee');
} catch (\Throwable $e) { $a('legacyRole() does not throw on DB error: ' . $e->getMessage(), false); }

try {
    $isAdmin = RBACResolver::isGlobalAdmin(1);
    $a('isGlobalAdmin() returns false when DB unreachable',   $isAdmin === false);
} catch (\Throwable $e) { $a('isGlobalAdmin() does not throw: ' . $e->getMessage(), false); }

try {
    $m = RBACResolver::activeMembership(1, 1);
    $a('activeMembership() returns null when DB unreachable', $m === null);
} catch (\Throwable $e) { $a('activeMembership() does not throw: ' . $e->getMessage(), false); }

try {
    $ms = RBACResolver::memberships(1, 1);
    $a('memberships() returns [] when DB unreachable',        $ms === []);
} catch (\Throwable $e) { $a('memberships() does not throw: ' . $e->getMessage(), false); }

// can() with no membership + no DB hits legacyCan(), which falls to
// legacyRole() = 'employee' → grants 'read' on any module.
try {
    $rd = RBACResolver::can(1, 1, 'people', 'read');
    $wr = RBACResolver::can(1, 1, 'people', 'write');
    $a('can(employee fall-through, read)  → true',           $rd === true);
    $a('can(employee fall-through, write) → false',          $wr === false);
} catch (\Throwable $e) { $a('can() fall-through does not throw: ' . $e->getMessage(), false); }

// ----------------------------------------------------------------- copyPermissions signature
echo "\ncopyPermissions() contract\n";
$ref = new ReflectionMethod('RBACResolver', 'copyPermissions');
$params = $ref->getParameters();
$a('copyPermissions() is static',                         $ref->isStatic());
$a('copyPermissions() takes from/to/actor (3 params)',    count($params) === 3);
$a('param 1 named fromMembershipId',                      ($params[0]->getName() ?? '') === 'fromMembershipId');
$a('param 2 named toMembershipId',                        ($params[1]->getName() ?? '') === 'toMembershipId');
$a('param 3 named actorUserId',                           ($params[2]->getName() ?? '') === 'actorUserId');

// ----------------------------------------------------------------- api_bootstrap wiring
echo "\napi_bootstrap.php wiring\n";
$bootSrc = (string) file_get_contents($ROOT . '/core/api_bootstrap.php');
$a('requires rbac/permissions.php',                       $c($bootSrc, "require_once __DIR__ . '/rbac/permissions.php'"));
$a('$ctx exposes membership_id',                          $c($bootSrc, "'membership_id'"));
$a('$ctx exposes persona_type',                           $c($bootSrc, "'persona_type'"));
$a('$ctx exposes is_global_admin',                        $c($bootSrc, "'is_global_admin'"));
$a('uses RBACResolver::activeMembership',                 $c($bootSrc, 'RBACResolver::activeMembership'));
$a('uses RBACResolver::isGlobalAdmin',                    $c($bootSrc, 'RBACResolver::isGlobalAdmin'));
$a('defines api_can() helper',                            $c($bootSrc, 'function api_can('));
$a('defines api_require_can() helper',                    $c($bootSrc, 'function api_require_can('));
$a('reads active_persona_id from session',                $c($bootSrc, "active_persona_id"));
$a('guards on class_exists(RBACResolver)',                $c($bootSrc, "class_exists('RBACResolver')"));

// ----------------------------------------------------------------- auth.php persona helpers
echo "\nauth.php persona helpers\n";
$authSrc = (string) file_get_contents($ROOT . '/core/auth.php');
$a('defines setActivePersona()',                          $c($authSrc, 'function setActivePersona('));
$a('defines getActivePersonaId()',                        $c($authSrc, 'function getActivePersonaId('));
$a('defines clearActivePersona()',                        $c($authSrc, 'function clearActivePersona('));
$a('setActivePersona uses RBACResolver::memberships',     $c($authSrc, 'RBACResolver::memberships'));
$a('setActivePersona requires permissions.php on demand', $c($authSrc, "require_once __DIR__ . '/rbac/permissions.php'"));

// ----------------------------------------------------------------- LEVEL_RANK ordering (via reflection)
echo "\nLEVEL_RANK ordering\n";
$reflClass = new ReflectionClass('RBACResolver');
$rankProp  = $reflClass->getReflectionConstant('LEVEL_RANK');
$rank      = $rankProp ? $rankProp->getValue() : null;
$a('LEVEL_RANK defined',                                  is_array($rank));
$a('none rank lowest',                                    is_array($rank) && $rank['none']  === 0);
$a('read < write < admin',                                is_array($rank) && $rank['read']  < $rank['write'] && $rank['write'] < $rank['admin']);

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "RBAC B2 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
