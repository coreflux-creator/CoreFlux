<?php
/**
 * People — AI: Employee summary narrative (advisory only, read-only)
 *
 * Deterministic data is assembled here. The AI describes it in natural language
 * for the directory card / detail header. NO numbers come back as values.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['employee_id']);
$empId = (int) $body['employee_id'];

$emp = peopleGetEmployee($empId);
if (!$emp) api_error('Employee not found', 404);

// Direct reports count (deterministic)
$reportsRow = scopedFind(
    'SELECT COUNT(*) AS n FROM people_employees
     WHERE tenant_id = :tenant_id AND manager_id = :mgr AND status = "active"',
    ['mgr' => $empId]
);
$directReports = (int)($reportsRow['n'] ?? 0);

$tenure = $emp['hire_date']
    ? (new DateTime())->diff(new DateTime($emp['hire_date']))->format('%y years, %m months')
    : null;

try {
    $envelope = aiAsk([
        'feature_class' => 'summary',
        'kind'          => 'summary',
        'feature_key'   => 'people.employee_summary',
        'system'        => 'You summarize employee records for HR managers. Brief, professional, never speculative.',
        'prompt'        => 'Write a one-paragraph summary of this employee for a manager reading their directory card.',
        'context'       => [
            'name'            => ($emp['preferred_name'] ?: $emp['legal_first_name']) . ' ' . $emp['legal_last_name'],
            'job_title'       => $emp['job_title'],
            'department'      => $emp['department'],
            'location'        => $emp['location'],
            'employment_type' => $emp['employment_type'],
            'flsa_class'      => $emp['flsa_class'],
            'tenure_text'     => $tenure,
            'direct_reports'  => $directReports,
            'status'          => $emp['status'],
        ],
    ]);
    api_ok(['ai' => $envelope]);
} catch (AIDisabledException $e) {
    api_ok(['ai' => null]);
}
