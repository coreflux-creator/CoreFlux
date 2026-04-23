<?php
/**
 * CoreFlux Field Encryption — AES-256-GCM
 *
 * Used for PII / financial values at rest (SSN, bank routing/account numbers).
 * Columns store the raw ciphertext as VARBINARY; a companion `_last4` column
 * stores the last 4 plaintext digits for UI masking so the ciphertext never
 * has to be decrypted just to render a masked display.
 *
 * Key: COREFLUX_DATA_KEY (32-byte base64-encoded). Set once in the PHP host's
 * environment (core/config.local.php or Cloudways env). Never commit.
 *
 * Usage:
 *   $cipher = encryptField($ssn);      // VARBINARY bytes
 *   $last4  = last4($ssn);             // '1234'
 *   ... store $cipher + $last4 ...
 *   $ssn    = decryptField($cipher);   // plaintext — only when authorized
 */

function _coreflux_data_key(): string {
    static $key = null;
    if ($key !== null) return $key;

    $localConfig = __DIR__ . '/config.local.php';
    if (file_exists($localConfig)) require_once $localConfig;

    $b64 = (defined('COREFLUX_DATA_KEY') ? COREFLUX_DATA_KEY : (getenv('COREFLUX_DATA_KEY') ?: ''));
    if (!$b64) {
        throw new RuntimeException(
            'COREFLUX_DATA_KEY not configured. Generate with: '
            . 'php -r \'echo base64_encode(random_bytes(32));\''
        );
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException('COREFLUX_DATA_KEY must be 32 random bytes, base64-encoded');
    }
    $key = $raw;
    return $key;
}

/**
 * Encrypt a plaintext string. Returns raw bytes suitable for VARBINARY storage.
 * Format: [12-byte nonce][16-byte tag][ciphertext]
 */
function encryptField(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    $key   = _coreflux_data_key();
    $nonce = random_bytes(12);
    $tag   = '';
    $ct    = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('encryptField failed');
    return $nonce . $tag . $ct;
}

/**
 * Decrypt. Throws on tampering / bad key / corruption.
 */
function decryptField(?string $blob): ?string {
    if ($blob === null || $blob === '') return null;
    if (strlen($blob) < 28) throw new RuntimeException('encrypted blob too short');
    $key   = _coreflux_data_key();
    $nonce = substr($blob, 0, 12);
    $tag   = substr($blob, 12, 16);
    $ct    = substr($blob, 28);
    $pt    = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($pt === false) throw new RuntimeException('decryptField failed — tamper or wrong key');
    return $pt;
}

/**
 * Last 4 display helper. Strips non-digits first so it works for SSNs ('123-45-6789'),
 * bank account numbers, etc.
 */
function last4(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    $digits = preg_replace('/\D+/', '', $plaintext);
    return $digits === '' ? null : substr($digits, -4);
}

/**
 * Deterministic hash for de-dup / lookup without decrypting.
 * Uses HMAC-SHA256 with the data key so the same plaintext always produces
 * the same hash inside a tenant, but a different key (different CoreFlux install)
 * produces a different hash.
 */
function fieldHash(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    return hash_hmac('sha256', $plaintext, _coreflux_data_key());
}
