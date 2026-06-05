<?php
/**
 * Smoke — Outbox UI unmapped-accounts banner.
 *
 * Locks the contract for the heads-up banner shipped this session:
 *   1. /api/admin/accounting/outbox.php (GET) returns an
 *      `unmapped_by_provider` summary keyed by provider with
 *      { total, by_sub_tenant } shape.
 *   2. The summary is computed by walking distinct (provider,
 *      sub_tenant_id) pairs from non-posted outbox rows and asking
 *      accountingAccountMappingsUnmapped() for each.
 *   3. AccountingOutbox.jsx reads the new field into state, renders
 *      the <UnmappedAccountsBanner /> only when at least one provider
 *      has total>0, and links to the mapping grid.
 *
 * Run: php -d zend.assertions=1 /app/tests/outbox_unmapped_banner_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nOutbox unmapped-accounts banner smoke\n";
echo "=====================================\n\n";

$api  = file_get_contents(__DIR__ . '/../api/admin/accounting/outbox.php');
$jsx  = file_get_contents(__DIR__ . '/../dashboard/src/pages/AccountingOutbox.jsx');

echo "── outbox.php server response ──\n";
check('imports account_mapping_service',
    str_contains($api, "require_once __DIR__ . '/../../../core/accounting/account_mapping_service.php'"));
check('queries distinct (provider, sub_tenant_id) from non-posted rows',
    str_contains($api, 'SELECT DISTINCT provider, sub_tenant_id') &&
    str_contains($api, "status IN ('queued','processing','retrying','failed','dead_letter')"));
check('limits the active-provider walk to 50 pairs',
    preg_match('/FROM accounting_outbox_events.*?LIMIT 50/s', $api) === 1);
check('calls accountingAccountMappingsUnmapped per pair',
    str_contains($api, 'accountingAccountMappingsUnmapped($tid, $st, $prov)'));
check('aggregates per provider with total + by_sub_tenant breakdown',
    str_contains($api, "'total' => 0, 'by_sub_tenant' => []"));
check('emits unmapped_by_provider in api_ok payload',
    str_contains($api, "'unmapped_by_provider' => \$unmappedByProvider"));
check('does NOT alter the rows / by_status keys (back-compat)',
    str_contains($api, "'rows' => \$rows") && str_contains($api, "'by_status' => \$byStatus"));

echo "\n── AccountingOutbox.jsx wiring ──\n";
check('declares unmappedByProvider state',
    str_contains($jsx, 'const [unmappedByProvider, setUnmappedByProvider] = useState({})'));
check('reload() captures r.unmapped_by_provider',
    str_contains($jsx, 'setUnmappedByProvider(r.unmapped_by_provider || {})'));
check('renders <UnmappedAccountsBanner /> in the page body',
    str_contains($jsx, '<UnmappedAccountsBanner data={unmappedByProvider} />'));
check('banner placed AFTER error block and BEFORE filter pills',
    preg_match('/outbox-error.*?UnmappedAccountsBanner.*?outbox-filter-all/s', $jsx) === 1);

echo "\n── banner component shape ──\n";
check('component name UnmappedAccountsBanner',           str_contains($jsx, 'function UnmappedAccountsBanner('));
check('returns null when no provider has total > 0',     str_contains($jsx, 'if (providers.length === 0) return null'));
check('filters providers with v.total > 0',              preg_match("/\.filter\(\(\[, v\]\) => \(v\?\.total \|\| 0\) > 0\)/", $jsx) === 1);
check('shows total + correct singular/plural',           str_contains($jsx, "v.total === 1 ? '' : 's'"));
check('per-provider testid outbox-unmapped-${prov}',     str_contains($jsx, 'data-testid={`outbox-unmapped-${prov}`}'));
check('root testid outbox-unmapped-banner',              str_contains($jsx, 'data-testid="outbox-unmapped-banner"'));
check('fix-link testid outbox-unmapped-fix-link',        str_contains($jsx, 'data-testid="outbox-unmapped-fix-link"'));
check('fix-link points to Jaz settings when jaz is first', str_contains($jsx, "providers[0][0] === 'jaz' ? '/admin/integrations/jaz'"));
check('uses role="alert" for screen-reader hint',        str_contains($jsx, "role=\"alert\""));

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "outbox_unmapped_banner smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
if ($failures) {
    foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
    exit(1);
}
exit(0);
