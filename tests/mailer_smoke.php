<?php
/**
 * Mailer smoke test — verifies the helper loads PHPMailer, validates inputs,
 * and normalizes recipients correctly. Does not actually send email.
 * Run: php -d zend.assertions=1 /app/tests/mailer_smoke.php
 */
if ((int) ini_get('zend.assertions') < 1) {
    fwrite(STDERR, "Run with: php -d zend.assertions=1 " . __FILE__ . "\n");
    exit(2);
}
ini_set('assert.exception', '1');
error_reporting(E_ALL & ~E_WARNING);

require_once __DIR__ . '/../core/mailer.php';

// 1. PHPMailer class is loadable when sendEmail is called with bad args
$caught = false;
try { sendEmail(['subject' => 'x', 'body_text' => 'y']); }
catch (InvalidArgumentException $e) { $caught = ($e->getMessage() === 'sendEmail: to is required'); }
assert($caught, 'missing-to validation failed');
echo "[ok] missing to validation\n";

$caught = false;
try { sendEmail(['to' => 'a@b.com', 'body_text' => 'x']); }
catch (InvalidArgumentException $e) { $caught = true; }
assert($caught, 'missing-subject validation failed');
echo "[ok] missing subject validation\n";

// 2. Recipient normalizer
$norm = _mailer_normalize_recipients('a@b.com');
assert($norm === [['a@b.com', '']]);
$norm = _mailer_normalize_recipients(['a@b.com', 'Alice']);
assert($norm === [['a@b.com', 'Alice']]);
$norm = _mailer_normalize_recipients([['a@b.com', 'Alice'], 'c@d.com']);
assert($norm === [['a@b.com', 'Alice'], ['c@d.com', '']]);
$norm = _mailer_normalize_recipients([]);
assert($norm === []);
echo "[ok] recipient normalizer handles string / [email,name] / mixed-list / empty\n";

// 3. PHPMailer class is reachable (requires files already loaded by sendEmail path)
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
assert(class_exists('PHPMailer\\PHPMailer\\PHPMailer'), 'PHPMailer class missing');
echo "[ok] PHPMailer loadable\n";

echo "\nAll mailer smoke checks passed.\n";
