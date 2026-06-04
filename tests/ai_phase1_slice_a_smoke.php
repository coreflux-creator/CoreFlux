<?php
/**
 * Smoke — Slice A: Tool Registry persistence + Artifact Layer (2026-02).
 *
 * Locks the Phase 1 foundation work from the AI-Native Extension spec:
 *   - Migration 105: tool_registry, tool_permissions, artifact_objects,
 *     artifact_events, artifact_relationships.
 *   - core/ai/artifacts.php: full lifecycle helper surface
 *     (artifactCreate / artifactUpdate / artifactTransition /
 *      artifactLink / artifactGet / artifactList / artifactLineage).
 *   - core/ai/tool_gateway.php: aiToolRegistrySync() mirrors the PHP
 *     array into tool_registry idempotently.
 *   - /api/ai/admin.php: 6 read endpoints (list_runs, get_run,
 *     list_tools, list_invocations, list_artifacts, get_artifact).
 *   - dashboard ArtifactsAdmin.jsx mounted at /admin/ai/artifacts.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 105 — five tables in one file.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 105 ──\n";
$mig = (string) file_get_contents('/app/core/migrations/105_ai_phase1_tool_registry_and_artifact_layer.sql');
$a('migration file exists',             $mig !== '');
$a('CREATE TABLE tool_registry',         $c($mig, 'CREATE TABLE IF NOT EXISTS tool_registry'));
$a('CREATE TABLE tool_permissions',      $c($mig, 'CREATE TABLE IF NOT EXISTS tool_permissions'));
$a('CREATE TABLE artifact_objects',      $c($mig, 'CREATE TABLE IF NOT EXISTS artifact_objects'));
$a('CREATE TABLE artifact_events',       $c($mig, 'CREATE TABLE IF NOT EXISTS artifact_events'));
$a('CREATE TABLE artifact_relationships',$c($mig, 'CREATE TABLE IF NOT EXISTS artifact_relationships'));

// Spec-mandated columns on tool_registry.
$a('tool_registry has risk_level enum (read/draft/transactional/irreversible)',
    $c($mig, "ENUM('read','draft','transactional','irreversible')"));
$a('tool_registry uniques tool_name',    $c($mig, 'UNIQUE KEY uq_tool_name (tool_name)'));
$a('tool_registry carries args_schema JSON', $c($mig, 'args_schema          JSON NOT NULL'));

// tool_permissions per-tenant overrides.
$a('tool_permissions uniques (tenant_id, tool_name)',
    $c($mig, 'UNIQUE KEY uq_tp_tenant_tool (tenant_id, tool_name)'));
$a('tool_permissions can hard-disable a tool per tenant',
    $c($mig, 'allowed             TINYINT(1) NOT NULL DEFAULT 1'));
$a('tool_permissions can force approval per tenant',
    $c($mig, 'approval_required   TINYINT(1) NOT NULL DEFAULT 0'));

// artifact_objects shape.
$a('artifact_objects.id is CHAR(36) UUIDv4 (matches ai_runs.artifact_id)',
    $c($mig, 'id                  CHAR(36) NOT NULL PRIMARY KEY'));
$a('artifact_objects has lifecycle enum incl. archived/rejected',
    $c($mig, "ENUM('draft','review','approved','final','archived','rejected')"));
$a('artifact_objects has version counter (optimistic bump)',
    $c($mig, 'version             INT UNSIGNED NOT NULL DEFAULT 1'));
$a('artifact_objects carries provenance: created_by_user_id + created_by_ai_run',
    $c($mig, 'created_by_user_id  INT UNSIGNED NULL')
    && $c($mig, 'created_by_ai_run   CHAR(36) NULL'));
$a('artifact_objects has source_module/source_record provenance',
    $c($mig, 'source_module       VARCHAR(60) NULL')
    && $c($mig, 'source_record_id    INT UNSIGNED NULL'));
$a('artifact_objects has storage_uri for binary payloads',
    $c($mig, 'storage_uri         VARCHAR(512) NULL'));

// artifact_events shape.
$a('artifact_events tracks prior_status + new_status',
    $c($mig, 'prior_status        VARCHAR(40) NULL')
    && $c($mig, 'new_status          VARCHAR(40) NULL'));
$a('artifact_events supports user/AI/worker actors',
    $c($mig, 'actor_user_id       INT UNSIGNED NULL')
    && $c($mig, 'actor_ai_run        CHAR(36) NULL')
    && $c($mig, 'actor_worker_id     CHAR(36) NULL'));

// artifact_relationships shape.
$a('artifact_relationships can target another artifact OR a domain row',
    $c($mig, 'target_artifact_id   CHAR(36) NULL')
    && $c($mig, 'target_table         VARCHAR(60) NULL')
    && $c($mig, 'target_record_id     INT UNSIGNED NULL'));

// ──────────────────────────────────────────────────────────────────────
// 2) core/ai/artifacts.php — lifecycle helpers.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/artifacts.php ──\n";
$art = (string) file_get_contents('/app/core/ai/artifacts.php');
$a('strict types',                            $c($art, 'declare(strict_types=1);'));
$a('declares ARTIFACT_TRANSITIONS state machine', $c($art, 'const ARTIFACT_TRANSITIONS = ['));
$a('declares artifactCreate',                  $c($art, 'function artifactCreate('));
$a('declares artifactUpdate',                  $c($art, 'function artifactUpdate('));
$a('declares artifactTransition',              $c($art, 'function artifactTransition('));
$a('declares artifactLink',                    $c($art, 'function artifactLink('));
$a('declares artifactGet',                     $c($art, 'function artifactGet('));
$a('declares artifactList',                    $c($art, 'function artifactList('));
$a('declares artifactLineage',                 $c($art, 'function artifactLineage('));
$a('declares artifactWriteEvent (internal)',   $c($art, 'function artifactWriteEvent('));
$a('declares artifactGenerateUuid',            $c($art, 'function artifactGenerateUuid('));

// State-machine guards
$a('transition refuses illegal moves',         $c($art, 'Illegal transition'));
$a('transition is idempotent on same-status',  $c($art, '// idempotent no-op'));
$a('archived is a closed state (no outbound transitions)',
    preg_match("/'archived' => \\[\\]/", $art) === 1);
$a('final → archived only',                    $c($art, "'final'    => ['archived']"));
$a('draft can go to review/rejected/archived', $c($art, "'draft'    => ['review', 'rejected', 'archived']"));

// Link enforcement — exactly one of artifact / table+row.
$a('artifactLink rejects ambiguous targets',
    $c($art, 'Provide EXACTLY one of (targetArtifactId) or (targetTable + targetRecordId)'));

// Version bump + event log on every write.
$a('artifactUpdate bumps version + writes updated event',
    $c($art, 'version = version + 1')
    && $c($art, "artifactWriteEvent(\$tenantId, \$artifactId, 'updated'"));
$a('artifactTransition bumps version + sets archived_at',
    $c($art, 'archived_at = CASE WHEN :s2 = "archived" THEN NOW() ELSE archived_at END'));

// Lineage shape.
$a('artifactLineage returns outgoing + incoming + event_history',
    $c($art, "'outgoing'      => \$outgoing")
    && $c($art, "'incoming'      => \$incoming")
    && $c($art, "'event_history' => \$events"));

// UUID generator matches RFC 4122 v4.
$a('artifactGenerateUuid sets version bits + variant bits',
    $c($art, '$data[6] = chr((ord($data[6]) & 0x0f) | 0x40)')
    && $c($art, '$data[8] = chr((ord($data[8]) & 0x3f) | 0x80)'));

// ──────────────────────────────────────────────────────────────────────
// 3) core/ai/tool_gateway.php — aiToolRegistrySync.
// ──────────────────────────────────────────────────────────────────────
echo "\n── tool_gateway.php sync ──\n";
$tg = (string) file_get_contents('/app/core/ai/tool_gateway.php');
$a('declares aiToolRegistrySync',           $c($tg, 'function aiToolRegistrySync('));
$a('idempotent via static $synced cache',   $c($tg, 'static $synced = false'));
$a('mirrors via INSERT … ON DUPLICATE KEY UPDATE',
    $c($tg, 'ON DUPLICATE KEY UPDATE'));
$a('source = "php_array_seed" on every row', $c($tg, 'source               = "php_array_seed"'));
$a('treats missing tool_registry table as no-op',
    $c($tg, 'tool_registry table missing — run migration 105'));
$a('declares aiToolInferRiskLevel',         $c($tg, 'function aiToolInferRiskLevel('));
$a('infers .draft_ / .propose_ → draft',    $c($tg, "str_contains(\$n, '.draft_')"));
$a('infers .post_ / .approve_ → transactional', $c($tg, "str_contains(\$n, '.post_')"));
$a('infers .send_ / .release_ / .file_ → irreversible',
    $c($tg, "str_contains(\$n, '.release_')")
    && $c($tg, "str_contains(\$n, '.file_')"));
$a('respects explicit risk_level override on the array entry',
    $c($tg, "!empty(\$tool['risk_level']) && is_string(\$tool['risk_level'])"));

// ──────────────────────────────────────────────────────────────────────
// 4) /api/ai/admin.php — 6 read endpoints, RBAC gated.
// ──────────────────────────────────────────────────────────────────────
echo "\n── /api/ai/admin.php ──\n";
$adm = (string) file_get_contents('/app/api/ai/admin.php');
$a('exists',                              $adm !== '');
$a('RBAC gated on ai.audit.view',         $c($adm, "rbac_legacy_require(\$user, 'ai.audit.view')"));
$a('GET-only',                            $c($adm, "if (\$method !== 'GET') api_error('Method not allowed', 405)"));
$a('runs aiToolRegistrySync on load',     $c($adm, 'aiToolRegistrySync()'));
foreach (['list_runs', 'get_run', 'list_tools', 'list_invocations', 'list_artifacts', 'get_artifact'] as $act) {
    $a("action '{$act}' wired", $c($adm, "case '{$act}':"));
}
$a('get_run returns tool_calls array',    $c($adm, "api_ok(['run' => \$run, 'tool_calls' => \$toolCalls])"));
$a('list_tools returns invocation_counts_30d',
    $c($adm, "'invocation_counts_30d' => \$counts"));
$a('list_artifacts returns distribution by type+status',
    $c($adm, "'distribution' => \$dist"));
$a('get_artifact returns lineage envelope',
    $c($adm, "'outgoing'      => \$lineage['outgoing']")
    && $c($adm, "'incoming'      => \$lineage['incoming']")
    && $c($adm, "'event_history' => \$lineage['event_history']"));

// ──────────────────────────────────────────────────────────────────────
// 5) dashboard/src/pages/ArtifactsAdmin.jsx — UI surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── ArtifactsAdmin.jsx ──\n";
$ui = (string) file_get_contents('/app/dashboard/src/pages/ArtifactsAdmin.jsx');
$a('component declared as default export',
    $c($ui, 'export default function ArtifactsAdmin()'));
$a('hits /api/ai/admin.php list_artifacts',
    $c($ui, "action: 'list_artifacts'"));
$a('hits /api/ai/admin.php get_artifact for detail',
    $c($ui, 'action=get_artifact'));
$detailSectionMatch = function (string $tid) use ($c, $ui): bool {
    return $c($ui, "data-testid=\"{$tid}\"") || $c($ui, "testId=\"{$tid}\"");
};
foreach ([
    'artifacts-admin-page',
    'artifacts-admin-title',
    'artifacts-distribution',
    'artifacts-filter-type',
    'artifacts-filter-status',
    'artifacts-filter-module',
    'artifacts-filter-clear',
    'artifacts-list',
    'artifacts-list-empty',
    'artifacts-detail-placeholder',
    'artifacts-detail',
    'artifacts-detail-title',
    'artifacts-detail-payload',
    'artifacts-detail-events',
    'artifacts-detail-outgoing',
    'artifacts-detail-incoming',
] as $tid) {
    $a("testid '{$tid}' present", $detailSectionMatch($tid));
}
foreach ([
    'artifacts-list-row-${r.id}',
    'artifacts-dist-${type}',
    'artifacts-detail-event-${e.id}',
    'artifacts-edge-${direction}-${e.edge_id}',
    'artifacts-status-${status}',
] as $template) {
    $a("template testid '{$template}' present",
        $c($ui, "data-testid={`{$template}`}"));
}

// AdminModule routing.
$admMod = (string) file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports ArtifactsAdmin',
    $c($admMod, "import ArtifactsAdmin from './ArtifactsAdmin'"));
$a('AdminModule routes /admin/ai/artifacts',
    $c($admMod, '/ai/artifacts')
    && $c($admMod, '<ArtifactsAdmin'));
$a('Artifacts surfaced in sidebar nav',
    $c($admMod, "'/admin/ai/artifacts'")
    && $c($admMod, "label: 'Artifacts'"));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Slice A smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
