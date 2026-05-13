<?php
/**
 * AI Interpretation Records helper (Phase 1b — 2026-02-14).
 *
 * One row per interpretation (AI-proposed OR rule-derived) of an
 * accounting_events row. Persistent. Queryable. The historical record of
 * "why did the system propose THIS journal entry for that event?"
 *
 * Public API:
 *   aiInterpretationRecord(tenantId, eventId, args)
 *     → INSERT row. args = {
 *         proposed_by:           'ai:bookkeeper-v1' | 'posting_rule:<id>' | 'human:<uid>',
 *         model?:                'gpt-4o' etc,
 *         confidence:            0.000 — 1.000,
 *         proposed_je_lines:     [{account_code, debit, credit, memo, dims}],
 *         reasoning?:            string,
 *         evidence?:             [{type,id,label,hash}],
 *         status?:               'proposed'|'accepted',
 *         requires_review?:      bool,
 *         journal_entry_id?:     bigint    (set when status=accepted and JE is posted),
 *         typical_accounting_hint?: snapshot from event_registry,
 *       }
 *     Returns ['id' => int].
 *
 *   aiInterpretationLatestForEvent(tenantId, eventId)
 *     → latest row by proposed_at desc, with status filter aware
 *
 *   aiInterpretationAccept(tenantId, interpretationId, reviewerUserId, journalEntryId, ?note)
 *   aiInterpretationOverride(tenantId, interpretationId, reviewerUserId, correctedJeId, note)
 *   aiInterpretationReject(tenantId, interpretationId, reviewerUserId, reason)
 *
 *   aiInterpretationListPendingReview(tenantId, limit = 100)
 *     → exception queue feed.
 *
 * Backwards-compat: every function returns gracefully (or no-ops) when the
 * accounting_ai_interpretations table is missing. Module emit sites are
 * therefore safe to call these even on tenants that haven't run migration
 * 037 yet.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function _aiInterpretationTableExists(?\PDO $pdo = null): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = $pdo ?: getDB();
    try {
        $pdo->query('SELECT 1 FROM accounting_ai_interpretations LIMIT 0');
        return $cache = true;
    } catch (\Throwable $_) {
        return $cache = false;
    }
}

function aiInterpretationRecord(int $tenantId, int $eventId, array $args): array {
    if (!_aiInterpretationTableExists()) return ['id' => 0, 'skipped' => 'table_missing'];

    $proposedBy = trim((string) ($args['proposed_by'] ?? ''));
    if ($proposedBy === '') throw new \InvalidArgumentException('proposed_by required');
    $lines = $args['proposed_je_lines'] ?? null;
    if (!is_array($lines)) throw new \InvalidArgumentException('proposed_je_lines must be an array');

    $confidence = (float) ($args['confidence'] ?? 1.0);
    if ($confidence < 0.0) $confidence = 0.0;
    if ($confidence > 1.0) $confidence = 1.0;

    $status = $args['status'] ?? 'proposed';
    if (!in_array($status, ['proposed','accepted','overridden','rejected','superseded'], true)) {
        $status = 'proposed';
    }

    $pdo = getDB();
    $pdo->prepare(
        "INSERT INTO accounting_ai_interpretations
            (tenant_id, event_id, proposed_by, model, confidence,
             proposed_je_json, reasoning, evidence_json,
             typical_accounting_hint, status, requires_review, journal_entry_id)
         VALUES (:t, :e, :pb, :m, :c, :pj, :r, :ev, :hint, :st, :rr, :je)"
    )->execute([
        't'   => $tenantId,
        'e'   => $eventId,
        'pb'  => $proposedBy,
        'm'   => $args['model'] ?? null,
        'c'   => $confidence,
        'pj'  => json_encode(['lines' => $lines, 'je_number' => $args['je_number'] ?? null]),
        'r'   => $args['reasoning'] ?? null,
        'ev'  => isset($args['evidence']) ? json_encode($args['evidence']) : null,
        'hint'=> $args['typical_accounting_hint'] ?? null,
        'st'  => $status,
        'rr'  => (int) (bool) ($args['requires_review'] ?? ($confidence < 0.75)),
        'je'  => isset($args['journal_entry_id']) ? (int) $args['journal_entry_id'] : null,
    ]);
    return ['id' => (int) $pdo->lastInsertId()];
}

function aiInterpretationLatestForEvent(int $tenantId, int $eventId): ?array {
    if (!_aiInterpretationTableExists()) return null;
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT *
           FROM accounting_ai_interpretations
          WHERE tenant_id = :t AND event_id = :e
          ORDER BY proposed_at DESC, id DESC
          LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId, 'e' => $eventId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['proposed_je'] = json_decode((string) $row['proposed_je_json'], true) ?: [];
    $row['evidence']    = json_decode((string) ($row['evidence_json'] ?? '[]'), true) ?: [];
    return $row;
}

function aiInterpretationAccept(int $tenantId, int $interpretationId, int $reviewerUserId, int $journalEntryId, ?string $note = null): bool {
    if (!_aiInterpretationTableExists()) return false;
    $pdo = getDB();

    // Supersede prior accepted rows for this event so only one is current.
    $row = $pdo->prepare(
        "SELECT event_id FROM accounting_ai_interpretations
          WHERE id = :id AND tenant_id = :t LIMIT 1"
    );
    $row->execute(['id' => $interpretationId, 't' => $tenantId]);
    $eventId = (int) ($row->fetchColumn() ?: 0);
    if ($eventId) {
        $pdo->prepare(
            "UPDATE accounting_ai_interpretations
                SET status = 'superseded'
              WHERE tenant_id = :t AND event_id = :e
                AND id != :id AND status = 'accepted'"
        )->execute(['t' => $tenantId, 'e' => $eventId, 'id' => $interpretationId]);
    }

    $stmt = $pdo->prepare(
        "UPDATE accounting_ai_interpretations
            SET status = 'accepted',
                reviewer_user_id = :u,
                reviewed_at = NOW(),
                review_disposition = :d,
                journal_entry_id = :je
          WHERE id = :id AND tenant_id = :t"
    );
    $stmt->execute([
        'id' => $interpretationId, 't' => $tenantId,
        'u'  => $reviewerUserId, 'd' => $note ?: 'accepted',
        'je' => $journalEntryId,
    ]);
    return $stmt->rowCount() > 0;
}

function aiInterpretationOverride(int $tenantId, int $interpretationId, int $reviewerUserId, int $correctedJeId, string $note): bool {
    if (!_aiInterpretationTableExists()) return false;
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "UPDATE accounting_ai_interpretations
            SET status = 'overridden',
                reviewer_user_id = :u,
                reviewed_at = NOW(),
                review_disposition = :d,
                journal_entry_id = :je
          WHERE id = :id AND tenant_id = :t"
    );
    $stmt->execute([
        'id' => $interpretationId, 't' => $tenantId,
        'u'  => $reviewerUserId, 'd' => $note,
        'je' => $correctedJeId,
    ]);
    return $stmt->rowCount() > 0;
}

function aiInterpretationReject(int $tenantId, int $interpretationId, int $reviewerUserId, string $reason): bool {
    if (!_aiInterpretationTableExists()) return false;
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "UPDATE accounting_ai_interpretations
            SET status = 'rejected',
                reviewer_user_id = :u,
                reviewed_at = NOW(),
                review_disposition = :r
          WHERE id = :id AND tenant_id = :t"
    );
    $stmt->execute(['id' => $interpretationId, 't' => $tenantId, 'u' => $reviewerUserId, 'r' => $reason]);
    return $stmt->rowCount() > 0;
}

function aiInterpretationListPendingReview(int $tenantId, int $limit = 100): array {
    if (!_aiInterpretationTableExists()) return [];
    $pdo  = getDB();
    $stmt = $pdo->prepare(
        "SELECT aai.*, ae.event_type, ae.source_module, ae.source_record_id,
                ae.event_date, ae.payload AS event_payload
           FROM accounting_ai_interpretations aai
           JOIN accounting_events ae
             ON ae.id = aai.event_id AND ae.tenant_id = aai.tenant_id
          WHERE aai.tenant_id = :t
            AND aai.status = 'proposed'
            AND aai.requires_review = 1
          ORDER BY aai.proposed_at DESC
          LIMIT " . (int) $limit
    );
    $stmt->execute(['t' => $tenantId]);
    $out = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
        $row['proposed_je']   = json_decode((string) $row['proposed_je_json'], true) ?: [];
        $row['evidence']      = json_decode((string) ($row['evidence_json'] ?? '[]'), true) ?: [];
        $row['event_payload'] = json_decode((string) ($row['event_payload'] ?? '{}'), true) ?: [];
        $out[] = $row;
    }
    return $out;
}
