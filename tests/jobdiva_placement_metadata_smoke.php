<?php
/**
 * Slice 5b smoke — JobDiva placement metadata expansion (Job ID, recruiter,
 * account manager). Pairs with migration 071 and the sync.php upsert
 * extension.
 *
 * Pure static + structural assertions. Production INSERT/UPDATE round-trip
 * is covered transitively by jobdiva_field_mapping_slice4/slice5 smokes
 * that exercise the same code path.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$mig  = (string) file_get_contents('/app/core/migrations/071_jobdiva_placement_metadata.sql');
$sync = (string) file_get_contents('/app/core/jobdiva/sync.php');
$fmap = (string) file_get_contents('/app/core/integrations/field_map.php');

echo "\n1. Migration 071 — placements schema columns\n";
$a('migration file exists', $mig !== '');
foreach ([
    'jobdiva_job_id',
    'recruiter_name', 'recruiter_email',
    'account_manager_name', 'account_manager_email',
] as $col) {
    $a("ALTER adds column '{$col}'", (bool) preg_match("/ADD COLUMN {$col}\\b/", $mig));
}
$a('idx_placements_jobdiva_job_id index defined',
    str_contains($mig, 'idx_placements_jobdiva_job_id'));

echo "\n2. Sync upsert — fields routed through tenant registry\n";
foreach ([
    "'jobdiva_job_id'",
    "'recruiter_name'",
    "'recruiter_email'",
    "'account_manager_name'",
    "'account_manager_email'",
] as $needle) {
    $a("sync.php pluck for {$needle}", str_contains($sync, $needle));
}

echo "\n3. Sync upsert — INSERT/UPDATE bindings\n";
foreach (['jobdiva_job_id', 'recruiter_name', 'recruiter_email',
          'account_manager_name', 'account_manager_email'] as $col) {
    $a("INSERT statement includes '{$col}'",
        (bool) preg_match("/INSERT INTO placements[\\s\\S]+{$col}[\\s\\S]+VALUES/", $sync));
}
$a('UPDATE allFields map keys jobdiva_job_id',
    str_contains($sync, "'jobdiva_job_id'       => ['jji'"));
$a('UPDATE allFields map keys recruiter_name',
    str_contains($sync, "'recruiter_name'       => ['rn'"));
$a('UPDATE allFields map keys account_manager_email',
    str_contains($sync, "'account_manager_email'=> ['ame'"));

echo "\n4. Bulk enrichment — every related JobDiva entity reachable\n";
foreach (['_jd_job', '_jd_candidate', '_jd_customer', '_jd_contact', '_jd_start'] as $injectKey) {
    $a("enricher injects '{$injectKey}'", str_contains($sync, "'inject'   => '{$injectKey}'"));
}
$a('enricher hits /apiv2/jobdiva/searchJob',      str_contains($sync, '/apiv2/jobdiva/searchJob'));
$a('enricher hits /apiv2/jobdiva/searchCandidate',str_contains($sync, '/apiv2/jobdiva/searchCandidate'));
$a('enricher hits /apiv2/jobdiva/searchCustomer', str_contains($sync, '/apiv2/jobdiva/searchCustomer'));
$a('enricher hits /apiv2/jobdiva/searchContact',  str_contains($sync, '/apiv2/jobdiva/searchContact'));
$a('legacy thin wrapper preserved',               str_contains($sync, 'function jobdivaSyncResolveJobTitles'));

echo "\n5. Allow-list — new placement fields available to operators\n";
foreach ([
    'jobdiva_job_id',
    'recruiter_name', 'recruiter_email',
    'account_manager_name', 'account_manager_email',
] as $col) {
    $a("placement allow-list has '{$col}'", str_contains($fmap, "'{$col}'"));
}

echo "\n6. End-client modelling — column AND companies link both wired\n";
$a('jobdivaResolveOrAutoCreateEndClient declared',
    str_contains($sync, 'function jobdivaResolveOrAutoCreateEndClient'));
$a('end_client_company_id written on INSERT',
    str_contains($sync, ':ecc'));
$a('end_client_name written on INSERT alongside FK',
    str_contains($sync, ':ecn'));

echo "\n7. PHP syntax (no parse errors)\n";
foreach ([
    '/app/core/jobdiva/sync.php',
    '/app/core/integrations/field_map.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "JobDiva placement metadata smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
