<?php
/**
 * Backwards-compat shim test — the original narrow job-title resolver
 * is now a thin wrapper over jobdivaSyncEnrichRelatedEntities (which
 * has its own dedicated smoke at jobdiva_related_enrich_smoke.php).
 *
 * This file remains so callers/tests referencing the old function name
 * continue to pass.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");

echo "Backwards-compat — jobdivaSyncResolveJobTitles still routes via the general enricher\n";
$assert('legacy function declared',
    strpos($sync, 'function jobdivaSyncResolveJobTitles(int $tid, array $items, ?int $userId): array') !== false);
$assert('legacy function delegates to jobdivaSyncEnrichRelatedEntities',
    strpos($sync, 'return jobdivaSyncEnrichRelatedEntities($tid, $items, $userId, []);') !== false);
$assert('upsert title pluck still preferentially uses __cf_resolved_job_title',
    strpos($sync, "if (!empty(\$jd['__cf_resolved_job_title'])) {") !== false
    && strpos($sync, "return (string) \$jd['__cf_resolved_job_title'];") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
