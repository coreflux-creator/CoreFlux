<?php
/**
 * AI-assisted column mapping for CSV imports.
 *
 * Modules calling Core\CsvImportService can optionally ask the LLM to
 * suggest a header → field mapping for columns the deterministic
 * auto-detect couldn't match (e.g. "FName", "Empl_Ext_Code",
 * "Bill_Per_Hour_Amt", "Customer Address Line 1"). The user always sees
 * and confirms the suggestion before the import runs — AI never silently
 * decides what data goes where (per AI_GUARDRAIL_SYSTEM in ai_service.php
 * and HARD_RULES on AI advisory-only output).
 *
 * Contract (one call → one suggestion):
 *   aiSuggestColumnMap([
 *     'feature_key'    => 'csv.mapping.people',
 *     'entity_label'   => 'People',
 *     'schema_fields'  => [['key'=>'first_name','label'=>'First name','required'=>true], ...],
 *     'headers'        => ['FName','LName','Mail','Skip me'],
 *     'sample_rows'    => [
 *        ['Alice','Smith','alice@ex.com','ignored'],
 *        ['Bob','Jones','bob@ex.com','also ignored'],
 *     ],
 *     'already_mapped' => ['Mail' => 'email'],   // optional, keep these as-is
 *   ]) => [
 *     'suggestions' => ['FName' => 'first_name', 'LName' => 'last_name', 'Mail' => 'email', 'Skip me' => null],
 *     'reasoning'   => 'FName clearly maps to first_name; Skip me has unstructured text.',
 *     'model'       => 'gpt-5.4-mini',
 *     'latency_ms'  => 412,
 *   ]
 */

require_once __DIR__ . '/ai_service.php';

