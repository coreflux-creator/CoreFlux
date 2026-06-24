<?php
/**
 * Payroll — Settings (one row per tenant)
 *
 * GET   → returns tenant settings (creates a stub if missing)
 * PUT   → upsert
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/payroll.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'payroll.settings.view');
        $row = scopedFind(
            'SELECT * FROM payroll_settings WHERE tenant_id = :tenant_id LIMIT 1'
        );
        api_ok(['settings' => $row]);
    }

    case 'PUT':
    case 'POST': {
        rbac_legacy_require($user, 'payroll.settings.manage');
        $body = api_json_body();
        $existing = scopedFind('SELECT id FROM payroll_settings WHERE tenant_id = :tenant_id LIMIT 1');

        $fields = [
            'legal_name','dba_name','ein','primary_state','state_tax_id',
            'address_street1','address_street2','address_city','address_region',
            'address_postal','address_country','default_pay_schedule_id',
            'suta_rate_bps','futa_credit_rate_bps','ai_run_summary_enabled',
            'disbursement_rail','nacha_company_id','nacha_origin_routing',
        ];
        $data = [];
        foreach ($fields as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];

        if ($existing) {
            scopedUpdate('payroll_settings', (int) $existing['id'], $data);
            payrollAudit('payroll.settings.updated', [
                'settings_id' => (int) $existing['id'],
                'changed_fields' => array_keys($data),
            ], (int) $existing['id']);
            api_ok(['id' => (int) $existing['id']]);
        } else {
            api_require_fields($body, ['legal_name']);
            $id = scopedInsert('payroll_settings', $data + ['legal_name' => $body['legal_name']]);
            payrollAudit('payroll.settings.created', [
                'settings_id' => $id,
                'changed_fields' => array_keys($data + ['legal_name' => $body['legal_name']]),
            ], $id);
            api_ok(['id' => $id], 201);
        }
    }
}

api_error('Method not allowed', 405);
