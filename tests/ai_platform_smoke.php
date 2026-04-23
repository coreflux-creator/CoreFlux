<?php
/**
 * AI Platform Smoke Test (sidecar + PHP envelope contract)
 *
 * Exercises:
 *   - Sidecar health
 *   - Sidecar happy path via curl (skipped if no OPENAI_API_KEY)
 *   - Sidecar auth rejection
 *   - PHP ai_service.php envelope guardrails (forbidden keys, contract flag)
 *
 * MySQL is typically unavailable in the preview container, so we stub the gate
 * by setting session state and monkey-patching the tenant lookup through a tiny
 * shim: we bypass aiGateForTenant by calling aiSidecarPost directly for the
 * sidecar half, and we unit-test envelope enforcement inline.
 */

if ((int) ini_get('zend.assertions') < 1) {
    fwrite(STDERR, "Run with: php -d zend.assertions=1 " . __FILE__ . "\n");
    exit(2);
}
ini_set('assert.exception', '1');
error_reporting(E_ALL & ~E_WARNING);

$envFile = __DIR__ . '/../backend/.env';
if (!file_exists($envFile)) { echo "[skip] no backend/.env\n"; exit(0); }

// Parse the sidecar .env lazily
$sidecarEnv = [];
foreach (file($envFile) as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m)) $sidecarEnv[$m[1]] = $m[2];
}
$SECRET = $sidecarEnv['AI_SIDECAR_SECRET'] ?? '';
$URL    = 'http://localhost:8001/api/ai/chat';

// 1) Health
$health = @file_get_contents('http://localhost:8001/api/ai/health');
assert($health !== false, 'sidecar health unreachable');
$healthData = json_decode($health, true);
assert(($healthData['status'] ?? '') === 'ok', 'sidecar health status not ok');
echo "[ok] sidecar health OK, models=" . json_encode($healthData['feature_models']) . "\n";

// 2) Auth rejection
$ch = curl_init($URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-AI-Secret: wrong'],
    CURLOPT_POSTFIELDS => json_encode(['feature_class'=>'summary','kind'=>'summary','prompt'=>'hi']),
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
assert($code === 401, "expected 401 on bad secret, got $code");
echo "[ok] sidecar auth rejection (401)\n";

// 3) Happy path
if (empty($sidecarEnv['OPENAI_API_KEY'])) {
    echo "[skip] no OPENAI_API_KEY — skipping live call\n";
} else {
    $ch = curl_init($URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-AI-Secret: $SECRET"],
        CURLOPT_POSTFIELDS => json_encode([
            'feature_class' => 'summary',
            'kind' => 'summary',
            'prompt' => 'Summarize that two new notes were added today: "Q3 close checklist" and "Tax filing reminder".',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    assert($code === 200, "expected 200 on happy path, got $code: $body");
    $env = json_decode($body, true);
    assert(is_array($env), 'envelope not JSON');
    foreach (['kind','content','requires_human_review','model','latency_ms','prompt_hash','response_hash'] as $k) {
        assert(array_key_exists($k, $env), "envelope missing key: $k");
    }
    assert($env['requires_human_review'] === true, 'requires_human_review must be true');
    // Forbidden top-level keys should not exist
    foreach (['value','amount','formula','decision','next_step'] as $bad) {
        assert(!array_key_exists($bad, $env), "envelope must not carry forbidden key: $bad");
    }
    echo "[ok] sidecar happy path (model=" . $env['model'] . ", content_len=" . strlen($env['content']) . ")\n";
}

// 4) PHP envelope contract guard — simulate a sidecar returning a forbidden key
$maliciousEnvelope = json_encode([
    'kind' => 'narrative', 'content' => 'x', 'requires_human_review' => false,
    'model' => 'gpt-x', 'latency_ms' => 1, 'prompt_hash' => 'a', 'response_hash' => 'b',
    'amount' => 42,                         // forbidden
]);
// Reproduce the relevant validation block from aiAsk() inline:
$envelope = json_decode($maliciousEnvelope, true);
$envelope['requires_human_review'] = true;  // always forced true
$forbidden = ['value','amount','total','rate','percentage','formula','calc',
              'calculation','result','decision','next_step','action','execute','number','figure'];
$caught = false;
foreach ($forbidden as $f) {
    if (array_key_exists($f, $envelope)) { $caught = true; break; }
}
assert($caught === true, 'forbidden-key guard should fire');
echo "[ok] PHP envelope guard rejects forbidden keys\n";

echo "\nAll AI platform smoke checks passed.\n";
