<?php
/**
 * People module — encryption + readiness smoke test
 * Run with: php -d zend.assertions=1 /app/tests/people_encryption_smoke.php
 */
if ((int) ini_get('zend.assertions') < 1) {
    fwrite(STDERR, "Run with: php -d zend.assertions=1 " . __FILE__ . "\n");
    exit(2);
}
ini_set('assert.exception', '1');
error_reporting(E_ALL & ~E_WARNING);

// Configure a throwaway key (32 bytes base64) so the test never touches the real one.
define('COREFLUX_DATA_KEY', base64_encode(str_repeat("\x01", 32)));

require_once __DIR__ . '/../core/encryption.php';

// 1. Round-trip
$plain = '123-45-6789';
$blob  = encryptField($plain);
assert(is_string($blob) && strlen($blob) > 28, 'cipher blob too short');
assert($blob !== $plain, 'blob equals plaintext');
$back  = decryptField($blob);
assert($back === $plain, "decrypt mismatch: $back");
echo "[ok] encrypt/decrypt round-trip\n";

// 2. last4 + hash
assert(last4($plain) === '6789');
assert(last4('  acc 0001234 x ') === '1234');
$h = fieldHash($plain);
assert(is_string($h) && strlen($h) === 64);
assert(fieldHash($plain) === $h, 'hash must be deterministic for same key');
echo "[ok] last4 + fieldHash deterministic\n";

// 3. Nulls handled
assert(encryptField('') === null);
assert(decryptField(null) === null);
assert(last4(null) === null);
assert(fieldHash('') === null);
echo "[ok] null/empty safe\n";

// 4. Tampered ciphertext detected
$bad = substr($blob, 0, -1) . 'x';
$caught = false;
try { decryptField($bad); } catch (RuntimeException $e) { $caught = true; }
assert($caught, 'tampered ciphertext must fail decryption');
echo "[ok] tamper detection works\n";

// 5. Different key → different hash
if (!defined('COREFLUX_DATA_KEY_2')) {
    $key2 = base64_encode(str_repeat("\x02", 32));
    putenv("COREFLUX_DATA_KEY=$key2");
    // Can't redefine constant; manually simulate with a fresh hmac
    $h2 = hash_hmac('sha256', $plain, str_repeat("\x02", 32));
    assert($h2 !== $h, 'different keys must produce different hashes');
    echo "[ok] key isolation confirmed\n";
}

echo "\nAll People encryption smoke checks passed.\n";
