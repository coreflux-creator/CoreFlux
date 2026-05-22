<?php
/**
 * Sync History — per-record change log smoke.
 *
 * Verifies:
 *   1. Migration 069 creates `entity_sync_history` with the right
 *      shape and indexes.
 *   2. mappingUpsert() now accepts $actorUserId and writes a history
 *      row IFF content_hash changed (signal-only — unchanged-but-touched
 *      syncs do NOT write).
 *   3. entitySyncHistoryList() decodes payload_before/after as objects.
 *   4. /api/integrations/sync_history.php exists, validates input, runs
 *      RBAC, resolves actor emails in one query (no N+1).
 *   5. SyncHistoryDrawer renders a header button and an open drawer
 *      with field-level diff computation that hides unchanged keys.
 *   6. JobDiva sync paths thread $userId through mappingUpsert() so the
 *      history actor reflects the operator who hit "Sync now".
 *   7. History write failure is non-fatal (try/catch around the INSERT
 *      so a malformed payload doesn't break the actual sync).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration 069 — entity_sync_history\n";
$mig = (string) file_get_contents("{$ROOT}/core/migrations/069_entity_sync_history.sql");
$assert('creates entity_sync_history',           strpos($mig, 'CREATE TABLE IF NOT EXISTS entity_sync_history') !== false);
$assert('captures payload_before (nullable for first record)',
    strpos($mig, 'payload_before    LONGTEXT     DEFAULT NULL') !== false);
$assert('captures payload_after (NOT NULL — every history row has a final state)',
    strpos($mig, 'payload_after     LONGTEXT     NOT NULL') !== false);
$assert('captures content_hash_before + content_hash_after',
    strpos($mig, 'content_hash_before VARCHAR(64) DEFAULT NULL') !== false
    && strpos($mig, 'content_hash_after  VARCHAR(64) NOT NULL') !== false);
$assert('captures actor_user_id (nullable for cron-driven syncs)',
    strpos($mig, 'actor_user_id     BIGINT UNSIGNED DEFAULT NULL') !== false);
$assert('indexed for drawer query (tenant, entity_type, internal_id, time)',
    strpos($mig, 'KEY ix_entity_recent (tenant_id, internal_entity_type, internal_entity_id, created_at)') !== false);
$assert('secondary index for system-wide source queries',
    strpos($mig, 'KEY ix_source_recent (tenant_id, source_system, created_at)') !== false);

echo "\nmappingUpsert — actor + history hook\n";
$emPath = "{$ROOT}/core/integrations/entity_mappings.php";
$em     = (string) file_get_contents($emPath);
$assert('parses',                                $lint($emPath));
$assert('accepts $actorUserId param (default null for backwards compat)',
    strpos($em, '?int $actorUserId = null') !== false);
$assert('writes history ONLY when content changed (signal-only)',
    strpos($em, 'if ($changed && $snapshot !== null && $hash !== null) {') !== false);
$assert('forwards actor + before/after payloads to history recorder',
    strpos($em, 'entitySyncHistoryRecord(') !== false
    && strpos($em, '$existing[\'payload_snapshot\']') !== false
    && strpos($em, '$existing[\'content_hash\']') !== false);
$assert('declares entitySyncHistoryRecord helper',
    strpos($em, 'function entitySyncHistoryRecord(') !== false);
$assert('history write is non-fatal (try/catch logs but does not throw)',
    strpos($em, 'try {') !== false
    && strpos($em, "error_log('entitySyncHistoryRecord failed (non-fatal)") !== false);
$assert('declares entitySyncHistoryList(tid, entity_type, internal_id, limit)',
    strpos($em, 'function entitySyncHistoryList(int $tenantId, string $entityType, int $internalId, int $limit = 50)') !== false);
$assert('list limit is clamped to safe range (1..500)',
    strpos($em, '$limit = max(1, min(500, $limit));') !== false);
$assert('list decodes payload_before + payload_after as objects',
    strpos($em, "foreach (['payload_before', 'payload_after'] as \$col)") !== false);

echo "\nGET /api/integrations/sync_history.php\n";
$apiPath = "{$ROOT}/api/integrations/sync_history.php";
$api     = (string) file_get_contents($apiPath);
$assert('file exists + parses',                  strlen($api) > 0 && $lint($apiPath));
$assert('RBAC: integrations.read',               strpos($api, "rbac_legacy_require(\$user, 'integrations.read')") !== false);
$assert('rejects non-GET',                       strpos($api, "api_method() !== 'GET'") !== false);
$assert('422 when entity_type missing',          strpos($api, "api_error('entity_type required', 422)") !== false);
$assert('422 when internal_id missing',          strpos($api, "api_error('internal_id required', 422)") !== false);
$assert('resolves actor emails in single IN(...) query (no N+1)',
    strpos($api, '$stmt = $pdo->prepare("SELECT id, email FROM users WHERE id IN ({$placeholders})")') !== false);
$assert('attaches actor object to each row (id + email)',
    strpos($api, "\$r['actor'] = (\$r['actor_user_id'] !== null && isset(\$actorMap[(int) \$r['actor_user_id']]))") !== false);

echo "\nSyncHistoryDrawer — UI\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/components/SyncHistoryDrawer.jsx");
$assert('renders trigger button with History icon',
    strpos($ui, "<History size={12} /> Sync history") !== false);
$assert('trigger has stable test id',
    strpos($ui, 'data-testid={`sync-history-open-${entityType}-${internalId}`}') !== false);
$assert('drawer opens as right-anchored slide-out',
    strpos($ui, 'justifyContent: \'flex-end\'') !== false
    && strpos($ui, "width: 'min(640px, 100vw)'") !== false);
$assert('useApi gated on open so it does not pre-fetch',
    strpos($ui, 'const url = open && entityType && internalId') !== false);
$assert('declares diffPayloads helper (per-key change diff)',
    strpos($ui, 'function diffPayloads(before, after)') !== false);
$assert('diff hides unchanged keys (signal-only)',
    strpos($ui, 'if (as !== bs) changes.push({ key: k, before: av, after: bv });') !== false);
$assert('diff sorted alphabetically for stable scanning',
    strpos($ui, 'changes.sort((x, y) => x.key.localeCompare(y.key))') !== false);
$assert('HistoryRow renders source label + change count badge',
    strpos($ui, '{changes.length} field{changes.length === 1 ? \'\' : \'s\'} changed') !== false);
$assert('per-row toggle exposes diff table on expand',
    strpos($ui, 'data-testid={`sync-history-diff-${row.id}`}') !== false);
$assert('per-field diff row has stable test id',
    strpos($ui, 'data-testid={`sync-history-diff-row-${row.id}-${c.key}`}') !== false);
$assert('shows "system (cron)" when no actor',
    strpos($ui, 'row.actor_user_id ? `User #${row.actor_user_id}` : \'system (cron)\'') !== false);
$assert('empty state message points to next sync',
    strpos($ui, 'No sync changes recorded yet') !== false);

echo "\nPlacementDetail wiring\n";
$pd = (string) file_get_contents("{$ROOT}/modules/placements/ui/PlacementDetail.jsx");
$assert('imports SyncHistoryDrawer',
    strpos($pd, "import SyncHistoryDrawer from '../../../dashboard/src/components/SyncHistoryDrawer'") !== false);
$assert('renders drawer for placement entity',
    strpos($pd, '<SyncHistoryDrawer entityType="placement" internalId={placement.id} />') !== false);

echo "\nJobDiva sync — actor threaded into mappingUpsert\n";
$syncSrc = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$assert('companies upsert passes $userId',
    strpos($syncSrc, "mappingUpsert(\$tid, 'jobdiva', 'company', \$extId, \$companyId, \$jd, 'pull', \$userId);") !== false);
$assert('contacts upsert passes $userId',
    strpos($syncSrc, "mappingUpsert(\$tid, 'jobdiva', 'contact', \$extId, \$internalId, \$jd, 'pull', \$userId);") !== false);
$assert('placements upsert passes $userId',
    strpos($syncSrc, "mappingUpsert(\$tid, 'jobdiva', 'placement', \$extId, \$internalId, \$jd, 'pull', \$userId);") !== false);
$pSrc = (string) file_get_contents("{$ROOT}/core/jobdiva/sync_placements.php");
$assert('auto-create person upserts existing match with $userId',
    strpos($pSrc, "mappingUpsert(\$tid, 'jobdiva', 'person', \$candidateExtId, \$existingId, \$jd, 'pull', \$userId);") !== false);
$assert('auto-create person upserts new person with $userId',
    strpos($pSrc, "mappingUpsert(\$tid, 'jobdiva', 'person', \$candidateExtId, \$newId, \$jd, 'pull', \$userId);") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
