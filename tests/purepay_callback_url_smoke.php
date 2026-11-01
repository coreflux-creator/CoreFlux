<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/purepay_callback_url.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    ++$checks;
    if (!$ok) throw new RuntimeException($label);
};
$path = '/api/webhooks/purepay.php?t=999';
$check(purepayCallbackUrl(999, '', true) === null, 'Missing staging origin must not fall back to production');
$check(purepayCallbackUrl(999, 'https://www.corefluxapp.com', true) === null, 'Staging must reject production override');
$check(purepayCallbackUrl(999, 'https://corefluxapp.com', true) === null, 'Staging must reject bare production origin');
foreach (['https://stage.corefluxapp.com', 'https://staging.corefluxapp.com', 'https://phpstack-1516771-6707602.cloudwaysapps.com'] as $origin) {
    $check(purepayCallbackUrl(999, $origin, true) === $origin . $path, 'Trusted staging origin retained');
}
$check(purepayCallbackUrl(999, ' https://stage.corefluxapp.com/ ', true) === 'https://stage.corefluxapp.com' . $path, 'Normalize outer whitespace and slash');
foreach (['http://stage.corefluxapp.com', 'https://stage.corefluxapp.com.attacker.invalid',
    'https://stage.corefluxapp.com@attacker.invalid', 'https://user:pass@stage.corefluxapp.com',
    'https://stage.corefluxapp.com:443', 'https://stage.corefluxapp.com/path',
    'https://stage.corefluxapp.com?target=x', 'https://stage.corefluxapp.com#fragment',
    "https://stage.corefluxapp.com\nheader", '//stage.corefluxapp.com'] as $origin) {
    $check(purepayCallbackUrl(999, $origin, true) === null, 'Reject unsafe or ambiguous staging origin');
}
$check(purepayCallbackUrl(0, 'https://stage.corefluxapp.com', true) === null, 'Tenant is required');
$check(purepayCallbackUrl(-1, '', false) === null, 'Invalid tenant cannot get a production URL');
$check(purepayCallbackUrl(2, '', false) === 'https://www.corefluxapp.com/api/webhooks/purepay.php?t=2', 'Production default preserved');
$check(purepayCallbackUrl(2, 'https://www.corefluxapp.com', false) === 'https://www.corefluxapp.com/api/webhooks/purepay.php?t=2', 'Production URL preserved');
$check(purepayCallbackUrl(2, 'https://accounting.fixture.invalid', false) === 'https://accounting.fixture.invalid/api/webhooks/purepay.php?t=2', 'Configured standalone origin preserved');
$check(purepayCallbackUrl(2, 'http://accounting.fixture.invalid', false) === null, 'Non-HTTPS production override rejected');
echo "PurePay callback URL: {$checks} checks passed\n";
