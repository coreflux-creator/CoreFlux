<?php
/**
 * WorkflowEngine AI summary (Sprint 6c add-on).
 *
 *   POST /api/workflow_ai.php?action=summarize&id=N
 *     → { summary: "One-sentence human-friendly gist of why this approval
 *                   matters + any obvious red flags." }
 *
 * Pure narrative advisory per /app/AI_INTEGRATION_RULES.md — never outputs
 * values, formulas, or decisions the system consumes. The engine's own
 * state machine (approve/reject) is always the source of truth; this
 * endpoint exists only so the Workflow Inbox can show a 1-line "what am
 * I looking at" hint above each card.
 *
 * Best-effort: any failure (missing key, rate limit, disabled feature)
 * returns 200 + empty summary so the UI degrades gracefully.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/workflow_engine.php';
require_once __DIR__ . '/../core/ai_service.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();
$action   = (string) (api_query('action') ?? '');

if ($method !== 'POST' || $action !== 'summarize') {
    api_error('Unknown method/action', 405);
}
rbac_legacy_require($ctx['user'], 'ai.use');

$instanceId = (int) (api_query('id') ?? 0);
if (!$instanceId) api_error('id required', 422);

$instance = workflowGetInstance($tenantId, $instanceId);
if (!$instance) api_error('Instance not found', 404);

$payload = is_array($instance['payload'] ?? null) ? $instance['payload'] : [];

// Keep the prompt minimal + structured so the model stays grounded.
$prompt = "You are helping a finance reviewer triage a pending approval in 1 sentence.\n"
        . "Summarise what needs their attention and flag anything unusual.\n"
        . "Never restate dollar amounts as numerals — refer to them qualitatively.\n"
        . "Do not invent data not present in the context.";

$context = [
    'subject_type'  => $instance['subject_type'],
    'subject_id'    => $instance['subject_id'],
    'label'         => $instance['label'],
    'current_step'  => $instance['current_step'],
    'sla_due_at'    => $instance['sla_due_at'],
    'payload_body'  => $payload['body']         ?? null,
    'amount_label'  => $payload['amount_label'] ?? null,
    'risk'          => $payload['risk']         ?? null,
    'policy_id'     => $payload['policy_id']    ?? null,
];

$summary = '';
try {
    $r = aiAsk([
        'feature_class'     => 'narrative',
        'kind'              => 'narrative',
        'feature_key'       => 'workflow.inbox.summary',
        'prompt'            => $prompt,
        'context'           => $context,
        'max_output_tokens' => 140,
    ]);
    $summary = trim((string) ($r['content'] ?? ''));
} catch (\Throwable $_) {
    $summary = '';  // UI degrades silently
}

api_ok(['instance_id' => $instanceId, 'summary' => $summary]);
