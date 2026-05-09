<?php
/**
 * Sprint 7f.4 smoke — Missing-dimension alerts.
 *
 * Asserts:
 *   - api/missing_dimensions.php contract: GET-only, RBAC, days/limit clamp,
 *     reads accounting_dimensions registry + per-account rules, scans posted
 *     JE lines only, sorts by_account by missing_count desc.
 *   - books_health envelope adds `missing_dims: {count, sample_accounts}`,
 *     graceful when accounting_dimensions table absent or no required dims.
 *   - BookkeepingOverview yellow CTA renders when count > 0, deep-links to
 *     /modules/accounting/missing-dimensions, shows top-offenders sample.
 *   - MissingDimensions.jsx page renders by-account + per-row tables with
 *     "Open JE" deep-link.
 *   - Module-namespaced kebab alias /api/accounting/missing-dimensions.
 *   - AccountingModule routes /missing-dimensions.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "API — api/missing_dimensions.php\n";
$apiPath = "{$ROOT}/api/missing_dimensions.php";
$api = (string) file_get_contents($apiPath);
$assert('parses',                                 $lint($apiPath));
$assert('GET-only',                               strpos($api, "if (api_method() !== 'GET') api_error('Method not allowed', 405)") !== false);
$assert('RBAC accounting.je.view',                strpos($api, "RBAC::requirePermission(\$user, 'accounting.je.view')") !== false);
$assert('days clamped 1..1825',                   strpos($api, "max(1, min(1825, (int) (api_query('days') ?? 90)))") !== false);
$assert('limit clamped 1..500',                   strpos($api, "max(1, min(500, (int) (api_query('limit') ?? 100)))") !== false);
$assert('reads accounting_dimensions registry',   strpos($api, "FROM accounting_dimensions\n      WHERE tenant_id = :t AND active = 1") !== false);
$assert('returns no-dimensions early note when empty', strpos($api, 'No active dimensions defined for this tenant') !== false);
$assert('joins JE for posted-only filter',
    strpos($api, "AND je.status    = 'posted'") !== false);
$assert('uses accountingAccountDimRules per row',  strpos($api, '$rules  = accountingAccountDimRules($tid, $accId)') !== false);
$assert("filters to required-only rules",
    strpos($api, "if (\$req !== 'required') continue") !== false);
$assert('skips lines with no missing dims',       strpos($api, 'if (!$missing) continue;') !== false);
$assert('aggregates by_account with missing_count', strpos($api, "'missing_count'     => 0") !== false);
$assert('sorts by_account by missing_count desc',
    strpos($api, 'usort($byAccount, static fn($a, $b) => $b[\'missing_count\'] <=> $a[\'missing_count\'])') !== false);
$assert('rows envelope includes je_id + line_id + dim keys',
    strpos($api, "'je_id'            => (int) \$r['je_id']") !== false
    && strpos($api, "'missing_dim_keys' => \$missing") !== false);

echo "\nAlias — /api/accounting/missing-dimensions\n";
$aliasPath = "{$ROOT}/modules/accounting/api/missing_dimensions.php";
$assert('alias file exists',                      file_exists($aliasPath));
$assert('alias delegates via require_once',
    strpos((string) file_get_contents($aliasPath), "require_once __DIR__ . '/../../../api/missing_dimensions.php'") !== false);

echo "\nBooks-health envelope\n";
$bh = (string) file_get_contents("{$ROOT}/api/books_health.php");
$assert('declares missing_dims envelope',         strpos($bh, "\$missingDims = ['count' => 0, 'sample_accounts' => []]") !== false);
$assert('guards on accounting_dimensions table existing',
    strpos($bh, "SHOW TABLES LIKE 'accounting_dimensions'") !== false);
$assert('guards on dimension_values column existing',
    strpos($bh, "AND COLUMN_NAME = 'dimension_values'") !== false);
$assert('requires dimensions lib only when needed',
    strpos($bh, "require_once __DIR__ . '/../modules/accounting/lib/dimensions.php'") !== false);
$assert('uses 90-day window for dashboard tile',  strpos($bh, "strtotime('-90 days')") !== false);
$assert('emits missing_dims into envelope',       strpos($bh, "'missing_dims'     => \$missingDims") !== false);
$assert('sample_accounts truncated to 3',         strpos($bh, 'array_slice(array_values($mdByAcct), 0, 3)') !== false);

echo "\nUI — BookkeepingOverview yellow CTA\n";
$bo = (string) file_get_contents("{$ROOT}/dashboard/src/pages/BookkeepingOverview.jsx");
$assert('imports AlertTriangle icon',             strpos($bo, 'AlertTriangle, ArrowRight,') !== false);
$assert('CTA card testid',                        strpos($bo, 'data-testid="bookkeeping-overview-missing-dims-card"') !== false);
$assert('count testid',                           strpos($bo, 'data-testid="bookkeeping-overview-missing-dims-count"') !== false);
$assert('sample testid',                          strpos($bo, 'data-testid="bookkeeping-overview-missing-dims-sample"') !== false);
$assert('CTA testid',                             strpos($bo, 'data-testid="bookkeeping-overview-missing-dims-cta"') !== false);
$assert('renders only when count > 0',
    strpos($bo, '(data.missing_dims?.count ?? 0) > 0') !== false);
$assert('CTA deep-links to /missing-dimensions',  strpos($bo, 'to="/modules/accounting/missing-dimensions"') !== false);
$assert('amber palette',                          strpos($bo, "background: '#fffbeb'") !== false);
$assert('renders sample top-offenders compact',
    strpos($bo, 'Top offenders:') !== false);

echo "\nUI — MissingDimensions.jsx page\n";
$mdPath = "{$ROOT}/dashboard/src/pages/MissingDimensions.jsx";
$md = (string) file_get_contents($mdPath);
$assert('page exists',                            strlen($md) > 0);
$assert('reads missing_dimensions endpoint',      strpos($md, "'/api/missing_dimensions.php?days=90&limit=200'") !== false);
$assert('page testid',                            strpos($md, 'data-testid="missing-dims-page"') !== false);
$assert('empty-state testid',                     strpos($md, 'data-testid="missing-dims-empty"') !== false);
$assert('by-account row dynamic testid',          strpos($md, 'data-testid={`missing-dims-account-row-${a.account_id}`}') !== false);
$assert('per-row dynamic testid',                 strpos($md, 'data-testid={`missing-dims-row-${r.line_id}`}') !== false);
$assert('open-JE deep-link template',
    strpos($md, 'data-testid={`missing-dims-open-je-${r.je_id}`}') !== false
    && strpos($md, 'to={`/modules/accounting/journal-entries/${r.je_id}`}') !== false);
$assert('back link to BookkeepingOverview',       strpos($md, 'to="/modules/accounting/bookkeeping"') !== false);

echo "\nRouting — AccountingModule\n";
$am = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('imports MissingDimensions',              strpos($am, "import MissingDimensions from '../../../dashboard/src/pages/MissingDimensions'") !== false);
$assert('mounts /missing-dimensions route',
    strpos($am, '<Route path="missing-dimensions" element={<MissingDimensions />} />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
