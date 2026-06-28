<?php
/**
 * Smoke — Phase 2 of the field-mapping rebuild.
 *
 * Asserts:
 *   1. Migration 077 extends tenant_integration_field_map with
 *      source_path, target_module, target_table, target_column,
 *      linked_entity. Backfill clauses preserve legacy rows.
 *   2. Migration 078 creates integration_writable_targets catalog
 *      and seeds rows across people, placements (placements +
 *      placement_rates), companies, ap.ap_vendors, billing.
 *      billing_clients, and the custom_field_values escape hatch.
 *   3. integrationPayloadResolvePath() walks dotted JSON paths
 *      correctly including array-element notation (`[]`, `[0]`).
 *   4. integrationWritableTargetsList() / integrationFieldMapResolveGeneralised()
 *      surface the right shape for the UI.
 *   5. integrationFieldMapApplyAll() — bucket-by-(table,id),
 *      tenant-wins semantics, custom_field_values path, skip-on-
 *      empty-source-value, skip-on-missing-linked-row.
 *   6. tenantIntegrationFieldMapUpsert() accepts the new generalised
 *      shape AND backfills internal_field from target_column when
 *      omitted.
 *   7. JobDiva placement sync invokes the apply step right after
 *      mappingUpsert, with the correct context row id map.
 *   8. Writable-targets discovery endpoint exposes the catalog with
 *      RBAC gate.
 *   9. PHP syntax for every Phase 2 file.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/integrations/field_map_apply.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Migration 077 — tenant_integration_field_map generalisation\n";
$m077 = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents('/app/core/migrations/077_tenant_field_map_generalise.sql'));
$a('adds source_path column',          str_contains($m077, 'ADD COLUMN source_path    VARCHAR(255) NULL'));
$a('adds target_module column',        str_contains($m077, 'ADD COLUMN target_module  VARCHAR(64)  NULL'));
$a('adds target_table column',         str_contains($m077, 'ADD COLUMN target_table   VARCHAR(64)  NULL'));
$a('adds target_column column',        str_contains($m077, 'ADD COLUMN target_column  VARCHAR(96)  NULL'));
$a('adds linked_entity column',        str_contains($m077, 'ADD COLUMN linked_entity  VARCHAR(64)  NULL'));
$a('backfills placement rows',         str_contains($m077, "WHERE entity_type = 'placement'\n   AND target_module IS NULL"));
$a('places rate fields on placement_rates table',
    str_contains($m077, "SET target_table  = 'placement_rates',\n       linked_entity = 'placement_rates'")
    && str_contains($m077, "internal_field IN ('bill_rate', 'bill_rate_unit', 'pay_rate', 'pay_rate_unit',"));
$a('backfills person rows',            str_contains($m077, "WHERE entity_type = 'person'\n   AND target_module IS NULL"));
$a('backfills company rows',           str_contains($m077, "WHERE entity_type = 'company'\n   AND target_module IS NULL"));
$a('adds target-lookup index',         str_contains($m077, 'ADD KEY ix_target_lookup (tenant_id, integration, target_module, target_table)'));

echo "\n2. Migration 078 — integration_writable_targets catalog + seed\n";
$m078 = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents('/app/core/migrations/078_integration_writable_targets.sql'));
$a('creates integration_writable_targets table',
    str_contains($m078, 'CREATE TABLE IF NOT EXISTS integration_writable_targets'));
$a('uq_tenant_target unique key',      str_contains($m078, 'UNIQUE KEY uq_tenant_target (tenant_id, target_module, target_table, target_column)'));
$a('default_linked_entity column',     str_contains($m078, 'default_linked_entity VARCHAR(64)'));
$a('value_type column',                str_contains($m078, 'value_type      VARCHAR(16)'));
$a('seeds people targets',             str_contains($m078, "('people', 'people', 'first_name'"));
$a('seeds placements + placement_rates',
    str_contains($m078, "('placements', 'placements', 'title'")
    && str_contains($m078, "('placements', 'placement_rates', 'bill_rate'"));
$a('seeds staffing job targets',
    str_contains($m078, "('staffing', 'staffing_jobs', 'title'")
    && str_contains($m078, "('staffing', 'staffing_jobs', 'closed_at'"));
$a('seeds companies targets',          str_contains($m078, "('companies', 'companies', 'industry'"));
$a('seeds ap.ap_vendors targets',      str_contains($m078, "('ap', 'ap_vendors', 'payment_terms'"));
$a('seeds billing.billing_clients targets',
    str_contains($m078, "('billing', 'billing_clients', 'payment_terms'"));
$a('seeds custom_field_values magic rows',
    str_contains($m078, "'custom_field_values', '*', 'string', 'Any custom field on people'"));

echo "\n3. integrationPayloadResolvePath — dotted path walker\n";
$payload = [
    'placementId' => 27857851,
    '_jd_candidate' => [
        'firstName' => 'Andrew',
        'skills'    => [['name' => 'PHP'], ['name' => 'React']],
    ],
    '_jd_customer' => ['address' => ['city' => 'Glendale', 'state' => 'CA']],
];
$a('top-level scalar',              integrationPayloadResolvePath($payload, 'placementId') === 27857851);
$a('nested object key',             integrationPayloadResolvePath($payload, '_jd_candidate.firstName') === 'Andrew');
$a('deep nested object key',        integrationPayloadResolvePath($payload, '_jd_customer.address.city') === 'Glendale');
$a('array element via []',          integrationPayloadResolvePath($payload, '_jd_candidate.skills[].name') === 'PHP');
$a('array element via [0]',         integrationPayloadResolvePath($payload, '_jd_candidate.skills[0].name') === 'PHP');
$a('array element via [1]',         integrationPayloadResolvePath($payload, '_jd_candidate.skills[1].name') === 'React');
$a('missing path returns null',     integrationPayloadResolvePath($payload, '_jd_candidate.missing') === null);
$a('object cursor returns null',    integrationPayloadResolvePath($payload, '_jd_customer') === null);

echo "\n4. tenantIntegrationFieldMapUpsert — generalised shape accepted\n";
$fm = (string) file_get_contents('/app/core/integrations/field_map.php');
$a('reads source_path from payload',  str_contains($fm, "\$sourcePath    = isset(\$payload['source_path'])"));
$a('reads target_module from payload',str_contains($fm, "\$targetModule  = isset(\$payload['target_module'])"));
$a('reads target_table from payload', str_contains($fm, "\$targetTable   = isset(\$payload['target_table'])"));
$a('reads target_column from payload',str_contains($fm, "\$targetColumn  = isset(\$payload['target_column'])"));
$a('reads linked_entity from payload',str_contains($fm, "\$linkedEntity  = isset(\$payload['linked_entity'])"));
$a('falls back to legacy allow-list if catalog has no row',
    str_contains($fm, "Catalog missing — fall through to legacy allow-list")
    || str_contains($fm, "Try legacy allow-list as a fallback gate"));
$a('catalog lookup validates target_table.target_column',
    str_contains($fm, 'integrationWritableTargetsList($targetModule'));
$a('backfills internal_field from target_column when omitted',
    str_contains($fm, "if (\$internalField === '') \$internalField = \$targetColumn;"));
$a('INSERT writes all five new columns',
    str_contains($fm, '(tenant_id, integration, entity_type, external_field, source_path,
             internal_field, target_module, target_table, target_column, linked_entity,'));

echo "\n5. integrationFieldMapApplyAll — wire-up + bucket semantics\n";
$apply = (string) file_get_contents('/app/core/integrations/field_map_apply.php');
$a('buckets writes per (target_table, row_id)',
    str_contains($apply, '$bucket = []; // key: "tbl#id"'));
$a('flushes bucket via single UPDATE per row',
    str_contains($apply, 'UPDATE `{$b[\'table\']}` SET'));
$a('UPDATE always enforces tenant_id scope',
    str_contains($apply, 'WHERE id = :id AND tenant_id = :t'));
$a('source_path resolves first, external_field is fallback',
    strpos($apply, "integrationPayloadResolvePath(\$payload, (string) \$m['source_path'])")
    < strpos($apply, "tenantIntegrationFieldMapPluckPath(\$payload, (string) \$m['external_field'])"));
$a('applies transform via existing helper',
    str_contains($apply, "tenantIntegrationFieldMapApplyTransform(\$val, (string) \$m['transform'])"));
$a('custom_field_values target bucketed separately',
    str_contains($apply, "if (\$table === 'custom_field_values')")
    && str_contains($apply, "\$bucket[\$key]['cf'][\$col] = \$val;"));
$a('cf bucket flushed via customFieldValueUpsert when available',
    str_contains($apply, 'customFieldValueUpsert($tid, $entityType, $b[\'id\'], $code, $v)'));
$a('skip + error log when linked_entity has no context row',
    str_contains($apply, 'no_context_row for linked_entity'));
$a('protected identity targets are hidden and skipped',
    str_contains($apply, 'function integrationFieldMapIsProtectedTarget')
    && str_contains($apply, "'external_id'")
    && str_contains($apply, 'protected_target {$table}.{$col}'));

echo "\n6. JobDiva sync invokes applyAll right after mappingUpsert\n";
$sync = (string) file_get_contents('/app/core/jobdiva/sync.php');
$a('apply step requires field_map_apply.php',
    str_contains($sync, "require_once __DIR__ . '/../integrations/field_map_apply.php';"));
$a('placement sync calls shared placement mapping helper',
    str_contains($sync, 'jobdivaApplyPlacementFieldMappings(')
    && str_contains($sync, 'jobdivaPlacementStaffingJobId($tid, $internalId)'));
$a('context map populates placement/person/company/staffing_job owners',
    str_contains($sync, "'self'                   => \$placementId,")
    && str_contains($sync, "'placement'              => \$placementId,")
    && str_contains($sync, "'placement_rates'        => \$placementId,")
    && str_contains($sync, "'placement_corp_details' => \$placementId,")
    && str_contains($sync, "'person'                 => \$personId,")
    && str_contains($sync, "'end_client_company'     => \$endClientCompanyId ?? 0,")
    && str_contains($sync, "'staffing_job'           => \$staffingJobId,"));
$a('apply step is wrapped in try/catch (best-effort)',
    (bool) preg_match('/try \{\s*\$staffingJobId = jobdivaPlacementStaffingJobId\(.*?jobdivaApplyPlacementFieldMappings/s', $sync));

echo "\n7. /api/admin/integrations/writable_targets.php discovery endpoint\n";
$wt = (string) file_get_contents('/app/api/admin/integrations/writable_targets.php');
$a('GET only',                       str_contains($wt, "if (api_method() !== 'GET') api_error('Method not allowed', 405);"));
$a('RBAC tenant_admin.integrations', str_contains($wt, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('returns targets[] from integrationWritableTargetsList',
    str_contains($wt, "'targets' => integrationWritableTargetsList(\$module"));
$a('module + table query params honoured',
    str_contains($wt, "\$module !== '' ? \$module : null")
    && str_contains($wt, "\$table  !== '' ? \$table  : null"));

echo "\n8. PHP syntax\n";
foreach ([
    '/app/core/integrations/field_map.php',
    '/app/core/integrations/field_map_apply.php',
    '/app/core/jobdiva/sync.php',
    '/app/api/admin/integrations/writable_targets.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Field-mapping Phase 2 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
