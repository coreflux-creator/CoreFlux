<?php
/** One-time private Resend key install for the disposable CoreAccounting QA app. */
declare(strict_types=1);

const QA_PRIVATE = '/home/master/.coreaccounting-cleanqa';
const QA_MAIL_DIR = '/home/master/.coreaccounting-cleanqa2';
const QA_MAIL_CONFIG = QA_MAIL_DIR . '/mail.local.php';

if (PHP_SAPI !== 'cli' || realpath(__DIR__) !== QA_PRIVATE
    || realpath(QA_MAIL_DIR) !== QA_MAIL_DIR
    || !in_array('--confirm-disposable-qa', $argv, true)
    || is_file(QA_MAIL_CONFIG)) {
    fwrite(STDERR, "Disposable QA private mail setup only; existing settings are never overwritten.\n");
    exit(2);
}

$key = trim((string) stream_get_contents(STDIN, 129));
if (!preg_match('/^re_[A-Za-z0-9_-]{20,120}$/D', $key)) {
    fwrite(STDERR, "A valid Resend key is required on standard input.\n");
    exit(2);
}

$settings = [
    'COREFLUX_ACCOUNTING_RESEND_API_KEY' => $key,
    'COREFLUX_ACCOUNTING_FROM_EMAIL' => 'qa-invoices@mail.corefluxapp.com',
    'COREFLUX_ACCOUNTING_FROM_NAME' => 'CoreAccounting QA',
    'COREFLUX_ACCOUNTING_MAIL_TEST_MODE' => '1',
];
$content = "<?php\n";
foreach ($settings as $name => $value) {
    $content .= 'define(' . var_export($name, true) . ', '
        . var_export($value, true) . ");\n";
}

$before = umask(0077);
$file = @fopen(QA_MAIL_CONFIG, 'x');
umask($before);
if ($file === false) {
    fwrite(STDERR, "Could not create private QA mail settings.\n");
    exit(1);
}
try {
    if (!chmod(QA_MAIL_CONFIG, 0640) || !chgrp(QA_MAIL_CONFIG, 'www-data')
        || fwrite($file, $content) !== strlen($content) || !fflush($file)) {
        throw new RuntimeException('Could not secure private QA mail settings.');
    }
} catch (Throwable $error) {
    fclose($file);
    @unlink(QA_MAIL_CONFIG);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
fclose($file);
echo "Disposable QA mail key installed privately; recipient allowlist remains empty.\n";
