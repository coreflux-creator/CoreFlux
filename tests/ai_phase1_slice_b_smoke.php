<?php
/**
 * Smoke — Slice B: LangGraph MVP vendor-alias + exception queue UX (2026-02).
 *
 * Locks the Phase 2 finish work from the AI-Native Extension spec:
 *   - Migration 106 — vendor_aliases (alias → canonical vendor / label,
 *     pinned flag, hit counter, AI provenance).
 *   - core/ai/vendor_aliases.php — normalize / resolve / record / list.
 *   - core/ai/tool_gateway.php registers
 *       coreflux.resolve_vendor_alias (read)
 *       coreflux.record_vendor_alias  (draft)
 *     plus their handlers.
 *   - /api/ai/exceptions.php — list / detail / resolve / dismiss / assign.
 *   - dashboard AccountingExceptionQueue.jsx mounted at /admin/ai/exceptions.
 *   - dashboard TransactionRecommendationCard.jsx wired into
 *     TransactionsToReview.jsx with vendor-alias enrichment.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 106 — vendor_aliases table.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 106 ──\n";
$mig = (string) file_get_contents('/app/core/migrations/106_vendor_aliases.sql');
$a('migration file exists',                                      $mig !== '');
$a('CREATE TABLE IF NOT EXISTS vendor_aliases',                  $c($mig, 'CREATE TABLE IF NOT EXISTS vendor_aliases'));
$a('vendor_aliases keyed by tenant + normalized alias',          $c($mig, 'UNIQUE KEY uq_va_tenant_alias (tenant_id, alias_normalized)'));
$a('vendor_aliases carries canonical_vendor_id OR canonical_label',
    $c($mig, 'canonical_vendor_id  INT UNSIGNED NULL') && $c($mig, 'canonical_label      VARCHAR(180) NULL'));
$a('vendor_aliases tracks AI provenance',                        $c($mig, "ENUM('ai_suggestion','manual','imported')"));
$a('vendor_aliases supports operator pinning',                   $c($mig, 'pinned               TINYINT(1) NOT NULL DEFAULT 0'));
$a('vendor_aliases tracks hits + last_hit_at for queue UI sort', $c($mig, 'hits                 INT UNSIGNED NOT NULL DEFAULT 0'));
$a('vendor_aliases carries confidence numeric',                  $c($mig, 'confidence           DECIMAL(4,3) NULL'));
$a('vendor_aliases links the AI run id that proposed it',        $c($mig, 'created_by_ai_run    CHAR(36) NULL'));

// ──────────────────────────────────────────────────────────────────────
// 2) core/ai/vendor_aliases.php — library surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/ai/vendor_aliases.php ──\n";
$lib = (string) file_get_contents('/app/core/ai/vendor_aliases.php');
$a('declares strict_types',                                      $c($lib, 'declare(strict_types=1)'));
$a('function vendorAliasNormalize(string)',                      $c($lib, 'function vendorAliasNormalize(string $payee): string'));
$a('function vendorAliasResolve(int, string)',                   $c($lib, 'function vendorAliasResolve(int $tenantId, string $payee): ?array'));
$a('function vendorAliasRecord(int, string, array)',             $c($lib, 'function vendorAliasRecord(int $tenantId, string $payee, array $opts = []): array'));
$a('function vendorAliasList for the queue UI',                  $c($lib, 'function vendorAliasList(int $tenantId, array $filters = []): array'));
$a('normalize uppercases + collapses whitespace',
    $c($lib, 'strtoupper(trim($payee))') && $c($lib, "preg_replace('/\\s+/u', ' ', "));
$a('normalize strips trailing punctuation',                      $c($lib, "rtrim(\$s, \".,;:!? "));
$a('resolve bumps hit counter side-effect',                      $c($lib, 'hits = hits + 1, last_hit_at = NOW()'));
$a('record enforces exactly one of (vendor_id, label)',          $c($lib, 'Provide EXACTLY one of (canonical_vendor_id) or (canonical_label)'));
$a('record refuses to silently override pinned rows on ai_suggestion',
    $c($lib, "pinned_skip"));
$a('record validates source enum',                               $c($lib, "['ai_suggestion', 'manual', 'imported']"));

// Pure-function probe: normalize collapses obvious variants.
require_once '/app/core/ai/vendor_aliases.php';
$a('normalize: "ACME Co." == "acme  co"',                        vendorAliasNormalize('ACME Co.') === vendorAliasNormalize('acme  co'));
$a('normalize: trailing comma stripped',                         vendorAliasNormalize('ACME Co,') === 'ACME CO');
$a('normalize: empty input yields empty string',                 vendorAliasNormalize('   ') === '');

// ──────────────────────────────────────────────────────────────────────
// 3) Tool registry — 2 new tools wired + handlers present.
// ──────────────────────────────────────────────────────────────────────
echo "\n── tool_gateway.php registry ──\n";
$gw = (string) file_get_contents('/app/core/ai/tool_gateway.php');
$a('coreflux.resolve_vendor_alias registered',                   $c($gw, "'coreflux.resolve_vendor_alias'"));
$a('coreflux.record_vendor_alias registered',                    $c($gw, "'coreflux.record_vendor_alias'"));
$a('resolve_vendor_alias is read-tier risk_level',               $c($gw, "'risk_level'  => 'read'"));
$a('record_vendor_alias is draft-tier risk_level',               $c($gw, "'risk_level'  => 'draft'"));
$a('record_vendor_alias declares idempotency_args=[payee]',      $c($gw, "'idempotency_args' => ['payee']"));
$a('aiToolResolveVendorAliasHandler implemented',                $c($gw, 'function aiToolResolveVendorAliasHandler('));
$a('aiToolRecordVendorAliasHandler implemented',                 $c($gw, 'function aiToolRecordVendorAliasHandler('));
$a('handlers require vendor_aliases.php',                        $c($gw, "require_once __DIR__ . '/vendor_aliases.php'"));
$a('record handler maps InvalidArgumentException to bad_args',   $c($gw, "'code' => 'bad_args'"));
$a('resolve handler returns {alias, matched, normalized}',
    $c($gw, "'matched'    => \$row !== null"));

// aiToolRegistrySync risk-level inference — record_vendor_alias must
// be classified draft, not transactional, in the persisted catalog.
$a('aiToolInferRiskLevel respects explicit risk_level on tool entry',
    $c($gw, "if (!empty(\$tool['risk_level']) && is_string(\$tool['risk_level']))"));

// ──────────────────────────────────────────────────────────────────────
// 4) /api/ai/exceptions.php — full reviewer surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── /api/ai/exceptions.php ──\n";
$api = (string) file_get_contents('/app/api/ai/exceptions.php');
$a('endpoint declares strict_types',                             $c($api, 'declare(strict_types=1)'));
$a('GET defaults to open status',                                $c($api, "(string) (\$_GET['status'] ?? 'open')"));
$a('GET status whitelist enforced',                              $c($api, "['open','assigned','resolved','dismissed','all']"));
$a('list query is tenant-scoped',                                $c($api, 'WHERE tenant_id = :t'));
$a('list ORDER BY severity-first',                               $c($api, "FIELD(severity, 'critical','high','medium','low')"));
$a('action=detail returns single exception',                     $c($api, "GET' && \$action === 'detail'"));
$a('action=resolve updates status + writes audit event',
    $c($api, "in_array(\$action, ['resolve','dismiss']")
    && $c($api, 'ai_exception_'));
$a('action=assign gated on accounting.approve RBAC',
    $c($api, "rbac_legacy_can(\$user, 'accounting.approve')"));
$a('list+resolve+dismiss gated on ai.audit.view OR accounting.review',
    $c($api, "rbac_legacy_can(\$user, 'ai.audit.view')")
    && $c($api, "rbac_legacy_can(\$user, 'accounting.review')"));
$a('mutations write spec-§15 audit events',                      $c($api, 'aiGatewayAuditEvent('));
$a('detail decodes detail_json into structured payload',         $c($api, "json_decode((string) \$row['detail_json'], true)"));

// ──────────────────────────────────────────────────────────────────────
// 5) AccountingExceptionQueue.jsx — UI surface + testids.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AccountingExceptionQueue.jsx ──\n";
$queue = (string) file_get_contents('/app/dashboard/src/pages/AccountingExceptionQueue.jsx');
$a('file exists',                                                $queue !== '');
$a('default export AccountingExceptionQueue',                    $c($queue, 'export default function AccountingExceptionQueue()'));
$a('reads /api/ai/exceptions.php list',                          $c($queue, "/api/ai/exceptions.php?status="));
$a('reads /api/ai/exceptions.php detail',                        $c($queue, "?action=detail&id="));
$a('POSTs resolve/dismiss to the same endpoint',                 $c($queue, "/api/ai/exceptions.php?action="));
$a('two-column grid layout',                                     $c($queue, "gridTemplateColumns: 'minmax(320px, 1fr) 2fr'"));
$a('filter bar surfaces severity / type / status',
    $c($queue, "exception-queue-filter-status")
    && $c($queue, "exception-queue-filter-severity")
    && $c($queue, "exception-queue-filter-type"));
$a('action surface includes resolve / dismiss',
    $c($queue, "exception-queue-detail-resolve")
    && $c($queue, "exception-queue-detail-dismiss"));
$a('client-side filter computes by severity + type',
    $c($queue, "filters.severity") && $c($queue, "filters.type"));
$a('SeverityChip + StatusChip subcomponents present',
    $c($queue, 'function SeverityChip(') && $c($queue, 'function StatusChip('));

// Testid surface required for fork-agent automation.
foreach ([
    'exception-queue-page',
    'exception-queue-title',
    'exception-queue-filter-status',
    'exception-queue-filter-severity',
    'exception-queue-filter-type',
    'exception-queue-filter-clear',
    'exception-queue-count',
    'exception-queue-list-loading',
    'exception-queue-list-empty',
    'exception-queue-list',
    'exception-queue-detail-placeholder',
    'exception-queue-detail-loading',
    'exception-queue-detail-empty',
    'exception-queue-detail',
    'exception-queue-detail-summary',
    'exception-queue-detail-payload',
    'exception-queue-detail-note',
    'exception-queue-detail-resolve',
    'exception-queue-detail-dismiss',
    'exception-queue-detail-readonly',
] as $tid) {
    $a("testid '$tid' present", $c($queue, "data-testid=\"$tid\""));
}

// Template testids — interpolated, so search for the literal template.
$a("template testid 'exception-queue-row-\${r.id}' present",     $c($queue, 'exception-queue-row-${r.id}'));
$a("template testid 'exception-severity-\${severity}' present",  $c($queue, 'exception-severity-${severity}'));
$a("template testid 'exception-status-\${status}' present",      $c($queue, 'exception-status-${status}'));

// ──────────────────────────────────────────────────────────────────────
// 6) TransactionRecommendationCard.jsx — drop-in card surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── TransactionRecommendationCard.jsx ──\n";
$card = (string) file_get_contents('/app/dashboard/src/components/TransactionRecommendationCard.jsx');
$a('file exists',                                                $card !== '');
$a('default export TransactionRecommendationCard',               $c($card, 'export default function TransactionRecommendationCard('));
$a('accepts {recommendation, transactionId, onAccept, onEdit, onReject}',
    $c($card, 'recommendation') && $c($card, 'transactionId')
    && $c($card, 'onAccept') && $c($card, 'onReject'));
$a('renders confidence pill colour-coded by tier',
    $c($card, "confidence >= 0.85 ? '#16a34a'"));
$a('pin-alias button POSTs to record_vendor_alias tool',
    $c($card, "tool: 'coreflux.record_vendor_alias'")
    && $c($card, "pinned:              true"));
$a('pin-alias passes the normalized payee',                      $c($card, "payee:               recommendation.payee_normalized"));
$a('explain toggle exposes AI reasoning',
    $c($card, 'explain-toggle') && $c($card, 'recommendation.reasoning'));
$a("returns null when no recommendation",                        $c($card, 'if (!recommendation) return null'));

// Card testid surface.
foreach ([
    'txn-recommendation-${transactionId}',
    'txn-recommendation-${transactionId}-confidence',
    'txn-recommendation-${transactionId}-vendor',
    'txn-recommendation-${transactionId}-account',
    'txn-recommendation-${transactionId}-explain-toggle',
    'txn-recommendation-${transactionId}-reasoning',
    'txn-recommendation-${transactionId}-accept',
    'txn-recommendation-${transactionId}-edit',
    'txn-recommendation-${transactionId}-reject',
    'txn-recommendation-${transactionId}-pin-alias',
    'txn-recommendation-${transactionId}-error',
] as $tid) {
    // Card uses template literals: data-testid={`txn-recommendation-${id}-…`}
    $a("card testid '$tid' present", $c($card, "`$tid`"));
}

// ──────────────────────────────────────────────────────────────────────
// 7) TransactionsToReview.jsx — wire-in of the recommendation card.
// ──────────────────────────────────────────────────────────────────────
echo "\n── TransactionsToReview.jsx wire-in ──\n";
$ttr = (string) file_get_contents('/app/dashboard/src/pages/TransactionsToReview.jsx');
$a('imports TransactionRecommendationCard',                      $c($ttr, "import TransactionRecommendationCard from '../components/TransactionRecommendationCard'"));
$a('tracks aliasByLine state',                                   $c($ttr, 'aliasByLine'));
$a('fires resolve_vendor_alias in parallel after AI suggest',    $c($ttr, "tool: 'coreflux.resolve_vendor_alias'"));
$a('passes normalized + canonical_vendor into the card',
    $c($ttr, 'payee_normalized:') && $c($ttr, 'canonical_vendor:'));
$a('card receives onAccept + onReject from existing handlers',
    $c($ttr, 'onAccept={async () =>') && $c($ttr, 'onReject={async () =>'));
$a('proposed_account block passes code + name + type',
    $c($ttr, "proposed_account:") && $c($ttr, 'code: ai.account_code'));

// ──────────────────────────────────────────────────────────────────────
// 8) AdminModule routing — Exception Queue reachable from sidebar + tile.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AdminModule.jsx routing ──\n";
$adm = (string) file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports AccountingExceptionQueue',               $c($adm, "import AccountingExceptionQueue from './AccountingExceptionQueue'"));
$a('AdminModule routes /admin/ai/exceptions',                    $c($adm, 'path="/ai/exceptions"'));
$a('Exception queue surfaced in sidebar nav',                    $c($adm, "to: '/admin/ai/exceptions'"));
$a('Exception queue surfaced as AdminOverview tile',             $c($adm, 'href="/admin/ai/exceptions"'));
$a('Tile uses AlertTriangle icon',                               $c($adm, 'AlertTriangle'));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Slice B smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
