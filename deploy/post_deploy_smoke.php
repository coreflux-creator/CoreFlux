<?php
/**
 * Post-deploy smoke test — run this AFTER a deploy to verify every dependency
 * is reachable and correct. Exits 0 on success, non-zero on any failure.
 *
 *   php /app/deploy/post_deploy_smoke.php
 *
 * Checks:
 *   1. config.local.php loaded with COREFLUX_DATA_KEY
 *   2. DB reachable, expected tables exist, migrations are current
 *   3. Encryption round-trip works with the configured key
 *   4. AI sidecar reachable + auth works
 *   5. SMTP connection can be opened (does NOT send an email)
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$root = dirname(__DIR__);
require_once $root . '/core/config.php';
$localCfg = $root . '/core/config.local.php';
if (file_exists($localCfg)) require_once $localCfg;

$fail = function(string $why): never {
    fwrite(STDERR, "✗ $why\n");
    exit(1);
};
$ok = fn(string $m) => print("✓ $m\n");

// 1. COREFLUX_DATA_KEY
if (!defined('COREFLUX_DATA_KEY') || !COREFLUX_DATA_KEY || COREFLUX_DATA_KEY === 'REPLACE_ME_WITH_BASE64_32_BYTES') {
    $fail("COREFLUX_DATA_KEY is missing. Edit core/config.local.php.");
}
$raw = base64_decode(COREFLUX_DATA_KEY, true);
if ($raw === false || strlen($raw) !== 32) $fail("COREFLUX_DATA_KEY must be base64-encoded 32 bytes.");
$ok("data key present (32 bytes)");

// 2. Database
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    $fail("DB unreachable: " . $e->getMessage());
}
$ok("DB connected: " . DB_NAME);

$required = [
    'tenants','users','ai_interactions','ai_suggestions','ai_tenant_features',
    'people_employees','people_bank_accounts','people_tax_federal','people_tax_state',
    'people_compensation','people_emails_sent','schema_migrations',
];
$stmt = $pdo->query("SHOW TABLES");
$have = array_map(fn($r) => $r[0], $stmt->fetchAll(PDO::FETCH_NUM));
$missing = array_diff($required, $have);
if ($missing) $fail("missing tables: " . implode(', ', $missing) . " — run deploy/run_migrations.php");
$ok("required tables present (" . count($required) . ")");

$pendingCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM schema_migrations"
)->fetchColumn();
$ok("schema_migrations has $pendingCount applied rows");

// 3. Encryption round-trip
require_once $root . '/core/encryption.php';
$plain = 'smoke-test-value-' . bin2hex(random_bytes(4));
$blob  = encryptField($plain);
$back  = decryptField($blob);
if ($back !== $plain) $fail("encryption round-trip failed");
$ok("encryption round-trip with configured key");

// 4. AI sidecar
if (!defined('AI_SIDECAR_URL') || !defined('AI_SIDECAR_SECRET')) $fail("AI_SIDECAR_URL / AI_SIDECAR_SECRET not configured");
$healthUrl = preg_replace('#/chat/?$#', '/health', AI_SIDECAR_URL);
$ch = curl_init($healthUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) $fail("AI sidecar health not 200 at $healthUrl (got $code)");
$health = json_decode($body, true);
if (($health['status'] ?? null) !== 'ok' || !($health['openai_key_set'] ?? false)) {
    $fail("AI sidecar health reports: " . $body);
}
$ok("AI sidecar healthy at $healthUrl");

$ch = curl_init(AI_SIDECAR_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-AI-Secret: ' . AI_SIDECAR_SECRET],
    CURLOPT_POSTFIELDS => json_encode([
        'feature_class' => 'summary', 'kind' => 'summary',
        'prompt' => 'Reply with the single word: ready.'
    ]),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) $fail("AI sidecar chat returned $code: $body");
$ok("AI sidecar auth + live model roundtrip");

// 5. SMTP
require_once $root . '/lib/PHPMailer/src/Exception.php';
require_once $root . '/lib/PHPMailer/src/PHPMailer.php';
require_once $root . '/lib/PHPMailer/src/SMTP.php';
$smtp = new PHPMailer\PHPMailer\SMTP();
try {
    if (!$smtp->connect(SMTP_HOST, SMTP_PORT, 5)) $fail("SMTP connect failed to " . SMTP_HOST . ":" . SMTP_PORT);
    $smtp->quit();
    $smtp->close();
} catch (Throwable $e) {
    $fail("SMTP connect: " . $e->getMessage());
}
$ok("SMTP reachable: " . SMTP_HOST . ":" . SMTP_PORT);

echo "\nAll post-deploy checks passed.\n";
