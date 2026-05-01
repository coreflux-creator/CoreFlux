<?php
/**
 * CoreFlux AI Service — single chokepoint for LLM calls.
 *
 * PHP calls OpenAI directly via cURL. No sidecar.
 *
 * Modules MUST go through aiAsk() — never call OpenAI directly. That keeps
 *   - the response-shape contract enforced in one place
 *   - tenant + per-feature toggles enforced in one place
 *   - the audit log honest
 *
 * Configuration (in core/config.local.php on each host):
 *     define('OPENAI_API_KEY', 'sk-proj-...');     // required
 *     define('AI_MODEL_SUMMARY',        'gpt-5.4-mini');
 *     define('AI_MODEL_NARRATIVE',      'gpt-5.4');
 *     define('AI_MODEL_DRAFT',          'gpt-5.4');
 *     define('AI_MODEL_CLASSIFICATION', 'gpt-5.4-mini');
 *     define('AI_MODEL_DEEP_REASONING', 'gpt-5.4-thinking');
 *     define('AI_FALLBACK_MODEL',       'gpt-5.2');
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant_scope.php';

// Load host-only secrets
$_localConfig = __DIR__ . '/config.local.php';
if (file_exists($_localConfig)) require_once $_localConfig;

if (!defined('OPENAI_API_URL')) define('OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions');

// Default model map — override any of these in config.local.php
$_defaults = [
    'AI_MODEL_SUMMARY'        => 'gpt-5.4-mini',
    'AI_MODEL_NARRATIVE'      => 'gpt-5.4',
    'AI_MODEL_DRAFT'          => 'gpt-5.4',
    'AI_MODEL_CLASSIFICATION' => 'gpt-5.4-mini',
    'AI_MODEL_DEEP_REASONING' => 'gpt-5.4-thinking',
    'AI_FALLBACK_MODEL'       => 'gpt-5.2',
];
foreach ($_defaults as $k => $v) if (!defined($k)) define($k, $v);

class AIDisabledException  extends RuntimeException {}
class AIContractException  extends RuntimeException {}

const AI_GUARDRAIL_SYSTEM =
    "You are a business narrative assistant embedded in CoreFlux, a multi-tenant ERP.\n" .
    "HARD RULES — violating any of these is a critical failure:\n" .
    "1. You NEVER output numbers the application could use in a calculation. " .
    "If you reference a number from the provided context, wrap it in natural language and " .
    "cite it — do not present it as a raw value the system could parse.\n" .
    "2. You NEVER output formulas, decisions, or tasks the system should auto-execute. " .
    "All of your output is advisory for a human reader.\n" .
    "3. You NEVER output JSON, code, or structured data unless the caller explicitly asks " .
    "for a classification label.\n" .
    "4. If asked to produce a value, formula, or decision, refuse and explain that the " .
    "application's deterministic logic must produce it.\n" .
    "5. Everything you produce will be reviewed by a human before any system uses it.\n";

const AI_KIND_HINTS = [
    'narrative'      => 'Produce a short natural-language narrative. 1–3 short paragraphs.',
    'summary'        => 'Produce a concise bulleted summary. 3–6 bullets, each one sentence.',
    'suggestion'     => 'Produce a draft suggestion a human will edit and approve. Plain prose.',
    'classification' => 'Return a single short label followed by a one-sentence rationale. Format: "LABEL — rationale".',
    'question'       => 'Produce a clarifying question for the human user. One sentence.',
];

const AI_FEATURE_CLASS_TO_MODEL_CONST = [
    'summary'        => 'AI_MODEL_SUMMARY',
    'narrative'      => 'AI_MODEL_NARRATIVE',
    'draft'          => 'AI_MODEL_DRAFT',
    'classification' => 'AI_MODEL_CLASSIFICATION',
    'deep_reasoning' => 'AI_MODEL_DEEP_REASONING',
];

const AI_FORBIDDEN_KEYS = [
    'value','amount','total','rate','percentage','formula','calc',
    'calculation','result','decision','next_step','action','execute','number','figure',
];

/**
 * Module-facing entry point. Returns the standard envelope:
 *   ['kind','content','confidence','citations','requires_human_review',
 *    'model','latency_ms','prompt_hash','response_hash','interaction_id']
 *
 * @throws AIDisabledException  tenant or feature is off
 * @throws AIContractException  bad response / forbidden key
 * @throws RuntimeException     transport/HTTP failure
 */
