<?php
/**
 * Post-deploy smoke test — run after every deploy to verify the stack.
 *   php /app/deploy/post_deploy_smoke.php
 *
 * Checks:
 *   1. config.local.php loaded with COREFLUX_DATA_KEY + OPENAI_API_KEY
 *   2. DB reachable, expected tables exist, migrations are current
 *   3. Encryption round-trip with the configured key
 *   4. Live OpenAI roundtrip via core/ai_service.php direct path
 *   5. SMTP connection can be opened (does NOT send mail)
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(2); }

$root = dirname(__DIR__);
require_once $root . '/core/config.php';
$localCfg = $root . '/core/config.local.php';
if (file_exists($localCfg)) require_once $localCfg;

$fail = function(string $why): never { fwrite(STDERR, "✗ $why\n"); exit(1); };
$ok   = fn(string $m) => print("✓ $m\n");

// 1. Required secrets
if (!defined('COREFLUX_DATA_KEY') || !COREFLUX_DATA_KEY || COREFLUX_DATA_KEY === 'REPLACE_ME_WITH_BASE64_32_BYTES') {
    $fail("COREFLUX_DATA_KEY missing in core/config.local.php");
}
$raw = base64_decode(COREFLUX_DATA_KEY, true);
if ($raw === false || strlen($raw) !== 32) $fail("COREFLUX_DATA_KEY must be base64(32 bytes)");
$ok("data key present (32 bytes)");

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY || OPENAI_API_KEY === 'sk-proj-REPLACE_ME') {
    $fail("OPENAI_API_KEY missing in core/config.local.php");
}
$ok("OpenAI key configured");

// 2. Database
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) { $fail("DB unreachable: " . $e->getMessage()); }
$ok("DB connected: " . DB_NAME);

$required = [
    'tenants','users','ai_interactions','ai_suggestions','ai_tenant_features',
    'people_employees','people_bank_accounts','people_tax_federal','people_tax_state',
    'people_compensation','people_emails_sent','coreflux_migrations',
    'payroll_settings','payroll_pay_schedules','payroll_pay_periods','payroll_profiles',
    'payroll_runs','payroll_line_items','payroll_earnings','payroll_taxes','payroll_deductions',
];
$have = array_map(fn($r) => $r[0], $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM));
$missing = array_diff($required, $have);
if ($missing) $fail("missing tables: " . implode(', ', $missing) . " — run deploy/run_migrations.php");
$ok("required tables present (" . count($required) . ")");

$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM coreflux_migrations")->fetchColumn();
$ok("coreflux_migrations has $pendingCount applied rows");

// 3. Encryption
require_once $root . '/core/encryption.php';
$plain = 'smoke-test-' . bin2hex(random_bytes(4));
if (decryptField(encryptField($plain)) !== $plain) $fail("encryption round-trip failed");
$ok("encryption round-trip with configured key");

// 4. OpenAI direct call
require_once $root . '/core/ai_service.php';
[$content, $latency, $model, $http, $err] = aiCallOpenAI([
    'model' => defined('AI_MODEL_SUMMARY') ? AI_MODEL_SUMMARY : 'gpt-5.4-mini',
    'messages' => [
        ['role' => 'system', 'content' => 'You answer in one short word.'],
        ['role' => 'user',   'content' => 'Reply with the single word: ready.'],
    ],
    'max_completion_tokens' => 30,
]);
if ($content === null) $fail("OpenAI call failed (http $http): " . substr((string)$err, 0, 200));
$ok("OpenAI direct call works (model=$model, ${latency}ms)");

// 5. SMTP (connect-only, does not send)
require_once $root . '/lib/PHPMailer/src/Exception.php';
require_once $root . '/lib/PHPMailer/src/PHPMailer.php';
require_once $root . '/lib/PHPMailer/src/SMTP.php';
$smtp = new PHPMailer\PHPMailer\SMTP();
try {
    if (!$smtp->connect(SMTP_HOST, SMTP_PORT, 5)) $fail("SMTP connect failed to " . SMTP_HOST . ":" . SMTP_PORT);
    $smtp->quit();
    $smtp->close();
} catch (Throwable $e) { $fail("SMTP connect: " . $e->getMessage()); }
$ok("SMTP reachable: " . SMTP_HOST . ":" . SMTP_PORT);

echo "\nAll post-deploy checks passed.\n";
