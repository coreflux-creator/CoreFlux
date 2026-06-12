<?php
/**
 * AI Provider Boundary smoke.
 *
 * Product-path code may use:
 *   - aiAsk() for advisory prose
 *   - aiExtract() for document/image extraction
 *   - aiExtractJson() for text-only structured JSON drafts
 *   - AI Gateway / Tool Gateway for agentic runs and workers
 *
 * It may not call the low-level provider tuple helper directly.
 * Installer/deploy/live-smoke checks are the only non-service exceptions.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};
$ROOT = dirname(__DIR__);
$read = fn (string $p): string => is_file($p) ? (string) file_get_contents($p) : '';
$norm = fn (string $p): string => str_replace('\\', '/', substr($p, strlen($ROOT) + 1));

echo "Core structured JSON helper\n";
$svc = $read("{$ROOT}/core/ai_service.php");
$a('aiExtractJson exists',                         str_contains($svc, 'function aiExtractJson(array $args): array'));
$a('aiExtractJson enforces tenant feature gate',   str_contains($svc, '$gate = aiGateForTenant($tenantId, $featureClass);'));
$a('aiExtractJson forces json_object response',    str_contains($svc, "'response_format' => ['type' => 'json_object']"));
$a('aiExtractJson supports required_keys',         str_contains($svc, '$requiredKeys') && str_contains($svc, 'Missing required JSON keys'));
$a('aiExtractJson writes ai_interactions audit',   str_contains($svc, "'feature_class' => \$featureClass") && str_contains($svc, "'status' => 'ok'"));

echo "\nProvider tuple calls are isolated\n";
$allowedAiCall = [
    'core/ai_service.php',
    'core/installer_helpers.php',
];
$offenders = [];
foreach (['core', 'api', 'modules'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$ROOT}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
        $rel = $norm($f->getPathname());
        if (in_array($rel, $allowedAiCall, true)) continue;
        $body = $read($f->getPathname());
        if (str_contains($body, 'aiCallOpenAI(')) $offenders[] = $rel;
    }
}
$a('no core/api/module product path calls aiCallOpenAI directly', $offenders === []);
if ($offenders) echo "    offenders: " . implode(', ', $offenders) . "\n";

echo "\nNo provider credentials in module/API/UI code\n";
$secretOffenders = [];
foreach (['api', 'modules', 'dashboard/src'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$ROOT}/{$dir}", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, ['php','js','jsx','ts','tsx'], true)) continue;
        $body = $read($f->getPathname());
        if (str_contains($body, 'OPENAI_API_KEY') || str_contains($body, 'api.openai.com')) {
            $secretOffenders[] = $norm($f->getPathname());
        }
    }
}
$a('modules/API/UI do not reference OpenAI provider credentials or host', $secretOffenders === []);
if ($secretOffenders) echo "    offenders: " . implode(', ', $secretOffenders) . "\n";

echo "\nKnown structured callers use the shared helper\n";
$csv = $read("{$ROOT}/core/ai_csv_mapper.php");
$cat = $read("{$ROOT}/core/ai_categorization.php");
$settle = $read("{$ROOT}/modules/time/lib/settlement_ai.php");
$a('CSV mapping uses aiExtractJson',               str_contains($csv, 'aiExtractJson([') && !str_contains($csv, 'aiCallOpenAI('));
$a('categorization LLM fallback uses aiExtractJson', str_contains($cat, 'aiExtractJson([') && !str_contains($cat, 'aiCallOpenAI('));
$a('time settlement AI uses aiExtractJson',        str_contains($settle, 'aiExtractJson([') && !str_contains($settle, 'aiCallOpenAI('));

echo "\nGateway surfaces remain canonical for agentic AI\n";
$runs = $read("{$ROOT}/api/ai/runs.php");
$gateway = $read("{$ROOT}/core/ai/gateway.php");
$worker = $read("{$ROOT}/cron/ai_worker.php");
$a('/api/ai/runs.php invokes aiGatewayRunWithLlm', str_contains($runs, 'aiGatewayRunWithLlm('));
$a('gateway routes every tool through aiGatewayInvokeTool', str_contains($gateway, 'aiGatewayInvokeTool($runId, $name, $args, $callerCtx)'));
$a('worker dispatches through aiToolInvoke',       str_contains($worker, 'aiToolInvoke('));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
