<?php
/** Convert the first disposable QA mail file without displaying its key. */
declare(strict_types=1);

const QA_PRIVATE = '/home/master/.coreaccounting-cleanqa';
const QA_MAIL_CONFIG = '/home/master/.coreaccounting-cleanqa2/mail.local.php';

if (PHP_SAPI !== 'cli' || realpath(__DIR__) !== QA_PRIVATE
    || !in_array('--confirm-disposable-qa', $argv, true)
    || is_link(QA_MAIL_CONFIG) || !is_file(QA_MAIL_CONFIG)) {
    fwrite(STDERR, "Disposable QA private mail conversion only.\n");
    exit(2);
}
$old = file_get_contents(QA_MAIL_CONFIG);
if (!is_string($old) || !preg_match("/^<\\?php\\nputenv\\('COREFLUX_ACCOUNTING_RESEND_API_KEY=(re_[A-Za-z0-9_-]{20,120})'\\);\\n/", $old, $match)) {
    fwrite(STDERR, "The private QA mail file is not in the expected original format.\n");
    exit(2);
}
$key = $match[1];
$expected = "<?php\n"
    . "putenv('COREFLUX_ACCOUNTING_RESEND_API_KEY=$key');\n"
    . "putenv('COREFLUX_ACCOUNTING_FROM_EMAIL=qa-invoices@mail.corefluxapp.com');\n"
    . "putenv('COREFLUX_ACCOUNTING_FROM_NAME=CoreAccounting QA');\n"
    . "putenv('COREFLUX_ACCOUNTING_MAIL_TEST_MODE=1');\n";
if (!hash_equals($expected, $old)) {
    fwrite(STDERR, "The private QA mail file has unexpected content.\n");
    exit(2);
}

$settings = [
    'COREFLUX_ACCOUNTING_RESEND_API_KEY' => $key,
    'COREFLUX_ACCOUNTING_FROM_EMAIL' => 'qa-invoices@mail.corefluxapp.com',
    'COREFLUX_ACCOUNTING_FROM_NAME' => 'CoreAccounting QA',
    'COREFLUX_ACCOUNTING_MAIL_TEST_MODE' => '1',
];
$new = "<?php\n";
foreach ($settings as $name => $value) {
    $new .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
}

$temporary = QA_MAIL_CONFIG . '.new-' . bin2hex(random_bytes(6));
$before = umask(0077);
$written = file_put_contents($temporary, $new, LOCK_EX);
umask($before);
if ($written !== strlen($new) || !chgrp($temporary, 'www-data')
    || !chmod($temporary, 0640) || !rename($temporary, QA_MAIL_CONFIG)) {
    @unlink($temporary);
    fwrite(STDERR, "Could not replace private QA mail settings.\n");
    exit(1);
}
echo "Disposable QA private mail settings converted without disclosing the key.\n";