function aiSuggestColumnMap(array $args): array
{
    $entityLabel  = (string) ($args['entity_label']  ?? 'records');
    $schemaFields = (array)  ($args['schema_fields'] ?? []);
    $headers      = (array)  ($args['headers']       ?? []);
    $sampleRows   = (array)  ($args['sample_rows']   ?? []);
    $alreadyMap   = (array)  ($args['already_mapped']?? []);
    $featureKey   = (string) ($args['feature_key']   ?? 'csv.mapping.generic');

    if (!$headers)      throw new InvalidArgumentException('aiSuggestColumnMap: headers required');
    if (!$schemaFields) throw new InvalidArgumentException('aiSuggestColumnMap: schema_fields required');

    $tenantId = currentTenantId();
    $userId   = $_SESSION['user']['id'] ?? null;

    // Tenant + per-feature gate. We piggyback on 'classification' since
    // mapping is a single-token labelling problem.
    $gate = aiGateForTenant($tenantId, 'classification');
    if (!$gate['tenant_enabled'])  throw new AIDisabledException('AI is disabled for this tenant');
    if (!$gate['feature_enabled']) throw new AIDisabledException("AI feature class 'classification' is disabled for this tenant");
    $logFullContent = (bool) $gate['full_content_logging'];

    $model = defined('AI_MODEL_CLASSIFICATION') ? AI_MODEL_CLASSIFICATION : AI_FALLBACK_MODEL;

    // Build a compact schema description for the prompt.
    $schemaDesc = [];
    foreach ($schemaFields as $f) {
        $line = "- {$f['key']} (label: \"{$f['label']}\""
              . (!empty($f['required']) ? ', REQUIRED' : '')
              . (!empty($f['type'])     ? ", type: {$f['type']}" : '')
              . ')';
        if (!empty($f['enum']) && is_array($f['enum'])) {
            $line .= '  enum: [' . implode('|', $f['enum']) . ']';
        }
        $schemaDesc[] = $line;
    }

    // Compact sample-row preview (truncate cell values for token thrift).
    $sampleLines = [];
    foreach (array_slice($sampleRows, 0, 3) as $rowIdx => $r) {
        $cells = [];
        foreach ($r as $i => $v) {
            $hdr  = $headers[$i] ?? "col{$i}";
            $val  = substr((string) $v, 0, 60);
            $cells[] = "{$hdr}=" . json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $sampleLines[] = "  row " . ($rowIdx + 1) . ": " . implode('; ', $cells);
    }

    $alreadyLines = [];
    foreach ($alreadyMap as $h => $k) {
        if ($k !== null && $k !== '') $alreadyLines[] = "  - \"{$h}\" → {$k}";
    }

    $systemMsg =
        "You are a CSV column-mapping assistant inside CoreFlux, a multi-tenant ERP.\n" .
        "Your only job is to match each SOURCE column header from the user's CSV to one " .
        "TARGET FIELD KEY from the provided schema, using header name + sample row values.\n" .
        "HARD RULES:\n" .
        "1. Return ONLY a JSON object. No prose, no markdown fences.\n" .
        "2. Use ONLY field keys present in the schema below — never invent new keys.\n" .
        "3. If a column does not match any schema field, set its value to null.\n" .
        "4. Never modify a header the user has already mapped — keep that pairing as-is.\n" .
        "5. Prefer required fields when a header looks ambiguous.\n" .
        "6. Your suggestion is reviewed by a human before any data is written, so play it safe:\n" .
        "   when unsure, choose null and let the user pick manually.";

    $userMsg =
        "Target entity: {$entityLabel}\n\n" .
        "Schema (target field keys you may use):\n" . implode("\n", $schemaDesc) . "\n\n" .
        "Source headers (verbatim from the user's CSV):\n  " .
        implode("\n  ", array_map(fn($h) => '"' . $h . '"', $headers)) . "\n\n" .
        "Sample rows (first " . count($sampleLines) . "):\n" . implode("\n", $sampleLines) . "\n\n" .
        ($alreadyLines
            ? "Already-mapped pairings (keep these unchanged):\n" . implode("\n", $alreadyLines) . "\n\n"
            : '') .
        "Return JSON shaped exactly as:\n" .
        "{\n" .
        "  \"suggestions\": {\n" .
        "    \"<source header>\": \"<target field key OR null>\"\n" .
        "  },\n" .
        "  \"reasoning\": \"<one short sentence explaining the calls>\"\n" .
        "}";

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemMsg],
            ['role' => 'user',   'content' => $userMsg],
        ],
        'max_completion_tokens' => 800,
        'response_format' => ['type' => 'json_object'],
    ];

    [$content, $latencyMs, $usedModel, $http, $rawErr] = aiCallOpenAI($payload);
    if ($content === null && $model !== AI_FALLBACK_MODEL) {
        $payload['model'] = AI_FALLBACK_MODEL;
        [$content, $latencyMs, $usedModel, $http, $rawErr] = aiCallOpenAI($payload);
    }
    if ($content === null) {
        aiAuditWrite([
            'tenant_id'     => $tenantId, 'user_id' => $userId,
            'feature_class' => 'classification', 'feature_key' => $featureKey,
            'kind'          => 'classification', 'status' => 'error',
            'http_status'   => $http, 'error' => substr((string) $rawErr, 0, 1000),
        ]);
        throw new RuntimeException("AI column mapping failed ($http): " . substr((string) $rawErr, 0, 300));
    }

    $cleaned = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/m', '', trim($content));
    $parsed  = json_decode((string) $cleaned, true);
    if (!is_array($parsed) || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
        aiAuditWrite([
            'tenant_id'     => $tenantId, 'user_id' => $userId,
            'feature_class' => 'classification', 'feature_key' => $featureKey,
            'kind'          => 'classification', 'status' => 'error',
            'http_status'   => $http, 'model' => $usedModel, 'latency_ms' => $latencyMs,
            'error'         => 'Non-conformant JSON: ' . substr($content, 0, 200),
            'response'      => $logFullContent ? $content : null,
        ]);
        throw new AIContractException('AI mapper returned non-conformant JSON');
    }

    // Sanitise: only allow known field keys; coerce empty strings to null.
    $allowedKeys = array_column($schemaFields, 'key');
    $sanitised   = [];
    foreach ($headers as $h) {
        $raw = $parsed['suggestions'][$h] ?? null;
        if ($raw === '' || $raw === false) $raw = null;
        if ($raw !== null && !in_array($raw, $allowedKeys, true)) $raw = null;
        $sanitised[$h] = $raw;
    }
    // Force-preserve already-mapped pairs.
    foreach ($alreadyMap as $h => $k) {
        if ($k !== null && $k !== '') $sanitised[$h] = $k;
    }

    $auditId = aiAuditWrite([
        'tenant_id'     => $tenantId, 'user_id' => $userId,
        'feature_class' => 'classification', 'feature_key' => $featureKey,
        'kind'          => 'classification', 'status' => 'ok',
        'http_status'   => $http, 'model' => $usedModel, 'latency_ms' => $latencyMs,
        'prompt_hash'   => hash('sha256', $systemMsg . $userMsg),
        'response_hash' => hash('sha256', $content),
        'prompt'        => $logFullContent ? ($systemMsg . "\n\n" . $userMsg) : null,
        'response'      => $logFullContent ? $content : null,
    ]);

    return [
        'suggestions'   => $sanitised,
        'reasoning'     => (string) ($parsed['reasoning'] ?? ''),
        'model'         => $usedModel,
        'latency_ms'    => $latencyMs,
        'interaction_id'=> $auditId,
    ];
}
