<?php
/**
 * CoreFlux AI Service — the ONE chokepoint for LLM calls.
 *
 * Rules (enforced here, NOT negotiable per module):
 *   1. Modules may not call the sidecar directly — they call aiAsk().
 *   2. Every call is gated by tenant.ai_enabled + ai_tenant_features[feature_class].
 *   3. Every call is logged to ai_interactions (metadata + hashes always; full
 *      content only when the tenant has opted-in via ai_full_content_logging).
 *   4. The response envelope never carries values the app can calculate with.
 *      Modules must never parse AI output for numbers — build a review/commit
 *      workflow with human approval instead.
 *
 * Usage from a module endpoint:
 *
 *     require_once __DIR__ . '/../../../core/api_bootstrap.php';
 *     require_once __DIR__ . '/../../../core/ai_service.php';
 *     $ctx = api_require_auth();
 *
 *     $envelope = aiAsk([
 *         'feature_class' => 'summary',              // summary | narrative | draft | classification | deep_reasoning
 *         'kind'          => 'summary',
 *         'feature_key'   => 'payroll.pay_period_summary',  // free-form; used for per-feature toggle + audit
 *         'system'        => 'You are a payroll domain assistant.',
 *         'prompt'        => 'Summarize the pay period for pay_run_id=42.',
 *         'context'       => $deterministic_facts,    // OK to send; never trusted back
 *     ]);
 *     api_ok(['ai' => $envelope]);
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tenant_scope.php';

if (!defined('AI_SIDECAR_URL')) {
    define('AI_SIDECAR_URL', getenv('AI_SIDECAR_URL') ?: 'http://localhost:8001/api/ai/chat');
}
if (!defined('AI_SIDECAR_SECRET')) {
    // In production, read from an env file mounted on the PHP host.
    // For local dev, core/config.local.php (gitignored) can define it.
    $localSecretFile = __DIR__ . '/config.local.php';
    if (file_exists($localSecretFile)) {
        require_once $localSecretFile;
    }
    if (!defined('AI_SIDECAR_SECRET')) {
        define('AI_SIDECAR_SECRET', getenv('AI_SIDECAR_SECRET') ?: '');
    }
}

/**
 * Exception surfaced when the tenant (or feature) is AI-disabled.
 */
class AIDisabledException extends RuntimeException {}

/**
 * Exception surfaced when the sidecar returns a malformed / contract-violating response.
 */
class AIContractException extends RuntimeException {}

/**
 * Ask the AI sidecar. Returns a typed envelope:
 *   [
 *     'kind'                  => 'narrative',
 *     'content'               => 'human readable text',
 *     'confidence'            => null|float,
 *     'citations'             => [...]|null,
 *     'requires_human_review' => true,
 *     'model'                 => 'gpt-5.4',
 *     'latency_ms'            => 2300,
 *     'prompt_hash'           => '...',
 *     'response_hash'         => '...',
 *     'interaction_id'        => 123,  // audit row id (PHP-added)
 *   ]
 *
 * @throws AIDisabledException  if tenant or feature is off
 * @throws AIContractException  if the sidecar returns something malformed
 * @throws RuntimeException     on transport/HTTP errors
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
    $session_id    = $args['session_id']    ?? null;

    if ($prompt === '') {
        throw new InvalidArgumentException('aiAsk: prompt is required');
    }

    $tenantId = currentTenantId();
    $userId   = $_SESSION['user']['id'] ?? null;

    // Gate: tenant + per-feature-class toggles
    $gate = aiGateForTenant($tenantId, $feature_class);
    if (!$gate['tenant_enabled']) {
        throw new AIDisabledException('AI is disabled for this tenant');
    }
    if (!$gate['feature_enabled']) {
        throw new AIDisabledException("AI feature class '$feature_class' is disabled for this tenant");
    }
    $logFullContent = (bool) $gate['full_content_logging'];

    // Build sidecar request
    $payload = [
        'feature_class'     => $feature_class,
        'kind'              => $kind,
        'system'            => $system,
        'prompt'            => $prompt,
        'context'           => $context,
        'citations'         => $citations,
        'max_output_tokens' => $max_tokens,
        'session_id'        => $session_id,
    ];

    [$http, $body] = aiSidecarPost($payload);

    if ($http < 200 || $http >= 300) {
        // Always audit failures with metadata
        aiAuditWrite([
            'tenant_id'     => $tenantId,
            'user_id'       => $userId,
            'feature_class' => $feature_class,
            'feature_key'   => $feature_key,
            'kind'          => $kind,
            'status'        => 'error',
            'http_status'   => $http,
            'error'         => substr($body, 0, 1000),
        ]);
        throw new RuntimeException("AI sidecar error ($http): " . substr($body, 0, 300));
    }

    $envelope = json_decode($body, true);
    if (!is_array($envelope) || !isset($envelope['content'], $envelope['kind'], $envelope['requires_human_review'])) {
        throw new AIContractException('AI sidecar returned malformed envelope');
    }
    // Enforce the hard-rule flag (defense in depth)
    $envelope['requires_human_review'] = true;

    // Block any attempt to attach calculable fields
    $forbidden = ['value','amount','total','rate','percentage','formula','calc',
                  'calculation','result','decision','next_step','action','execute','number','figure'];
    foreach ($forbidden as $f) {
        if (array_key_exists($f, $envelope)) {
            throw new AIContractException("AI envelope contained forbidden key: $f");
        }
    }

    // Audit
    $auditId = aiAuditWrite([
        'tenant_id'     => $tenantId,
        'user_id'       => $userId,
        'feature_class' => $feature_class,
        'feature_key'   => $feature_key,
        'kind'          => $kind,
        'status'        => 'ok',
        'http_status'   => $http,
        'model'         => $envelope['model']        ?? null,
        'latency_ms'    => $envelope['latency_ms']   ?? null,
        'prompt_hash'   => $envelope['prompt_hash']  ?? null,
        'response_hash' => $envelope['response_hash']?? null,
        'prompt'        => $logFullContent ? $prompt              : null,
        'response'      => $logFullContent ? $envelope['content'] : null,
    ]);

    $envelope['interaction_id'] = $auditId;
    return $envelope;
}


/**
 * Resolve the tenant + feature-class gating flags.
 * Returns: ['tenant_enabled' => bool, 'feature_enabled' => bool, 'full_content_logging' => bool]
 */
function aiGateForTenant(?int $tenantId, string $featureClass): array {
    $pdo = getDB();
    $default = ['tenant_enabled' => false, 'feature_enabled' => false, 'full_content_logging' => false];
    if (!$pdo || !$tenantId) return $default;

    // Tenant-level toggle
    $stmt = $pdo->prepare('SELECT ai_enabled, ai_full_content_logging FROM tenants WHERE id = :id');
    $stmt->execute(['id' => $tenantId]);
    $tenant = $stmt->fetch();
    if (!$tenant || !(int)($tenant['ai_enabled'] ?? 0)) return $default;

    // Per-feature-class toggle (default ON when tenant is on and no explicit row)
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
 * Write one audit row. Returns the inserted id (0 if DB unavailable).
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
 * Low-level POST to the sidecar. Returns [httpStatus, body].
 */
function aiSidecarPost(array $payload): array {
    $ch = curl_init(AI_SIDECAR_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-AI-Secret: ' . AI_SIDECAR_SECRET,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('AI sidecar transport error: ' . $err);
    }
    return [$http, $body];
}
