<?php
/**
 * CSV Phase E smoke (2026-02-XX) — closes out the CSV backlog.
 *
 * Verifies:
 *   1. Saved mapping presets: migration + endpoint + signature helper.
 *   2. AP payments + billing payments CSV import endpoints exist with
 *      the full action set (template/sample/inspect/dry_run/commit/ai_suggest_map).
 *   3. Update-existing mode is wired through placements + time + people + clients.
 *   4. Bulk CSV Import Wizard auto-applies matching presets on file pick.
 *   5. Shared CsvImportPage exposes presets UI + update-existing toggle.
 *   6. Legacy people/placements CsvImport pages now use the shared component.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Saved mapping presets — migration + endpoint\n";
$mig = $read(__DIR__ . '/../core/migrations/041_csv_mapping_presets.sql');
$a('migration file exists',                  $mig !== '');
$a('table csv_mapping_presets',              str_contains($mig, 'CREATE TABLE IF NOT EXISTS csv_mapping_presets'));
foreach (['tenant_id','entity','name','header_signature','column_map','source_headers','used_count','last_used_at'] as $col) {
    $a("column: {$col}",                     str_contains($mig, " {$col} ") || str_contains($mig, "{$col} "));
}
$a('unique on (tenant, entity, name)',       str_contains($mig, 'uq_tenant_entity_name (tenant_id, entity, name)'));
$a('index by header_signature',              preg_match('/ix_tenant_entity_signature\s+\(tenant_id, entity, header_signature\)/', $mig) === 1);

$ep = $read(__DIR__ . '/../api/admin/csv_mapping_presets.php');
$a('preset endpoint exists',                 $ep !== '');
$a('GET lists presets',                      str_contains($ep, "method === 'GET'"));
$a('POST creates preset',                    str_contains($ep, "scopedInsert('csv_mapping_presets'"));
$a('POST upserts by (tenant,entity,name)',   str_contains($ep, "scopedUpdate('csv_mapping_presets'"));
$a('?action=use bumps used_count',           str_contains($ep, 'used_count = used_count + 1'));
$a('DELETE removes preset',                  str_contains($ep, "method === 'DELETE'"));
$a('signature uses sorted lowercase sha256', str_contains($ep, 'sort($norm, SORT_STRING)') && str_contains($ep, "hash('sha256'"));
foreach (['people','ap_vendors','staffing_clients','placements','time','ap_bills','billing_invoices','ap_payments','billing_payments'] as $e) {
    $a("entity allowlist includes {$e}",     str_contains($ep, "'{$e}'"));
}

echo "\nAP + Billing payments CSV import\n";
$apP = $read(__DIR__ . '/../modules/ap/api/payments_csv_import.php');
$a('ap payments_csv_import exists',          $apP !== '');
$a('ap payments registers ap_payments schema',
    str_contains($apP, "registerSchema('ap_payments'"));
foreach (['template','sample','inspect','dry_run','commit','ai_suggest_map'] as $act) {
    $a("ap payments action: ?action={$act}", str_contains($apP, "action === '{$act}'"));
}
$a('ap payments dedupe by full amount',      str_contains($apP, 'unallocated_amount'));
$a('ap payments audit emitted',              str_contains($apP, 'ap.payment.csv_imported'));

$blP = $read(__DIR__ . '/../modules/billing/api/payments_csv_import.php');
$a('billing payments_csv_import exists',     $blP !== '');
$a('billing registers billing_payments',     str_contains($blP, "registerSchema('billing_payments'"));
foreach (['template','sample','inspect','dry_run','commit','ai_suggest_map'] as $act) {
    $a("billing payments action: ?action={$act}", str_contains($blP, "action === '{$act}'"));
}
$a('billing uses billing.payments.record',   str_contains($blP, "'billing.payments.record'"));

echo "\nUpdate-existing on placements + time\n";
$pl = $read(__DIR__ . '/../modules/placements/api/csv_import.php');
$a('placements reads ?update_existing=1',    str_contains($pl, "\$updateExisting = !empty(\$_GET['update_existing'])"));
$a('placements matches by external_id',      str_contains($pl, "external_id = :x"));
$a('placements falls back to (person,title,start_date)',
    str_contains($pl, "person_id = :p AND title = :t AND start_date = :s"));
$a('placements updates instead of insert',   str_contains($pl, "scopedUpdate('placements'"));
$a('placements audit includes update_existing', str_contains($pl, "'update_existing' => \$updateExisting"));

$tm = $read(__DIR__ . '/../modules/time/api/csv_import.php');
$a('time reads ?update_existing=1',          str_contains($tm, "\$updateExisting = !empty(\$_GET['update_existing'])"));
$a('time dedupes by composite key',          str_contains($tm, 'placement_id = :pl AND person_id = :p') && str_contains($tm, 'work_date = :wd AND category = :cat'));
$a('time refuses to update approved rows',   str_contains($tm, "entry already approved"));
$a('time updates instead of insert',         str_contains($tm, "scopedUpdate('time_entries'"));
$a('time audit includes update_existing',    str_contains($tm, "'update_existing' => \$updateExisting"));

echo "\nBulk wizard auto-applies presets\n";
$bulk = $read(__DIR__ . '/../dashboard/src/pages/CsvBulkImport.jsx');
$a('wizard computes header signature',       str_contains($bulk, 'signatureFor') && str_contains($bulk, 'SHA-256'));
$a('wizard tryApplyPreset helper',           str_contains($bulk, 'tryApplyPreset'));
$a('wizard hits preset endpoint',            str_contains($bulk, '/api/admin/csv_mapping_presets?entity=') && str_contains($bulk, 'signature='));
$a('wizard bumps used_count after apply',    str_contains($bulk, 'csv_mapping_presets?action=use'));
$a('wizard surfaces preset name in row',     str_contains($bulk, 'preset: {f.presetName}'));
$a('wizard forwards column_map on dry_run',  str_contains($bulk, 'body.column_map = f.columnMap') || str_contains($bulk, 'body.column_map = f.columnMap;'));
$a('wizard forwards column_map on commit',   substr_count($bulk, 'body.column_map = f.columnMap') >= 2);
$a('wizard supports ap_payments entity',     str_contains($bulk, "'ap_payments'") && str_contains($bulk, 'AP Payments'));
$a('wizard supports billing_payments entity', str_contains($bulk, "'billing_payments'") && str_contains($bulk, 'Billing Payments'));

echo "\nShared CsvImportPage — preset UI + update-existing toggle\n";
$cmp = $read(__DIR__ . '/../dashboard/src/components/CsvImportPage.jsx');
$a('CsvImportPage accepts presetEntity prop', str_contains($cmp, 'presetEntity = null'));
$a('UI computes header signature',           str_contains($cmp, 'computeHeaderSignature') && str_contains($cmp, 'SHA-256'));
$a('UI loads presets on inspect',            str_contains($cmp, 'loadPresetsForHeaders'));
$a('UI applies matching preset',             str_contains($cmp, 'preset-match') || str_contains($cmp, 'presetMatch'));
$a('UI lists all presets for entity',        str_contains($cmp, 'preset-apply-${i}') || str_contains($cmp, 'preset-apply-`'));
$a('UI saves new preset',                    str_contains($cmp, "api.post('/api/admin/csv_mapping_presets'"));
$a('UI update-existing checkbox',            str_contains($cmp, 'update-existing') && str_contains($cmp, 'setUpdateExisting'));
$a('UI sends ?update_existing=1 on commit',  str_contains($cmp, "'update_existing=1'"));

echo "\nLegacy per-entity pages use shared component\n";
foreach ([
    'people'     => '/../modules/people/ui/CsvImport.jsx',
    'placements' => '/../modules/placements/ui/CsvImport.jsx',
] as $name => $rel) {
    $src = $read(__DIR__ . $rel);
    $a("{$name} CsvImport uses CsvImportPage", str_contains($src, 'import CsvImportPage'));
    $a("{$name} declares presetEntity",        str_contains($src, 'presetEntity='));
}

echo "\nList-page Import CSV buttons (payments)\n";
$apPL = $read(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$a('AP PaymentsList Import CSV link',        str_contains($apPL, 'data-testid="ap-payments-import-csv"'));
$blPL = $read(__DIR__ . '/../modules/billing/ui/PaymentsList.jsx');
$a('Billing PaymentsList Import CSV link',   str_contains($blPL, 'data-testid="billing-payments-import-csv"'));

$apm = $read(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$a('APModule mounts payments/csv_import',    str_contains($apm, 'path="payments/csv_import"'));
$blm = $read(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('BillingModule mounts payments/csv_import', str_contains($blm, 'path="payments/csv_import"'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
