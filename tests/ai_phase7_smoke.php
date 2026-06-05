<?php
/**
 * Smoke — Phase 7: AI Worker Runtime + Knowledge Graph + Agent Registry
 * (2026-02). Locks the final delivery in the AI-Native Extension
 * roadmap.
 *
 * Coverage:
 *   - Migrations 109/110/111
 *   - core/ai/worker.php           — durable queue + worker lifecycle
 *   - core/ai/knowledge_graph.php  — docs / entities / edges / FULLTEXT
 *   - core/ai/agents.php           — agent registry + handoff lifecycle
 *   - core/ai/tool_gateway.php     — 4 new tools registered + handlers
 *   - cron/ai_worker.php           — CLI worker loop syntax + structure
 *   - /api/ai/workers.php          — admin endpoints
 *   - /api/ai/knowledge.php        — search / entity / edge endpoints
 *   - /api/ai/agents.php           — list / handoff / resolve endpoints
 *   - AiWorkersAdmin.jsx / KnowledgeGraphExplorer.jsx /
 *     AgentRegistryAdmin.jsx mounted in AdminModule
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 109 — ai_workers + ai_worker_jobs.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 109 (worker queue) ──\n";
$mig109 = (string) file_get_contents('/app/core/migrations/109_ai_workers_and_jobs.sql');
$a('109 file exists',                                       $mig109 !== '');
$a('CREATE TABLE ai_workers',                               $c($mig109, 'CREATE TABLE IF NOT EXISTS ai_workers'));
$a('CREATE TABLE ai_worker_jobs',                           $c($mig109, 'CREATE TABLE IF NOT EXISTS ai_worker_jobs'));
$a('ai_workers status enum',                                $c($mig109, "ENUM('online','draining','stalled','offline')"));
$a('ai_workers UNIQUE(worker_key)',                         $c($mig109, 'UNIQUE KEY uq_ai_worker_key (worker_key)'));
$a('ai_worker_jobs status enum (7 states)',
    $c($mig109, "ENUM('queued','claimed','running','succeeded','failed','dead','cancelled')"));
$a('ai_worker_jobs UNIQUE(tenant, idempotency_key)',        $c($mig109, 'UNIQUE KEY uq_ai_job_idem (tenant_id, idempotency_key)'));
$a('ai_worker_jobs hot-path dequeue index',                 $c($mig109, 'KEY ix_ai_job_dequeue (status, queue, scheduled_at, id)'));
$a('ai_worker_jobs links to artifact_objects',              $c($mig109, 'artifact_id             CHAR(36) NULL'));
$a('ai_worker_jobs links to ai_tool_invocations',           $c($mig109, 'ai_run_id               CHAR(36) NULL'));

// ──────────────────────────────────────────────────────────────────────
// 2) Migration 110 — knowledge graph tables.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 110 (knowledge graph) ──\n";
$mig110 = (string) file_get_contents('/app/core/migrations/110_knowledge_graph.sql');
$a('110 file exists',                                       $mig110 !== '');
foreach (['knowledge_documents','knowledge_entities','knowledge_edges','knowledge_embeddings'] as $tbl) {
    $a("CREATE TABLE $tbl",                                 $c($mig110, "CREATE TABLE IF NOT EXISTS $tbl"));
}
$a('knowledge_documents has FULLTEXT(title, content)',      $c($mig110, 'FULLTEXT KEY ft_kd_text (title, content)'));
$a('knowledge_documents UNIQUE(tenant, doc_uri)',           $c($mig110, 'UNIQUE KEY uq_kd_tenant_uri (tenant_id, doc_uri)'));
$a('knowledge_entities UNIQUE(tenant, type, normalized_key)',$c($mig110, 'UNIQUE KEY uq_ke_tenant_type_key (tenant_id, entity_type, normalized_key)'));
$a('knowledge_edges UNIQUE prevents duplicate edges',       $c($mig110, 'UNIQUE KEY uq_kx_edge (tenant_id, from_entity_id, to_entity_id, relation)'));
$a('knowledge_embeddings stores LONGBLOB for pgvector future',
    $c($mig110, 'vector_bytes         LONGBLOB NOT NULL'));

// ──────────────────────────────────────────────────────────────────────
// 3) Migration 111 — agent registry + handoffs.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 111 (agents + handoffs) ──\n";
$mig111 = (string) file_get_contents('/app/core/migrations/111_agent_registry_and_handoffs.sql');
$a('111 file exists',                                       $mig111 !== '');
$a('CREATE agent_registry',                                 $c($mig111, 'CREATE TABLE IF NOT EXISTS agent_registry'));
$a('CREATE agent_handoffs',                                 $c($mig111, 'CREATE TABLE IF NOT EXISTS agent_handoffs'));
$a('agent_registry UNIQUE(tenant_id, agent_key)',           $c($mig111, 'UNIQUE KEY uq_ar_tenant_key (tenant_id, agent_key)'));
$a('agent_registry status enum',                            $c($mig111, "ENUM('draft','active','retired')"));
$a('agent_handoffs status enum (5 states)',
    $c($mig111, "ENUM('pending','accepted','refused','completed','cancelled')"));
$a('agent_handoffs links to parent_workflow_run_id',        $c($mig111, 'parent_workflow_run_id  CHAR(36) NULL'));
$a('agent_handoffs supports nested handoffs',               $c($mig111, 'parent_handoff_id       BIGINT UNSIGNED NULL'));

// ──────────────────────────────────────────────────────────────────────
// 4) core/ai/worker.php — public surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/worker.php ──\n";
$wk = (string) file_get_contents('/app/core/ai/worker.php');
foreach ([
    'aiWorkerRegister(string $workerKey,',
    'aiWorkerHeartbeat(int $workerId): bool',
    'aiWorkerList(): array',
    'aiWorkerSweepStalled(): int',
    'aiWorkerEnqueue(int $tenantId, string $toolName, array $payload, array $opts = []): array',
    'aiWorkerClaim(int $workerId, array $queues = [], int $limit = 1): array',
    'aiWorkerMarkRunning(int $jobId): void',
    'aiWorkerComplete(int $jobId, array $result, ?string $aiRunId = null): void',
    'aiWorkerFail(int $jobId, string $errorMessage, ?string $errorCode = null, bool $retryable = true): void',
    'aiWorkerCancel(int $tenantId, int $jobId, ?string $reason = null): bool',
    'aiWorkerRetry(int $tenantId, int $jobId): bool',
    'aiWorkerJobGet(int $tenantId, int $jobId): ?array',
    'aiWorkerJobList(?int $tenantId, array $filters = []): array',
    'aiWorkerQueueDepth(?int $tenantId = null): array',
] as $sig) {
    $a("worker.php declares $sig",                           $c($wk, 'function ' . $sig));
}
$a('claim path uses FOR UPDATE',                            $c($wk, 'FOR UPDATE'));
$a('claim path wraps in transaction',                       $c($wk, '$pdo->beginTransaction()'));
$a('fail() retries with exponential backoff',               $c($wk, 'AI_WORKER_BACKOFF_BASE * (1 << max(0, $attempt - 1))'));
$a('fail() marks dead at max_attempts',                     $c($wk, '"dead"') && $c($wk, '$attempt >= $maxAttempts'));
$a('enqueue idempotency: replay returns existing row',
    $c($wk, 'aiWorkerJobGetByIdempotencyKey($tenantId, $idempKey)'));
$a('stalled sweep flips workers w/o heartbeat',
    $c($wk, '"stalled"') && $c($wk, 'INTERVAL :sec SECOND'));
$lint1=[]; exec('php -l /app/core/ai/worker.php 2>&1', $lint1, $rc1);
$a('worker.php passes php -l',                              $rc1 === 0);

// ──────────────────────────────────────────────────────────────────────
// 5) core/ai/knowledge_graph.php — public surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/knowledge_graph.php ──\n";
$kg = (string) file_get_contents('/app/core/ai/knowledge_graph.php');
foreach ([
    'knowledgeNormalizeKey(string $s): string',
    'knowledgeDocumentUpsert(int $tenantId, array $opts): array',
    'knowledgeEntityUpsert(int $tenantId, string $entityType, string $label, array $opts = []): array',
    'knowledgeEdgeCreate(int $tenantId, int $fromEntityId, int $toEntityId, string $relation, array $opts = []): array',
    'knowledgeSearchFulltext(int $tenantId, string $query, int $limit = 20): array',
    'knowledgeEntityGet(int $tenantId, int $entityId): ?array',
    'knowledgeNeighbours(int $tenantId, int $entityId, int $limit = 50): array',
    'knowledgeEntityList(int $tenantId, array $filters = []): array',
] as $sig) {
    $a("knowledge_graph.php declares $sig",                  $c($kg, 'function ' . $sig));
}
$a('doc upsert is idempotent on (tenant, doc_uri)',
    $c($kg, "WHERE tenant_id = :t AND doc_uri = :u LIMIT 1"));
$a('entity upsert uses normalized_key',                     $c($kg, '$normKey = knowledgeNormalizeKey'));
$a('edge create uses ON DUPLICATE KEY UPDATE',              $c($kg, 'ON DUPLICATE KEY UPDATE'));
$a('FULLTEXT search uses NATURAL LANGUAGE MODE',            $c($kg, 'IN NATURAL LANGUAGE MODE'));
$a('FULLTEXT failure falls back to LIKE',                   $c($kg, '$stmt = getDB()->prepare(') && $c($kg, 'LIKE :q'));
$lint2=[]; exec('php -l /app/core/ai/knowledge_graph.php 2>&1', $lint2, $rc2);
$a('knowledge_graph.php passes php -l',                     $rc2 === 0);

// Pure-function probe — normalize survives jitter.
require_once '/app/core/ai/knowledge_graph.php';
$a('knowledgeNormalizeKey: "ACME Co." == "acme  co"',
    knowledgeNormalizeKey('ACME Co.') === knowledgeNormalizeKey('acme  co'));
$a('knowledgeNormalizeKey: trailing punct stripped',
    knowledgeNormalizeKey('ACME Co,') === 'acme co');

// ──────────────────────────────────────────────────────────────────────
// 6) core/ai/agents.php — public surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/agents.php ──\n";
$ag = (string) file_get_contents('/app/core/ai/agents.php');
foreach ([
    'agentRegistryUpsert(?int $tenantId, string $agentKey, array $opts = []): array',
    'agentRegistryGet(int $agentId): ?array',
    'agentRegistryGetByKey(?int $tenantId, string $agentKey): ?array',
    'agentRegistryList(?int $tenantId, array $filters = []): array',
    'agentHandoffCreate(int $tenantId, string $fromAgentKey, string $toAgentKey, array $opts = []): array',
    'agentHandoffResolve(int $tenantId, int $handoffId, string $newStatus, array $opts = []): array',
    'agentHandoffGet(int $tenantId, int $handoffId): ?array',
    'agentHandoffList(int $tenantId, array $filters = []): array',
] as $sig) {
    $a("agents.php declares $sig",                           $c($ag, 'function ' . $sig));
}
$a('AGENT_HANDOFF_STATUSES exposes 5 lifecycle states',
    $c($ag, "['pending','accepted','refused','completed','cancelled']"));
$a('agent_key validator enforces snake_case ascii',
    $c($ag, "/^[a-z][a-z0-9_]{1,118}$/"));
$a('handoff refuses self-handoffs',
    $c($ag, 'from_agent_key and to_agent_key cannot be equal'));
$a('handoff resolver refuses pending → pending',
    $c($ag, "cannot resolve to 'pending'"));
$a('tenant-specific override of platform agents',
    $c($ag, 'agentRegistryGetByKey($tenantId, $fromAgentKey) ?? agentRegistryGetByKey(null'));
$lint3=[]; exec('php -l /app/core/ai/agents.php 2>&1', $lint3, $rc3);
$a('agents.php passes php -l',                              $rc3 === 0);

// ──────────────────────────────────────────────────────────────────────
// 7) cron/ai_worker.php — CLI loop.
// ──────────────────────────────────────────────────────────────────────
echo "\n── cron/ai_worker.php ──\n";
$cli = (string) file_get_contents('/app/cron/ai_worker.php');
$a('CLI shebang',                                           $c($cli, '#!/usr/bin/env php'));
$a('declares strict_types',                                 $c($cli, 'declare(strict_types=1)'));
$a('requires worker + tool_gateway',                        $c($cli, "require_once __DIR__ . '/../core/ai/worker.php'") && $c($cli, "require_once __DIR__ . '/../core/ai/tool_gateway.php'"));
$a('--queue / --max-jobs / --label / --once getopt',
    $c($cli, "['queue::', 'max-jobs::', 'label::', 'once', 'verbose']"));
$a('registers worker key as host:pid',                      $c($cli, "%s:%d', gethostname() ?: 'unknown', getmypid()"));
$a('heartbeats on AI_WORKER_HEARTBEAT_SEC interval',        $c($cli, 'time() - $lastBeat >= AI_WORKER_HEARTBEAT_SEC'));
$a('claims via aiWorkerClaim($workerId, $queues, 1)',       $c($cli, 'aiWorkerClaim($workerId, $queues, 1)'));
$a('dispatches through aiToolInvoke',                       $c($cli, 'aiToolInvoke('));
$a('on success calls aiWorkerComplete',                     $c($cli, 'aiWorkerComplete('));
$a('on tool envelope error calls aiWorkerFail',             $c($cli, 'aiWorkerFail('));
$a('signal handlers for SIGINT/SIGTERM',                    $c($cli, 'pcntl_signal(SIGINT,')
    && $c($cli, 'pcntl_signal(SIGTERM,'));
$a('non-retryable error codes whitelist',
    $c($cli, "['bad_args','not_found','approval_required','approval_invalid','permission_denied']"));
$lint4=[]; exec('php -l /app/cron/ai_worker.php 2>&1', $lint4, $rc4);
$a('cron/ai_worker.php passes php -l',                      $rc4 === 0);

// ──────────────────────────────────────────────────────────────────────
// 8) Tool gateway — 4 new Phase-7 tools wired + handlers.
// ──────────────────────────────────────────────────────────────────────
echo "\n── tool_gateway.php Phase 7 tools ──\n";
$gw = (string) file_get_contents('/app/core/ai/tool_gateway.php');
foreach ([
    'coreflux.enqueue_job',
    'coreflux.search_knowledge',
    'coreflux.record_knowledge',
    'coreflux.handoff_to_agent',
] as $tool) {
    $a("$tool registered",                                   $c($gw, "'$tool'"));
}
foreach ([
    'aiToolEnqueueJobHandler',
    'aiToolSearchKnowledgeHandler',
    'aiToolRecordKnowledgeHandler',
    'aiToolHandoffToAgentHandler',
] as $handler) {
    $a("$handler implemented",                               $c($gw, "function $handler("));
}
$a('enqueue_job declares idempotency_args=[idempotency_key]',
    $c($gw, "'idempotency_args' => ['idempotency_key']"));
$a('record_knowledge declares idempotency_args=[doc_uri]',
    $c($gw, "'idempotency_args' => ['doc_uri']"));
$a('handoff handler maps RuntimeException → not_found',
    $c($gw, "'code' => 'not_found', 'message' => \$e->getMessage()"));

// ──────────────────────────────────────────────────────────────────────
// 9) APIs — workers / knowledge / agents.
// ──────────────────────────────────────────────────────────────────────
echo "\n── /api/ai/workers.php ──\n";
$apiW = (string) file_get_contents('/app/api/ai/workers.php');
foreach (['?action=workers', '?action=depth', '?action=retry', '?action=cancel'] as $hint) {
    $a("workers.php documents $hint",                        $c($apiW, $hint));
}
$a('list jobs via aiWorkerJobList',                         $c($apiW, 'aiWorkerJobList($tid'));
$a('depth via aiWorkerQueueDepth',                          $c($apiW, 'aiWorkerQueueDepth($tid)'));
$a('retry route uses aiWorkerRetry',                        $c($apiW, 'aiWorkerRetry($tid, $id)'));
$a('writes gated on ai.gateway.invoke / accounting.approve',$c($apiW, "rbac_legacy_can(\$user, 'ai.gateway.invoke')") && $c($apiW, "rbac_legacy_can(\$user, 'accounting.approve')"));
$lint5=[]; exec('php -l /app/api/ai/workers.php 2>&1', $lint5, $rc5);
$a('workers.php passes php -l',                             $rc5 === 0);

echo "\n── /api/ai/knowledge.php ──\n";
$apiK = (string) file_get_contents('/app/api/ai/knowledge.php');
foreach (['?action=search', '?action=entity', '?action=entities', '?action=record', '?action=entity_upsert', '?action=edge_create'] as $hint) {
    $a("knowledge.php documents $hint",                      $c($apiK, $hint));
}
$a('search reads q from query string',                      $c($apiK, "(string) (\$_GET['q'] ?? '')"));
$a('reads gated on ai.knowledge.read or fallback',          $c($apiK, "ai.knowledge.read"));
$a('writes gated on ai.knowledge.write or fallback',        $c($apiK, "ai.knowledge.write"));
$lint6=[]; exec('php -l /app/api/ai/knowledge.php 2>&1', $lint6, $rc6);
$a('knowledge.php passes php -l',                           $rc6 === 0);

echo "\n── /api/ai/agents.php ──\n";
$apiA = (string) file_get_contents('/app/api/ai/agents.php');
foreach (['?action=handoffs', '?action=handoff_detail', '?action=upsert', '?action=handoff', '?action=resolve'] as $hint) {
    $a("agents.php documents $hint",                         $c($apiA, $hint));
}
$a('upsert via agentRegistryUpsert',                        $c($apiA, 'agentRegistryUpsert($tid'));
$a('handoff via agentHandoffCreate',                        $c($apiA, 'agentHandoffCreate($tid'));
$a('resolve via agentHandoffResolve',                       $c($apiA, 'agentHandoffResolve($tid'));
$lint7=[]; exec('php -l /app/api/ai/agents.php 2>&1', $lint7, $rc7);
$a('agents.php passes php -l',                              $rc7 === 0);

// ──────────────────────────────────────────────────────────────────────
// 10) AiWorkersAdmin.jsx surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AiWorkersAdmin.jsx ──\n";
$wui = (string) file_get_contents('/app/dashboard/src/pages/AiWorkersAdmin.jsx');
$a('default export AiWorkersAdmin',                         $c($wui, 'export default function AiWorkersAdmin()'));
$a('parallel load of workers + depth + jobs',               $c($wui, 'Promise.all(['));
$a('depth strip covers all 7 statuses',
    $c($wui, "'queued',")
    && $c($wui, "'claimed',")
    && $c($wui, "'running',")
    && $c($wui, "'succeeded',")
    && $c($wui, "'failed',")
    && $c($wui, "'dead',")
    && $c($wui, "'cancelled',"));
foreach ([
    'ai-workers-page','ai-workers-title','ai-workers-depth',
    'ai-workers-list-loading','ai-workers-list-empty','ai-workers-list',
    'ai-workers-jobs','ai-workers-jobs-filter-status','ai-workers-jobs-empty',
] as $tid) {
    $a("testid '$tid' present",                              $c($wui, "data-testid=\"$tid\""));
}
$a("template testid 'ai-worker-row-\${w.id}' present",       $c($wui, 'ai-worker-row-${w.id}'));
$a("template testid 'ai-workers-job-row-\${j.id}' present",  $c($wui, 'ai-workers-job-row-${j.id}'));
$a("template testid 'ai-workers-job-\${j.id}-retry' present",$c($wui, 'ai-workers-job-${j.id}-retry'));
$a("template testid 'ai-workers-depth-\${k}' present",       $c($wui, 'ai-workers-depth-${k}'));

// ──────────────────────────────────────────────────────────────────────
// 11) KnowledgeGraphExplorer.jsx surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── KnowledgeGraphExplorer.jsx ──\n";
$kui = (string) file_get_contents('/app/dashboard/src/pages/KnowledgeGraphExplorer.jsx');
$a('default export KnowledgeGraphExplorer',                  $c($kui, 'export default function KnowledgeGraphExplorer()'));
$a('two tabs (search + entities)',
    $c($kui, "['search', 'entities']") && $c($kui, '`knowledge-tab-${t}`'));
$a('search calls /api/ai/knowledge.php?action=search',       $c($kui, '?action=search&q='));
$a('entities tab loads via ?action=entities',                $c($kui, '?action=entities'));
$a('entity drill via ?action=entity&id=',                    $c($kui, '?action=entity&id='));
foreach ([
    'knowledge-page','knowledge-title','knowledge-search-input','knowledge-search-run',
    'knowledge-search-empty','knowledge-search-results',
    'knowledge-entities-panel','knowledge-entities-type-input','knowledge-entities-list',
    'knowledge-entity-detail-label',
] as $tid) {
    $a("testid '$tid' present",                              $c($kui, "data-testid=\"$tid\""));
}
$a("template testid 'knowledge-entity-row-\${e.id}' present",  $c($kui, 'knowledge-entity-row-${e.id}'));
$a("template testid 'knowledge-search-result-\${r.id}' present",$c($kui, 'knowledge-search-result-${r.id}'));

// ──────────────────────────────────────────────────────────────────────
// 12) AgentRegistryAdmin.jsx surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AgentRegistryAdmin.jsx ──\n";
$aui = (string) file_get_contents('/app/dashboard/src/pages/AgentRegistryAdmin.jsx');
$a('default export AgentRegistryAdmin',                      $c($aui, 'export default function AgentRegistryAdmin()'));
$a('handoff resolve flows via /api/ai/agents.php?action=resolve',
    $c($aui, '/api/ai/agents.php?action=resolve'));
$a('Accept / Refuse / Complete actions present',
    $c($aui, "resolve(h.id, 'accepted')")
    && $c($aui, "resolve(h.id, 'refused')")
    && $c($aui, "resolve(h.id, 'completed')"));
$a('refused requires a non-empty reason prompt',
    $c($aui, "if (status === 'refused' && (note === null || note.trim() === ''))"));
foreach ([
    'agents-page','agents-title','agents-list-loading','agents-list-empty','agents-list',
    'agents-handoff-filter','agents-handoffs-empty','agents-handoffs',
] as $tid) {
    $a("testid '$tid' present",                              $c($aui, "data-testid=\"$tid\""));
}
$a("template testid 'agents-row-\${a.id}' present",          $c($aui, 'agents-row-${a.id}'));
$a("template testid 'agents-handoff-row-\${h.id}' present",  $c($aui, 'agents-handoff-row-${h.id}'));
$a("template testid 'agents-handoff-\${h.id}-accept' present",$c($aui, 'agents-handoff-${h.id}-accept'));

// ──────────────────────────────────────────────────────────────────────
// 13) AdminModule routing wire-in for all 3 Phase 7 pages.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AdminModule.jsx routing ──\n";
$adm = (string) file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
$a('imports AiWorkersAdmin',                                 $c($adm, "import AiWorkersAdmin from './AiWorkersAdmin'"));
$a('imports KnowledgeGraphExplorer',                         $c($adm, "import KnowledgeGraphExplorer from './KnowledgeGraphExplorer'"));
$a('imports AgentRegistryAdmin',                             $c($adm, "import AgentRegistryAdmin from './AgentRegistryAdmin'"));
$a('routes /admin/ai/workers',                               $c($adm, 'path="/ai/workers"'));
$a('routes /admin/ai/knowledge',                             $c($adm, 'path="/ai/knowledge"'));
$a('routes /admin/ai/agents',                                $c($adm, 'path="/ai/agents"'));
$a('Cpu icon for workers tile',                              $c($adm, 'Cpu'));
$a('BookMarked icon for knowledge tile',                     $c($adm, 'BookMarked'));
$a('Network icon for agents tile',                           $c($adm, 'Network'));
$a('sidebar nav: workers + knowledge + agents',
    $c($adm, "to: '/admin/ai/workers'")
    && $c($adm, "to: '/admin/ai/knowledge'")
    && $c($adm, "to: '/admin/ai/agents'"));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Phase 7 smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
