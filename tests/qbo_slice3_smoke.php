<?php
/**
 * QuickBooks Online — Slice 2.5 (Skipped JE inbox) + Slice 3 (Customer /
 * Vendor pull) smoke.
 *
 * Validates:
 *   - api/qbo.php exposes the `skipped_jes`, `sync_customers`, and
 *     `sync_vendors` actions with RBAC + 409 conflict handling
 *   - core/qbo/sync_in.php exposes the documented public surface and
 *     translates QBO payloads into the right CoreFlux table columns
 *   - cron/qbo_sync_inbound.php iterates active tenants and honours
 *     per-entity direction
 *   - dashboard/src/pages/QboSettings.jsx surfaces the Skipped JE inbox
 *     card + Pull customers / Pull vendors buttons, conditionally
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- core/qbo/sync_in.php surface
echo "core/qbo/sync_in.php — public surface\n";
$siPath = $ROOT . '/core/qbo/sync_in.php';
$si = (string) file_get_contents($siPath);
$a('file exists',                                $si !== '');
$a('strict types',                               $c($si, 'declare(strict_types=1);'));
foreach (['qboSyncCustomers', 'qboSyncVendors', 'qboUpsertCustomer', 'qboUpsertVendor'] as $fn) {
    $a("declares $fn()",                         $c($si, "function $fn"));
}
$a('pagination via STARTPOSITION + MAXRESULTS',  $c($si, 'STARTPOSITION') && $c($si, 'MAXRESULTS'));
$a('refuses when customers direction off',       $c($si, "in_array(\$config[\$cfgKey] ?? 'off', ['pull', 'two_way']"));
$a('uses mappingFindInternal for idempotency',   $c($si, 'mappingFindInternal') && $c($si, 'mappingUpsert'));
$a('customer falls back to name match',          $c($si, 'staffing_clients WHERE tenant_id = :t AND name = :n'));
$a('vendor falls back to name match',            $c($si, 'ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n'));
$a('customer inserts staffing_clients',          $c($si, 'INSERT INTO staffing_clients'));
$a('vendor inserts ap_vendors_index',            $c($si, 'INSERT INTO ap_vendors_index'));
$a('vendor honors Vendor1099 flag',              $c($si, "Vendor1099") && $c($si, "requires_1099"));
$a('audits sync_customer + sync_vendor',         $c($si, "qboAudit(\$tenantId, 'sync_' . \$entity"));

// ----------------------------------------------------------------- API: skipped_jes + sync_customers + sync_vendors
echo "\napi/qbo.php — Slice 2.5 + Slice 3 dispatch\n";
$api = (string) file_get_contents($ROOT . '/api/qbo.php');
$a('requires sync_in.php',                       $c($api, "require_once __DIR__ . '/../core/qbo/sync_in.php'"));
$a('handles skipped_jes',                        $c($api, "case 'skipped_jes'"));
$a('handles sync_customers',                     $c($api, "case 'sync_customers'"));
$a('handles sync_vendors',                       $c($api, "case 'sync_vendors'"));
$a('shim sync_customers.php present',            file_exists($ROOT . '/api/qbo/sync_customers.php'));
$a('shim sync_vendors.php present',              file_exists($ROOT . '/api/qbo/sync_vendors.php'));
$a('shim skipped_jes.php present',               file_exists($ROOT . '/api/qbo/skipped_jes.php'));
$a('409 on direction conflict',                  $c($api, "api_error(\$e->getMessage(), 409)"));
$a('skipped_jes joins accounting_accounts',      $c($api, 'FROM accounting_accounts WHERE id IN'));
$a('skipped_jes window 30 DAY',                  $c($api, 'INTERVAL 30 DAY'));
$a('skipped_jes returns blockers array',         $c($api, "'blockers'") && $c($api, "'blocked_je_count'"));

// ----------------------------------------------------------------- Cron
echo "\ncron/qbo_sync_inbound.php\n";
$cron = (string) file_get_contents($ROOT . '/cron/qbo_sync_inbound.php');
$a('file exists',                                $cron !== '');
$a('requires sync_in.php',                       $c($cron, "require_once __DIR__ . '/../core/qbo/sync_in.php'"));
$a('iterates active connections',                $c($cron, "WHERE status = 'active'"));
$a('honours per-entity direction',               $c($cron, "in_array(\$cfg['customers'] ?? 'off', ['pull', 'two_way']")
                                                && $c($cron, "in_array(\$cfg['vendors'] ?? 'off', ['pull', 'two_way']"));
$a('calls qboSyncCustomers + qboSyncVendors',    $c($cron, 'qboSyncCustomers') && $c($cron, 'qboSyncVendors'));
$a('migration-not-applied bail-out',             $c($cron, 'migration 052 not applied yet'));

// ----------------------------------------------------------------- Syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/qbo/sync_in.php',
    'api/qbo.php',
    'api/qbo/sync_customers.php',
    'api/qbo/sync_vendors.php',
    'api/qbo/skipped_jes.php',
    'cron/qbo_sync_inbound.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- UI
echo "\nUI — QboSettings inbox + pull buttons\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/QboSettings.jsx');
$a('inbox card testid',                          $c($ui, 'data-testid="qbo-skipped-je-inbox"'));
$a('inbox per-row testid template',              $c($ui, 'data-testid={`qbo-skipped-row-${b.account_id}`}'));
$a('Map account → link template',                $c($ui, 'data-testid={`qbo-skipped-map-link-${b.account_id}`}'));
$a('pull customers button testid',               $c($ui, 'data-testid="qbo-sync-customers-btn"'));
$a('pull vendors button testid',                 $c($ui, 'data-testid="qbo-sync-vendors-btn"'));
$a('pull buttons conditional on direction',
    $c($ui, "['pull', 'two_way'].includes(custDir)") &&
    $c($ui, "['pull', 'two_way'].includes(vendDir)"));
$a('endpoint POST to sync_customers',            $c($ui, '/api/qbo/sync_customers.php?action=sync_customers') || $c($ui, '`/api/qbo/sync_${entity}.php?action=sync_${entity}`'));
$a('endpoint GET to skipped_jes',                $c($ui, '/api/qbo/skipped_jes.php?action=skipped_jes'));

echo "\n=========================================\n";
echo "QBO Slice 2.5 + 3 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