function aiAsk(array $args): array {
    $feature_class = $args['feature_class'] ?? 'narrative';
    $kind          = $args['kind']          ?? 'narrative';
    $feature_key   = $args['feature_key']   ?? ($feature_class . '.generic');
    $system        = $args['system']        ?? null;
    $prompt        = trim((string)($args['prompt'] ?? ''));
    $context       = $args['context']       ?? null;
    $citations     = $args['citations']     ?? null;
    $max_tokens    = (int)($args['max_output_tokens'] ?? 800);

    if ($prompt === '') throw new InvalidArgumentException('aiAsk: prompt is required');
    if (!isset(AI_KIND_HINTS[$kind])) throw new InvalidArgumentException("aiAsk: invalid kind '$kind'");

    $tenantId = currentTenantId();
    $userId   = $_SESSION['user']['id'] ?? null;

    // Tenant + per-feature gating
    $gate = aiGateForTenant($tenantId, $feature_class);
    if (!$gate['tenant_enabled'])  throw new AIDisabledException('AI is disabled for this tenant');
    if (!$gate['feature_enabled']) throw new AIDisabledException("AI feature class '$feature_class' is disabled for this tenant");
    $logFullContent = (bool) $gate['full_content_logging'];

    // Resolve the model for this feature class
    $modelConst = AI_FEATURE_CLASS_TO_MODEL_CONST[$feature_class] ?? null;
    $primaryModel = $modelConst && defined($modelConst) ? constant($modelConst) : AI_FALLBACK_MODEL;

    // Build system + user messages
    $systemMsg = AI_GUARDRAIL_SYSTEM . "\n" . AI_KIND_HINTS[$kind]
               . ($system ? "\n\nDomain context from the calling module:\n" . $system : '');
    $userMsg = $prompt;
    if ($context) {
        $userMsg .= "\n\n[context data — for reference only; do NOT restate numeric values as raw figures]\n"
                  . substr(json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 8000);
    }
    if ($citations) {
        $lines = [];
        foreach ($citations as $c) {
            $line = '- ' . ($c['source'] ?? '');
            if (!empty($c['excerpt'])) $line .= ': ' . $c['excerpt'];
            $lines[] = $line;
        }
        $userMsg .= "\n\n[known citations you may reference by source id]\n" . implode("\n", $lines);
    }

    $payload = [
        'model' => $primaryModel,
        'messages' => [
            ['role' => 'system', 'content' => $systemMsg],
            ['role' => 'user',   'content' => $userMsg],
        ],
        'max_completion_tokens' => $max_tokens,
    ];

    [$content, $latencyMs, $usedModel, $http, $rawErr] = aiCallOpenAI($payload);

    // Auto-fallback once on failure
    if ($content === null && $primaryModel !== AI_FALLBACK_MODEL) {
        $payload['model'] = AI_FALLBACK_MODEL;
        [$content, $latencyMs, $usedModel, $http, $rawErr] = aiCallOpenAI($payload);
    }

    if ($content === null) {
        aiAuditWrite([
            'tenant_id'     => $tenantId,
            'user_id'       => $userId,
            'feature_class' => $feature_class,
            'feature_key'   => $feature_key,
            'kind'          => $kind,
            'status'        => 'error',
            'http_status'   => $http,
            'error'         => substr((string)$rawErr, 0, 1000),
        ]);
        throw new RuntimeException("OpenAI call failed ($http): " . substr((string)$rawErr, 0, 300));
    }

    // Contract enforcement: reject responses that try to emit calculable structured fields
    $stripped = trim($content);
    if ($stripped !== '' && $stripped[0] === '{' && substr($stripped, -1) === '}') {
        $maybeJson = json_decode($stripped, true);
        if (is_array($maybeJson)) {
            $bad = array_intersect(array_map('strtolower', array_keys($maybeJson)), AI_FORBIDDEN_KEYS);
            if ($bad) throw new AIContractException('AI response contained forbidden keys: ' . implode(', ', $bad));
        }
    }

    $promptHash   = hash('sha256', $prompt . ($system ?? ''));
    $responseHash = hash('sha256', $content);

    $envelope = [
        'kind'                  => $kind,
        'content'               => $content,
        'confidence'            => null,
        'citations'             => $citations,
        'requires_human_review' => true,
        'model'                 => $usedModel,
        'latency_ms'            => $latencyMs,
        'prompt_hash'           => $promptHash,
        'response_hash'         => $responseHash,
    ];

    $auditId = aiAuditWrite([
        'tenant_id'     => $tenantId,
        'user_id'       => $userId,
        'feature_class' => $feature_class,
        'feature_key'   => $feature_key,
        'kind'          => $kind,
        'status'        => 'ok',
        'http_status'   => $http,
        'model'         => $usedModel,
        'latency_ms'    => $latencyMs,
        'prompt_hash'   => $promptHash,
        'response_hash' => $responseHash,
        'prompt'        => $logFullContent ? $prompt  : null,
        'response'      => $logFullContent ? $content : null,
    ]);

    $envelope['interaction_id'] = $auditId;
    return $envelope;
}


