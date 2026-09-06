<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sync = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$api = (string) file_get_contents($root . '/api/jobdiva.php');
$ui = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaSettings.jsx');

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
    && str_contains($sync, 'min(8, $limit)'));
$assert('each enriched contract is persisted and projected immediately',
    str_contains($sync, 'SET payload_snapshot = :payload')
    && str_contains($sync, 'jobdivaProjectorProjectPlacement'));
$assert('existing active placement batches preserve the canonical person identity',
    str_contains($sync, 'p.person_id AS existing_person_id')
    && str_contains($sync, "'person_id' => \$isArchived ? 0 : \$rowMeta['person_id']"));
$assert('stale exact placement mappings are revalidated by authoritative contract detail',
    str_contains($sync, "m.sync_status IN ('ok', 'stale')")
    && str_contains($sync, 'p.deleted_at AS placement_deleted_at'));
$assert('archived rows restore only from an explicit current contract lifecycle',
    str_contains($sync, "\$contractStatus !== ''")
    && str_contains($sync, "['active', 'pending_start', 'on_hold']")
    && str_contains($sync, "'person_id' => \$isArchived ? 0")
    && str_contains($sync, "'force_source_contract' => true"));
$assert('rate-field overrides receive the source payload before their fallback callback',
    str_contains($sync, "            \$field,\n            \$jd,\n            static fn() => \$fallbackKeys"));
$assert('the API exposes a separately bounded contract action',
    str_contains($api, "case 'assignment_contracts_batch':")
    && str_contains($api, 'jobdivaSyncAssignmentContractsBatch'));
$assert('Sync now automatically drains contract batches to completion',
    str_contains($ui, "action=assignment_contracts_batch")
    && str_contains($ui, 'if (batch.done) break')
    && str_contains($ui, 'nextCursor <= cursor'));
$assert('operator results include projected and unavailable contract counts',
    str_contains($ui, 'assignment_contract: contractProjected')
    && str_contains($ui, 'restored: contractRestored')
    && str_contains($ui, 'skipped_not_current: contractSkippedNotCurrent')
    && str_contains($ui, 'failed: contractFailed'));

echo "\nJobDiva assignment contract batch smoke: {$pass} ok / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
