<?php
/**
 * CSV-import external_id sweep — Wave 1 (P1 audit-trail correlation).
 *
 * Locks the architectural change agreed with the user (2026-02):
 *   • every CSV-imported primary entity captures `external_id` +
 *     `source_system` columns;
 *   • source_system is constrained to the enum
 *     manual|jobdiva|qbo|mercury|plaid|jaz|zoho|airtable|gusto|other;
 *   • when external_id is present, it (plus source_system) becomes the
 *     upsert key so re-uploading the same CSV does NOT duplicate rows;
 *   • the shared CsvImportPage UI flags external_id with ★ in the
 *     mapping table.
 *
 * Wave 1 tables: ap_vendors_index, ap_bills, billing_invoices, time_entries.
 *
 *   php -d zend.assertions=1 /app/tests/csv_external_id_wave1_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- migration
echo "core/migrations/095_csv_import_external_ids.sql\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/095_csv_import_external_ids.sql');
foreach (['ap_vendors_index', 'ap_bills', 'billing_invoices', 'time_entries'] as $tbl) {
    $a("adds external_id to {$tbl}",
        $c($mig, "ALTER TABLE {$tbl} ADD COLUMN external_id VARCHAR(128) NULL"));
    $a("adds source_system enum to {$tbl}",
        $c($mig, "ALTER TABLE {$tbl} ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other')"));
}
$a("ap_vendors_index unique key (tenant,src,ext)",
    $c($mig, 'uq_apv_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("ap_bills unique key (tenant,src,ext)",
    $c($mig, 'uq_apb_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("billing_invoices unique key (tenant,src,ext)",
    $c($mig, 'uq_inv_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("time_entries unique key (tenant,src,ext)",
    $c($mig, 'uq_te_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("uses information_schema-gated PREPARE for idempotency",
    $c($mig, 'FROM information_schema.columns') && $c($mig, 'PREPARE stmt FROM @sql'));

// ----------------------------------------------------------------- AP vendors
echo "\nmodules/ap/api/csv_import.php — vendors\n";
$vimp = (string) file_get_contents($ROOT . '/modules/ap/api/csv_import.php');
$a("registers external_id field",                 $c($vimp, "'external_id'      => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($vimp, "'source_system'    => ['label' => 'Source system',")
                                               && $c($vimp, "'manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other'"));
$a("unique_within_batch includes external_id",    $c($vimp, "'unique_within_batch' => ['external_id', 'vendor_name']"));
$a("INSERT writes external_id + source_system",   $c($vimp, "external_id, source_system,")
                                               && $c($vimp, '(:t, :v, :ext, :src,'));
$a("ON DUPLICATE KEY preserves existing external_id",
                                                  $c($vimp, "external_id     = COALESCE(VALUES(external_id), external_id)"));
$a("ON DUPLICATE refreshes source_system",         $c($vimp, "source_system   = VALUES(source_system)"));
$a("fallback resolve by (tenant,src,ext)",        $c($vimp, 'AND source_system = :s AND external_id = :e'));

// ----------------------------------------------------------------- AP bills
echo "\nmodules/ap/api/bills_csv_import.php — bills\n";
$bimp = (string) file_get_contents($ROOT . '/modules/ap/api/bills_csv_import.php');
$a("registers external_id field",                 $c($bimp, "'external_id'      => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($bimp, "'source_system'    => ['label' => 'Source system',"));
$a("idempotency check prefers (src,ext)",         $c($bimp, "ap_bills WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e"));
$a("scopedInsert writes external_id + source_system",
                                                  $c($bimp, "'external_id'        => \$externalId,")
                                               && $c($bimp, "'source_system'      => \$sourceSystem,"));

// ----------------------------------------------------------------- Billing
echo "\nmodules/billing/api/csv_import.php — invoices\n";
$iimp = (string) file_get_contents($ROOT . '/modules/billing/api/csv_import.php');
$a("registers external_id field",                 $c($iimp, "'external_id'      => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($iimp, "'source_system'    => ['label' => 'Source system',"));
$a("idempotency check prefers (src,ext)",         $c($iimp, "billing_invoices WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e"));
$a("scopedInsert writes external_id + source_system",
                                                  $c($iimp, "'external_id'        => \$externalId,")
                                               && $c($iimp, "'source_system'      => \$sourceSystem,"));

// ----------------------------------------------------------------- Time
echo "\nmodules/time/api/csv_import.php — time entries\n";
$timp = (string) file_get_contents($ROOT . '/modules/time/api/csv_import.php');
$a("registers external_id field",                 $c($timp, "'external_id'           => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($timp, "'source_system'         => ['label' => 'Source system',"));
$a("unique_within_batch on external_id",          $c($timp, "'unique_within_batch' => ['external_id']"));
$a("update-existing prefers (src,ext) over composite",
                                                  $c($timp, "AND source_system = :s AND external_id = :e"));
$a("scopedInsert writes external_id + source_system",
                                                  $c($timp, "'external_id'   => \$externalId,")
                                               && $c($timp, "'source_system' => \$sourceSystem,"));
$a("approved-status guard preserved on src/ext match",
                                                  $c($timp, "entry already approved — cannot update; void first"));

// ----------------------------------------------------------------- frontend
echo "\ndashboard/src/components/CsvImportPage.jsx — audit-field UI\n";
$jsx = (string) file_get_contents($ROOT . '/dashboard/src/components/CsvImportPage.jsx');
$a("audit fields starred in mapping options",     $c($jsx, "f.key === 'external_id' || f.key === 'source_system'")
                                               && $c($jsx, "isAuditField ? ' ★' : ''"));
$a("renders hint when external_id mapped",        $c($jsx, "mapped === 'external_id'")
                                               && $c($jsx, "idempotent re-imports + audit-trail correlation"));

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity\n";
foreach ([
    '/modules/ap/api/csv_import.php',
    '/modules/ap/api/bills_csv_import.php',
    '/modules/billing/api/csv_import.php',
    '/modules/time/api/csv_import.php',
] as $rel) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . $rel) . ' 2>&1', $o, $rc);
    $a("php -l {$rel}", $rc === 0);
}

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "CSV external_id (wave 1): {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
