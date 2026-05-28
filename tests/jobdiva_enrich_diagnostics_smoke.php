<?php
/**
 * jobdiva_enrich_diagnostics_smoke.php
 *
 * Operator wanted the FULL JobDiva Assignment-screen field set so they
 * could clone the UI in CoreFlux. Root cause for "no real change":
 *   1. The backfill called the enricher WITHOUT `enrich_start=1`, so
 *      _jd_start was never populated → assignment entity stayed empty
 *      of rate / billing / pay / overhead / VMS / division fields.
 *   2. The enricher swallowed per-endpoint failures silently, so the
 *      operator had no way to see WHICH endpoints worked vs 4xx'd.
 *
 * This smoke locks in:
 *   1. Enricher's new `&$diagnostics` ref param + the broken-flag /
 *      sample-error capture.
 *   2. Backfill passes `enrich_start=1` and surfaces diagnostics in
 *      the summary.
 *   3. API endpoint bubbles `endpoint_diagnostics` to the response.
 *   4. Studio banner renders the per-endpoint diagnostics table with
 *      colour-coded rows (broken=red, OK=green).
 *
 * Run:  php -d zend.assertions=1 tests/jobdiva_enrich_diagnostics_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "JobDiva enrich diagnostics smoke\n";
echo "================================\n";

// 1) Enricher signature accepts the new diagnostics ref param.
echo "\n1. jobdivaSyncEnrichRelatedEntities signature\n";
$sync = file_get_contents("$root/core/jobdiva/sync.php");
$a('signature accepts &$diagnostics ref param (default null)',
    str_contains($sync, 'function jobdivaSyncEnrichRelatedEntities(int $tid, array $items, ?int $userId, array $opts = [], ?array &$diagnostics = null): array'));
$a('per-kind diagnostics map initialised with ids_seen/attempted/succeeded/etc.',
    str_contains($sync, "\$diag[\$k] = [")
    && str_contains($sync, "'endpoint'        => \$configs[\$k]['endpoint']")
    && str_contains($sync, "'ids_seen'        => 0")
    && str_contains($sync, "'attempted'       => 0")
    && str_contains($sync, "'succeeded'       => 0")
    && str_contains($sync, "'empty_response'  => 0")
    && str_contains($sync, "'failed'          => 0")
    && str_contains($sync, "'broken_endpoint' => false")
    && str_contains($sync, "'sample_error'    => null")
    && str_contains($sync, "'skipped_self'    => 0"));
$a('skipped_self incremented when start endpoint is skipped',
    str_contains($sync, "if (\$kind === 'start' && empty(\$opts['enrich_start']))")
    && str_contains($sync, "\$diag[\$kind]['skipped_self']++"));
$a('attempted++ before each jobdivaCall',
    str_contains($sync, "\$diag[\$kind]['attempted']++"));
$a('succeeded++ on row return',
    str_contains($sync, "\$diag[\$kind]['succeeded']++"));
$a('empty_response++ when payload returns no rows',
    str_contains($sync, "\$diag[\$kind]['empty_response']++"));
$a('failed++ on exception',
    str_contains($sync, "\$diag[\$kind]['failed']++"));
$a('broken_endpoint flag set on 4xx detection',
    str_contains($sync, "\$diag[\$kind]['broken_endpoint'] = true"));
$a('first error stored in sample_error',
    str_contains($sync, "if (\$diag[\$kind]['sample_error'] === null)")
    && str_contains($sync, "\$diag[\$kind]['sample_error'] = substr(\$msg, 0, 240)"));
$a('diagnostics assigned to caller via ref when passed',
    str_contains($sync, 'if ($diagnostics !== null) $diagnostics = $diag'));

// 2) Backfill passes enrich_start=1 + collects diagnostics.
echo "\n2. jobdivaBackfillJoinedIndexes uses enrich_start + diagnostics\n";
$a('declares $enrichDiag = null pre-enrichment',
    str_contains($sync, '$enrichDiag = null;'));
$a('passes enrich_start=1 to enricher',
    str_contains($sync, "['enrich_start' => 1]"));
$a('passes $enrichDiag by reference as 5th arg',
    preg_match('/jobdivaSyncEnrichRelatedEntities\(\s*\$tenantId,\s*\$items,\s*null,\s*\[\'enrich_start\' => 1\],\s*\$enrichDiag\s*\)/', $sync) === 1);
$a('summary.endpoint_diagnostics surfaced when diag is non-empty',
    str_contains($sync, "\$summary['endpoint_diagnostics'] = \$enrichDiag"));

// 3) API endpoint bubbles diagnostics.
echo "\n3. reindex_jobdiva_subpayloads.php\n";
$ep = (string) file_get_contents("$root/api/admin/integrations/reindex_jobdiva_subpayloads.php");
$a('endpoint surfaces endpoint_diagnostics in response',
    str_contains($ep, "'endpoint_diagnostics'")
    && str_contains($ep, "\$summary['endpoint_diagnostics'] ?? []"));

// 4) Studio renders the diagnostics table.
echo "\n4. FieldMappingStudio.jsx diagnostics table\n";
$fms = (string) file_get_contents("$root/dashboard/src/pages/FieldMappingStudio.jsx");
$a('table rendered in collapsible <details>',
    str_contains($fms, 'data-testid="fms-jobdiva-endpoint-diagnostics"')
    && str_contains($fms, '<details'));
$a('per-row stable testid + data-broken attribute',
    str_contains($fms, 'data-testid={`fms-jobdiva-diag-${kind}`}')
    && str_contains($fms, "data-broken={d.broken_endpoint ? 'yes' : 'no'}"));
$a('row colour-coding for broken vs succeeded',
    str_contains($fms, "background: d.broken_endpoint ? '#fef2f2'")
    && str_contains($fms, "d.succeeded > 0    ? '#f0fdf4'"));
$a('table columns: ids_seen / attempted / succeeded / empty / failed / sample_error',
    str_contains($fms, '{d.ids_seen ?? 0}')
    && str_contains($fms, '{d.attempted ?? 0}')
    && str_contains($fms, '{d.succeeded ?? 0}')
    && str_contains($fms, '{d.empty_response ?? 0}')
    && str_contains($fms, '{d.failed ?? 0}')
    && str_contains($fms, '{d.sample_error'));

// 5) PHP syntax + JSX compile sanity (covered by the global suite).
echo "\n5. PHP syntax\n";
$lint = shell_exec('php -l ' . escapeshellarg("$root/core/jobdiva/sync.php") . ' 2>&1');
$a('php -l core/jobdiva/sync.php', str_contains((string) $lint, 'No syntax errors detected'));

echo "\n================================\n";
echo "Enrich diagnostics smoke: $pass ✓ / $fail ✗\n";
echo "================================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
