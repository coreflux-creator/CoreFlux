<?php
/** Login redirects must remain valid when the SPA is reloaded on a deep route. */
declare(strict_types=1);

$root = dirname(__DIR__);
$spa = (string) file_get_contents($root . '/spa.php');
$login = (string) file_get_contents($root . '/login.php');
$logout = (string) file_get_contents($root . '/auth/logout.php');
$failures = [];

if (!str_contains($spa, 'Location: /login.php?redirect=spa')) {
    $failures[] = 'SPA unauthenticated redirect is not root-absolute.';
}
if (preg_match('/header\(["\']Location: (?!\/)/', $login)) {
    $failures[] = 'Login handler contains a relative redirect.';
}
if (!str_contains($logout, 'Location: /login.php')) {
    $failures[] = 'Logout redirect is not root-absolute.';
}

foreach ($failures as $failure) echo "FAIL {$failure}\n";
if ($failures === []) echo "Login redirect paths: 3 ok / 0 failed\n";
exit($failures === [] ? 0 : 1);
