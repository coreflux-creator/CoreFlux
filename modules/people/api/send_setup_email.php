<?php
/**
 * People — Send the (human-approved) setup email.
 *
 * The body MUST come from an approved ai_suggestions row (or a human-written
 * override passed inline). We never read directly from an AI envelope —
 * only the human's committed text is sent.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/mailer.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['employee_id']);
$empId = (int) $body['employee_id'];

$emp = peopleGetEmployee($empId);
if (!$emp) api_error('Employee not found', 404);

$to = $emp['personal_email'] ?: $emp['work_email'];
if (!$to) api_error('Employee has no email on file', 422);

// Body source: an approved ai_suggestions row OR an explicit body provided by the UI
$finalBody = null;
$suggestionId = isset($body['suggestion_id']) ? (int) $body['suggestion_id'] : 0;

if ($suggestionId > 0) {
    $row = scopedFind(
        'SELECT id, final_content, status FROM ai_suggestions
         WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $suggestionId]
    );
    if (!$row) api_error('Suggestion not found', 404);
    if ($row['status'] !== 'approved') api_error('Suggestion is not approved', 409);
    $finalBody = $row['final_content'];
} elseif (!empty($body['body_text'])) {
    // Accept a human-typed fallback (e.g. when AI is disabled for the tenant)
    $finalBody = (string) $body['body_text'];
}

if (!$finalBody || trim($finalBody) === '') {
    api_error('No approved body to send', 422);
}

$tenantName = $_SESSION['tenant'] ?? 'CoreFlux';
$subject    = $body['subject'] ?? "Finish your $tenantName onboarding";
$senderName = trim(($ctx['user']['first_name'] ?? '') . ' ' . ($ctx['user']['last_name'] ?? '')) ?: 'HR';

// Assemble the full email: deterministic header + approved body + deterministic footer.
$fullText = "Hi " . ($emp['preferred_name'] ?: $emp['legal_first_name']) . ",\n\n"
          . trim($finalBody) . "\n\n"
          . "— $senderName\n$tenantName\n";

$bodyHash = hash('sha256', $fullText);

$logRow = [
    'user_id'       => $ctx['user']['id'] ?? null,
    'employee_id'   => $empId,
    'kind'          => 'setup_email',
    'suggestion_id' => $suggestionId ?: null,
    'to_email'      => $to,
    'subject'       => $subject,
    'body_hash'     => $bodyHash,
    'status'        => 'failed',
    'smtp_message_id' => null,
    'error'         => null,
];

try {
    $result = mailerSend([
        'tenant_id' => (int) ($ctx['tenant_id'] ?? currentTenantId()),
        'module'    => 'people',
        'purpose'   => 'people.setup_email',
        'to'        => [$to, trim(($emp['preferred_name'] ?: $emp['legal_first_name']) . ' ' . $emp['legal_last_name'])],
        'subject'   => $subject,
        'body_text' => $fullText,
    ]);
    if (($result['driver'] ?? '') === 'log') {
        throw new RuntimeException('mail is configured for local log-only delivery');
    }
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException((string) ($result['error'] ?? 'mail send failed'));
    }
    $logRow['status']          = 'sent';
    $logRow['smtp_message_id'] = $result['message_id'] ?? null;
} catch (Throwable $e) {
    $logRow['error'] = substr($e->getMessage(), 0, 1000);
    try { scopedInsert('people_emails_sent', $logRow); } catch (Throwable $ie) {}
    api_error('Send failed: ' . $e->getMessage(), 502);
}

try { scopedInsert('people_emails_sent', $logRow); } catch (Throwable $e) { error_log($e->getMessage()); }

api_ok([
    'ok'              => true,
    'to'              => $to,
    'subject'         => $subject,
    'smtp_message_id' => $logRow['smtp_message_id'],
]);
