<?php
/**
 * Smoke for jobdivaSyncEnrichRelatedEntities — the bulk related-entity
 * enricher that supersedes the narrow jobdivaSyncResolveJobTitles.
 *
 * Asserts the enricher:
 *   1. Drives off a declarative config of (kind, id-keys, endpoint,
 *      body-key, inject-key) so future JobDiva entities can be added
 *      with a one-line config edit
 *   2. Fetches every related entity ONCE per sync run (dedupes ids)
 *   3. Soft-fails endpoint by endpoint (a tenant lacking
 *      /searchCandidate doesn't block /searchJob enrichment)
 *   4. Marks a 4xx endpoint as broken so subsequent items skip it
 *   5. Injects nested objects under stable `_jd_<kind>` keys so the
 *      existing dotted-path mapping syntax surfaces them in the editor
 *   6. Preserves the `__cf_resolved_job_title` legacy convenience
 *      field (so the existing title pluck chain keeps working)
 *   7. Skips the `_jd_start` re-fetch by default (avoids 1:1 fan-out)
 *      but honours opts.enrich_start to opt-in for pay-rate retrieval
 *   8. The rate fields fall through to `_jd_start.payRate` /
 *      `_jd_start.finalBillRate` when the BI feed nulls them on the
 *      Assignment payload
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');
$sync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");

echo "jobdivaSyncEnrichRelatedEntities — declaration + config\n";
$assert('canonical signature accepts $opts for opt-in toggles',
    strpos($sync, 'function jobdivaSyncEnrichRelatedEntities(int $tid, array $items, ?int $userId, array $opts = []): array') !== false);
$assert('configs job kind → /searchJob with jobId body key',
    strpos($sync, "'job'       => [") !== false
    && strpos($sync, "'endpoint' => '/apiv2/jobdiva/searchJob',") !== false
    && strpos($sync, "'body_key' => 'jobId',") !== false
    && strpos($sync, "'inject'   => '_jd_job',") !== false);
$assert('configs candidate kind → /searchCandidate',
    strpos($sync, "'candidate' => [") !== false
    && strpos($sync, "'endpoint' => '/apiv2/jobdiva/searchCandidate',") !== false
    && strpos($sync, "'inject'   => '_jd_candidate',") !== false);
$assert('configs customer kind → /searchCustomer',
    strpos($sync, "'customer'  => [") !== false
    && strpos($sync, "'endpoint' => '/apiv2/jobdiva/searchCustomer',") !== false
    && strpos($sync, "'inject'   => '_jd_customer',") !== false);
$assert('configs contact kind → /searchContact with job contact id',
    strpos($sync, "'contact'   => [") !== false
    && strpos($sync, "'job contact id'") !== false
    && strpos($sync, "'inject'   => '_jd_contact',") !== false);
$assert('configs start kind for opt-in detail enrichment',
    strpos($sync, "'start'     => [") !== false
    && strpos($sync, "'inject'   => '_jd_start',") !== false);

echo "\njobdivaSyncEnrichRelatedEntities — fan-out hygiene\n";
$assert('dedupes ids per kind so 100 placements at one client = 1 fetch',
    strpos($sync, '$idsByKind[$kind][(int) $raw] = true;') !== false);
$assert('skips start re-fetch by default, opt-in via opts.enrich_start',
    strpos($sync, "if (\$kind === 'start' && empty(\$opts['enrich_start'])) continue;") !== false);
$assert('marks an endpoint broken on first 4xx so we stop hammering it',
    strpos($sync, "preg_match('/\\b4\\d\\d\\b/', \$msg)") !== false
    && strpos($sync, '$brokenEndpoint[$cfg[\'endpoint\']] = true;') !== false);

echo "\njobdivaSyncEnrichRelatedEntities — injection\n";
$assert("each placement gets `_jd_<kind>` injected when cache has the row",
    strpos($sync, "\$jd[\$cfg['inject']] = \$cache[\$kind][\$id];") !== false);
$assert('preserves __cf_resolved_job_title legacy hint from _jd_job.title',
    strpos($sync, "if (isset(\$jd['_jd_job']) && is_array(\$jd['_jd_job']))") !== false
    && strpos($sync, "\$jd['__cf_resolved_job_title'] = \$title;") !== false);

echo "\nWiring — placement sync calls the enricher, propagates opts\n";
$assert('placement sync calls jobdivaSyncEnrichRelatedEntities with opts.enrich_start',
    preg_match('/jobdivaSyncEnrichRelatedEntities\(\$tid, \$items, \$userId, \[\s*\'enrich_start\'\s*=>\s*!empty\(\$opts\[\'enrich_start\'\]\),\s*\]\)/', $sync) === 1);
$assert('legacy jobdivaSyncResolveJobTitles still exists as thin wrapper',
    strpos($sync, 'function jobdivaSyncResolveJobTitles(int $tid, array $items, ?int $userId): array') !== false
    && strpos($sync, 'return jobdivaSyncEnrichRelatedEntities($tid, $items, $userId, []);') !== false);

echo "\nRate fallback — _jd_start picked up when BI feed nulls rates\n";
$assert('bill_rate default falls through to _jd_start.finalBillRate',
    strpos($sync, "isset(\$jd['_jd_start']) && is_array(\$jd['_jd_start'])\n                ? jobdivaPluckField(\$jd['_jd_start'], [\n                    'finalBillRate', 'billRate', 'final_bill_rate', 'bill_rate',\n                ])") !== false);
$assert('pay_rate default falls through to _jd_start.payRate',
    strpos($sync, "'payRate', 'agreedPayRate', 'pay_rate', 'agreed_pay_rate'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