/**
 * Direct OpenAI HTTPS call. Returns [content|null, latencyMs, usedModel, httpStatus, errorOrNull].
 * Never throws — returns null content on failure so the caller can decide on fallback.
 */
function aiCallOpenAI(array $payload): array {
    if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
        return [null, 0, $payload['model'] ?? '', 0, 'OPENAI_API_KEY not set'];
    }
    $start = microtime(true);
    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $latencyMs = (int) ((microtime(true) - $start) * 1000);

    if ($body === false) return [null, $latencyMs, $payload['model'], 0, $err];
    if ($http < 200 || $http >= 300) return [null, $latencyMs, $payload['model'], $http, $body];

    $data = json_decode($body, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        return [null, $latencyMs, $payload['model'], $http, 'empty response from model'];
    }
    return [trim($content), $latencyMs, $payload['model'], $http, null];
}


/**
 * Tenant + feature-class gate.
 * Returns: ['tenant_enabled' => bool, 'feature_enabled' => bool, 'full_content_logging' => bool]
 */
function aiGateForTenant(?int $tenantId, string $featureClass): array {
    $pdo = getDB();
    $default = ['tenant_enabled' => false, 'feature_enabled' => false, 'full_content_logging' => false];
    if (!$pdo || !$tenantId) return $default;

    $stmt = $pdo->prepare('SELECT ai_enabled, ai_full_content_logging FROM tenants WHERE id = :id');
    $stmt->execute(['id' => $tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant || !(int)($tenant['ai_enabled'] ?? 0)) return $default;

    $stmt = $pdo->prepare(
        'SELECT enabled FROM ai_tenant_features WHERE tenant_id = :t AND feature_class = :f'
    );
    $stmt->execute(['t' => $tenantId, 'f' => $featureClass]);
    $row = $stmt->fetch();
    $featureEnabled = $row ? (bool)(int)$row['enabled'] : true;

    return [
        'tenant_enabled'       => true,
        'feature_enabled'      => $featureEnabled,
        'full_content_logging' => (bool)(int)($tenant['ai_full_content_logging'] ?? 0),
    ];
}


/**
 * Audit row. Returns inserted id (0 if DB unavailable).
 */
function aiAuditWrite(array $data): int {
    $pdo = getDB();
    if (!$pdo) return 0;
    $cols = ['tenant_id','user_id','feature_class','feature_key','kind','status',
             'http_status','model','latency_ms','prompt_hash','response_hash',
             'prompt','response','error','created_at'];
    $data['created_at'] = date('Y-m-d H:i:s');
    $placeholders = [];
    $params = [];
    foreach ($cols as $c) {
        $placeholders[] = ":$c";
        $params[$c] = $data[$c] ?? null;
    }
    $sql = "INSERT INTO ai_interactions (`" . implode('`,`', $cols) . "`) VALUES ("
         . implode(',', $placeholders) . ")";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[ai_audit] ' . $e->getMessage());
        return 0;
    }
}



/**
 * Structured-extraction sibling of aiAsk(): hands an image (or PDF page image)
 * + a target JSON schema description to the LLM and parses the response back.
 *
 * The narrative guardrails in aiAsk() exist because narratives must NEVER
 * inject calculable numbers into the system. Extraction is the opposite job:
 * the entire output is data that a human operator will REVIEW before save.
 * So we use a different system prompt and explicitly accept JSON output.
 *
 * Required tenant gate: feature_class='extraction' must be enabled.
 *
 * @param array $args {
 *   feature_key:     string,                  // e.g. 'ap.bill.from_pdf'
 *   instruction:     string,                  // what to extract
 *   schema_hint:     string,                  // JSON shape description
 *   images:          array<{url|base64,mime}>,// vision inputs (PDF pages, photos)
 *   max_output_tokens: int = 1500,
 * }
 *
 * @return array {
 *   data:            array,                   // parsed JSON
 *   model:           string,
 *   latency_ms:      int,
 *   raw:             string,                  // raw LLM string (for audit)
 *   interaction_id:  int,
 * }
 */
