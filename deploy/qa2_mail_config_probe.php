<?php
/** Read-only mail readiness probe pinned to the disposable QA app. */
declare(strict_types=1);

const QA_PRIVATE = '/home/master/.coreaccounting-cleanqa';
const QA_ROOT = '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html';
const QA_DB_CONFIG = '/home/master/.coreaccounting-cleanqa2/db.local.php';
const QA_MAIL_CONFIG = '/home/master/.coreaccounting-cleanqa2/mail.local.php';

if (PHP_SAPI !== 'cli' || realpath(__DIR__) !== QA_PRIVATE
    || !in_array('--confirm-disposable-qa', $argv, true)
    || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH') !== QA_DB_CONFIG
    || getenv('COREFLUX_ACCOUNTING_MAIL_CONFIG_PATH') !== QA_MAIL_CONFIG
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'aqdcpvafpj'
    || getenv('COREFLUX_PUBLIC_ORIGIN') !== 'https://phpstack-1516771-6717961.cloudwaysapps.com') {
    fwrite(STDERR, "Disposable QA mail probe only.\n");
    exit(2);
}

require_once QA_ROOT . '/core/config.php';
require_once QA_ROOT . '/core/mail/ResendDriver.php';

$key = coreAccountingMailSetting('COREFLUX_ACCOUNTING_RESEND_API_KEY');
$sender = coreAccountingMailSetting('COREFLUX_ACCOUNTING_FROM_EMAIL');
$testMode = coreAccountingMailSetting('COREFLUX_ACCOUNTING_MAIL_TEST_MODE') === '1';
$called = false;
$driver = new \Core\Mail\ResendDriver($key, $sender, 'CoreAccounting QA',
    static function () use (&$called): array {
        $called = true;
        return ['ok' => true, 'id' => 'unexpected'];
    }, true);
$blocked = $driver->send(['to' => ['unapproved@example.test'], 'subject' => 'QA probe']);
$result = [
    'database' => DB_NAME,
    'sender' => $sender,
    'key_loaded' => (bool) preg_match('/^re_[A-Za-z0-9_-]{20,120}$/D', $key),
    'test_mode' => $testMode,
    'unapproved_recipient_blocked' => $blocked['status'] === 'failed' && !$called,
];
if ($result['database'] !== 'aqdcpvafpj'
    || $result['sender'] !== 'qa-invoices@mail.corefluxapp.com'
    || !$result['key_loaded'] || !$result['test_mode']
    || !$result['unapproved_recipient_blocked']) {
    fwrite(STDERR, "Disposable QA mail readiness failed.\n");
    exit(1);
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
