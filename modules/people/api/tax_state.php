<?php
/**
 * People — State Tax. Per-state, append-only history.
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
            peopleAudit('people.tax.viewed', ['employee_id' => $empId, 'resource' => 'state', 'mode' => 'active'], $empId);
            api_ok(['tax_state' => peopleActiveStateTaxes($empId)]);
        }
        $rows = scopedQuery(
            'SELECT * FROM people_tax_state
             WHERE tenant_id = :tenant_id AND employee_id = :emp
             ORDER BY state_code, effective_date DESC, id DESC',
            ['emp' => $empId]
        );
        peopleAudit('people.tax.viewed', ['employee_id' => $empId, 'resource' => 'state', 'row_count' => count($rows)], $empId);
        api_ok(['tax_state' => $rows]);
    }
    case 'POST': {
        rbac_legacy_require($user, 'people.tax.manage');
        $body = api_json_body();
        api_require_fields($body, ['employee_id','state_code','effective_date']);
        $id = scopedInsert('people_tax_state', [
            'employee_id'             => (int) $body['employee_id'],
            'state_code'              => strtoupper($body['state_code']),
            'filing_status'           => $body['filing_status']           ?? null,
            'allowances'              => (int)($body['allowances']        ?? 0),
            'extra_withholding_cents' => (int)($body['extra_withholding_cents'] ?? 0),
            'effective_date'          => $body['effective_date'],
            'extra_fields_json'       => isset($body['extra_fields']) ? json_encode($body['extra_fields']) : null,
            'created_by'              => $ctx['user']['id'] ?? null,
        ]);
        peopleAudit('people.tax.updated', ['employee_id' => (int) $body['employee_id'], 'resource' => 'state', 'tax_id' => $id], (int) $body['employee_id']);
        api_ok(['id' => $id], 201);
    }
}
api_error('Method not allowed', 405);
