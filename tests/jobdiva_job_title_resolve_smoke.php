<?php
/**
 * Smoke for jobdivaSyncResolveJobTitles — fixes "(JobDiva Placement N)"
 * placeholder titles caused by the V2 BI Assignment payload not
 * carrying the job's title (only its FK `job id`).
 *
 * Asserts the resolver:
 *   1. Collects unique non-zero `job id` values across the batch
 *   2. Calls /apiv2/jobdiva/searchJob with {jobId: N} per id
 *   3. Tries multiple title key shapes (title / jobTitle / positionTitle)
 *   4. Tries multiple response shapes (data[] / items[] / direct array)
 *   5. Injects `__cf_resolved_job_title` onto each matching item
 *   6. Soft-fails when JobDiva returns an error (logs + continues)
 *
 * Plus: the upsert title pluck now prefers `__cf_resolved_job_title`
 * over the inline keys, so resolved titles ALWAYS win.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");

echo "jobdivaSyncResolveJobTitles — declaration + wiring\n";
$assert('helper declared with canonical signature',
    strpos($sync, 'function jobdivaSyncResolveJobTitles(int $tid, array $items, ?int $userId): array') !== false);
$assert('called from jobdivaSyncPlacements BEFORE the upsert loop',
    preg_match('/\$discovery = jobdivaPlacementsDiscover\([^;]+;\s*\$items\s*= \$discovery\[\'items\'\];\s*\$channel\s*= \$discovery\[\'channel\'\];\s*\/\/.*?\s*\$items = jobdivaSyncResolveJobTitles\(\$tid, \$items, \$userId\);/s', $sync) === 1);

echo "\njobdivaSyncResolveJobTitles — id collection\n";
$assert('plucks job id from V2 BI key shapes',
    strpos($sync, "jobdivaPluckField(\$jd, ['job id', 'jobId', 'job_id', 'jobID'])") !== false);
$assert('dedupes via assoc array (so a batch of 100 placements with same job costs 1 API call)',
    strpos($sync, '$jobIds[(int) $jid] = true;') !== false);
$assert('skips zero / non-numeric ids',
    strpos($sync, "ctype_digit(\$jid) && (int) \$jid > 0") !== false);

echo "\njobdivaSyncResolveJobTitles — API call\n";
$assert('POSTs /apiv2/jobdiva/searchJob with {jobId: N}',
    strpos($sync, "jobdivaCall(\$tid, 'POST', '/apiv2/jobdiva/searchJob', ['jobId' => \$jid])") !== false);
$assert('handles response shape variance (data[] / items[] / direct array)',
    strpos($sync, "\$body['data'] ?? \$body['items'] ?? (is_array(\$body) && isset(\$body[0]) ? \$body : [])") !== false);
$assert('plucks title from multiple JobDiva key shapes',
    strpos($sync, "'title', 'jobTitle', 'job_title', 'job title',") !== false
    && strpos($sync, "'positionTitle', 'position_title', 'roleName',") !== false);

echo "\njobdivaSyncResolveJobTitles — fault tolerance\n";
$assert('catches Throwable so one bad fetch does not abort the sync',
    strpos($sync, "catch (\\Throwable \$e) {\n            error_log(\"[jobdiva] resolveJobTitles failed for job_id={\$jid}") !== false);
$assert('returns the input items array unchanged when no ids resolve',
    strpos($sync, 'if (empty($titles)) return $items;') !== false);

echo "\njobdivaSyncResolveJobTitles — injection\n";
$assert('writes __cf_resolved_job_title onto matching items by reference',
    strpos($sync, "\$jd['__cf_resolved_job_title'] = \$titles[\$j];") !== false);
$assert('upsert title pluck checks __cf_resolved_job_title FIRST',
    preg_match("/if \(!empty\(\\\$jd\['__cf_resolved_job_title'\]\)\) \{\s*return \(string\) \\\$jd\['__cf_resolved_job_title'\];/", $sync) === 1);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
