<?php
/**
 * /api/accounting/je_trace — Trace a posted journal entry back to its
 * originating business event, walk the lineage tree upward, and surface
 * every AI interpretation row along the way.
 *
 *   GET ?je_id=N
 *
 *   Response:
 *     {
 *       je:               { id, je_number, posting_date, total_debit, total_credit, status, source_module },
 *       source_event:     accounting_events row that produced this JE (via subledger_links),
 *       ancestors:        [event row + depth] back to the originating root,
 *       interpretations:  { [event_id]: [interpretation rows ordered newest-first] },
 *     }
 *
 * The pane this powers — `JeTracePane.jsx` — gives a CPA a one-click answer
 * to "why was this amount booked to that account?".
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/event_lineage.php';
require_once __DIR__ . '/../../core/ai_interpretation.php';

$ctx      = api_require_auth();
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$jeId = (int) api_query('je_id', 0);
if (!$jeId) api_error('je_id required', 422);

$pdo = getDB();

// 1) JE header.
$jeStmt = $pdo->prepare(
    "SELECT id, je_number, posting_date, total_debit, total_credit, status,
            source_module, source_ref_id, memo
       FROM journal_entries
      WHERE tenant_id = :t AND id = :id LIMIT 1"
);
$jeStmt->execute(['t' => $tenantId, 'id' => $jeId]);
$je = $jeStmt->fetch(PDO::FETCH_ASSOC);
if (!$je) api_error('Journal entry not found', 404);

// 2) Source event via subledger link. There may be more than one link if
// multiple events posted into the same JE (rare); take the primary.
$srcEvent = null;
try {
    $stmt = $pdo->prepare(
        "SELECT ae.*
           FROM accounting_subledger_links sl
           JOIN accounting_events ae ON ae.id = sl.accounting_event_id AND ae.tenant_id = sl.tenant_id
          WHERE sl.tenant_id = :t AND sl.journal_entry_id = :je
          ORDER BY (sl.link_kind = 'primary') DESC, sl.id ASC
          LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'je' => $jeId]);
    $srcEvent = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $_) { /* link table optional */ }

// 3) Walk lineage up + down from the source event.
$ancestors   = [];
$descendants = [];
if ($srcEvent) {
    $ancestors   = eventLineageGetAncestors($tenantId, (int) $srcEvent['id'], 32);
    $descendants = eventLineageGetDescendants($tenantId, (int) $srcEvent['id'], 32);
}

// 4) Gather every event id we touched + fetch their interpretations.
$eventIds = [];
if ($srcEvent) $eventIds[] = (int) $srcEvent['id'];
foreach ($ancestors as $a)   $eventIds[] = (int) $a['related_event_id'];
foreach ($descendants as $d) $eventIds[] = (int) $d['related_event_id'];
$eventIds = array_values(array_unique($eventIds));

$interpretations = [];
if ($eventIds) {
    try {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $params       = array_merge([$tenantId], $eventIds);
        $stmt = $pdo->prepare(
            "SELECT id, event_id, proposed_at, proposed_by, model, confidence,
                    proposed_je_json, reasoning, evidence_json,
                    typical_accounting_hint, status, requires_review,
                    reviewer_user_id, reviewed_at, review_disposition,
                    journal_entry_id
               FROM accounting_ai_interpretations
              WHERE tenant_id = ? AND event_id IN ({$placeholders})
              ORDER BY event_id ASC, proposed_at DESC, id DESC"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $eid = (int) $r['event_id'];
            $r['proposed_je'] = json_decode((string) $r['proposed_je_json'], true) ?: [];
            $r['evidence']    = json_decode((string) ($r['evidence_json'] ?? '[]'), true) ?: [];
            $interpretations[$eid][] = $r;
        }
    } catch (\Throwable $_) { /* interpretations table optional */ }
}

// 5) Decode source_event payload for display.
if ($srcEvent && isset($srcEvent['payload'])) {
    $srcEvent['payload'] = json_decode((string) $srcEvent['payload'], true) ?: [];
}

api_ok([
    'je'              => $je,
    'source_event'    => $srcEvent,
    'ancestors'       => $ancestors,
    'descendants'     => $descendants,
    'interpretations' => $interpretations,
]);
