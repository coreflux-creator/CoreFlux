<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/mail/ResendDriver.php';

use Core\Mail\ResendDriver;

$previous = [];
foreach (['COREFLUX_ENV', 'COREFLUX_ACCOUNTING_MAIL_TEST_MODE', 'COREFLUX_ACCOUNTING_TEST_RECIPIENTS'] as $name) {
    $previous[$name] = getenv($name);
}
$calls = 0;
$driver = new ResendDriver(
    're_synthetic_test_key',
    'qa@mail.corefluxapp.com',
    'CoreAccounting QA',
    static function (array $request) use (&$calls): array {
        $calls++;
        return ['ok' => true, 'id' => 'synthetic-message-id'];
    },
    true
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    putenv('COREFLUX_ENV=coreaccounting');
    putenv('COREFLUX_ACCOUNTING_MAIL_TEST_MODE=1');
    putenv('COREFLUX_ACCOUNTING_TEST_RECIPIENTS');
    $result = $driver->send(['to' => ['approved@example.test'], 'subject' => 'Test']);
    $assert($result['status'] === 'failed' && $calls === 0, 'Missing allowlist must fail closed');

    putenv('COREFLUX_ACCOUNTING_TEST_RECIPIENTS= approved@example.test ');
    $result = $driver->send(['to' => ['APPROVED@example.test'], 'subject' => 'Test']);
    $assert($result['status'] === 'sent' && $calls === 1, 'Exact approved recipient should send');

    $result = $driver->send(['to' => ['other@example.test'], 'subject' => 'Test']);
    $assert($result['status'] === 'failed' && $calls === 1, 'Other To recipient must not reach provider');

    $result = $driver->send([
        'to' => ['approved@example.test'], 'cc' => ['other@example.test'], 'subject' => 'Test',
    ]);
    $assert($result['status'] === 'failed' && $calls === 1, 'Other CC recipient must not reach provider');

    putenv('COREFLUX_ACCOUNTING_MAIL_TEST_MODE=0');
    $result = $driver->send(['to' => ['other@example.test'], 'subject' => 'Test']);
    $assert($result['status'] === 'sent' && $calls === 2, 'Live-mode driver remains unchanged');
} finally {
    foreach ($previous as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}

echo "CoreAccounting mail recipient fence: passed\n";
