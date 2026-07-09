<?php
/**
 * Smoke for the 2026-02 transform + customer-id fixes:
 *
 *   A) `date_normalise` transform is now defensive — when the input
 *      doesn't look like a date (e.g. "Public Storage"), it returns the
 *      trimmed original instead of NULL. Prevents the silent
 *      data-nuking that hit the user's `customer name → end_client_name`
 *      mapping with the wrong transform selected.
 *
 *   B) The projector resolves the end-client company from trusted
 *      company ids or company-name evidence. Placement-level `customer id`
 *      is deliberately NOT treated as a company identity because this
 *      tenant's JobDiva payload uses it as a contact id.
 *
 *   C) The placement sync resolution chain now tries `companyId` first,
 *      then falls back to trusted nested customer/company ids or name.
 *
 *   D) The mapping editor UI surfaces a `!` warning chip when
 *      `date_normalise` is applied to a non-date internal field.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/integrations/field_map.php';

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Transform — date_normalise defensive fallback (regression: customer name → end_client_name)\n";
// Stub jobdivaNormaliseDate if not loaded (smoke isolation).
if (!function_exists('jobdivaNormaliseDate')) {
    require_once __DIR__ . '/../core/jobdiva/sync.php';
}
$assert('epoch-ms input is still normalised to YYYY-MM-DD',
    tenantIntegrationFieldMapApplyTransform('1779105600000', 'date_normalise') === '2026-05-12'
    || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) tenantIntegrationFieldMapApplyTransform('1779105600000', 'date_normalise')) === 1);
$assert('ISO date input is still normalised',
    preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) tenantIntegrationFieldMapApplyTransform('2026-05-12', 'date_normalise')) === 1);
$assert('non-date STRING falls back to trimmed original (no silent NULL)',
    tenantIntegrationFieldMapApplyTransform('Public Storage', 'date_normalise') === 'Public Storage');
$assert('non-date STRING with surrounding whitespace is trimmed but preserved',
    tenantIntegrationFieldMapApplyTransform('  Acme Corp  ', 'date_normalise') === 'Acme Corp');
$assert('empty string still returns empty (no crash on empty input)',
    tenantIntegrationFieldMapApplyTransform('', 'date_normalise') === '');
$assert('null still returns null',
    tenantIntegrationFieldMapApplyTransform(null, 'date_normalise') === null);

echo "\nJobDiva end-client projection — declaration + wiring\n";
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$projector = (string) file_get_contents("{$ROOT}/core/jobdiva/projector.php");
$assert('helper declared',
    strpos($sync, 'function jobdivaResolveOrAutoCreateEndClient(') !== false);
$assert('placement loop delegates end-client resolution to projector',
    strpos($sync, '$endClientCompanyId = jobdivaProjectorResolveEndClientCompany($tid, $jd, $userId);') !== false);
$assert('projector declares trusted customer/company id helper',
    strpos($projector, 'function jobdivaProjectorTrustedCustomerCompanyExternalId') !== false);
$assert('projector documents shallow customer id as unsafe company identity',
    strpos($projector, 'Placement-level `customer id` is not safe as a company identity') !== false);
$assert('projector still plucks customer/company names from V2 BI key shapes',
    strpos($projector, "'customerName', 'customer_name', 'customer name'") !== false
    && strpos($projector, "'clientName', 'client_name', 'client name'") !== false);
$assert('projector only binds trusted jobdiva_customer identities',
    strpos($projector, '$trustedCustomerExtId = jobdivaProjectorTrustedCustomerCompanyExternalId($payload);') !== false
    && strpos($projector, "\$bind('jobdiva_customer', \$trustedCustomerExtId") !== false);

echo "\njobdivaResolveOrAutoCreateEndClient — dedupe + bind\n";
$assert('case-insensitive name lookup before auto-create (avoid dupes)',
    strpos($sync, 'WHERE tenant_id = :t AND LOWER(name) = LOWER(:n) AND deleted_at IS NULL') !== false);
$assert('mapping collisions are logged but do not abort end-client resolution',
    strpos($sync, '[jobdiva end-client resolver] customer mapping bind skipped') !== false
    && strpos($projector, '[jobdiva projector] end-client mapping bind skipped') !== false);
$assert('auto-creates with name-only (operator enriches later)',
    strpos($sync, "'INSERT INTO companies (tenant_id, name) VALUES (:t, :n)'") !== false);
$assert('legacy helper still attempts a trusted mapping bind after auto-create',
    preg_match("/mappingUpsert\(\\\$tid, 'jobdiva', 'jobdiva_customer', \\\$customerExtId, \\\$newId/", $sync) === 1);

echo "\nUI — transform warning chip when date_normalise targets a non-date column\n";
$panel = (string) file_get_contents("{$ROOT}/dashboard/src/components/LinkedExternalSystemsPanel.jsx");
$assert('isDateField heuristic declared',
    strpos($panel, 'const isDateField = (f) =>') !== false);
$assert('renders a "!" warning chip beside the transform when mismatched',
    strpos($panel, 'data-testid={`field-map-transform-warn-${r.internal_field}`}') !== false);
$assert('chip is conditional on date_normalise + non-date target',
    strpos($panel, "r.transform === 'date_normalise' && !isDateField(r.internal_field)") !== false);
$assert('chip exposes a helpful tooltip explaining the footgun',
    strpos($panel, "title=\"date_normalise will discard non-date values") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
