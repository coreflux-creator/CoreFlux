<?php
/**
 * Payroll — Pay Stub (per-employee line item view)
 *
 * GET ?line_item_id=N    → full pay stub data (employee + run + components)
 *
 * Read-only. Pure deterministic data. No AI in this endpoint.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();
rbac_legacy_require($ctx['user'], 'payroll.view');

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$lineId = (int) (api_query('line_item_id') ?? 0);
if (!$lineId) api_error('Missing line_item_id', 422);

$line = scopedFind(
    "SELECT li.*,
            pp.period_start, pp.period_end, pp.pay_date,
            r.run_type, r.status AS run_status,
            e.employee_number, e.legal_first_name, e.legal_last_name,
            e.preferred_name, e.work_email
     FROM payroll_line_items li
     JOIN payroll_runs r ON r.id = li.run_id AND r.tenant_id = li.tenant_id
     JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
     JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
     WHERE li.tenant_id = :tenant_id AND li.id = :id",
    ['id' => $lineId]
);
if (!$line) api_error('Not found', 404);

$earnings   = scopedQuery('SELECT * FROM payroll_earnings   WHERE tenant_id = :tenant_id AND line_item_id = :lid ORDER BY id', ['lid' => $lineId]);
$taxes      = scopedQuery('SELECT * FROM payroll_taxes      WHERE tenant_id = :tenant_id AND line_item_id = :lid ORDER BY is_employer, code', ['lid' => $lineId]);
$deductions = scopedQuery('SELECT * FROM payroll_deductions WHERE tenant_id = :tenant_id AND line_item_id = :lid ORDER BY is_pretax DESC, code', ['lid' => $lineId]);

$settings = payrollGetTenantSettings();

api_ok([
    'line'       => $line,
    'earnings'   => $earnings,
    'taxes'      => $taxes,
    'deductions' => $deductions,
    'company'    => [
        'legal_name'     => $settings['legal_name']     ?? '',
        'address_city'   => $settings['address_city']   ?? '',
        'address_region' => $settings['address_region'] ?? '',
    ],
]);
