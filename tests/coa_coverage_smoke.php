<?php
/**
 * Chart-of-Accounts coverage report — smoke.
 *
 * Validates:
 *   - api/admin/accounting_coa_coverage.php is shaped correctly
 *     (auth, RBAC for read/write, both GET and POST flows, joins
 *     against accounting_journal_entry_lines for the 90d window,
 *     calls both resolvers).
 *   - dashboard/src/pages/AccountingSyncDashboard.jsx renders the
 *     documented CoaCoverageCard testids (table, summary, search,
 *     filter, per-row mapped/unmapped cells, Discover button).
 *
 * Run via: php -d zend.assertions=1 tests/coa_coverage_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- API endpoint
echo "API — api/admin/accounting_coa_coverage.php\n";
$apiPath = $ROOT . '/api/admin/accounting_coa_coverage.php';
$api = file_exists($apiPath) ? (string) file_get_contents($apiPath) : '';
$a('file exists',                                $api !== '');
$a('strict types',                               $c($api, 'declare(strict_types=1);'));
$a('requires qbo sync_je (resolver)',            $c($api, "require_once __DIR__ . '/../../core/qbo/sync_je.php'"));
$a('requires zoho sync_je (resolver)',           $c($api, "require_once __DIR__ . '/../../core/zoho_books/sync_je.php'"));
$a('GET rbac integrations.qbo.view',             $c($api, "rbac_legacy_require(\$user, 'integrations.qbo.view')"));
$a('POST rbac integrations.qbo.manage',          $c($api, "rbac_legacy_require(\$user, 'integrations.qbo.manage')"));
$a('POST validates system enum',                 $c($api, "['qbo', 'zoho_books']"));
$a('POST scopes account by tenant',              $c($api, "FROM accounting_accounts WHERE id = :id AND tenant_id = :t"));
$a('POST calls qboResolveAccountRef',            $c($api, 'qboResolveAccountRef('));
$a('POST calls zohoBooksResolveAccountRef',      $c($api, 'zohoBooksResolveAccountRef('));
$a('GET bulk-loads mappings',                    $c($api, "internal_entity_type = 'account'"));
$a('GET counts JE refs over 90d',                $c($api, 'INTERVAL 90 DAY'));
$a('GET decodes payload_snapshot for QBO Name',  $c($api, "\$snap['Name']"));
$a('GET decodes payload_snapshot for Zoho name', $c($api, "\$snap['account_name']"));
$a('GET coverage tracks both/qbo_only/zoho_only/neither',
    $c($api, "'mapped_both'") && $c($api, "'qbo_only'") && $c($api, "'zoho_only'") && $c($api, "'unmapped'"));
$a('GET returns qbo_active flag',                $c($api, "'qbo_active'"));
$a('GET returns zoho_active flag',               $c($api, "'zoho_active'") && $c($api, "'pending'"));
$a('POST returns status: mapped',                $c($api, "'status'         => 'mapped'"));
$a('POST returns status: not_found',             $c($api, "'status'     => 'not_found'"));

$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($apiPath) . ' 2>&1', $out, $rc);
$a('php -l accounting_coa_coverage.php',         $rc === 0);

// ----------------------------------------------------------------- UI section
echo "\nUI — CoaCoverageCard\n";
$uiPath = $ROOT . '/dashboard/src/pages/AccountingSyncDashboard.jsx';
$ui = (string) file_get_contents($uiPath);
$a('section testid coa-coverage-section',        $c($ui, 'data-testid="coa-coverage-section"'));
$a('summary block testid',                       $c($ui, 'data-testid="coa-coverage-summary"'));
$a('stat-both testid',                           $c($ui, 'testid="coa-coverage-stat-both"'));
$a('stat-qbo-only testid',                       $c($ui, 'testid="coa-coverage-stat-qbo-only"'));
$a('stat-zoho-only testid',                      $c($ui, 'testid="coa-coverage-stat-zoho-only"'));
$a('stat-unmapped testid',                       $c($ui, 'testid="coa-coverage-stat-unmapped"'));
$a('search input testid',                        $c($ui, 'data-testid="coa-coverage-search"'));
$a('filter select testid',                       $c($ui, 'data-testid="coa-coverage-filter"'));
$a('table testid',                               $c($ui, 'data-testid="coa-coverage-table"'));
$a('row testid pattern',                         $c($ui, 'data-testid={`coa-coverage-row-${account.id}`}'));
$a('mapped cell testid pattern (QBO)',           $c($ui, 'data-testid={`${testidBase}-mapped`}'));
$a('unmapped cell testid pattern',               $c($ui, 'data-testid={`${testidBase}-unmapped`}'));
$a('discover button testid pattern',             $c($ui, 'data-testid={`${testidBase}-discover-btn`}'));
$a('empty-state testid',                         $c($ui, 'data-testid="coa-coverage-empty"'));
$a('POSTs to /api/admin/accounting_coa_coverage',$c($ui, '/api/admin/accounting_coa_coverage.php'));
$a('passes system: qbo to handler',              $c($ui, "onDiscover(account, 'qbo')"));
$a('passes system: zoho_books to handler',       $c($ui, "onDiscover(account, 'zoho_books')"));
$a('disables Discover when system not active',   $c($ui, 'disabled={!systemActive'));
$a('mounted inside dashboard between drift+activity',
    $c($ui, '<CoaCoverageCard'));

// bulk discover
$a('checkbox column testid',                     $c($ui, 'data-testid="coa-coverage-select-all"'));
$a('row checkbox testid pattern',                $c($ui, 'data-testid={`coa-coverage-checkbox-${account.id}`}'));
$a('bulk QBO button testid',                     $c($ui, 'data-testid="coa-coverage-bulk-qbo-btn"'));
$a('bulk Zoho button testid',                    $c($ui, 'data-testid="coa-coverage-bulk-zoho-btn"'));
$a('bulk progress container testid',             $c($ui, 'data-testid="coa-coverage-bulk-progress"'));
$a('bulk progress bar testid',                   $c($ui, 'data-testid="coa-coverage-bulk-progress-bar"'));
$a('bulk only runs unmapped on chosen system',   $c($ui, 'system === \'qbo\' ? !a.qbo_mapped : !a.zoho_mapped'));
$a('bulk runs sequentially',                     $c($ui, 'for (let i = 0; i < queue.length; i++)'));

echo "\n=========================================\n";
echo "CoA Coverage smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
