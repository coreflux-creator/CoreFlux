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
rbac_legacy_require($ctx['user'], 'payroll.view');
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

// ----------------------------------------------------------------------
// Anomaly flags — deterministic, computed in SQL, never invented by AI.
// The model receives them in `context.anomalies` and can call them out.
//   - new_hires:        in this run but not in prior run
//   - terminations:     in prior run but not in this run
//   - large_swings:     gross delta vs prior run > 25% per employee
//   - missing_tax_setup: employees included with no active fed tax setup
// ----------------------------------------------------------------------
$anomalies = [
    'new_hires'         => [],
    'terminations'      => [],
    'large_swings'      => [],
    'missing_tax_setup' => [],
];

$thisLines = scopedQuery(
    "SELECT li.employee_id, li.gross_cents,
            e.legal_first_name, e.legal_last_name, e.employee_number
     FROM payroll_line_items li
     JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
     WHERE li.tenant_id = :tenant_id AND li.run_id = :rid",
    ['rid' => $runId]
);
$thisByEmp = [];
foreach ($thisLines as $r) $thisByEmp[(int) $r['employee_id']] = $r;

if ($prior) {
    $priorLines = scopedQuery(
        "SELECT li.employee_id, li.gross_cents
         FROM payroll_line_items li
         WHERE li.tenant_id = :tenant_id AND li.run_id = :rid",
        ['rid' => (int) $prior['id']]
    );
    $priorByEmp = [];
    foreach ($priorLines as $r) $priorByEmp[(int) $r['employee_id']] = (int) $r['gross_cents'];

    foreach ($thisByEmp as $empId => $row) {
        if (!isset($priorByEmp[$empId])) {
            $anomalies['new_hires'][] = [
                'employee_number' => $row['employee_number'],
                'name'            => trim($row['legal_first_name'] . ' ' . $row['legal_last_name']),
            ];
            continue;
        }
        $was = $priorByEmp[$empId];
        $now = (int) $row['gross_cents'];
        if ($was > 0) {
            $deltaPct = round((($now - $was) / $was) * 100, 1);
            if (abs($deltaPct) >= 25.0) {
                $anomalies['large_swings'][] = [
                    'employee_number' => $row['employee_number'],
                    'name'            => trim($row['legal_first_name'] . ' ' . $row['legal_last_name']),
                    'delta_pct'       => $deltaPct,
                    'direction'       => $deltaPct > 0 ? 'up' : 'down',
                ];
            }
        }
    }
    foreach ($priorByEmp as $empId => $_g) {
        if (!isset($thisByEmp[$empId])) {
            $row = scopedFind(
                'SELECT employee_number, legal_first_name, legal_last_name
                 FROM people_employees WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => $empId]
            );
            if ($row) {
                $anomalies['terminations'][] = [
                    'employee_number' => $row['employee_number'],
                    'name'            => trim($row['legal_first_name'] . ' ' . $row['legal_last_name']),
                ];
            }
        }
    }
}

// Missing federal tax setup detection (LEFT JOIN — employees in this run with no active row)
$missing = scopedQuery(
    "SELECT e.employee_number, e.legal_first_name, e.legal_last_name
     FROM payroll_line_items li
     JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
     LEFT JOIN people_tax_federal tf
            ON tf.tenant_id = e.tenant_id AND tf.employee_id = e.id AND tf.is_active = 1
     WHERE li.tenant_id = :tenant_id AND li.run_id = :rid AND tf.id IS NULL",
    ['rid' => $runId]
);
foreach ($missing as $r) {
    $anomalies['missing_tax_setup'][] = [
        'employee_number' => $r['employee_number'],
        'name'            => trim($r['legal_first_name'] . ' ' . $r['legal_last_name']),
    ];
}

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
    'anomalies' => $anomalies,
];

try {
    $envelope = aiAsk([
        'feature_class' => 'narrative',
        'kind'          => 'narrative',
        'feature_key'   => 'payroll.run_summary',
        'system'        => 'You narrate a payroll run for an HR/finance reader. Stay descriptive; do NOT '
                          .'restate dollar figures as raw numbers — say "headcount rose modestly" or "the '
                          .'largest department by spend was Engineering" rather than echoing values. The '
                          .'numbers below are already shown to the human in a deterministic table. When '
                          .'context.anomalies has entries, call them out by name (new hires, terminations, '
                          .'large pay swings, missing tax setups) so the reviewer knows where to look.',
        'prompt'        => 'Write a 2-paragraph narrative summarizing this completed payroll run. '
                          .'Highlight notable distribution across departments and any meaningful '
                          .'change vs the prior run if present. Surface every anomaly in the '
                          .'context.anomalies block by name. End with one suggestion of what a '
                          .'reviewer should double-check.',
        'context'       => $context,
    ]);
    api_ok(['ai' => $envelope, 'context' => $context]);
} catch (AIDisabledException $e) {
    api_error('AI is disabled for this tenant or feature', 403);
} catch (Throwable $e) {
    api_error('AI call failed: ' . $e->getMessage(), 500);
}
