<?php
/**
 * CSV-import external_id sweep — Wave 2 (P1 audit-trail correlation).
 *
 * Companion to wave 1 (vendors / bills / invoices / time entries).
 * Wave 2 tables:
 *   ap_payments, billing_payments, staffing_clients,
 *   accounting_bank_statement_lines.
 *
 * Treasury's importer is bespoke (not CsvImportService) — it gets
 * `external_id` + `source_system` columns *and* uses the external_id
 * as the fitid seed when present, so re-imports of the same Mercury
 * export are deterministically idempotent on the bank's own id.
 *
 *   php -d zend.assertions=1 /app/tests/csv_external_id_wave2_smoke.php
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
echo "core/migrations/096_csv_import_external_ids_wave2.sql\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/096_csv_import_external_ids_wave2.sql');
foreach (['ap_payments', 'billing_payments', 'staffing_clients', 'accounting_bank_statement_lines'] as $tbl) {
    $a("adds external_id to {$tbl}",
        $c($mig, "ALTER TABLE {$tbl} ADD COLUMN external_id VARCHAR(128) NULL"));
    $a("adds source_system enum to {$tbl}",
        $c($mig, "ALTER TABLE {$tbl} ADD COLUMN source_system ENUM('manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other')"));
}
$a("ap_payments unique key uq_app_tenant_source_ext",
    $c($mig, 'uq_app_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("billing_payments unique key uq_bp_tenant_source_ext",
    $c($mig, 'uq_bp_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("staffing_clients unique key uq_sc_tenant_source_ext",
    $c($mig, 'uq_sc_tenant_source_ext (tenant_id, source_system, external_id)'));
// Treasury keeps its existing fitid UNIQUE — gets a non-unique index
// because (src,ext) is for reporting joins, not race-prevention.
$a("bank_statement_lines non-unique idx_bsl_tenant_source_ext",
    $c($mig, 'idx_bsl_tenant_source_ext (tenant_id, source_system, external_id)'));
$a("uses information_schema-gated PREPARE for idempotency",
    $c($mig, 'FROM information_schema.columns') && $c($mig, 'PREPARE stmt FROM @sql'));

// ----------------------------------------------------------------- AP payments
echo "\nmodules/ap/api/payments_csv_import.php\n";
$apP = (string) file_get_contents($ROOT . '/modules/ap/api/payments_csv_import.php');
$a("registers external_id field",                 $c($apP, "'external_id'  => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($apP, "'source_system'=> ['label' => 'Source system',"));
$a("unique_within_batch on external_id",          $c($apP, "'unique_within_batch' => ['external_id']"));
$a("upsert prefers (src,ext) match",              $c($apP, 'AND source_system = :s AND external_id = :e'));
$a("scopedUpdate path on match",                  $c($apP, "scopedUpdate('ap_payments'"));
$a("scopedInsert writes external_id + source_system",
                                                  $c($apP, "'external_id'        => \$externalId,")
                                               && $c($apP, "'source_system'      => \$sourceSystem,"));

// ----------------------------------------------------------------- Billing payments
echo "\nmodules/billing/api/payments_csv_import.php\n";
$bP = (string) file_get_contents($ROOT . '/modules/billing/api/payments_csv_import.php');
$a("registers external_id field",                 $c($bP, "'external_id'  => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($bP, "'source_system'=> ['label' => 'Source system',"));
$a("upsert prefers (src,ext) match",              $c($bP, 'AND source_system = :s AND external_id = :e'));
$a("scopedUpdate path on match",                  $c($bP, "scopedUpdate('billing_payments'"));
$a("scopedInsert writes external_id + source_system",
                                                  $c($bP, "'external_id'        => \$externalId,")
                                               && $c($bP, "'source_system'      => \$sourceSystem,"));

// ----------------------------------------------------------------- Staffing
echo "\nmodules/staffing/api/csv_import.php\n";
$st = (string) file_get_contents($ROOT . '/modules/staffing/api/csv_import.php');
$a("registers external_id field",                 $c($st, "'external_id'           => ['label' => 'External ID"));
$a("registers source_system enum field",          $c($st, "'source_system'         => ['label' => 'Source system',"));
$a("unique_within_batch includes external_id",    $c($st, "'unique_within_batch' => ['external_id', 'name']"));
$a("resolver prefers (src,ext) over fuzzy name",  $c($st, 'AND source_system = :s AND external_id = :e'));
$a("idempotent feed bypasses already-exists guard",
                                                  $c($st, '!$updateExisting && $externalId === null'));
$a("scopedInsert writes external_id + source_system",
                                                  $c($st, "'external_id'           => \$externalId,")
                                               && $c($st, "'source_system'         => \$sourceSystem,"));

// ----------------------------------------------------------------- Treasury
echo "\nmodules/treasury/lib/csv_import.php\n";
$tr = (string) file_get_contents($ROOT . '/modules/treasury/lib/csv_import.php');
$a("detects external_id header aliases",
    $c($tr, "treasuryCsvFindColumn(\$headers, ['external_id', 'source_id', 'mercury_id', 'plaid_transaction_id', 'transaction_id']"));
$a("detects source_system header alias",
    $c($tr, "treasuryCsvFindColumn(\$headers, ['source_system', 'source']"));
$a("INSERT carries external_id + source_system",  $c($tr, 'bank_reference, external_id, source_system, fitid'));
$a("validates source_system against enum",         $c($tr, "['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other'], true)"));
$a("when external_id present, uses it as fitid seed",
                                                  $c($tr, "\$fitid = \$srcSys . '_' . substr(sha1(\$extId), 0, 24)"));
$a("legacy date|amount|desc fitid seed still works",
                                                  $c($tr, '$fitidSeed = $date . \'|\' . number_format($amount'));
$a("execute binds :ext and :src",                 $c($tr, "'ext'   => \$extId !== null ? mb_substr(\$extId, 0, 128)"));

// ----------------------------------------------------------------- syntax
echo "\nSyntax sanity\n";
foreach ([
    '/modules/ap/api/payments_csv_import.php',
    '/modules/billing/api/payments_csv_import.php',
    '/modules/staffing/api/csv_import.php',
    '/modules/treasury/lib/csv_import.php',
] as $rel) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . $rel) . ' 2>&1', $o, $rc);
    $a("php -l {$rel}", $rc === 0);
}

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "CSV external_id (wave 2): {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
