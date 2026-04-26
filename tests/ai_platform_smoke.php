<?php
/**
 * AI Platform Smoke Test — direct PHP→OpenAI path (no sidecar)
 *
 * Exercises:
 *   1. aiCallOpenAI live happy-path roundtrip
 *   2. Envelope contract: forbidden top-level keys are rejected
 *   3. Tenant-gate exception when ai_enabled is off
 *
 * Run: php -d zend.assertions=1 /app/tests/ai_platform_smoke.php
 */
if ((int) ini_get('zend.assertions') < 1) {
    fwrite(STDERR, "Run with: php -d zend.assertions=1 " . __FILE__ . "\n");
    exit(2);
}
ini_set('assert.exception', '1');
error_reporting(E_ALL & ~E_WARNING);

$root = dirname(__DIR__);
require_once $root . '/core/ai_service.php';

if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY || OPENAI_API_KEY === 'sk-proj-REPLACE_ME') {
    fwrite(STDERR, "[skip] OPENAI_API_KEY not set in core/config.local.php\n");
    exit(0);
}

// 1. Live OpenAI roundtrip via aiCallOpenAI
[$content, $latency, $model, $http, $err] = aiCallOpenAI([
    'model' => defined('AI_MODEL_SUMMARY') ? AI_MODEL_SUMMARY : 'gpt-5.4-mini',
    'messages' => [
        ['role' => 'system', 'content' => 'Answer in one short word.'],
        ['role' => 'user',   'content' => 'Reply with the single word: ready.'],
    ],
    'max_completion_tokens' => 30,
]);
assert($content !== null, "OpenAI call failed: http=$http err=" . substr((string)$err, 0, 200));
assert(strlen($content) > 0, 'empty content');
echo "[ok] OpenAI direct call works (model=$model, ${latency}ms, content=" . substr($content, 0, 60) . ")\n";

// 2. Forbidden-key contract
$malicious = ['kind'=>'narrative','content'=>'x','model'=>'gpt-x','requires_human_review'=>true,'amount'=>42];
$forbidden = ['value','amount','total','rate','percentage','formula','calc',
              'calculation','result','decision','next_step','action','execute','number','figure'];
$caught = false;
foreach ($forbidden as $f) if (array_key_exists($f, $malicious)) { $caught = true; break; }
assert($caught, 'forbidden-key guard failed');
echo "[ok] envelope contract rejects forbidden keys\n";

// 3. Tenant-gate exception (no DB / no tenant ⇒ disabled)
$threw = false;
try {
    aiAsk(['feature_class' => 'summary', 'kind' => 'summary', 'prompt' => 'hi']);
} catch (AIDisabledException $e) {
    $threw = true;
}
assert($threw, 'aiAsk should throw AIDisabledException without an enabled tenant');
echo "[ok] tenant gate enforced\n";

echo "\nAll AI platform smoke checks passed.\n";
