<?php
/**
 * People — AI: Draft the employee setup email
 *
 * The deterministic part: which fields are missing, who the email is to,
 * which link they should click. The AI part: write the friendly body.
 *
 * The returned envelope is rendered by <AISuggestion /> so the HR user can
 * edit + accept. Only the approved text flows to /send_setup_email.php.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();
$user = $ctx['user'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'people.manage');
rbac_legacy_require($user, 'people.pii.view');
rbac_legacy_require($user, 'ai.use');

$body = api_json_body();
api_require_fields($body, ['employee_id']);
$empId = (int) $body['employee_id'];

$emp = peopleGetEmployee($empId);
if (!$emp) api_error('Employee not found', 404);

$targetEmail = $emp['personal_email'] ?: $emp['work_email'];
if (!$targetEmail) api_error('Employee has no email on file', 422, ['needs' => 'personal_email or work_email']);

$gaps = peoplePayrollReadiness($empId);
$tenantName = $_SESSION['tenant'] ?? 'CoreFlux';
$senderName = trim(($ctx['user']['first_name'] ?? '') . ' ' . ($ctx['user']['last_name'] ?? '')) ?: 'HR';

try {
    $envelope = aiAsk([
        'feature_class' => 'draft',
        'kind'          => 'suggestion',
        'feature_key'   => 'people.setup_email_draft',
        'system'        =>
            "You draft short, warm onboarding emails from an HR admin to a new hire. " .
            "Use the recipient's preferred name. Be clear about what they need to do, " .
            "but do not invent specific dates, amounts, links, or deadlines. Plain email body only; " .
            "no subject line, no salutation boilerplate, no signature (those are added by the system).",
        'prompt'        =>
            "Write the BODY of an onboarding setup email from " . $senderName . " at " . $tenantName .
            " asking this person to complete their outstanding onboarding items.",
        'context'       => [
            'recipient_preferred_name' => $emp['preferred_name'] ?: $emp['legal_first_name'],
            'tenant_name'              => $tenantName,
            'sender_name'              => $senderName,
            'pending_items'            => $gaps,          // codes only, AI translates
        ],
    ]);

    // Deterministic parts (subject, to:, sender) — NEVER come from the AI.
    api_ok([
        'ai'      => $envelope,
        'to'      => $targetEmail,
        'subject' => "Finish your " . $tenantName . " onboarding",
        'gaps'    => $gaps,
    ]);
} catch (AIDisabledException $e) {
    // Fallback: a static template the HR user can edit before sending.
    $fallback = "Hi " . ($emp['preferred_name'] ?: $emp['legal_first_name']) . ",\n\n" .
                "A few items are still needed to finish your onboarding at " . $tenantName . ". " .
                "Please sign in to complete them at your earliest convenience.\n\n" .
                "Thanks,\n" . $senderName;
    api_ok([
        'ai' => null,
        'to' => $targetEmail,
        'subject' => "Finish your " . $tenantName . " onboarding",
        'gaps'    => $gaps,
        'fallback_body' => $fallback,
    ]);
}
