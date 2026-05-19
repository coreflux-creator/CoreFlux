<?php
/**
 * Sprint 7f.1 smoke — GL Detail report + Tax Mappings.
 *
 * Asserts:
 *   - api/gl_detail.php (RBAC, GET-only, account_id|code requirement,
 *     date validation, normal_side-aware running balance + opening
 *     balance, status filter, entity filter, totals envelope).
 *   - api/tax_mappings.php (3-verb endpoint, RBAC fork by verb,
 *     idempotent ON DUPLICATE upsert, form-code whitelist, unmapped
 *     query restricted to revenue/expense families).
 *   - Migration 020_tax_mappings.sql shape.
 *   - GLDetail.jsx + TaxMappings.jsx render with full testid coverage.
 *   - AccountingV1Module routes + sidebar actions wired.
 *   - Module-namespaced kebab aliases delegate.
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

echo "Backend — api/gl_detail.php\n";
$gl = (string) file_get_contents("{$ROOT}/api/gl_detail.php");
$assert('endpoint exists',                       strlen($gl) > 0);
$assert('parses',                                $lint("{$ROOT}/api/gl_detail.php"));
$assert('GET-only',                              strpos($gl, "if (api_method() !== 'GET')") !== false);
$assert('RBAC accounting.coa.view',              strpos($gl, "rbac_legacy_require(\$user, 'accounting.coa.view')") !== false);
$assert('requires account_id or account_code',
    strpos($gl, '!$accountId && $accountCode === \'\'') !== false);
$assert('start/end YYYY-MM-DD validation',
    strpos($gl, "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', \$start)") !== false
    && strpos($gl, "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', \$end)")   !== false);
$assert('rejects start > end',                   strpos($gl, '$start > $end') !== false);
$assert('opening balance pulled from posting_date < start',
    strpos($gl, 'je.posting_date < :start') !== false);
$assert('opening uses normal_side direction',
    strpos($gl, "\$normalSide === 'credit'") !== false
    && strpos($gl, '(float) $openRow[\'c\'] - (float) $openRow[\'d\']') !== false
    && strpos($gl, '(float) $openRow[\'d\'] - (float) $openRow[\'c\']') !== false);
$assert('detail rows in date order',             strpos($gl, 'ORDER BY je.posting_date ASC') !== false);
$assert('status filter posted-only by default',  strpos($gl, "je.status = 'posted'") !== false);
$assert('include_unposted broadens status',
    strpos($gl, "je.status IN ('posted','draft','reversed')") !== false);
$assert('entity_id filter applied',              strpos($gl, 'je.entity_id = :eid') !== false);
$assert('returns totals envelope',
    strpos($gl, "'totals'") !== false
    && strpos($gl, "'ending_balance'") !== false
    && strpos($gl, "'net'") !== false);
$assert('returns count',                         strpos($gl, "'count'           => count(\$out)") !== false);

echo "\nModule alias — /api/accounting/gl-detail\n";
$glAlias = "{$ROOT}/modules/accounting/api/gl_detail.php";
$assert('alias exists', is_file($glAlias));
$assert('alias parses', $lint($glAlias));
$assert('alias delegates',
    strpos((string) file_get_contents($glAlias), "require __DIR__ . '/../../../api/gl_detail.php'") !== false);

echo "\nMigration — 020_tax_mappings.sql\n";
$mig = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/020_tax_mappings.sql");
$assert('migration exists',                      strlen($mig) > 0);
$assert('CREATE TABLE IF NOT EXISTS (idempotent)',
    strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_tax_mappings') !== false);
$assert('UNIQUE (tenant_id, account_id, tax_form_code)',
    strpos($mig, 'UNIQUE KEY uk_tenant_acct_form (tenant_id, account_id, tax_form_code)') !== false);
$assert('tax_form_code VARCHAR(64)',             strpos($mig, 'tax_form_code     VARCHAR(64)')  !== false);
$assert('tax_form_line VARCHAR(32)',             strpos($mig, 'tax_form_line     VARCHAR(32)')  !== false);
$assert('tax_form_label VARCHAR(255) nullable',  strpos($mig, 'tax_form_label    VARCHAR(255) DEFAULT NULL') !== false);

echo "\nBackend — api/tax_mappings.php\n";
$tm = (string) file_get_contents("{$ROOT}/api/tax_mappings.php");
$assert('endpoint exists',                       strlen($tm) > 0);
$assert('parses',                                $lint("{$ROOT}/api/tax_mappings.php"));
$assert('GET RBAC accounting.coa.view',          strpos($tm, "rbac_legacy_require(\$user, 'accounting.coa.view')") !== false);
$assert('POST/DELETE RBAC accounting.je.create', strpos($tm, "rbac_legacy_require(\$user, 'accounting.je.create')") !== false);
$assert('TAX_FORMS whitelist',                   strpos($tm, "'US-1040-SCH-C'") !== false
                                              && strpos($tm, "'US-1120-S'")     !== false);
$assert('rejects unknown tax_form_code',         strpos($tm, "Unknown tax_form_code") !== false);
$assert('upsert via ON DUPLICATE',               strpos($tm, 'ON DUPLICATE KEY UPDATE') !== false);
$assert('account-tenant scope check',
    strpos($tm, "FROM accounting_accounts WHERE tenant_id = :t AND id = :id") !== false);
$assert('unmapped query restricted to rev/exp families',
    strpos($tm, "('revenue','expense','cost_of_goods_sold','other_income','other_expense','contra_revenue')") !== false);
$assert('DELETE by id verifies tenant scope',
    strpos($tm, 'DELETE FROM accounting_tax_mappings WHERE tenant_id = :t AND id = :id') !== false);

echo "\nModule alias — /api/accounting/tax-mappings\n";
$tmAlias = "{$ROOT}/modules/accounting/api/tax_mappings.php";
$assert('alias exists', is_file($tmAlias));
$assert('alias parses', $lint($tmAlias));
$assert('alias delegates',
    strpos((string) file_get_contents($tmAlias), "require __DIR__ . '/../../../api/tax_mappings.php'") !== false);

echo "\nFrontend — GLDetail.jsx\n";
$glJsx = (string) file_get_contents("{$ROOT}/modules/accounting/ui/GLDetail.jsx");
$assert('reads URL params',                      strpos($glJsx, 'useSearchParams') !== false);
$assert('hits gl_detail endpoint',               strpos($glJsx, '/api/gl_detail.php') !== false);
$assert('drills into JE detail',                 strpos($glJsx, '/modules/accounting/journal-entries/${l.je_id}') !== false);
foreach ([
    'page','refresh','account','start','end','include-unposted',
    'empty-state','loading','error','summary','account-code',
    'table','empty',
] as $id) {
    $assert("testid: accounting-gl-detail-{$id}",
        strpos($glJsx, "data-testid=\"accounting-gl-detail-{$id}\"") !== false);
}
foreach (['opening','total-debit','total-credit','total-net','ending'] as $id) {
    $assert("summary testId prop: accounting-gl-detail-{$id}",
        strpos($glJsx, "testId=\"accounting-gl-detail-{$id}\"") !== false);
}
$assert('row testid template',
    strpos($glJsx, 'data-testid={`accounting-gl-detail-row-${l.je_id}-${idx}`}') !== false);

echo "\nFrontend — TaxMappings.jsx\n";
$txJsx = (string) file_get_contents("{$ROOT}/modules/accounting/ui/TaxMappings.jsx");
$assert('hits tax_mappings endpoint',            strpos($txJsx, '/api/tax_mappings.php') !== false);
$assert('uses api.delete (not api.del)',         strpos($txJsx, 'api.delete(') !== false
                                              && strpos($txJsx, 'api.del(')    === false);
foreach ([
    'page','refresh','form','counts','loading','error','action-error',
    'empty-state','table-mapped','table-unmapped','mapped-empty','unmapped-empty',
] as $id) {
    $assert("testid: accounting-tax-mappings-{$id}",
        strpos($txJsx, "data-testid=\"accounting-tax-mappings-{$id}\"") !== false);
}
foreach ([
    'mapped-row-${m.account_id}','unmapped-row-${a.id}',
    'line-${a.id}','label-${a.id}','notes-${a.id}',
    'save-${a.id}','delete-${m.id}',
] as $tpl) {
    $assert("dynamic testid: accounting-tax-mappings-{$tpl}",
        strpos($txJsx, "data-testid={`accounting-tax-mappings-{$tpl}`}") !== false);
}

echo "\nWiring — AccountingV1Module + App sidebar\n";
$mod = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('imports GLDetail',                      strpos($mod, "import GLDetail from './GLDetail'") !== false);
$assert('imports TaxMappings',                   strpos($mod, "import TaxMappings from './TaxMappings'") !== false);
$assert('mounts /gl-detail route',               strpos($mod, 'path="gl-detail" element={<GLDetail />}') !== false);
$assert('mounts /tax-mappings route',            strpos($mod, 'path="tax-mappings" element={<TaxMappings />}') !== false);
$assert('sub-nav GL Detail tab',                 strpos($mod, '<Tab to="gl-detail" label="GL Detail" />') !== false);
$assert('sub-nav Tax mappings tab',              strpos($mod, '<Tab to="tax-mappings" label="Tax mappings" />') !== false);

$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert("sidebar GL Detail action",
    strpos($app, "name: 'GL Detail'") !== false
    && strpos($app, "route: 'gl-detail'") !== false);
$assert("sidebar Tax Mappings action",
    strpos($app, "name: 'Tax Mappings'") !== false
    && strpos($app, "route: 'tax-mappings'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
