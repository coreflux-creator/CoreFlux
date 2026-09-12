<?php
/** Login redirects must remain valid when the SPA is reloaded on a deep route. */
declare(strict_types=1);

$root = dirname(__DIR__);
$spa = (string) file_get_contents($root . '/spa.php');
$login = (string) file_get_contents($root . '/login.php');
$logout = (string) file_get_contents($root . '/auth/logout.php');
$app = (string) file_get_contents($root . '/dashboard/src/App.jsx');
$htaccess = (string) file_get_contents($root . '/.htaccess');
$failures = [];

if (!str_contains($spa, "Location: /login.html?next=")) {
    $failures[] = 'SPA unauthenticated redirect does not use login.html.';
}
if (preg_match('/header\(["\']Location: (?!\/)/', $login)) {
    $failures[] = 'Login handler contains a relative redirect.';
}
if (!str_contains($login, '$isLocalPath && $next === \'/\'')) {
    $failures[] = 'Root sign-in does not return to the canonical root.';
}
if (!str_contains($logout, 'Location: /login.html')) {
    $failures[] = 'Logout redirect does not use login.html.';
}
if (!str_contains($app, '/login.html?next=') || str_contains($app, '/login?next=')) {
    $failures[] = 'Dashboard session expiry does not use login.html.';
}
$rootRule = strpos($htaccess, 'RewriteRule ^$ spa.php [L,QSA]');
$filePass = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f');
if ($rootRule === false || $filePass === false || $rootRule > $filePass) {
    $failures[] = 'Root is not routed through spa.php before static files.';
}
if (!str_contains($htaccess, 'RewriteRule ^login/?$ /login.html')) {
    $failures[] = 'Legacy /login URL is not repaired.';
}

$spaRule = strpos($htaccess, 'RewriteRule ^(admin|ai-agents|ai|auth/m|cfo|dashboard|data|exec|inbox|modules|profile|select-tenant|settings|share/scenario|sim|vendor/portal)');
$dirPass = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} -d');
if ($spaRule === false || $dirPass === false || $spaRule > $dirPass) {
    $failures[] = 'Known SPA paths are not routed before physical directories.';
}
foreach (['admin', 'ai-agents', 'ai', 'auth/m', 'cfo', 'data', 'exec', 'inbox', 'modules', 'select-tenant', 'share/scenario', 'sim', 'vendor/portal'] as $path) {
    if (!str_contains($htaccess, $path)) {
        $failures[] = "SPA route namespace missing from Apache fallback: {$path}.";
    }
}

foreach ($failures as $failure) echo "FAIL {$failure}\n";
if ($failures === []) echo "Login and SPA redirect paths: ok\n";
exit($failures === [] ? 0 : 1);
