<?php
/**
 * People — AI: Missing-fields narrative
 *
 * Deterministic code does the gap check (peoplePayrollReadiness). The AI
 * receives ONLY the list of gap CODES and writes a friendly narrative for the
 * HR person. It never produces the gap list itself.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'people.pii.view');

$body = api_json_body();
api_require_fields($body, ['employee_id']);
$empId = (int) $body['employee_id'];

$employee = peopleGetEmployee($empId);
if (!$employee) api_error('Employee not found', 404);

$gaps = peoplePayrollReadiness($empId);        // deterministic — never from AI

if (!$gaps) {
    api_ok([
        'ready' => true,
        'gaps'  => [],
        'ai'    => null,  // nothing to narrate
    ]);
}

$displayName = $employee['preferred_name'] ?: $employee['legal_first_name'];

try {
    $envelope = aiAsk([
        'feature_class' => 'narrative',
        'kind'          => 'narrative',
        'feature_key'   => 'people.missing_fields',
        'system'        => 'You are a CoreFlux HR assistant. You help HR admins complete employee setup before payroll.',
        'prompt'        =>
            "Write a short, friendly paragraph telling an HR admin which items are still missing " .
            "so '$displayName' can be paid. Do not invent items. Do not propose specific values. " .
            "Translate the provided gap codes into plain English and suggest the person complete each one.",
        'context'       => [
            'employee_name' => $displayName,
            'gap_codes'     => $gaps,   // list of short codes only
        ],
    ]);
    api_ok([
        'ready' => false,
        'gaps'  => $gaps,
        'ai'    => $envelope,
    ]);
} catch (AIDisabledException $e) {
    // Graceful fallback: return the deterministic gap list without narrative.
    api_ok([
        'ready' => false,
        'gaps'  => $gaps,
        'ai'    => null,
    ]);
}