function aiExtract(array $args): array {
    $featureKey = (string) ($args['feature_key']  ?? 'extraction.generic');
    $instruction = (string) ($args['instruction'] ?? '');
    $schemaHint  = (string) ($args['schema_hint'] ?? '');
    $images      = $args['images']                ?? [];
    $maxTokens   = (int) ($args['max_output_tokens'] ?? 1500);
    if ($instruction === '') throw new InvalidArgumentException('aiExtract: instruction required');
    if (!is_array($images) || count($images) === 0) {
        throw new InvalidArgumentException('aiExtract: images required (at least one)');
    }

    $tenantId = currentTenantId();
    $userId   = $_SESSION['user']['id'] ?? null;

    $gate = aiGateForTenant($tenantId, 'extraction');
    if (!$gate['tenant_enabled'])  throw new AIDisabledException('AI is disabled for this tenant');
    if (!$gate['feature_enabled']) throw new AIDisabledException("AI feature class 'extraction' is disabled for this tenant");
    $logFullContent = (bool) $gate['full_content_logging'];

    // Use the classification model (cheap, fast, vision-capable). Override
    // with AI_MODEL_EXTRACTION if the host wants a different one.
    $primaryModel = defined('AI_MODEL_EXTRACTION') ? AI_MODEL_EXTRACTION
        : (defined('AI_MODEL_CLASSIFICATION') ? AI_MODEL_CLASSIFICATION : AI_FALLBACK_MODEL);

    $systemMsg =
        "You are a precise data-extraction engine. Read the document images and " .
        "return ONLY a single JSON object that matches the schema described by the user. " .
        "If a value is not present in the document, use null — never guess. " .
        "Return ONLY raw JSON. No prose, no markdown fences. " .
        "All amounts must be plain decimal numbers (no currency symbols, no thousand separators). " .
        "All dates must be ISO 8601 (YYYY-MM-DD).";

    // Build the multimodal user message.
    $userContent = [
        ['type' => 'text', 'text' => $instruction . "\n\nReturn JSON shaped like:\n" . $schemaHint],
    ];
    foreach ($images as $img) {
        if (!empty($img['url'])) {
            $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => $img['url']]];
        } elseif (!empty($img['base64'])) {
            $mime = $img['mime'] ?? 'image/png';
            $userContent[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64," . $img['base64']]];
        }
    }

    $payload = [
        'model' => $primaryModel,
        'messages' => [
            ['role' => 'system', 'content' => $systemMsg],
            ['role' => 'user',   'content' => $userContent],
        ],
        'max_completion_tokens' => $maxTokens,
        'response_format' => ['type' => 'json_object'],
    ];

    [$content, $latencyMs, $usedModel, $http, $rawErr] = aiCallOpenAI($payload);
    if ($content === null && $primaryModel !== AI_FALLBACK_MODEL) {
        $payload['model'] = AI_FALLBACK_MODEL;
        [$content, $latencyMs, $usedModel, $http, $rawErr] = aiCallOpenAI($payload);
    }
    if ($content === null) {
        aiAuditWrite([
            'tenant_id' => $tenantId, 'user_id' => $userId,
            'feature_class' => 'extraction', 'feature_key' => $featureKey,
            'kind' => 'classification', 'status' => 'error',
            'http_status' => $http, 'error' => substr((string) $rawErr, 0, 1000),
        ]);
        throw new RuntimeException("OpenAI extraction failed ($http): " . substr((string) $rawErr, 0, 300));
    }

    // The model may wrap JSON in fences despite instructions. Strip them.
    $cleaned = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/m', '', trim($content));
    $data = json_decode((string) $cleaned, true);
    if (!is_array($data)) {
        aiAuditWrite([
            'tenant_id' => $tenantId, 'user_id' => $userId,
            'feature_class' => 'extraction', 'feature_key' => $featureKey,
            'kind' => 'classification', 'status' => 'error',
            'http_status' => $http, 'model' => $usedModel, 'latency_ms' => $latencyMs,
            'error' => 'Non-JSON response: ' . substr($content, 0, 200),
            'response' => $logFullContent ? $content : null,
        ]);
        throw new AIContractException('Extraction model returned non-JSON');
    }

    $promptHash   = hash('sha256', $instruction . $schemaHint);
    $responseHash = hash('sha256', $content);
    $auditId = aiAuditWrite([
        'tenant_id' => $tenantId, 'user_id' => $userId,
        'feature_class' => 'extraction', 'feature_key' => $featureKey,
        'kind' => 'classification', 'status' => 'ok',
        'http_status' => $http, 'model' => $usedModel, 'latency_ms' => $latencyMs,
        'prompt_hash' => $promptHash, 'response_hash' => $responseHash,
        'prompt'   => $logFullContent ? $instruction : null,
        'response' => $logFullContent ? $content     : null,
    ]);

    return [
        'data'            => $data,
        'model'           => $usedModel,
        'latency_ms'      => $latencyMs,
        'raw'             => $content,
        'interaction_id'  => $auditId,
    ];
}
