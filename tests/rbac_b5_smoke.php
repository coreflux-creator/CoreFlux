<?php
/**
 * RBAC B5 smoke — header persona toggle.
 *
 *   - /api/active_persona.php (GET / POST / DELETE)
 *   - Header.jsx persona dropdown wiring
 *
 *   php -d zend.assertions=1 /app/tests/rbac_b5_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- endpoint
echo "active_persona.php endpoint\n";
$ep = (string) file_get_contents($ROOT . '/api/active_persona.php');
$a('file present',                                $ep !== '');
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/active_persona.php') . ' 2>&1', $o, $rc);
$a('php -l clean',                                $rc === 0);
$a('requires api_bootstrap',                      $c($ep, "require_once __DIR__ . '/../core/api_bootstrap.php'"));
$a('GET returns personas array',                  $c($ep, "'personas'          => \$rows"));
$a('GET returns active_persona_id',               $c($ep, "'active_persona_id' => \$activeId"));
$a('GET uses RBACResolver::memberships',          $c($ep, 'RBACResolver::memberships'));
$a('GET auto-resolves active when session empty', $c($ep, 'RBACResolver::activeMembership($userId, $tenantId)'));
$a('POST takes persona_id',                       $c($ep, "api_require_fields(\$body, ['persona_id'])"));
$a('POST calls setActivePersona helper',          $c($ep, 'setActivePersona($personaId)'));
$a('POST audits persona_switched',                $c($ep, "'persona_switched'") && $c($ep, 'RBACResolver::auditMembership'));
$a('POST resets resolver cache',                  $c($ep, 'RBACResolver::resetCache'));
$a('POST mirrors persona_type into $_SESSION[user][role]',
    $c($ep, "\$_SESSION['user']['role'] = (string) \$row['persona_type']"));
$a('DELETE clears active persona',                $c($ep, 'clearActivePersona') && $c($ep, "'cleared' => true"));
$a('rejects when persona invalid (404)',          $c($ep, "api_error('Persona not found"));

// ----------------------------------------------------------------- auth.php helpers used by endpoint
echo "\nauth.php helpers (B2 carry-overs)\n";
$auth = (string) file_get_contents($ROOT . '/core/auth.php');
$a('setActivePersona defined',                    $c($auth, 'function setActivePersona('));
$a('getActivePersonaId defined',                  $c($auth, 'function getActivePersonaId('));
$a('clearActivePersona defined',                  $c($auth, 'function clearActivePersona('));

// ----------------------------------------------------------------- Header.jsx wiring
echo "\nHeader.jsx persona dropdown\n";
$h = (string) file_get_contents($ROOT . '/dashboard/src/layout/Header.jsx');
$a('imports UserCog icon',                        $c($h, 'UserCog'));
$a('useState for personas',                       $c($h, 'setPersonas'));
$a('useState for activePersonaId',                $c($h, 'setActivePersonaId'));
$a('persona dropdown ref',                        $c($h, 'personaRef'));
$a('click-outside closes persona dropdown',       $c($h, 'setPersonaOpen(false)'));
$a('GET /api/active_persona.php on tenant change',$c($h, "api.get('/api/active_persona.php')"));
$a('POST persona_id to /api/active_persona.php',  $c($h, "api.post('/api/active_persona.php'"));
$a('dispatches cf:active-persona-changed event',  $c($h, 'cf:active-persona-changed'));
$a('only renders when ≥2 personas',               $c($h, 'personas.length > 1'));
$a('has data-testid="header-persona-switcher"',   $c($h, 'data-testid="header-persona-switcher"'));
$a('has data-testid="header-persona-button"',     $c($h, 'data-testid="header-persona-button"'));
$a('renders per-persona option testids',          $c($h, 'header-persona-option-'));
$a('shows PRIMARY badge on primary persona',      $c($h, 'is_primary &&'));

// ----------------------------------------------------------------- App.jsx listener
echo "\nApp.jsx persona-changed listener\n";
$app = (string) file_get_contents($ROOT . '/dashboard/src/App.jsx');
$a('App.jsx listens to cf:active-persona-changed',
    $c($app, "addEventListener('cf:active-persona-changed'"));
$a('App.jsx soft-reloads on persona change',
    $c($app, 'window.location.reload()'));
$a('App.jsx removes listener on cleanup',
    $c($app, "removeEventListener('cf:active-persona-changed'"));

// ----------------------------------------------------------------- $ctx integration sanity
echo "\napi_bootstrap.php still reads active_persona_id\n";
$boot = (string) file_get_contents($ROOT . '/core/api_bootstrap.php');
$a('reads $_SESSION[active_persona_id]',          $c($boot, "'active_persona_id'") && $c($boot, '$_SESSION'));
$a('passes personaId into activeMembership()',    $c($boot, 'RBACResolver::activeMembership'));

echo "\n=========================================\n";
echo "RBAC B5 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
