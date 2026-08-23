<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/qbo/payments_client.php';

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}\n";
    }
};

putenv('QBO_RECAPTCHA_SITE_KEY=site-test-key');
putenv('QBO_RECAPTCHA_SECRET_KEY=secret-test-key');
putenv('QBO_RECAPTCHA_ALLOWED_HOSTS=corefluxapp.com,www.corefluxapp.com');
$configuredSecret = qboCfg('QBO_RECAPTCHA_SECRET_KEY');

$captured = null;
$GLOBALS['__qbo_transport'] = static function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
    $captured = compact('method', 'url', 'headers', 'body');
    return [
        'status' => 200,
        'body' => [
            'success' => true,
            'hostname' => 'www.corefluxapp.com',
            'challenge_ts' => '2026-08-23T00:00:00Z',
        ],
        'headers' => [],
    ];
};

echo "QBO Payments reCAPTCHA smoke\n";
$verified = qboVerifyPaymentsRecaptcha('browser-response-token', '203.0.113.10');
parse_str((string) ($captured['body'] ?? ''), $sent);
$check('calls Google siteverify over POST',
    ($captured['method'] ?? '') === 'POST'
    && ($captured['url'] ?? '') === 'https://www.google.com/recaptcha/api/siteverify');
$check('sends the configured secret, browser token, and valid remote IP',
    $configuredSecret !== ''
    && ($sent['secret'] ?? '') === $configuredSecret
    && ($sent['response'] ?? '') === 'browser-response-token'
    && ($sent['remoteip'] ?? '') === '203.0.113.10');
$check('accepts an allowlisted CoreFlux hostname',
    ($verified['success'] ?? false) === true
    && ($verified['hostname'] ?? '') === 'www.corefluxapp.com');

$GLOBALS['__qbo_transport'] = static fn(): array => [
    'status' => 200,
    'body' => ['success' => true, 'hostname' => 'lookalike.example'],
    'headers' => [],
];
$hostRejected = false;
try {
    qboVerifyPaymentsRecaptcha('browser-response-token');
} catch (\InvalidArgumentException $e) {
    $hostRejected = str_contains($e->getMessage(), 'unexpected host');
}
$check('rejects a valid token issued for a non-CoreFlux hostname', $hostRejected);

$GLOBALS['__qbo_transport'] = static fn(): array => [
    'status' => 200,
    'body' => ['success' => false, 'error-codes' => ['timeout-or-duplicate']],
    'headers' => [],
];
$tokenRejected = false;
try {
    qboVerifyPaymentsRecaptcha('expired-response-token');
} catch (\InvalidArgumentException $e) {
    $tokenRejected = str_contains($e->getMessage(), 'verification failed');
}
$check('rejects expired or duplicate challenge tokens', $tokenRejected);

unset($GLOBALS['__qbo_transport']);
putenv('QBO_RECAPTCHA_SITE_KEY');
putenv('QBO_RECAPTCHA_SECRET_KEY');
putenv('QBO_RECAPTCHA_ALLOWED_HOSTS');

echo "\nQBO Payments reCAPTCHA smoke: {$pass} ✓ / {$fail} ✗\n";
exit($fail === 0 ? 0 : 1);
