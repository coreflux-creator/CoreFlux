<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sync = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$api = (string) file_get_contents($root . '/api/jobdiva.php');
$ui = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaSettings.jsx');
$contractBatch = strstr($sync, 'function jobdivaSyncAssignmentContractsBatch');
$contractBatch = is_string($contractBatch)
    ? (string) strstr($contractBatch, 'function jobdivaSyncCandidateAssignmentsBatch', true)
    : '';

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $condition) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "  OK {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
};

echo "JobDiva assignment contract batch smoke\n";
echo "=======================================\n";

$assert('ordinary placement sync excludes the per-Start financial fan-out',
    str_contains($sync, "'enrich_financial' => \$enrichFinancial")
    && str_contains($sync, ": false;"));
$assert('the enricher supports an explicit financial-only run',
    str_contains($sync, "'kinds' => ['financial']")
    && str_contains($sync, "'enrich_financial' => true"));
$assert('contract batches are cursor-based and capped below the PHP timeout',
    str_contains($sync, 'function jobdivaSyncAssignmentContractsBatch')
    && str_contains($sync, 'id > :cursor')
    && substr_count($sync, '$limit = 1;') >= 2);
$assert('each enriched contract is persisted and projected immediately',
    str_contains($sync, 'SET payload_snapshot = :payload')
    && str_contains($sync, 'jobdivaProjectorProjectPlacement'));
$assert('existing placement batches re-resolve the canonical source person identity',
    str_contains($sync, 'p.person_id AS existing_person_id')
    && str_contains($sync, "'person_id' => 0"));
$assert('ordinary contract batches resume from only unresolved exact contracts',
    !str_contains($contractBatch, 'm.sync_status =')
    && !str_contains($contractBatch, 'm.sync_status <>')
    && str_contains($contractBatch, "JSON_EXTRACT(m.payload_snapshot, '$._jd_contract.contract_version') IS NULL")
    && str_contains($contractBatch, 'p.deleted_at AS placement_deleted_at'));
$assert('archived rows restore only from an explicit current contract lifecycle',
    str_contains($sync, "\$contractStatus !== ''")
    && str_contains($sync, "['active', 'pending_start', 'on_hold']")
    && str_contains($sync, "'person_id' => 0")
    && str_contains($sync, "'force_source_contract' => true"));
$assert('candidate-scoped discovery stages census omissions for exact review',
    str_contains($sync, 'function jobdivaSyncCandidateAssignmentsBatch')
    && str_contains($sync, "'candidateid' => (int) \$candidateId")
    && str_contains($sync, "'jobdiva_assignment_review'"));
$assert('mapped review candidates are not skipped as already current',
    str_contains($sync, "(\$placementMapping['sync_status'] ?? '') === 'ok' && \$bucket === 'current'"));
$assert('ambiguous SearchStart rows are retained for exact contract review',
    str_contains($sync, "'jobdiva_assignment_review'")
    && str_contains($sync, 'function jobdivaSyncReviewAssignmentContractsBatch'));
$assert('review candidates contribute their Job and Candidate IDs to the bulk mirror',
    str_contains($sync, "internal_entity_type IN ('placement', 'jobdiva_assignment_review')")
    && str_contains($sync, "'review_candidates_scanned'"));
$assert('review candidates project only after an explicit current contract lifecycle',
    str_contains($sync, "EmployeeAssignmentRecordsDetail:contract_review")
    && str_contains($sync, "['active', 'pending_start', 'on_hold']")
    && str_contains($sync, "'unavailable' => 0"));
$assert('review candidates discard stale current contracts before exact validation',
    str_contains($sync, "\$payload['__cf_jobdiva_census_scope'] = 'review';")
    && str_contains($sync, "unset(\$payload['_jd_assignment_detail'], \$payload['_jd_contract']);"));
$assert('financial detail retries by exact employee and filters to the requested Start',
    str_contains($sync, "'employeeId' => \$candidateId")
    && str_contains($sync, "\$diag[\$kind]['fallback_attempted']++")
    && str_contains($sync, 'jobdivaAssignmentContractRowsForStart'));
$assert('review contracts demote stale source-bound placements when exact lifecycle is not current',
    str_contains($sync, "'demoted' => 0")
    && str_contains($sync, "['draft', 'ended', 'cancelled']")
    && str_contains($sync, "assignment_contract_lifecycle"));
$assert('financial detail lookup prefers the verified Start identity',
    str_contains($sync, "['__cf_jobdiva_assignment_id', 'startId', 'start_id', 'placementId', 'id']"));
$assert('rate-field overrides receive the source payload before their fallback callback',
    str_contains($sync, "            \$field,\n            \$jd,\n            static fn() => \$fallbackKeys"));
$assert('the API exposes a separately bounded contract action',
    str_contains($api, "case 'assignment_contracts_batch':")
    && str_contains($api, 'jobdivaSyncAssignmentContractsBatch'));
$assert('the API exposes a bounded assignment-review action',
    str_contains($api, "case 'review_assignment_contracts_batch':")
    && str_contains($api, 'jobdivaSyncReviewAssignmentContractsBatch'));
$assert('the API exposes candidate-scoped assignment discovery',
    str_contains($api, "case 'candidate_assignments_batch':")
    && str_contains($api, 'jobdivaSyncCandidateAssignmentsBatch'));
$assert('Sync now automatically drains contract batches to completion',
    str_contains($ui, "action=assignment_contracts_batch")
    && str_contains($ui, 'if (batch.done) break')
    && str_contains($ui, 'nextCursor <= cursor'));
$assert('Sync now drains exact assignment-review batches automatically',
    str_contains($ui, 'action=review_assignment_contracts_batch')
    && str_contains($ui, 'assignment_review: review.projected'));
$assert('Sync now checks known candidates before contract projection',
    str_contains($ui, 'action=candidate_assignments_batch')
    && str_contains($ui, 'candidate_assignment_discovery'));
$assert('operators can reconcile assignment identity without rerunning the full mirror sync',
    str_contains($ui, 'const onReconcileAssignments = async () =>')
    && preg_match(
        '/const onReconcileAssignments = async \(\) => \{.*?drainAssignmentContracts\(\).*?drainReviewAssignments\(\)/s',
        $ui
    ) === 1
    && str_contains($ui, 'jobdiva-settings-reconcile-assignments')
    && str_contains($ui, 'Reconcile assignments'));
$assert('operator results include projected and unavailable contract counts',
    str_contains($ui, 'assignment_contract: contracts.projected')
    && str_contains($ui, 'restored: contracts.restored')
    && str_contains($ui, 'skipped_not_current: contracts.skippedNotCurrent')
    && str_contains($ui, 'failed: contracts.failed')
    && str_contains($ui, 'unavailable_ids: review.unavailableIds')
    && str_contains($ui, 'Unresolved Start IDs:'));

echo "\nJobDiva assignment contract batch smoke: {$pass} ok / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
