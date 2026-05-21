<?php
/**
 * Zoho Books — Slice 3 (CoA + Contacts pull) smoke.
 *
 * Validates:
 *   - core/zoho_books/sync_accounts.php and sync_contacts.php expose
 *     the documented surface, follow the QBO match strategy, audit
 *     correctly, page until exhausted, and cap on opts.
 *   - api/zoho_books.php dispatches sync_accounts/sync_customers/sync_vendors
 *   - Dispatcher shims exist
 *   - cron/zoho_books_sync_inbound.php is wired correctly
 *   - reconcile endpoint registers Zoho runners for chart_of_accounts,
 *     customers, vendors
 *
 * Run via: php -d zend.assertions=1 tests/zoho_books_slice3_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// --------------------------------------------------------- sync_accounts
echo "core/zoho_books/sync_accounts.php\n";
$p = $ROOT . '/core/zoho_books/sync_accounts.php';
$s = file_exists($p) ? (string) file_get_contents($p) : '';
$a('file exists',                                $s !== '');
$a('declares zohoBooksSyncChartOfAccounts',      $c($s, 'function zohoBooksSyncChartOfAccounts'));
$a('requires entity_mappings',                   $c($s, "require_once __DIR__ . '/../integrations/entity_mappings.php'"));
$a('uses ZOHO_BOOKS_SOURCE',                     $c($s, 'ZOHO_BOOKS_SOURCE'));
$a('reads chart_of_accounts direction',          $c($s, "\$cfg['chart_of_accounts']"));
$a('gates on pull + two_way',                    $c($s, "['pull', 'two_way']"));
$a('calls /books/v3/chartofaccounts',            $c($s, '/books/v3/chartofaccounts'));
$a('paginates via page_context.has_more_page',   $c($s, "page_context"));
$a('lookup by existing mapping first',           $c($s, "mappingFindInternal(\$tenantId, ZOHO_BOOKS_SOURCE, 'account'"));
$a('falls back to byCode index',                 $c($s, '$byCode[$code]'));
$a('records unmapped audit',                     $c($s, "'unmapped_zoho_accounts'"));
$a('emits sync_accounts audit',                  $c($s, "zohoBooksAudit(\$tenantId, 'sync_accounts'"));
$a('limit cap 5000',                             $c($s, 'min(5000'));
$a('max_pages cap 100',                          $c($s, 'min(100'));

$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
$a('php -l sync_accounts.php',                   $rc === 0);

// --------------------------------------------------------- sync_contacts
echo "\ncore/zoho_books/sync_contacts.php\n";
$p = $ROOT . '/core/zoho_books/sync_contacts.php';
$s = file_exists($p) ? (string) file_get_contents($p) : '';
$a('file exists',                                $s !== '');
foreach (['zohoBooksSyncContactsCustomers', 'zohoBooksSyncContactsVendors',
          'zohoBooksUpsertCustomer', 'zohoBooksUpsertVendor'] as $fn) {
    $a("declares $fn()",                         $c($s, "function $fn"));
}
$a('passes contact_type=customer',               $c($s, "'contact_type' => \$kind") || $c($s, "'kind'   => 'customer'"));
$a('uses /books/v3/contacts',                    $c($s, '/books/v3/contacts'));
$a('customer upserter targets staffing_clients', $c($s, 'INSERT INTO staffing_clients'));
$a('vendor upserter targets ap_vendors_index',   $c($s, 'INSERT INTO ap_vendors_index'));
$a('name-match fallback (customer)',             $c($s, "FROM staffing_clients WHERE tenant_id = :t AND name = :n"));
$a('name-match fallback (vendor)',               $c($s, "FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n"));
$a('mapping under customer entity',              $c($s, "mappingUpsert(\$tenantId, ZOHO_BOOKS_SOURCE, 'customer'"));
$a('mapping under vendor entity',                $c($s, "mappingUpsert(\$tenantId, ZOHO_BOOKS_SOURCE, 'vendor'"));
$a('audit sync_customers',                       $c($s, "'sync_' . \$kind . 's'"));

$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
$a('php -l sync_contacts.php',                   $rc === 0);

// --------------------------------------------------------- API + shims
echo "\napi/zoho_books.php — Slice 3 actions\n";
$api = (string) file_get_contents($ROOT . '/api/zoho_books.php');
foreach (['sync_accounts', 'sync_customers', 'sync_vendors'] as $act) {
    $a("handles action: $act",                   $c($api, "case '$act'"));
    $a("shim api/zoho_books/$act.php exists",    file_exists($ROOT . "/api/zoho_books/$act.php"));
}
$a('requires sync_accounts module',              $c($api, "require_once __DIR__ . '/../core/zoho_books/sync_accounts.php'"));
$a('requires sync_contacts module',              $c($api, "require_once __DIR__ . '/../core/zoho_books/sync_contacts.php'"));
$a('rbac integrations.zoho_books.manage',        substr_count($api, "rbac_legacy_require(\$user, 'integrations.zoho_books.manage')") >= 5);

// --------------------------------------------------------- reconcile wiring
echo "\napi/admin/accounting_sync_reconcile.php — Slice 3 registered\n";
$rec = (string) file_get_contents($ROOT . '/api/admin/accounting_sync_reconcile.php');
$a('reconcile requires sync_accounts',           $c($rec, "require_once __DIR__ . '/../../core/zoho_books/sync_accounts.php'"));
$a('reconcile requires sync_contacts',           $c($rec, "require_once __DIR__ . '/../../core/zoho_books/sync_contacts.php'"));
$a('reconcile wires zoho CoA runner',            $c($rec, 'zohoBooksSyncChartOfAccounts('));
$a('reconcile wires zoho customers runner',      $c($rec, 'zohoBooksSyncContactsCustomers('));
$a('reconcile wires zoho vendors runner',        $c($rec, 'zohoBooksSyncContactsVendors('));

// --------------------------------------------------------- inbound cron
echo "\ncron/zoho_books_sync_inbound.php\n";
$cron = file_exists($ROOT . '/cron/zoho_books_sync_inbound.php')
    ? (string) file_get_contents($ROOT . '/cron/zoho_books_sync_inbound.php')
    : '';
$a('cron file exists',                           $cron !== '');
$a('cron requires sync_accounts',                $c($cron, "require_once __DIR__ . '/../core/zoho_books/sync_accounts.php'"));
$a('cron requires sync_contacts',                $c($cron, "require_once __DIR__ . '/../core/zoho_books/sync_contacts.php'"));
$a('cron iterates active connections',           $c($cron, "WHERE status = 'active'"));
$a('cron skips pending org rows',                $c($cron, "AND organization_id <> 'pending'"));
$a('cron runs CoA pull on direction match',     $c($cron, 'zohoBooksSyncChartOfAccounts('));
$a('cron runs customers pull',                   $c($cron, 'zohoBooksSyncContactsCustomers('));
$a('cron runs vendors pull',                     $c($cron, 'zohoBooksSyncContactsVendors('));
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($ROOT . '/cron/zoho_books_sync_inbound.php') . ' 2>&1', $out, $rc);
$a('php -l cron/zoho_books_sync_inbound.php',    $rc === 0);

echo "\n=========================================\n";
echo "Zoho Books Slice 3 smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
