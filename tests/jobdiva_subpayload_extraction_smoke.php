<?php
/**
 * jobdiva_subpayload_extraction_smoke.php
 *
 * Locks in the full architectural fix for the operator's repeated
 * complaint: "no available fields anywhere except placements, even
 * after sync."
 *
 * Three things this validates together:
 *  1. jobdivaExtractJoinedSubPayloads() correctly fans flat
 *     `candidate_*` / `job_*` / `customer_*` prefix fields AND
 *     nested `_jd_*` objects into per-entity sub-records.
 *  2. jobdivaBackfillJoinedIndexes() will index those sub-records
 *     from EXISTING placement payloads (no new HTTP sync needed).
 *  3. The new /api/admin/integrations/reindex_jobdiva_subpayloads.php
 *     endpoint is wired and gated.
 *  4. The Studio surfaces a re-index banner + button + result line.
 *
 * Run:  php -d zend.assertions=1 tests/jobdiva_subpayload_extraction_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/core/jobdiva/sync.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "JobDiva sub-payload extraction + backfill smoke\n";
echo "==============================================\n";

// 1) Extractor function — flat prefix shape.
echo "\n1. jobdivaExtractJoinedSubPayloads — flat prefix fields\n";
$flatPayload = [
    // Placement-native (should stay placement, not get fanned out)
    'jobRefNo'              => '26-03327',
    'optionalRefNumber'     => '26-03327',
    'pay_agreed_date'       => null,
    'position_type'         => 'contract',
    'startStatus'           => 'Active',
    // Job-prefix flat fields (snake_case)
    'job_id'                => 27857851,
    'job_contact_id'        => 12345,
    'job_contact_name'      => 'Sara Smith',
    'job_dept'              => 'Engineering',
    // Candidate-prefix flat fields (snake_case)
    'candidate_id'          => 9988,
    'candidate_first_name'  => 'Alice',
    'candidate_last_name'   => 'Wong',
    'candidate_email'       => 'alice@example.com',
    // Customer-prefix flat fields (snake_case)
    'customer_id'           => 5555,
    'customer_name'         => 'Public Storage',
    'customer_address1'     => '100 Main St',
    // Employee-prefix (alternate person prefix)
    'employee_phone'        => '555-1212',
    // CamelCase forms
    'candidateMiddleName'   => 'Q',
    'jobTitle'              => 'Senior Engineer',
    'customerCity'          => 'Glendale',
];
$extracted = jobdivaExtractJoinedSubPayloads($flatPayload);
$a('extractor produces a person bucket',
    isset($extracted['person']) && is_array($extracted['person']));
$a('person bucket has snake-case fields stripped',
    ($extracted['person']['first_name'] ?? null) === 'Alice'
    && ($extracted['person']['last_name'] ?? null) === 'Wong'
    && ($extracted['person']['email']    ?? null) === 'alice@example.com');
$a('person bucket merges employee_* into person',
    ($extracted['person']['phone'] ?? null) === '555-1212');
$a('person bucket picks up camelCase candidateMiddleName as middleName',
    ($extracted['person']['middleName'] ?? null) === 'Q');
$a('extractor produces a job bucket with id/contact_id/contact_name',
    ($extracted['job']['id']           ?? null) === 27857851
    && ($extracted['job']['contact_id']   ?? null) === 12345
    && ($extracted['job']['contact_name'] ?? null) === 'Sara Smith'
    && ($extracted['job']['dept']         ?? null) === 'Engineering');
$a('job bucket picks up camelCase jobTitle as title',
    ($extracted['job']['title'] ?? null) === 'Senior Engineer');
$a('extractor produces a jobdiva_customer bucket',
    ($extracted['jobdiva_customer']['id']        ?? null) === 5555
    && ($extracted['jobdiva_customer']['name']      ?? null) === 'Public Storage'
    && ($extracted['jobdiva_customer']['address1']  ?? null) === '100 Main St'
    && ($extracted['jobdiva_customer']['city']      ?? null) === 'Glendale');
$a('placement-native flat fields NOT pulled into joined buckets',
    !isset($extracted['person']['Status'])
    && !isset($extracted['job']['RefNo'])
    && !isset($extracted['job']['_id'])); // make sure jobRefNo / job_id distinguished

// 2) Extractor — nested _jd_* objects override placement-native.
echo "\n2. jobdivaExtractJoinedSubPayloads — nested _jd_* enrichment\n";
$nestedPayload = [
    'candidate_first_name' => 'Alice',                  // flat
    '_jd_candidate' => [
        'firstName' => 'Alicia',                         // nested wins on conflict
        'workEmail' => 'alicia@work.com',
        'mobilePhone' => '555-9999',
    ],
    '_jd_job' => [
        'title' => 'Lead Engineer',
        'description' => 'Lead a team of 5.',
    ],
    '_jd_customer' => [
        'name' => 'Public Storage Inc.',
        'website' => 'publicstorage.com',
    ],
    '_jd_contact' => [
        'firstName' => 'Sara',
        'workEmail' => 'sara@client.com',
    ],
    '_jd_start' => [
        'payRate' => '52.50',
        'billRate' => '120.00',
    ],
];
$ext2 = jobdivaExtractJoinedSubPayloads($nestedPayload);
$a('nested _jd_candidate fields land in person bucket',
    ($ext2['person']['workEmail']   ?? null) === 'alicia@work.com'
    && ($ext2['person']['mobilePhone'] ?? null) === '555-9999');
$a('nested record overrides flat-prefix on key collision',
    ($ext2['person']['firstName'] ?? null) === 'Alicia');
$a('flat candidate_first_name still carried alongside under first_name',
    ($ext2['person']['first_name'] ?? null) === 'Alice');
$a('nested _jd_job → job bucket',
    ($ext2['job']['title'] ?? null) === 'Lead Engineer'
    && ($ext2['job']['description'] ?? null) === 'Lead a team of 5.');
$a('nested _jd_customer → jobdiva_customer bucket',
    ($ext2['jobdiva_customer']['name'] ?? null) === 'Public Storage Inc.'
    && ($ext2['jobdiva_customer']['website'] ?? null) === 'publicstorage.com');
$a('nested _jd_contact → contact bucket (only nested, no flat prefix)',
    ($ext2['contact']['firstName'] ?? null) === 'Sara'
    && ($ext2['contact']['workEmail'] ?? null) === 'sara@client.com');
$a('nested _jd_start → assignment bucket',
    ($ext2['assignment']['payRate']  ?? null) === '52.50'
    && ($ext2['assignment']['billRate'] ?? null) === '120.00');

// 3) Empty buckets dropped.
echo "\n3. Empty buckets dropped from output\n";
$ext3 = jobdivaExtractJoinedSubPayloads(['someUnrelatedKey' => 'x']);
$a('payload with no joined data returns empty array', $ext3 === []);

// 4) Backfill function is declared + uses extractor.
echo "\n4. jobdivaBackfillJoinedIndexes wired\n";
$syncSrc = file_get_contents("$root/core/jobdiva/sync.php");
$a('jobdivaBackfillJoinedIndexes function declared',
    str_contains($syncSrc, 'function jobdivaBackfillJoinedIndexes(int $tenantId): array'));
$a('backfill queries existing placement payloads',
    str_contains($syncSrc, "AND source_system = 'jobdiva'")
    && str_contains($syncSrc, "AND internal_entity_type = 'placement'")
    && str_contains($syncSrc, "AND payload_snapshot IS NOT NULL"));
$a('backfill decodes JSON payload + skips invalid',
    str_contains($syncSrc, 'json_decode($snap, true)')
    && str_contains($syncSrc, 'if (!is_array($payload)) continue'));
$a('backfill calls extractor + indexer per row',
    str_contains($syncSrc, 'jobdivaExtractJoinedSubPayloads($payload)')
    && str_contains($syncSrc, "integrationPayloadFieldIndexRecord(\$tenantId, 'jobdiva', \$entityType, \$sub)"));
$a('backfill returns placements_walked + sub_records_indexed counters',
    str_contains($syncSrc, "'placements_walked'")
    && str_contains($syncSrc, "'sub_records_indexed'"));

// 4.5) Backfill now also pulls full joined records via the JobDiva
// enrichment endpoints (searchJob / searchCandidate / searchCustomer /
// searchContact). Operator complaint: "once I link the job I want to be
// able to pull from the job fields and keep mapping" — the V2 BI
// placement payload only carries flat ref-number stubs (jobRefNo,
// candidateRefNo) so we have to hit the per-id search endpoints to
// surface the full schema.
echo "\n4.5 Backfill calls enricher + re-saves payload_snapshot\n";
$a('backfill detects placements missing _jd_* enrichment',
    str_contains($syncSrc, "empty(\$jd['_jd_job']) || empty(\$jd['_jd_candidate'])")
    && str_contains($syncSrc, "empty(\$jd['_jd_customer'])"));
$a('backfill invokes jobdivaSyncEnrichRelatedEntities on the missing batch',
    str_contains($syncSrc, "jobdivaSyncEnrichRelatedEntities(\n                \$tenantId, \$items, null,\n                ['enrich_start' => 1],\n                \$enrichDiag\n            )"));
$a('backfill persists the enriched payload back to external_entity_mappings',
    str_contains($syncSrc, 'UPDATE external_entity_mappings')
    && str_contains($syncSrc, 'SET payload_snapshot = :p')
    && str_contains($syncSrc, 'json_encode($newPayload'));
$a('summary surfaces enrichment_ran_for + enrichment_errors',
    str_contains($syncSrc, "\$summary['enrichment_ran_for'] = \$enrichmentRanFor")
    && str_contains($syncSrc, "\$summary['enrichment_errors']  = \$enrichmentBroken"));

// 5) Sync site uses extractor for applyAll fan-out (covers flat + nested).
echo "\n5. Placement sync uses extractor for applyAll fan-out\n";
$a('JOINED_CTX map present',
    str_contains($syncSrc, 'static $JOINED_CTX = [')
    && str_contains($syncSrc, "'person'           => 'person'")
    && str_contains($syncSrc, "'jobdiva_customer' => 'end_client_company'"));
$a('joined applyAll iterates extracted sub-payloads',
    str_contains($syncSrc, 'jobdivaExtractJoinedSubPayloads($jd)')
    && str_contains($syncSrc, 'foreach ($joinedSubs as $joinedEntity => $subPayload)'));
$a('joined applyAll invokes integrationFieldMapApplyAll per entity',
    str_contains($syncSrc, "integrationFieldMapApplyAll(\$tid, 'jobdiva', \$joinedEntity, \$subPayload, \$ctx)"));

// 6) Backfill API endpoint is present + protected.
echo "\n6. Re-index API endpoint\n";
$endpoint = "$root/api/admin/integrations/reindex_jobdiva_subpayloads.php";
$a('endpoint file exists', file_exists($endpoint));
$ep = (string) @file_get_contents($endpoint);
$a('endpoint requires sync.php for backfill function',
    str_contains($ep, "core/jobdiva/sync.php'"));
$a('endpoint requires api_require_auth',
    str_contains($ep, 'api_require_auth'));
$a('endpoint enforces POST',
    str_contains($ep, "if (api_method() !== 'POST')"));
$a('endpoint guarded by tenant_admin.integrations',
    str_contains($ep, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('endpoint calls jobdivaBackfillJoinedIndexes($tid)',
    str_contains($ep, 'jobdivaBackfillJoinedIndexes($tid)'));
$a('endpoint returns ok + counters',
    str_contains($ep, "'placements_walked'")
    && str_contains($ep, "'sub_records_indexed'")
    && str_contains($ep, "'enrichment_ran_for'")
    && str_contains($ep, "'enrichment_errors'"));

// 7) Studio surfaces re-index button + result + auto-trigger.
echo "\n7. Field Mapping Studio re-index UI\n";
$fms = file_get_contents("$root/dashboard/src/pages/FieldMappingStudio.jsx");
$a('Studio defines handleReindex POST helper',
    str_contains($fms, "const handleReindex = async (silent = false) =>")
    && str_contains($fms, "/api/admin/integrations/reindex_jobdiva_subpayloads.php"));
$a('Studio renders fms-jobdiva-reindex-banner with data-joined-source-count',
    str_contains($fms, 'data-testid="fms-jobdiva-reindex-banner"')
    && str_contains($fms, 'data-joined-source-count={joinedCount}'));
$a('Studio re-index button testid present + calls handleReindex',
    str_contains($fms, 'data-testid="fms-jobdiva-reindex-btn"')
    && str_contains($fms, 'onClick={() => handleReindex(false)}'));
$a('Studio shows fms-jobdiva-reindex-result line when result is set',
    str_contains($fms, 'data-testid="fms-jobdiva-reindex-result"'));
$a('Studio auto-triggers a silent re-index when only placement is indexed',
    str_contains($fms, 'handleReindex(/*silent=*/true)')
    && str_contains($fms, 'hasPlacement && !hasJoined'));
