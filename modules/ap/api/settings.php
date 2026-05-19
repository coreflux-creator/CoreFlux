<?php
/**
 * AP — Settings (one row per tenant)
 *
 *   GET  /api/ap/settings  → returns tenant settings (NULL when not yet saved)
 *   PUT  /api/ap/settings  → upsert
 *
 * Holds disbursement rail + NACHA originator config + Plaid funding link
 * cipher. Rail value is one of paymentRailsList() ids.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/payment_rails.php';
require_once __DIR__ . '/../lib/ap.php';

$ctx  = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'ap.view');
        $row = scopedFind(
            'SELECT id, tenant_id, disbursement_rail, nacha_company_id,
                    nacha_company_name, nacha_origin_routing,
                    plaid_account_id, created_at, updated_at
             FROM ap_settings WHERE tenant_id = :tenant_id LIMIT 1'
        );
        api_ok(['settings' => $row]);
    }

    case 'PUT':
    case 'POST': {
        rbac_legacy_require($user, 'ap.payment.create');
        $body = api_json_body();

        if (isset($body['disbursement_rail']) && $body['disbursement_rail'] !== null
            && $body['disbursement_rail'] !== '') {
            $valid = array_map(fn($r) => $r['id'], paymentRailsList());
            if (!in_array((string) $body['disbursement_rail'], $valid, true)) {
                api_error('Unknown disbursement_rail', 422, ['valid' => $valid]);
            }
        }

        $fields = [
            'disbursement_rail',
            'nacha_company_id',
            'nacha_company_name',
            'nacha_origin_routing',
            'plaid_account_id',
        ];
        $data = [];
        foreach ($fields as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];

        $existing = scopedFind('SELECT id FROM ap_settings WHERE tenant_id = :tenant_id LIMIT 1');
        if ($existing) {
            scopedUpdate('ap_settings', (int) $existing['id'], $data);
            apAudit('ap.settings.updated', ['fields' => array_keys($data)], (int) $existing['id']);
            api_ok(['id' => (int) $existing['id']]);
        }
        $id = scopedInsert('ap_settings', $data);
        apAudit('ap.settings.updated', ['fields' => array_keys($data), 'created' => true], $id);
        api_ok(['id' => $id], 201);
    }
}

api_error('Method not allowed', 405);
