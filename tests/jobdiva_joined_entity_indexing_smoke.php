<?php
/**
 * jobdiva_joined_entity_indexing_smoke.php
 *
 * Validates the new sync-side behaviour that makes the Field Mapping
 * Studio's canonical entity types (placement/person/company/contact)
 * actually populated and usable.
 *
 * Operator complaint that triggered this:
 *   "there are no available fields anywhere except placements, even
 *    after sync. it's still not doing what it needs to. get all jobs,
 *    assignments, people, EVERYTHING. link person to job, client,
 *    assignment etc."
 *
 * Root cause: JobDiva's V2 BI feed only ships
 * NewUpdatedCompanyRecords / NewUpdatedContactRecords / NewUpdated
 * TimesheetRecords. There is no NewUpdatedJobRecords or
 * NewUpdatedCandidateRecords. Every placement is enriched server-side
 * with `_jd_candidate`, `_jd_job`, `_jd_customer`, `_jd_contact`,
 * `_jd_start` sub-records — but those were never indexed under their
 * own entity_type, so the Studio's entity dropdown surfaced no paths
 * outside `placement`.
 *
 * Run:  php -d zend.assertions=1 tests/jobdiva_joined_entity_indexing_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "JobDiva joined-entity indexing smoke\n";
echo "=========================================\n";

// 1) Sync source declares the side-effect helper.
echo "\n1. jobdivaIndexJoinedSubPayloads helper\n";
$sync = file_get_contents("$root/core/jobdiva/sync.php");
$a('payload_field_index.php required at top of sync',
    str_contains($sync, "require_once __DIR__ . '/../integrations/payload_field_index.php'"));
$a('jobdivaIndexJoinedSubPayloads function declared',
    str_contains($sync, 'function jobdivaIndexJoinedSubPayloads(int $tenantId, array $enrichedPayload): void'));
$a('helper delegates to jobdivaExtractJoinedSubPayloads',
    str_contains($sync, '$subs = jobdivaExtractJoinedSubPayloads($enrichedPayload);'));
$a('extractor maps _jd_candidate → person',
    str_contains($sync, "'_jd_candidate' => 'person'"));
$a('extractor maps _jd_job → job',
    str_contains($sync, "'_jd_job'       => 'job'"));
$a('extractor maps _jd_customer → jobdiva_customer',
    str_contains($sync, "'_jd_customer'  => 'jobdiva_customer'"));
$a('extractor maps _jd_contact → contact',
    str_contains($sync, "'_jd_contact'   => 'contact'"));
$a('extractor maps _jd_start → assignment',
    str_contains($sync, "'_jd_start'     => 'assignment'"));
$a('helper indexes native and canonical entity types per sub-record',
    str_contains($sync, 'jobdivaCanonicalFieldIndexEntityTypes($entityType)')
    && str_contains($sync, 'jobdivaCanonicalPayloadForEntity($entityType, $indexEntityType, $subPayload)')
    && str_contains($sync, "integrationPayloadFieldIndexRecord(\$tenantId, 'jobdiva', \$indexEntityType, \$payloadForIndex)"));
$a('helper swallows errors so indexing failures never block sync',
    preg_match('/jobdivaIndexJoinedSubPayloads.*?catch \(\\\\Throwable \$e\)/s', $sync) === 1);

// 2) Placement sync call site wires the helper after mappingUpsert.
echo "\n2. Placement sync invokes the helper\n";
$a('helper invoked after placement mapping upsert',
    str_contains($sync, "jobdivaIndexJoinedSubPayloads(\$tid, \$jd);"));
$a('invocation wrapped in try/catch — best-effort',
    preg_match('/jobdivaIndexJoinedSubPayloads\(\$tid, \$jd\);.*?catch \(\\\\Throwable \$e\)/s', $sync) === 1);

// 3) applyAll fires for each joined entity type with its sub-payload.
echo "\n3. applyAll wired per joined entity\n";
$a('JOINED_CTX table declared with person/job/customer/contact/assignment',
    str_contains($sync, 'static $JOINED_CTX = [')
    && str_contains($sync, "'person'           => 'person'")
    && str_contains($sync, "'job'              => 'self'")
    && str_contains($sync, "'jobdiva_customer' => 'end_client_company'")
    && str_contains($sync, "'contact'          => 'self'")
    && str_contains($sync, "'assignment'       => 'self'"));
$a('per-entity applyAll iterates extracted sub-payloads through canonical aliases',
    str_contains($sync, 'jobdivaExtractJoinedSubPayloads($jd)')
    && str_contains($sync, 'jobdivaCanonicalApplyEntityTypes($joinedEntity)')
    && str_contains($sync, 'jobdivaCanonicalPayloadForEntity($joinedEntity, $mapEntityType, $subPayload)')
    && str_contains($sync, "integrationFieldMapApplyAll(\$tid, 'jobdiva', \$mapEntityType, \$payloadForApply, \$ctx)"));
$a('joined applyAll wrapped in try/catch',
    preg_match("/integrationFieldMapApplyAll\(\\\$tid, 'jobdiva', \\\$mapEntityType.*?catch \(\\\\Throwable \\\$e\)/s", $sync) === 1);
$a('placement payload is canonicalized before upsert/apply',
    str_contains($sync, 'jobdivaCanonicalPlacementPayload($jd, jobdivaExtractJoinedSubPayloads($jd))')
    && strpos($sync, 'jobdivaCanonicalPlacementPayload($jd, jobdivaExtractJoinedSubPayloads($jd))') < strpos($sync, 'jobdivaSyncUpsertPlacement('));

// 4) Field Mapping Studio surface adapts to joined entity types.
echo "\n4. FieldMappingStudio.jsx adapts root label per entity_type\n";
$fms = file_get_contents("$root/dashboard/src/pages/FieldMappingStudio.jsx");
$a('groupPathsByNamespace accepts entityType param',
    str_contains($fms, "function groupPathsByNamespace(paths, entityType = 'placement')"));
$a('groupedPaths memo passes entityType through',
    str_contains($fms, 'groupPathsByNamespace(filteredPaths, entityType)'));
$a('ROOT_LABELS map covers canonical entity types',
    str_contains($fms, "person:") && str_contains($fms, "company:")
    && str_contains($fms, "contact:") && str_contains($fms, "placement:")
    && str_contains($fms, "time_entry:"));
$a('joined-entity explainer banner rendered for non-placement',
    str_contains($fms, 'data-testid="fms-paths-explainer-joined"'));
$a('joined explainer exposes data-entity attribute',
    str_contains($fms, 'data-entity={entityType}'));

// 5) PHP syntax + JSX presence.
echo "\n5. Syntax + presence checks\n";
$lint = shell_exec("php -l " . escapeshellarg("$root/core/jobdiva/sync.php") . " 2>&1");
$a('php -l core/jobdiva/sync.php passes', str_contains((string) $lint, 'No syntax errors detected'));
$a('FieldMappingStudio.jsx still renders fms-paths-grouped',
    str_contains($fms, 'data-testid="fms-paths-grouped"'));
$a('FieldMappingStudio entity dropdown still data-driven',
    str_contains($fms, 's.integration === integration'));

echo "\n=========================================\n";
echo "JobDiva joined-entity indexing smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