$a('Studio reloads sources + pane after re-index',
    str_contains($fms, 'await reloadSources();')
    && str_contains($fms, 'await reload();'));
$a('Studio surfaces enrichment counter line when enrichment_ran_for > 0',
    str_contains($fms, 'data-testid="fms-jobdiva-reindex-enrichment"')
    && str_contains($fms, 'fetched full joined records for'));
$a('Studio surfaces enrichment-error hint when endpoints fail',
    str_contains($fms, 'data-testid="fms-jobdiva-reindex-enrichment-error"')
    && str_contains($fms, '/apiv2/jobdiva/search*'));

// 8) PHP syntax sanity.
echo "\n8. PHP syntax\n";
$lint = shell_exec('php -l ' . escapeshellarg($root . '/core/jobdiva/sync.php') . ' 2>&1');
$a('php -l core/jobdiva/sync.php', str_contains((string) $lint, 'No syntax errors detected'));
$lint2 = shell_exec('php -l ' . escapeshellarg($endpoint) . ' 2>&1');
$a('php -l reindex_jobdiva_subpayloads.php', str_contains((string) $lint2, 'No syntax errors detected'));

echo "\n==============================================\n";
echo "Sub-payload extraction + backfill smoke: $pass ✓ / $fail ✗\n";
echo "==============================================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
