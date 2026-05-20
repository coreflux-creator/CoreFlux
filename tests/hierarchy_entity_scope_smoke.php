<?php
/**
 * Hierarchy entity scope smoke (2026-02).
 *
 * Verifies that Accounting → Consolidation / Intercompany dropdowns can
 * now reach entities defined under sub-tenants of the currently-viewed
 * tenant. Pre-fix: a master_admin viewing a parent tenant saw only its
 * own entities, making consolidation impossible.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// Backend ---------------------------------------------------------------------
$ep = (string) file_get_contents($ROOT . '/modules/accounting/api/entities.php');
$a('GET honours ?scope=hierarchy', str_contains($ep, "scope === 'hierarchy'"));
$a('expands to direct sub-tenants (parent_id = current)',
    (bool) preg_match("/FROM\s+tenants\s+WHERE\s+parent_id\s*=\s*:p/", $ep));
$a('still requires accounting.entities.view RBAC',
    str_contains($ep, "rbac_legacy_require") && strpos($ep, "accounting.entities.view") !== false);
$a('rows include tenant_id + tenant_name labels',
    str_contains($ep, 'tenant_name') && str_contains($ep, 'is_current_tenant'));
$a('hierarchy branch tags response scope',
    str_contains($ep, "'scope' => 'hierarchy'"));
$a('tenant-leak-allow annotation present on hierarchy branch',
    str_contains($ep, 'tenant-leak-allow'));

// Frontend --------------------------------------------------------------------
$ui = (string) file_get_contents($ROOT . '/modules/accounting/ui/Consolidation.jsx');
$a('Consolidation.jsx requests scope=hierarchy in BOTH places',
    substr_count($ui, "entities.php?scope=hierarchy") === 2);
$a('Consolidation parent/child dropdowns show tenant_name suffix',
    substr_count($ui, "en.tenant_name") >= 2);
$a('Consolidated report entity picker shows a tenant chip for sub-tenants',
    str_contains($ui, '!en.is_current_tenant'));

$ic = (string) file_get_contents($ROOT . '/modules/accounting/ui/IntercompanyMappings.jsx');
$a('IntercompanyMappings.jsx requests scope=hierarchy',
    str_contains($ic, 'entities.php?scope=hierarchy'));
$a('IntercompanyMappings From/To dropdowns show tenant_name suffix',
    str_contains($ic, 'en.tenant_name'));

// PHP -l ---------------------------------------------------------------------
$rc = 0; $out = [];
exec('php -l ' . escapeshellarg($ROOT . '/modules/accounting/api/entities.php') . ' 2>&1', $out, $rc);
$a('php -l modules/accounting/api/entities.php', $rc === 0);

echo "\n=========================================\n";
echo "Hierarchy entity scope smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
