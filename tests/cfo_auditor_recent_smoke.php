<?php
/**
 * Recently viewed + CFO gate + External Auditor smoke (2026-02).
 *
 *   1. /api/admin/manageable_tenants.php exposes `recently_viewed`
 *   2. Header.jsx renders the "Recently viewed" strip
 *   3. api_require_cfo() exists & gates CFO endpoints
 *   4. CFOGuard.jsx is wired into App.jsx for /cfo
 *   5. auditor_tokens migration + helpers + /auditor.php + admin endpoint
 *   6. api_bootstrap blocks every non-GET while auditor_mode=true
 *   7. AuditorTokensAdmin.jsx + AdminModule route + banner in App.jsx
 *
 *   php -d zend.assertions=1 /app/tests/cfo_auditor_recent_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// 1. Recently viewed ---------------------------------------------------------
$mt = (string) file_get_contents($ROOT . '/api/admin/manageable_tenants.php');
$a('manageable_tenants returns recently_viewed',
    str_contains($mt, "'recently_viewed'"));
$a('recently_viewed orders by last_active_at DESC LIMIT 5',
    (bool) preg_match('/ORDER BY src\.last_active_at DESC\s+LIMIT 5/i', $mt));
$a('recently_viewed uses the read-fallback shim',
    str_contains($mt, 'membershipReadSourceSql()'));
$a('recently_viewed excludes the currently active tenant',
    (bool) preg_match('/\$activeTid\s*&&.+?continue;/s', $mt));

$hdr = (string) file_get_contents($ROOT . '/dashboard/src/layout/Header.jsx');
$a('Header renders Recently viewed heading',
    str_contains($hdr, 'tenant-switcher-recent-heading'));
$a('Header renders per-recent-tenant row',
    str_contains($hdr, 'tenant-switcher-recent-${rv.id}'));

// 2. CFO gating --------------------------------------------------------------
$bootstrap = (string) file_get_contents($ROOT . '/core/api_bootstrap.php');
$a('api_require_cfo() defined',           str_contains($bootstrap, 'function api_require_cfo'));
$a('cfo gate allows master_admin / is_global_admin',
    (bool) preg_match("/master_admin.*\|\|.*isGlobalAdm/", $bootstrap));
$a('cfo gate allows tenant_admin / admin',
    (bool) preg_match("/tenant_admin.*?admin/s", $bootstrap));
$a('cfo gate honours membership_module_access "cfo"',
    str_contains($bootstrap, "'cfo'") && str_contains($bootstrap, 'moduleAccessFor'));

foreach (['api/cfo_annotate.php','api/cfo_notes.php','api/cfo_send_report.php','api/cfo_formulas.php'] as $f) {
    $src = (string) file_get_contents($ROOT . '/' . $f);
    $a("{$f} calls api_require_cfo()", str_contains($src, 'api_require_cfo()'));
}

$cfoGuard = (string) file_get_contents($ROOT . '/dashboard/src/pages/CFOGuard.jsx');
$a('CFOGuard.jsx exists and gates on role + global_role',
    str_contains($cfoGuard, "global_role") && str_contains($cfoGuard, "is_global_admin"));
$a('CFOGuard renders a Forbidden card',
    str_contains($cfoGuard, 'data-testid="cfo-forbidden"'));
$app = (string) file_get_contents($ROOT . '/dashboard/src/App.jsx');
$a('App.jsx wires CFOGuard around /cfo route',
    (bool) preg_match('/<CFOGuard[^>]*>\s*<CFODashboard/', $app));

// 3. Header CFO link gated ---------------------------------------------------
$a('Header.jsx hides CFO link when canSeeCfo is false',
    str_contains($hdr, 'canSeeCfo') && (bool) preg_match('/canSeeCfo\s*&&\s*\(\s*\n?\s*<Link to="\/cfo"/', $hdr));

// 4. Auditor migration + helpers --------------------------------------------
$mig = $ROOT . '/core/migrations/061_auditor_tokens.sql';
$a('auditor_tokens migration exists',  file_exists($mig));
$migSrc = (string) file_get_contents($mig);
$a('migration creates auditor_tokens',     str_contains($migSrc, 'CREATE TABLE IF NOT EXISTS auditor_tokens'));
$a('migration creates auditor_access_log', str_contains($migSrc, 'CREATE TABLE IF NOT EXISTS auditor_access_log'));
$a('token_hash unique constraint',         str_contains($migSrc, 'token_hash      CHAR(64)      NOT NULL UNIQUE'));

require_once $ROOT . '/core/auditor.php';
$a('auditorGenerateToken() defined',  function_exists('auditorGenerateToken'));
$a('auditorRedeemAndStart() defined', function_exists('auditorRedeemAndStart'));
$a('auditorModeActive() defined',     function_exists('auditorModeActive'));

// generateToken produces sha256 hash that matches plain
[$plain, $hash] = auditorGenerateToken();
$a('generated token is URL-safe base64 (no + / =)',
    !preg_match('/[+\/=]/', $plain) && strlen($plain) >= 40);
$a('hash matches sha256 of plain',
    $hash === hash('sha256', $plain));

// 5. /auditor.php entry point -----------------------------------------------
$entry = (string) file_get_contents($ROOT . '/auditor.php');
$a('/auditor.php requires core/auditor.php',
    str_contains($entry, "require_once __DIR__ . '/core/auditor.php'"));
$a('/auditor.php calls auditorRedeemAndStart',
    str_contains($entry, 'auditorRedeemAndStart('));
$a('/auditor.php renders an error page for bad tokens',
    str_contains($entry, '_auditorErrorPage'));
$a('/auditor.php default landing is the CFO dashboard',
    str_contains($entry, "'/spa.php#/cfo'"));

// 6. Admin CRUD endpoint -----------------------------------------------------
$adm = (string) file_get_contents($ROOT . '/api/admin/auditor_tokens.php');
$a('admin auditor endpoint exists',          strlen($adm) > 400);
$a('endpoint gates non-admin users (403)',   str_contains($adm, "'Forbidden — only master_admin or tenant_admin can issue auditor tokens'"));
$a('endpoint caps days at 90, default 7',    str_contains($adm, 'max(1, min(90'));
$a('endpoint returns token plain ONCE (POST)', str_contains($adm, "'token'      => \$plain"));
$a('endpoint supports PATCH ?action=revoke',  str_contains($adm, "\$action === 'revoke'"));
$a('endpoint scoped by tenant for non-platform',
    str_contains($adm, '_auditorTenantAllowed'));

// 7. Auditor write-block at bootstrap ----------------------------------------
$a('api_bootstrap.php blocks non-GET while auditorModeActive()',
    (bool) preg_match('/auditorModeActive\(\)/', $bootstrap)
    && (bool) preg_match("/Forbidden — external auditor sessions are read-only/", $bootstrap));
$a('api_bootstrap.php logs each page view',
    str_contains($bootstrap, "auditorLog(") && str_contains($bootstrap, "'view'"));

// 8. session.php surfaces auditor_mode + expiry ------------------------------
$sess = (string) file_get_contents($ROOT . '/session.php');
$a('session.php exposes auditor_mode',        str_contains($sess, "'auditor_mode'"));
$a('session.php exposes auditor_expires_at',  str_contains($sess, "'auditor_expires_at'"));
$a('session.php exposes auditor_modules',     str_contains($sess, "'auditor_modules'"));

// 9. Admin SPA wiring --------------------------------------------------------
$adminMod = (string) file_get_contents($ROOT . '/dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports AuditorTokensAdmin', str_contains($adminMod, "import AuditorTokensAdmin from './AuditorTokensAdmin'"));
$a('AdminModule has /admin/auditor-tokens route',
    (bool) preg_match('/path="\/auditor-tokens"\s+element=\{<AuditorTokensAdmin/', $adminMod));
$a('AdminModule overview lists auditor link card',
    str_contains($adminMod, 'href="/admin/auditor-tokens"'));

$auditorUi = (string) file_get_contents($ROOT . '/dashboard/src/pages/AuditorTokensAdmin.jsx');
$a('AuditorTokensAdmin shows token reveal modal',
    str_contains($auditorUi, 'auditor-reveal-modal') && str_contains($auditorUi, "won't be shown again"));
$a('AuditorTokensAdmin offers revoke + delete actions',
    str_contains($auditorUi, 'auditor-revoke-${t.id}') && str_contains($auditorUi, 'auditor-delete-${t.id}'));

// 10. Site-wide banner -------------------------------------------------------
$a('App.jsx renders site-wide auditor banner',
    (bool) preg_match('/session\?\.auditor_mode\s*&&[\s\S]*?data-testid="auditor-banner"/', $app));

// 11. PHP syntax sanity ------------------------------------------------------
foreach ([
    'core/api_bootstrap.php', 'core/auditor.php',
    'auditor.php', 'api/admin/auditor_tokens.php',
    'api/admin/manageable_tenants.php',
] as $rel) {
    $rc = 0; $out = [];
    exec('php -l ' . escapeshellarg($ROOT . '/' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l $rel", $rc === 0);
}

echo "\n=========================================\n";
echo "CFO + Auditor + Recently-viewed smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
