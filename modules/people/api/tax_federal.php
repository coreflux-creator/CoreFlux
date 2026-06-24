<?php
/**
 * People — Federal Tax (W-4). Append-only history.
 * POST creates a new W-4; latest row is the "active" one for payroll.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/employees.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'people.tax.view');
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        if ((int) api_query('active') === 1) {
            peopleAudit('people.tax.viewed', ['employee_id' => $empId, 'resource' => 'federal', 'mode' => 'active'], $empId);
            api_ok(['tax_federal' => peopleActiveFederalTax($empId)]);
        }
        $rows = scopedQuery(
            'SELECT * FROM people_tax_federal
             WHERE tenant_id = :tenant_id AND employee_id = :emp
             ORDER BY effective_date DESC, id DESC',
            ['emp' => $empId]
        );
        peopleAudit('people.tax.viewed', ['employee_id' => $empId, 'resource' => 'federal', 'row_count' => count($rows)], $empId);
        api_ok(['tax_federal' => $rows]);
    }
    case 'POST': {
        rbac_legacy_require($user, 'people.tax.manage');
        $body = api_json_body();
        api_require_fields($body, ['employee_id','filing_status','effective_date']);
        $id = scopedInsert('people_tax_federal', [
            'employee_id'             => (int) $body['employee_id'],
            'form_version'            => $body['form_version']            ?? 'W4-2020',
            'filing_status'           => $body['filing_status'],
            'multiple_jobs'           => (int)($body['multiple_jobs']     ?? 0),
            'dependents_amount_cents' => (int)($body['dependents_amount_cents'] ?? 0),
            'other_income_cents'      => (int)($body['other_income_cents']      ?? 0),
            'deductions_cents'        => (int)($body['deductions_cents']        ?? 0),
            'extra_withholding_cents' => (int)($body['extra_withholding_cents'] ?? 0),
            'effective_date'          => $body['effective_date'],
            'signed_at'               => $body['signed_at']               ?? null,
            'notes'                   => $body['notes']                   ?? null,
            'created_by'              => $ctx['user']['id'] ?? null,
        ]);
        peopleAudit('people.tax.updated', ['employee_id' => (int) $body['employee_id'], 'resource' => 'federal', 'tax_id' => $id], (int) $body['employee_id']);
        api_ok(['id' => $id], 201);
    }
}
api_error('Method not allowed', 405);
