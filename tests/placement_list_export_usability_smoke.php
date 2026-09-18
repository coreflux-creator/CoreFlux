<?php
/**
 * Placement list and export usability regression coverage.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$list = $read('modules/placements/ui/List.jsx');
$export = $read('modules/placements/api/csv_export.php');
$datasets = $read('core/export_datasets.php');
$templates = $read('core/export_templates.php');
$admin = $read('dashboard/src/pages/ExportTemplatesAdmin.jsx');
$migration = $read('core/migrations/143_export_template_system_dedup.sql');
$deploy = $read('.github/workflows/deploy-light-workspace.yml');

echo "Placement list page size\n";
$assert('offers useful page sizes', str_contains($list, 'const PAGE_SIZES = [25, 50, 100, 200]'));
$assert('sends selected page size to API', str_contains($list, "p.set('per_page', String(pageSize))"));
$assert('resets to page one when size changes', str_contains($list, 'setPageSize(Number(e.target.value)); setPage(1)'));
$assert('shows range and total', str_contains($list, 'data-testid="placements-row-range"'));
$assert('renders accessible page size control', str_contains($list, 'data-testid="placements-page-size"')
    && str_contains($list, 'aria-label="Rows per page"'));

echo "\nPlacement template export\n";
$assert('endpoint reads template id', str_contains($export, "\$templateId = (int) (\$_GET['template_id'] ?? 0)"));
$assert('ordinary export resolves default placement template', str_contains($export, "exportTemplateDefault(\$tenantId, 'placements_directory')"));
$assert('selected template uses governed export runner', str_contains($export, 'exportTemplateStreamDatasetCsv(')
    && str_contains($export, "'placements_directory'"));
$assert('full raw extract remains explicit', str_contains($export, "\$_GET['raw']")
    && str_contains($list, 'data-testid="placements-full-csv-export-btn"'));
$assert('template export receives current search and client filters', str_contains($export, "'end_client_company_id'")
    && str_contains($export, "'q'")
    && str_contains($list, "params.set('end_client_company_id', endClientCompanyId)")
    && str_contains($list, "params.set('q', q)"));

echo "\nPlacement dataset person fields\n";
$assert('resolves placements module tenant', str_contains($datasets, "effectiveTenantIdForModule('placements', \$tenantId)"));
$assert('resolves People module tenant', str_contains($datasets, "effectiveTenantIdForModule('people', \$tenantId)"));
$assert('joins People with resolved tenant', str_contains($datasets, 'pe.tenant_id = :people_tenant_id'));
$assert('selects mapped person name', str_contains($datasets, 'CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name'));
$assert('governed dataset honors current-view search', str_contains($datasets, 'CONCAT_WS(" ", pe.first_name, pe.last_name) LIKE :q_person')
    && str_contains($datasets, 'p.end_client_company_id = :end_client_company_id'));

echo "\nTemplate screen consistency\n";
$assert('editor remounts for the selected template', str_contains($admin, 'key={editing._new ? `new-${editing.dataset || \'template\'}` : `edit-${editing.id}`}'));
$assert('editor heading follows editable name', str_contains($admin, "`Edit: \${name}`"));
$assert('system template list suppresses duplicates', str_contains($templates, '$seenSystemTemplates')
    && str_contains($templates, 'if (isset($seenSystemTemplates[$key])) continue'));
$assert('migration archives duplicate active system templates', str_contains($migration, 'UPDATE export_templates duplicate_template')
    && str_contains($migration, 'duplicate_template.is_active = 0'));

echo "\nProduction release coverage\n";
$assert('deploy package includes export template library', str_contains($deploy, 'core/export_templates.php'));
$assert('deploy package includes duplicate cleanup migration', str_contains($deploy, 'core/migrations/143_export_template_system_dedup.sql'));
$assert('deploy verifies duplicate cleanup migration', str_contains($deploy, "[ok] core/migrations/143_export_template_system_dedup.sql"));

echo "\nSyntax\n";
foreach ([
    'modules/placements/api/csv_export.php',
    'core/export_datasets.php',
    'core/export_templates.php',
] as $path) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $code);
    $assert("php -l {$path}", $code === 0);
}

echo "\nPlacement export usability smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
