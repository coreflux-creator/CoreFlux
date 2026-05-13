<?php
/**
 * Evidence Attachments helper (Phase 1e — 2026-02-14).
 *
 * Single canonical pivot replacing ad-hoc per-module attachment tables.
 * Every supporting document, OCR blob, AI reasoning trail, signed
 * contract, bank-statement scan attaches through THIS function.
 *
 * Public API:
 *   evidenceAttach($tenantId, $args)
 *     args = {
 *       subject_type   (required: 'accounting_event'|'ap_bill'|'billing_invoice'|'journal_entry'|'person'|...),
 *       subject_id     (required: bigint),
 *       document_type  (required: 'bill_image'|'signed_contract'|'bank_statement'|'w9'|...),
 *       label?         human-readable name,
 *       storage_key?   bucket key when bytes live in object storage,
 *       storage_bucket?,
 *       content_type?,
 *       size_bytes?,
 *       sha256_hash?,  content-addressable dedupe key,
 *       payload?       structured non-file evidence (OCR / AI reasoning / parsed rows),
 *       source?        'manual_upload'|'email_inbound'|'plaid_sync'|'ai_generated'|...,
 *       attached_by_user_id?
 *     }
 *     Returns ['id' => int, 'duplicate_of' => ?int].
 *
 *     If `sha256_hash` is supplied and we already have a non-deleted
 *     attachment for the same (tenant, subject_type, subject_id, hash),
 *     returns the existing row's id and `duplicate_of` flag — no new row.
 *
 *   evidenceListFor($tenantId, $subjectType, $subjectId, $includeDeleted = false)
 *     Returns attachments for a subject ordered by attached_at DESC.
 *
 *   evidenceListForEvents($tenantId, $eventIds)
 *     Bulk fetch keyed by event_id — used by /api/accounting/je_trace.
 *
 *   evidenceSupersede($tenantId, $oldId, $newId)
 *     Marks $oldId as superseded by $newId (versioning chain).
 *
 *   evidenceSoftDelete($tenantId, $id, $userId)
 *     Audit-preserving soft delete.
 *
 * All readers degrade gracefully when the table is missing.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function _evidenceAttachmentsTableExists(?\PDO $pdo = null): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = $pdo ?: getDB();
    try {
        $pdo->query('SELECT 1 FROM evidence_attachments LIMIT 0');
        return $cache = true;
    } catch (\Throwable $_) {
        return $cache = false;
    }
}

function evidenceAttach(int $tenantId, array $args): array {
    if (!_evidenceAttachmentsTableExists()) return ['id' => 0, 'skipped' => true];

    $subjectType = trim((string) ($args['subject_type']  ?? ''));
    $subjectId   = (int) ($args['subject_id']   ?? 0);
    $docType     = trim((string) ($args['document_type'] ?? ''));
    if ($subjectType === '' || $subjectId <= 0 || $docType === '') {
        throw new \InvalidArgumentException('subject_type + subject_id + document_type required');
    }

    $hash = isset($args['sha256_hash']) ? strtolower(substr((string) $args['sha256_hash'], 0, 64)) : null;
    $pdo  = getDB();

    // Dedupe by content hash within the same (tenant, subject) — same bytes
    // re-attached to the same bill should NOT create a second row.
    if ($hash) {
        $stmt = $pdo->prepare(
            "SELECT id FROM evidence_attachments
              WHERE tenant_id = :t AND subject_type = :st AND subject_id = :sid
                AND sha256_hash = :h AND deleted_at IS NULL
              LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'st' => $subjectType, 'sid' => $subjectId, 'h' => $hash]);
        $existing = (int) ($stmt->fetchColumn() ?: 0);
        if ($existing) return ['id' => $existing, 'duplicate_of' => $existing, 'skipped' => false];
    }

    $pdo->prepare(
        "INSERT INTO evidence_attachments
            (tenant_id, subject_type, subject_id, document_type, label,
             storage_key, storage_bucket, content_type, size_bytes, sha256_hash,
             payload, source, attached_by_user_id)
         VALUES (:t, :st, :sid, :dt, :label,
                 :sk, :sb, :ct, :sz, :h,
                 :p, :src, :uid)"
    )->execute([
        't'   => $tenantId,
        'st'  => $subjectType,
        'sid' => $subjectId,
        'dt'  => $docType,
        'label'=> $args['label']         ?? null,
        'sk'  => $args['storage_key']    ?? null,
        'sb'  => $args['storage_bucket'] ?? null,
        'ct'  => $args['content_type']   ?? null,
        'sz'  => isset($args['size_bytes']) ? (int) $args['size_bytes'] : null,
        'h'   => $hash,
        'p'   => isset($args['payload']) ? json_encode($args['payload']) : null,
        'src' => $args['source']         ?? null,
        'uid' => isset($args['attached_by_user_id']) ? (int) $args['attached_by_user_id'] : null,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'duplicate_of' => null, 'skipped' => false];
}

function evidenceListFor(int $tenantId, string $subjectType, int $subjectId, bool $includeDeleted = false): array {
    if (!_evidenceAttachmentsTableExists()) return [];
    $sql = "SELECT * FROM evidence_attachments
             WHERE tenant_id = :t AND subject_type = :st AND subject_id = :sid";
    if (!$includeDeleted) $sql .= " AND deleted_at IS NULL";
    $sql .= " ORDER BY attached_at DESC, id DESC";
    $stmt = getDB()->prepare($sql);
    $stmt->execute(['t' => $tenantId, 'st' => $subjectType, 'sid' => $subjectId]);
    return _evidenceDecodePayloads($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function evidenceListForEvents(int $tenantId, array $eventIds): array {
    if (!_evidenceAttachmentsTableExists() || !$eventIds) return [];
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT * FROM evidence_attachments
          WHERE tenant_id = ?
            AND subject_type = 'accounting_event'
            AND subject_id IN ({$placeholders})
            AND deleted_at IS NULL
          ORDER BY subject_id ASC, attached_at DESC"
    );
    $stmt->execute(array_merge([$tenantId], $eventIds));
    $out = [];
    foreach (_evidenceDecodePayloads($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $out[(int) $r['subject_id']][] = $r;
    }
    return $out;
}

function _evidenceDecodePayloads(array $rows): array {
    foreach ($rows as &$r) {
        if (isset($r['payload'])) {
            $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
        }
    }
    return $rows;
}

function evidenceSupersede(int $tenantId, int $oldId, int $newId): bool {
    if (!_evidenceAttachmentsTableExists()) return false;
    $stmt = getDB()->prepare(
        "UPDATE evidence_attachments
            SET superseded_by_id = :new
          WHERE id = :old AND tenant_id = :t AND superseded_by_id IS NULL"
    );
    $stmt->execute(['new' => $newId, 'old' => $oldId, 't' => $tenantId]);
    return $stmt->rowCount() > 0;
}

function evidenceSoftDelete(int $tenantId, int $id, int $userId): bool {
    if (!_evidenceAttachmentsTableExists()) return false;
    $stmt = getDB()->prepare(
        "UPDATE evidence_attachments
            SET deleted_at = NOW()
          WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL"
    );
    $stmt->execute(['id' => $id, 't' => $tenantId]);
    return $stmt->rowCount() > 0;
}
