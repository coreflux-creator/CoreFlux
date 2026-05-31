<?php
/**
 * mercury_webhooks_smoke.php
 *
 * Mercury Webhooks integration — push-based payment state advancement.
 *
 *   • migration 087 — mercury_webhook_endpoints + mercury_webhook_events
 *   • core/mercury_webhooks.php —
 *       - mercuryWebhookGet / mercuryWebhookGetSecret
 *       - mercuryWebhookSaveSecret (AES-256-GCM via encryptField)
 *       - mercuryWebhookPause
 *       - mercuryWebhookVerifySignature (functional HMAC-SHA256 test)
 *       - mercuryWebhookRecordEvent (idempotent on event_id)
 *       - mercuryWebhookProcessEvent (advances mpAdvance on match)
 *   • api/webhooks/mercury.php — POST receiver, returns 200 always,
 *     persists every event, verifies signature, calls processor on
 *     fresh+verified events.
 *   • api/admin/treasury/mercury_webhook.php — admin config GET/POST/PATCH
 *   • modules/treasury/ui/MercuryWebhookConfig.jsx — admin page
 *   • TreasuryModule.jsx — tab + route wired
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Mercury Webhooks smoke\n";
echo "======================\n\n";

$ROOT = dirname(__DIR__);

// --- migration 087 ----------------------------------------------
echo "core/migrations/087_mercury_webhooks.sql\n";
$mig = $read("{$ROOT}/core/migrations/087_mercury_webhooks.sql");
$a('migration file exists',                       $mig !== '');
$a('creates mercury_webhook_endpoints',           str_contains($mig, 'CREATE TABLE IF NOT EXISTS mercury_webhook_endpoints'));
$a('endpoint signing_secret_ct VARBINARY',        str_contains($mig, 'signing_secret_ct    VARBINARY(512) NOT NULL'));
$a('endpoint signing_secret_last4 for display',   str_contains($mig, 'signing_secret_last4 VARCHAR(8) NOT NULL'));
$a('endpoint status ENUM active/paused/error',    str_contains($mig, "status               ENUM('active','paused','error')"));
$a('endpoint unique on tenant_id',                str_contains($mig, 'UNIQUE KEY uq_mwh_tenant'));
$a('creates mercury_webhook_events',              str_contains($mig, 'CREATE TABLE IF NOT EXISTS mercury_webhook_events'));
$a('events.event_id PRIMARY KEY (dedupe)',        str_contains($mig, 'event_id          VARCHAR(80) NOT NULL PRIMARY KEY'));
$a('events.verified flag',                        str_contains($mig, 'verified          TINYINT(1) NOT NULL DEFAULT 0'));
$a('events.processing_outcome column',            str_contains($mig, 'processing_outcome VARCHAR(40)'));
$a('events.payment_instruction_id column',        str_contains($mig, 'payment_instruction_id INT UNSIGNED NULL'));
$a('events.payload_json is MEDIUMTEXT',           str_contains($mig, 'payload_json      MEDIUMTEXT'));
$a('index on (tenant_id, resource_type, resource_id)',
    str_contains($mig, 'ix_mwh_resource        (tenant_id, resource_type, resource_id)'));

// --- core/mercury_webhooks.php (static surface) -----------------
echo "\ncore/mercury_webhooks.php (surface)\n";
$lib = $read("{$ROOT}/core/mercury_webhooks.php");
$a('declares strict_types',                       str_contains($lib, 'declare(strict_types=1);'));
$a('requires encryption.php',                     str_contains($lib, "require_once __DIR__ . '/encryption.php';"));
$a('MERCURY_WEBHOOK_REPLAY_TOLERANCE_SEC=300',    str_contains($lib, 'MERCURY_WEBHOOK_REPLAY_TOLERANCE_SEC = 300'));

foreach ([
    'mercuryWebhookGet', 'mercuryWebhookGetSecret',
    'mercuryWebhookSaveSecret', 'mercuryWebhookPause',
    'mercuryWebhookVerifySignature', 'mercuryWebhookRecordEvent',
    'mercuryWebhookProcessEvent', 'mercuryWebhookFinalize',
    'mercuryWebhookRecentEvents',
] as $fn) {
    $a("declares {$fn}()",                        str_contains($lib, "function {$fn}"));
}

$a('saveSecret rejects empty',                    str_contains($lib, 'signing secret required'));
$a('saveSecret rejects too-short (<16)',          str_contains($lib, 'signing secret must be ≥ 16 characters'));
$a('saveSecret encrypts via encryptField',        str_contains($lib, '$ct = encryptField($secret);'));
$a('saveSecret stores last4 only',                str_contains($lib, 'substr($secret, -4)'));
$a('saveSecret upserts via ON DUPLICATE KEY',     str_contains($lib, 'ON DUPLICATE KEY UPDATE'));

$a('getSecret returns null when paused/missing',  str_contains($lib, "if (\$row['status'] !== 'active') return null;"));
$a('getSecret decrypts via decryptField',         str_contains($lib, 'decryptField($row[\'signing_secret_ct\'])'));

$a('verify parses t= and v1= header parts',       str_contains($lib, "if (\$k === 't')  \$ts  = \$v;")
                                               && str_contains($lib, "if (\$k === 'v1') \$sig = \$v;"));
$a('verify rejects malformed header',             str_contains($lib, "'error' => 'header_format'"));
$a('verify enforces 5min replay tolerance',       str_contains($lib, "if (\$skew > \$toleranceSec)")
                                               && str_contains($lib, "'error' => 'timestamp_skew'"));
$a('verify uses hash_hmac sha256',                str_contains($lib, "hash_hmac('sha256', \$signedPayload, \$secret)"));
$a('verify uses signed payload "ts.body"',        str_contains($lib, '$signedPayload = $ts . \'.\' . $rawBody;'));
$a('verify constant-time compare via hash_equals',str_contains($lib, 'hash_equals($expected, strtolower($sig))'));

$a('record dedupes via ON DUPLICATE KEY',         str_contains($lib, "INSERT INTO mercury_webhook_events")
                                               && str_contains($lib, 'ON DUPLICATE KEY UPDATE'));
$a('record returns true only on fresh INSERT',    str_contains($lib, 'return $stmt->rowCount() === 1;'));
$a('record synthesises id when payload omits',    str_contains($lib, "'no-id-' . bin2hex(random_bytes(8))"));

$a('process skips non transaction.update events', str_contains($lib, "\$resourceType !== 'transaction' || \$opType !== 'update'"));
$a('process requires mergePatch.status changed',  str_contains($lib, "!array_key_exists('status', \$mergePatch)"));
$a('process looks up PI by funding OR payout txn id',
    str_contains($lib, '(funding_mercury_txn_id = :rid_f OR payout_mercury_txn_id = :rid_p)'));
$a('process calls mpAdvance on match',            str_contains($lib, '$after = mpAdvance($tenantId, $piId);'));
$a('process records outcome=advanced on success', str_contains($lib, "\$outcome = 'advanced';"));
$a('process records outcome=no_match when miss',  str_contains($lib, "\$outcome = 'no_match';"));
$a('finalize stamps endpoint last_event_at',      str_contains($lib, 'SET last_event_at = NOW()'));

// --- functional HMAC signature verification ----------------------
echo "\nFunctional HMAC verification\n";
require_once "{$ROOT}/core/mercury_webhooks.php";

$secret    = 'whsec_test_secret_minimum_16_chars';
$body      = '{"id":"evt_abc","resourceType":"transaction","operationType":"update","resourceId":"txn_xyz","mergePatch":{"status":"sent"}}';
$ts        = time();
$signedPayload = $ts . '.' . $body;
$validSig  = hash_hmac('sha256', $signedPayload, $secret);
$header    = "t={$ts},v1={$validSig}";

$res = mercuryWebhookVerifySignature($header, $body, $secret);
$a('valid signature verifies ok',                 $res['ok'] === true && $res['error'] === null);
$a('valid signature returns correct timestamp',   $res['timestamp'] === $ts);

$bad = mercuryWebhookVerifySignature("t={$ts},v1=" . str_repeat('0', 64), $body, $secret);
$a('bad signature fails with signature_mismatch', $bad['ok'] === false && $bad['error'] === 'signature_mismatch');

$tampered = mercuryWebhookVerifySignature($header, $body . 'tamper', $secret);
$a('body tampering breaks verification',          $tampered['ok'] === false && $tampered['error'] === 'signature_mismatch');

$old = $ts - 600;
$oldSigned = $old . '.' . $body;
$oldSig    = hash_hmac('sha256', $oldSigned, $secret);
$oldHeader = "t={$old},v1={$oldSig}";
$replay = mercuryWebhookVerifySignature($oldHeader, $body, $secret);
$a('10-minute-old signature rejected (replay)',   $replay['ok'] === false && $replay['error'] === 'timestamp_skew');

$missingT = mercuryWebhookVerifySignature("v1={$validSig}", $body, $secret);
$a('missing t= rejected as header_format',        $missingT['ok'] === false && $missingT['error'] === 'header_format');

$empty = mercuryWebhookVerifySignature('', $body, $secret);
$a('empty header rejected as header_missing',     $empty['ok'] === false && $empty['error'] === 'header_missing');

$uppercase = mercuryWebhookVerifySignature("t={$ts},v1=" . strtoupper($validSig), $body, $secret);
$a('uppercase hex still verifies (lenient)',      $uppercase['ok'] === true);

// --- api/webhooks/mercury.php -----------------------------------
echo "\napi/webhooks/mercury.php\n";
$rcv = $read("{$ROOT}/api/webhooks/mercury.php");
$a('file exists',                                 $rcv !== '');
$a('requires api_bootstrap (no auth wall before us)',
    str_contains($rcv, "require_once __DIR__ . '/../../core/api_bootstrap.php';"));
$a('requires core/mercury_webhooks.php',          str_contains($rcv, "require_once __DIR__ . '/../../core/mercury_webhooks.php';"));
$a('GET returns 200 for liveness probe',          str_contains($rcv, "if (api_method() !== 'POST')"));
$a('reads tenant_id from query param ?t=',        str_contains($rcv, "(int) (\$_GET['t'] ?? 0)"));
$a('reads raw body via php://input',              str_contains($rcv, "file_get_contents('php://input')"));
$a('reads mercury-signature header (lowercased)', str_contains($rcv, "\$headers['mercury-signature']"));
$a('looks up tenant secret via helper',           str_contains($rcv, 'mercuryWebhookGetSecret($tenantId)'));
$a('records no_active_endpoint when secret null', str_contains($rcv, "\$verifyError = 'no_active_endpoint';"));
$a('records header_missing when sig empty',       str_contains($rcv, "\$verifyError = 'header_missing';"));
$a('json decode throws caught',                   str_contains($rcv, 'JSON_THROW_ON_ERROR'));
$a('persists event even when malformed',          str_contains($rcv, "'malformed-' . bin2hex(random_bytes(6))"));
$a('records event via mercuryWebhookRecordEvent', str_contains($rcv, 'mercuryWebhookRecordEvent('));
$a('processes only fresh + verified events',      str_contains($rcv, 'if ($verified && $isFresh && $eventId !== \'\')'));
$a('calls mercuryWebhookProcessEvent',            str_contains($rcv, 'mercuryWebhookProcessEvent($tenantId, $eventId, $event)'));
$a('always responds via api_ok (never 5xx)',      str_contains($rcv, 'api_ok([')
                                                && !str_contains($rcv, 'api_error('));
$a('response surfaces verify_error to operators', str_contains($rcv, "'verify_error' => \$verifyError,"));

// --- api/admin/treasury/mercury_webhook.php ---------------------
echo "\napi/admin/treasury/mercury_webhook.php\n";
$adm = $read("{$ROOT}/api/admin/treasury/mercury_webhook.php");
$a('file exists',                                 $adm !== '');
$a('rbac_legacy_require accounting.bank.manage',  str_contains($adm, "rbac_legacy_require(\$user, 'accounting.bank.manage');"));
$a('GET returns endpoint + recent_events + url',  str_contains($adm, "'endpoint'      => mercuryWebhookGet(\$tid)")
                                               && str_contains($adm, "'recent_events' => mercuryWebhookRecentEvents(\$tid"));
$a('POST validates signing_secret via saveSecret',str_contains($adm, 'mercuryWebhookSaveSecret('));
$a('PATCH requires paused boolean',               str_contains($adm, "paused boolean required"));
$a('PATCH delegates to mercuryWebhookPause',      str_contains($adm, "mercuryWebhookPause(\$tid, (bool) \$body['paused'])"));
$a('delivery URL built from APP_URL constant',    str_contains($adm, "APP_URL"));
$a('delivery URL appends ?t=<tenant_id>',         str_contains($adm, "'/api/webhooks/mercury.php?t=' . \$tid"));

// --- frontend MercuryWebhookConfig.jsx --------------------------
echo "\nmodules/treasury/ui/MercuryWebhookConfig.jsx\n";
$jsx = $read("{$ROOT}/modules/treasury/ui/MercuryWebhookConfig.jsx");
$a('component file exists',                       $jsx !== '');
$a('default exports MercuryWebhookConfig',        str_contains($jsx, 'export default function MercuryWebhookConfig()'));
$a('GET /api/admin/treasury/mercury_webhook.php', str_contains($jsx, "api.get('/api/admin/treasury/mercury_webhook.php')"));
$a('POST submits signing_secret',                 str_contains($jsx, "api.post('/api/admin/treasury/mercury_webhook.php'"));
$a('PATCH toggles paused via api.patch',          str_contains($jsx, "api.patch('/api/admin/treasury/mercury_webhook.php', { paused: next })"));
$a('renders delivery URL with testid',            str_contains($jsx, 'data-testid="mercury-webhook-delivery-url"'));
$a('renders copy button with testid',             str_contains($jsx, 'data-testid="mercury-webhook-copy-url"'));
$a('secret input has testid',                     str_contains($jsx, 'data-testid="mercury-webhook-secret-input"'));
$a('save button has testid + disabled <16 chars', str_contains($jsx, 'data-testid="mercury-webhook-secret-save"')
                                               && str_contains($jsx, 'secretInput.length < 16'));
$a('pause toggle has testid',                     str_contains($jsx, 'data-testid="mercury-webhook-toggle-pause"'));
$a('empty events placeholder testid',             str_contains($jsx, 'data-testid="mercury-webhook-events-empty"'));
$a('events table has testid',                     str_contains($jsx, 'data-testid="mercury-webhook-events-table"'));
$a('per-event row has dynamic testid',            str_contains($jsx, "data-testid={`mercury-webhook-event-\${ev.event_id}`}"));

// --- TreasuryModule wiring --------------------------------------
echo "\nmodules/treasury/ui/TreasuryModule.jsx\n";
$tm = $read("{$ROOT}/modules/treasury/ui/TreasuryModule.jsx");
$a('imports MercuryWebhookConfig',                str_contains($tm, "import MercuryWebhookConfig   from './MercuryWebhookConfig';"));
$a('tab renders "Webhooks"',                      str_contains($tm, '<TreasuryTab to="mercury-webhooks"   label="Webhooks" />'));
$a('route mounts MercuryWebhookConfig',           str_contains($tm, '<Route path="mercury-webhooks"   element={<MercuryWebhookConfig />}'));

// --- PHP syntax checks ------------------------------------------
echo "\nPHP syntax checks\n";
foreach ([
    'core/mercury_webhooks.php',
    'api/webhooks/mercury.php',
    'api/admin/treasury/mercury_webhook.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Mercury Webhooks: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
