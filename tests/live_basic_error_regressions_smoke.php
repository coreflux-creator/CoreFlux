<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$assert = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "[PASS] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
};

$journalReaders = [
    'api/books_health.php',
    'api/missing_dimensions.php',
    'api/gl_detail.php',
    'api/dimensional_pnl.php',
    'api/tax_form_export.php',
    'api/treasury_cash_position.php',
    'api/treasury_recommendations.php',
    'core/treasury/liquidity_projection.php',
    'core/ai_agents.php',
];

foreach ($journalReaders as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $assert("{$relative} uses the canonical journal-line table", !str_contains($source, 'accounting_journal_lines'));
    $assert("{$relative} does not use the phantom journal_entry_id line column", !str_contains($source, 'jl.journal_entry_id'));
}

$books = (string) file_get_contents($root . '/api/books_health.php');
$assert('books health reads the canonical dimension JSON column', str_contains($books, 'jl.dim_json AS dimension_values'));
$assert('books health aliases canonical account display fields', str_contains($books, 'a.code AS account_code, a.name AS account_name'));

$migration = (string) file_get_contents($root . '/core/migrations/141_payroll_module_access_backfill.sql');
$assert('payroll access repair only fills missing module rows', str_contains($migration, 'INSERT IGNORE INTO membership_module_access'));
$assert('payroll access repair covers tenant admins and admins',
    str_contains($migration, "WHEN 'tenant_admin' THEN 'admin'")
    && str_contains($migration, "WHEN 'admin'        THEN 'admin'"));
$assert('payroll access repair gives managers read-only access', str_contains($migration, "WHEN 'manager'      THEN 'read'"));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
