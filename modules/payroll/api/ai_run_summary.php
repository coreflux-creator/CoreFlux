<?php
/**
 * Payroll — AI Run Summary (advisory narrative ONLY)
 *
 * POST { run_id }
 *   Returns an AI envelope with a narrative summary of a computed run.
 *   - All numbers come from deterministic compute already in the DB
 *   - We pass them as `context`; the model only narrates them
 *   - Frontend renders via <AISuggestion /> with the human-review badge
 *
 * Hard contract: this endpoint NEVER returns numbers the system consumes.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();
if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['run_id']);
$runId = (int) $body['run_id'];

$run = scopedFind(
    'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date
     FROM payroll_runs r
     JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
     WHERE r.tenant_id = :tenant_id AND r.id = :id',
    ['id' => $runId]
);
if (!$run) api_error('Run not found', 404);
if ($run['status'] === 'draft') api_error('Run not yet computed', 409);

// Department breakdown (deterministic — sourced from DB)
$dept = scopedQuery(
    "SELECT COALESCE(e.department, '(none)') AS department,
            COUNT(*) AS headcount,
            SUM(li.gross_cents) AS gross
     FROM payroll_line_items li
     JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
     WHERE li.tenant_id = :tenant_id AND li.run_id = :rid
     GROUP BY e.department
     ORDER BY gross DESC",
    ['rid' => $runId]
);

// Prior run for variance context
$prior = scopedFind(
    "SELECT r.id, r.gross_total_cents, r.net_total_cents, r.employee_count, pp.pay_date
     FROM payroll_runs r
     JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
     WHERE r.tenant_id = :tenant_id
       AND r.status IN ('computed','approved','paid')
       AND r.id <> :rid
     ORDER BY pp.pay_date DESC, r.id DESC LIMIT 1",
    ['rid' => $runId]
);

$context = [
    'pay_period' => [
        'start'    => $run['period_start'],
        'end'      => $run['period_end'],
        'pay_date' => $run['pay_date'],
    ],
    'totals' => [
        'employee_count'  => (int) $run['employee_count'],
        'gross_dollars'   => round(((int)$run['gross_total_cents']) / 100, 2),
        'net_dollars'     => round(((int)$run['net_total_cents']) / 100, 2),
        'employee_taxes'  => round(((int)$run['taxes_total_cents']) / 100, 2),
        'employer_taxes'  => round(((int)$run['employer_taxes_cents']) / 100, 2),
        'deductions'      => round(((int)$run['deductions_total_cents']) / 100, 2),
    ],
    'departments' => array_map(fn($d) => [
        'name' => $d['department'],
        'headcount' => (int) $d['headcount'],
        'gross_dollars' => round(((int)$d['gross']) / 100, 2),
    ], $dept),
    'prior_run' => $prior ? [
        'pay_date'        => $prior['pay_date'],
        'employee_count'  => (int) $prior['employee_count'],
        'gross_dollars'   => round(((int)$prior['gross_total_cents']) / 100, 2),
        'net_dollars'     => round(((int)$prior['net_total_cents']) / 100, 2),
    ] : null,
];

try {
    $envelope = aiAsk([
        'feature_class' => 'narrative',
        'kind'          => 'narrative',
        'feature_key'   => 'payroll.run_summary',
        'system'        => 'You narrate a payroll run for an HR/finance reader. Stay descriptive; do NOT '
                          .'restate dollar figures as raw numbers — say "headcount rose modestly" or "the '
                          .'largest department by spend was Engineering" rather than echoing values. The '
                          .'numbers below are already shown to the human in a deterministic table.',
        'prompt'        => 'Write a 2-paragraph narrative summarizing this completed payroll run. '
                          .'Highlight notable distribution across departments and any meaningful '
                          .'change vs the prior run if present. End with one suggestion of what a '
                          .'reviewer should double-check.',
        'context'       => $context,
    ]);
    api_ok(['ai' => $envelope, 'context' => $context]);
} catch (AIDisabledException $e) {
    api_error('AI is disabled for this tenant or feature', 403);
} catch (Throwable $e) {
    api_error('AI call failed: ' . $e->getMessage(), 500);
}
