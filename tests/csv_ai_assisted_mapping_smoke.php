<?php
/**
 * AI-assisted CSV column mapping smoke (2026-02-XX).
 *
 * Static-analysis style — verifies the helper and endpoints exist with
 * the right shape, without actually calling OpenAI. Live OpenAI test
 * lives in ai_platform_smoke.php (expected-skip without keys).
 *
 * Validates:
 *   1. core/ai_csv_mapper.php exists and routes through aiCallOpenAI.
 *   2. aiSuggestColumnMap enforces tenant + classification feature gate.
 *   3. Helper uses JSON response format + AI_MODEL_CLASSIFICATION.
 *   4. Helper sanitises output (rejects invented field keys; preserves
 *      already-mapped pairs; coerces empty string to null).
 *   5. Each of the 7 csv_import endpoints exposes ?action=ai_suggest_map
 *      with RBAC, sample-row collection, and graceful AIDisabledException
 *      handling.
 *   6. CsvImportPage UI has an "Auto-map with AI" button that posts to
 *      the endpoint and merges suggestions into columnMap.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Core helper shape\n";
$h = $read(__DIR__ . '/../core/ai_csv_mapper.php');
$a('ai_csv_mapper.php exists',                $h !== '');
$a('includes ai_service.php',                 str_contains($h, "require_once __DIR__ . '/ai_service.php'"));
$a('declares aiSuggestColumnMap',             str_contains($h, 'function aiSuggestColumnMap'));
$a('enforces tenant gate',                    str_contains($h, 'aiGateForTenant'));
$a('uses classification feature class',       str_contains($h, "'classification'"));
$a('uses AI_MODEL_CLASSIFICATION',            str_contains($h, 'AI_MODEL_CLASSIFICATION'));
$a('requests json_object response format',    str_contains($h, "'response_format' => ['type' => 'json_object']"));
$a('falls back to AI_FALLBACK_MODEL',         str_contains($h, "\$model !== AI_FALLBACK_MODEL"));
$a('sanitises against allowed field keys',    str_contains($h, "in_array(\$raw, \$allowedKeys, true)"));
$a('preserves already-mapped pairs',          str_contains($h, '$alreadyMap as $h => $k'));
$a('coerces empty string to null',            str_contains($h, "\$raw === ''") || str_contains($h, "\$raw === '' || \$raw === false"));
$a('audit-writes on success',                 str_contains($h, "'status' => 'ok'"));
$a('audit-writes on non-JSON',                str_contains($h, "AIContractException"));
$a('truncates long sample cell values',       str_contains($h, "substr((string) \$v, 0, 60)"));
$a('uses guardrail-style system message',     str_contains($h, 'HARD RULES'));
$a('json-only output requirement',            str_contains($h, 'Return ONLY a JSON object'));
$a('returns suggestions, reasoning, model, latency_ms, interaction_id',
    str_contains($h, "'suggestions'")  &&
    str_contains($h, "'reasoning'")    &&
    str_contains($h, "'model'")        &&
    str_contains($h, "'latency_ms'")   &&
    str_contains($h, "'interaction_id'"));

echo "\nEndpoint coverage\n";
$endpoints = [
    'people'           => '/../modules/people/api/csv_import.php',
    'placements'       => '/../modules/placements/api/csv_import.php',
    'time'             => '/../modules/time/api/csv_import.php',
    'ap_vendors'       => '/../modules/ap/api/csv_import.php',
    'staffing_clients' => '/../modules/staffing/api/csv_import.php',
    'ap_bills'         => '/../modules/ap/api/bills_csv_import.php',
    'billing_invoices' => '/../modules/billing/api/csv_import.php',
];
foreach ($endpoints as $name => $rel) {
    $body = $read(__DIR__ . $rel);
    $a("{$name} exposes ?action=ai_suggest_map", str_contains($body, "action === 'ai_suggest_map'"));
    $a("{$name} requires RBAC permission",       str_contains($body, "ai_suggest_map'") && preg_match('/ai_suggest_map.*?RBAC::requirePermission/s', $body));
    $a("{$name} requires ai_csv_mapper.php",     str_contains($body, "require_once __DIR__ . '/../../../core/ai_csv_mapper.php'"));
    $a("{$name} forwards already_mapped",        str_contains($body, "already_mapped"));
    $a("{$name} reads up to 3 sample rows",      str_contains($body, "\$i < 3"));
    $a("{$name} passes feature_key per module",  str_contains($body, "csv.mapping.{$name}"));
    $a("{$name} surfaces AIDisabled as 503",     str_contains($body, "AIDisabledException") && str_contains($body, '503'));
}

echo "\nUI surface (CsvImportPage)\n";
$cmp = $read(__DIR__ . '/../dashboard/src/components/CsvImportPage.jsx');
$a('UI has aiSuggest function',                 str_contains($cmp, 'const aiSuggest'));
$a('UI posts to ?action=ai_suggest_map',        str_contains($cmp, "?action=ai_suggest_map"));
$a('UI forwards already_mapped pairs',          str_contains($cmp, 'already_mapped: already'));
$a('UI excludes null/unmapped from already',    str_contains($cmp, 'filter(([, v]) => v)'));
$a('UI merges AI suggestions into columnMap',   str_contains($cmp, '...(prev || {}), ...sugg'));
$a('UI invalidates dry-run after AI suggest',   preg_match('/aiSuggest.*setPreview\(null\)/s', $cmp) === 1);
$a('UI surfaces AI reasoning',                  str_contains($cmp, 'ai-reasoning'));
$a('UI surfaces AI errors separately',          str_contains($cmp, 'ai-error'));
$a('UI button has Auto-map with AI label',      str_contains($cmp, 'Auto-map with AI'));
$a('UI button has testid',                      str_contains($cmp, 'ai-suggest'));
$a('UI button shows loading state',             str_contains($cmp, 'AI mapping…'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
