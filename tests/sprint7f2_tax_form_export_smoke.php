<?php
/**
 * Sprint 7f.2 smoke — Tax form CSV export.
 *
 * Asserts:
 *   - api/tax_form_export.php (RBAC, GET-only, form whitelist, date
 *     validation, normal_side-aware totals, unmapped surfacing,
 *     CSV format & headers, JSON envelope).
 *   - Module-namespaced kebab alias delegates.
 *   - TaxExport.jsx renders + downloads CSV via `?format=csv`.
 *   - AccountingV1Module sub-nav + route, App.jsx sidebar, and
 *     Bookkeeping Overview Reports&Tax quick-links wired.
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

echo "Backend — api/tax_form_export.php\n";
$ep = (string) file_get_contents("{$ROOT}/api/tax_form_export.php");
$assert('endpoint exists',                       strlen($ep) > 0);
$assert('parses',                                $lint("{$ROOT}/api/tax_form_export.php"));
$assert('GET-only',                              strpos($ep, "if (api_method() !== 'GET')") !== false);
$assert('RBAC accounting.coa.view',              strpos($ep, "rbac_legacy_require(\$user, 'accounting.coa.view')") !== false);
$assert('TAX_FORMS whitelist (5 forms)',
    strpos($ep, "'US-1040-SCH-C'") !== false
    && strpos($ep, "'US-1120'")    !== false
    && strpos($ep, "'US-1120-S'")  !== false
    && strpos($ep, "'US-1065'")    !== false
    && strpos($ep, "'US-990'")     !== false);
$assert('rejects unknown tax_form_code',         strpos($ep, "Unknown tax_form_code") !== false);
$assert('date validation YYYY-MM-DD',            strpos($ep, "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', \$start)") !== false);
$assert('rejects start > end',                   strpos($ep, '$start > $end') !== false);
$assert('format whitelist json|csv',             strpos($ep, "['json', 'csv']") !== false);
$assert('joins mappings → accounts → JE lines',
    strpos($ep, 'JOIN accounting_accounts a ON a.id = m.account_id') !== false
    && strpos($ep, 'LEFT JOIN accounting_journal_lines jl') !== false
    && strpos($ep, 'LEFT JOIN accounting_journal_entries je') !== false);
$assert('posted-only filter',                    strpos($ep, "je.status = 'posted'") !== false);
$assert('entity_id filter applied',              strpos($ep, 'je.entity_id = :eid') !== false);
$assert('group by mapping + account (per-line breakdown)',
    strpos($ep, 'GROUP BY m.id, a.id') !== false);
$assert('total computed from normal_side',
    strpos($ep, "\$b['normal_side'] === 'credit'") !== false
    && strpos($ep, "round(\$b['credit'] - \$b['debit'], 2)") !== false
    && strpos($ep, "round(\$b['debit']  - \$b['credit'], 2)") !== false);
$assert('unmapped surfacing query',
    strpos($ep, "AND a.id NOT IN (") !== false
    && strpos($ep, 'FROM accounting_tax_mappings') !== false
    && strpos($ep, 'HAVING d > 0 OR c > 0') !== false);
$assert('unmapped accounts list cap reasonable (full list returned)',
    strpos($ep, "'accounts'      => []") !== false);
$assert('JSON envelope keys',
    strpos($ep, "'totals_by_line'") !== false
    && strpos($ep, "'unmapped_summary'") !== false
    && strpos($ep, "'mapped_count'")     !== false
    && strpos($ep, "'unmapped_count'")   !== false
    && strpos($ep, "'generated_at'")     !== false);

$assert('CSV branch sets text/csv content type',
    strpos($ep, "header('Content-Type: text/csv;") !== false);
$assert('CSV branch sets Content-Disposition attachment',
    strpos($ep, "header('Content-Disposition: attachment; filename=") !== false);
$assert('CSV header row matches spec',
    strpos($ep, "['Tax form', 'Line', 'Label', 'Total', 'Accounts', 'Account codes']") !== false);
$assert('CSV emits per-line totals',             strpos($ep, "fputcsv(\$out, [\n            \$form, \$b['line']") !== false);
$assert('CSV emits UNMAPPED row when present',   strpos($ep, "'UNMAPPED', 'Revenue/expense not yet mapped to a form line'") !== false);
$assert('exits after CSV stream',                strpos($ep, "fclose(\$out);\n    exit;") !== false);

echo "\nModule alias — /api/v1/accounting/tax-form-export\n";
$alias = "{$ROOT}/modules/accounting/api/tax_form_export.php";
$assert('alias exists', is_file($alias));
$assert('alias parses', $lint($alias));
$assert('alias delegates',
    strpos((string) file_get_contents($alias), "require __DIR__ . '/../../../api/tax_form_export.php'") !== false);

echo "\nFrontend — TaxExport.jsx\n";
$jsx = (string) file_get_contents("{$ROOT}/modules/accounting/ui/TaxExport.jsx");
$assert('hits v1 export endpoint for preview',
    strpos($jsx, "const TAX_EXPORT_API = '/api/v1/accounting/tax-form-export'") !== false);
$assert('reads available_forms from v1 tax-mappings',
    strpos($jsx, "const TAX_MAPPINGS_API = '/api/v1/accounting/tax-mappings'") !== false
    && strpos($jsx, 'useApi(TAX_MAPPINGS_API)') !== false
    && strpos($jsx, 'available_forms') !== false);
$assert('CSV download via window.location',
    strpos($jsx, '&format=csv') !== false
    && strpos($jsx, 'window.location.href = url') !== false);
$assert('grand total computed client-side',      strpos($jsx, 'totals_by_line.reduce') !== false);
foreach ([
    'page','refresh','download','form','start','end',
    'empty-state','loading','error','summary','table','empty',
    'mappings-link','mapped-count','unmapped-count','grand-total',
    'unmapped-warning','map-cta',
] as $id) {
    $assert("testid: accounting-tax-export-{$id}",
        strpos($jsx, "data-testid=\"accounting-tax-export-{$id}\"") !== false
        || strpos($jsx, "testId=\"accounting-tax-export-{$id}\"") !== false);
}
$assert('row testid template',
    strpos($jsx, 'data-testid={`accounting-tax-export-row-${b.line}-${i}`}') !== false);

echo "\nWiring — AccountingV1Module + App sidebar + BookkeepingOverview\n";
$mod = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('imports TaxExport',                     strpos($mod, "import TaxExport from './TaxExport'") !== false);
$assert('sub-nav Tax export tab',                strpos($mod, '<Tab to="tax-export" label="Tax export" />') !== false);
$assert('mounts /tax-export route',              strpos($mod, 'path="tax-export" element={<TaxExport />}') !== false);

$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert("sidebar Tax Export action",
    strpos($app, "name: 'Tax Export'") !== false
    && strpos($app, "route: 'tax-export'") !== false);

$bk = (string) file_get_contents("{$ROOT}/dashboard/src/pages/BookkeepingOverview.jsx");
$assert('Reports&Tax quick-links card',          strpos($bk, 'data-testid="bookkeeping-overview-quick-links-card"') !== false);
$assert('quick link → GL Detail',                strpos($bk, 'data-testid="bookkeeping-overview-gl-detail-link"') !== false);
$assert('quick link → Tax mappings',             strpos($bk, 'data-testid="bookkeeping-overview-tax-mappings-link"') !== false);
$assert('quick link → Tax export',               strpos($bk, 'data-testid="bookkeeping-overview-tax-export-link"') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
