<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passes = 0;
$failures = [];
$check = static function (string $label, bool $ok) use (&$passes, &$failures): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $passes++ : $failures[] = $label;
};
$read = static fn(string $path): string => (string) file_get_contents($path);

$privacy = $read($root . '/privacy.html');
$terms = $read($root . '/terms.html');
$connect = $read($root . '/quickbooks-connect.html');
$disconnect = $read($root . '/quickbooks-disconnect.html');
$workflow = $read($root . '/.github/workflows/deploy-cloudways.yml');

$check('privacy policy is public static HTML', str_contains($privacy, 'data-policy="coreflux-privacy-v1"'));
$check('privacy policy explains QuickBooks OAuth and records', str_contains($privacy, 'OAuth access and refresh tokens') && str_contains($privacy, 'QuickBooks records'));
$check('privacy policy explains direct Intuit tokenization', str_contains($privacy, 'sent directly') && str_contains($privacy, 'raw primary account number'));
$check('privacy policy covers no-sale and deletion', str_contains($privacy, 'We do not sell personal information') && str_contains($privacy, 'data-deletion requests'));
$check('terms page is an end-user license agreement', str_contains($terms, 'End-User License Agreement') && str_contains($terms, 'limited, non-exclusive'));
$check('terms page covers Intuit and payments', str_contains($terms, 'Intuit') && str_contains($terms, 'payment processor'));
$check('connect page routes administrators to QBO settings', str_contains($connect, 'data-qbo-lifecycle="connect-reconnect"') && str_contains($connect, 'href="/admin/integrations/qbo"'));
$check('disconnect page explains revocation and deletion', str_contains($disconnect, 'data-qbo-lifecycle="disconnect"') && str_contains($disconnect, 'disables future QuickBooks API access') && str_contains($disconnect, 'Data deletion'));
$check('all public pages link privacy and terms', str_contains($connect, '/privacy.html') && str_contains($connect, '/terms.html') && str_contains($disconnect, '/privacy.html') && str_contains($disconnect, '/terms.html'));
$check('deployment includes all go-live assets', str_contains($workflow, 'assets/css/legal.css') && str_contains($workflow, 'privacy.html') && str_contains($workflow, 'terms.html') && str_contains($workflow, 'quickbooks-connect.html') && str_contains($workflow, 'quickbooks-disconnect.html'));
$check('deployment performs public HTTP verification', str_contains($workflow, 'data-policy="coreflux-privacy-v1"') && str_contains($workflow, 'data-qbo-lifecycle="disconnect"'));

echo PHP_EOL . "QBO go-live assets smoke: {$passes} ✓ / " . count($failures) . " ✗" . PHP_EOL;
exit($failures ? 1 : 0);
