<?php
/** Set one approved recipient in the private, disposable QA mail configuration. */
declare(strict_types=1);

const QA_PRIVATE = '/home/master/.coreaccounting-cleanqa';
const QA_MAIL_DIR = '/home/master/.coreaccounting-cleanqa2';
const QA_MAIL_CONFIG = QA_MAIL_DIR . '/mail.local.php';

if (PHP_SAPI !== 'cli' || realpath(__DIR__) !== QA_PRIVATE
    || realpath(QA_MAIL_DIR) !== QA_MAIL_DIR
    || realpath(QA_MAIL_CONFIG) !== QA_MAIL_CONFIG
    || is_link(QA_MAIL_CONFIG)
    || !in_array('--confirm-disposable-qa', $argv, true)) {
    fwrite(STDERR, "Disposable QA private mail setup only.\n");
    exit(2);
}

$recipient = strtolower(trim((string) stream_get_contents(STDIN, 300)));
if (strlen($recipient) > 254 || preg_match('/[\r\n,;]/', $recipient)
    || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "One valid email address is required on standard input.\n");
    exit(2);
}

$lock = @fopen(QA_MAIL_DIR . '/.mail-recipient.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Could not lock private QA mail settings.\n");
    exit(1);
}

$content = file_get_contents(QA_MAIL_CONFIG);
if ($content === false || strlen($content) > 8192
    || !str_starts_with($content, "<?php\n")
    || str_contains($content, '?>')
    || str_contains($content, 'COREFLUX_ACCOUNTING_TEST_RECIPIENTS')) {
    fwrite(STDERR, "Private QA mail settings are missing, changed, or already restricted.\n");
    exit(2);
}
require QA_MAIL_CONFIG;
if (!defined('COREFLUX_ACCOUNTING_RESEND_API_KEY')
    || !preg_match('/^re_[A-Za-z0-9_-]{20,120}$/D', (string) COREFLUX_ACCOUNTING_RESEND_API_KEY)
    || (string) COREFLUX_ACCOUNTING_FROM_EMAIL !== 'qa-invoices@mail.corefluxapp.com'
    || (string) COREFLUX_ACCOUNTING_MAIL_TEST_MODE !== '1') {
    fwrite(STDERR, "Private QA mail settings failed identity checks.\n");
    exit(2);
}

$newContent = $content . "define('COREFLUX_ACCOUNTING_TEST_RECIPIENTS', "
    . var_export($recipient, true) . ");\n";
$temp = tempnam(QA_MAIL_DIR, 'mail-new-');
if ($temp === false) {
    fwrite(STDERR, "Could not prepare private QA mail settings.\n");
    exit(1);
}
try {
    if (!chmod($temp, 0640) || !chgrp($temp, 'www-data')
        || file_put_contents($temp, $newContent) !== strlen($newContent)
        || !rename($temp, QA_MAIL_CONFIG)) {
        throw new RuntimeException('Could not update private QA mail settings.');
    }
} catch (Throwable $error) {
    @unlink($temp);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
echo "One exact QA recipient is restricted in private mail settings.\n";
