<?php
/**
 * Phase 1e — Evidence Attachments + JE Trace exception merge smoke
 * (Live Books Rails, 2026-02-14).
 *
 * Pins:
 *   • Migration 040 creates evidence_attachments with the right shape.
 *   • core/evidence_attachments.php — attach / list / supersede / softDelete
 *     + hash-based dedupe + graceful degradation when table missing.
 *   • api/accounting/evidence.php — list / attach / supersede / soft-delete.
 *   • api/accounting/je_trace.php now returns evidence[] AND exceptions[]
 *     keyed by event_id.
 *   • JeTracePane.jsx renders inline exception rows + evidence chips per
 *     event in the chain.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 040\n";
$mig = $read(__DIR__ . '/../core/migrations/040_evidence_attachments.sql');
$a('creates evidence_attachments',          str_contains($mig, 'CREATE TABLE IF NOT EXISTS evidence_attachments'));
$a('polymorphic subject pointer',           str_contains($mig, 'subject_type VARCHAR(60) NOT NULL') && str_contains($mig, 'subject_id   BIGINT UNSIGNED NOT NULL'));
$a('document_type column',                  str_contains($mig, 'document_type VARCHAR(60) NOT NULL'));
$a('storage_key + bucket + content_type',   str_contains($mig, 'storage_key   VARCHAR(512)') && str_contains($mig, 'storage_bucket VARCHAR(120)') && str_contains($mig, 'content_type'));
$a('sha256_hash CHAR(64) for dedupe',       str_contains($mig, 'sha256_hash   CHAR(64)'));
$a('payload JSON for non-file evidence',    str_contains($mig, 'payload       JSON'));
$a('superseded_by_id versioning chain',     str_contains($mig, 'superseded_by_id'));
$a('soft-delete via deleted_at',            str_contains($mig, 'deleted_at    DATETIME'));
$a('subject index covers deleted_at',       str_contains($mig, 'idx_evidence_subject (tenant_id, subject_type, subject_id, deleted_at)'));
$a('hash index for dedupe lookup',          str_contains($mig, 'idx_evidence_hash    (tenant_id, sha256_hash)'));

echo "\nHelper library\n";
$lib = $read(__DIR__ . '/../core/evidence_attachments.php');
foreach ([
    'evidenceAttach','evidenceListFor','evidenceListForEvents',
    'evidenceSupersede','evidenceSoftDelete',
] as $fn) {
    $a("library defines {$fn}",             str_contains($lib, "function {$fn}("));
}
$a('table-missing graceful return',         str_contains($lib, '_evidenceAttachmentsTableExists'));
$a('rejects missing required tuple',        str_contains($lib, 'subject_type + subject_id + document_type required'));
$a('hash dedupe: returns existing id',      str_contains($lib, "'duplicate_of' => \$existing"));
$a('listFor filters deleted by default',    str_contains($lib, 'deleted_at IS NULL'));
$a('listForEvents keyed by subject_id',     str_contains($lib, '$out[(int) $r[\'subject_id\']][] = $r;'));

echo "\nEvidence API\n";
$api = $read(__DIR__ . '/../api/accounting/evidence.php');
$a('GET by subject',                        str_contains($api, 'subject_type + subject_id required'));
$a('POST creates attachment',               str_contains($api, "evidenceAttach(\$tenantId, \$args)"));
$a('POST ?action=supersede',                str_contains($api, "\$action === 'supersede'"));
$a('DELETE soft deletes',                   str_contains($api, "evidenceSoftDelete(\$tenantId, \$id, \$userId)"));

echo "\nJE Trace exception + evidence merge\n";
$jt = $read(__DIR__ . '/../api/accounting/je_trace.php');
$a('je_trace requires evidence helper',     str_contains($jt, "evidence_attachments.php"));
$a('je_trace returns evidence[]',           str_contains($jt, "'evidence'        => evidenceListForEvents"));
$a('je_trace returns exceptions[]',         str_contains($jt, "'exceptions'      => \$exceptions"));
$a('je_trace queries exception_queue',      str_contains($jt, "FROM exception_queue"));
$a('je_trace filters by subject_type=accounting_event',
    str_contains($jt, "subject_type = 'accounting_event'"));
$a('je_trace exceptions keyed by event_id', str_contains($jt, '$exceptions[$eid][]'));

echo "\nJE Trace UI inline panels\n";
$ui = $read(__DIR__ . '/../modules/accounting/ui/JeTracePane.jsx');
$a('TraceBody passes exceptions per node',  str_contains($ui, 'exceptions={exceptions[node.related_event_id]}'));
$a('TraceBody passes evidence per node',    str_contains($ui, 'evidence={evidence[node.related_event_id]}'));
$a('ExceptionRow component defined',        str_contains($ui, 'function ExceptionRow('));
$a('ExceptionRow shows resolution note',    str_contains($ui, 'row.resolution_note') && str_contains($ui, 'Resolution:'));
$a('ExceptionRow severity color map',       str_contains($ui, 'critical:') && str_contains($ui, 'high:') && str_contains($ui, 'warn:'));
$a('EvidenceChip component defined',        str_contains($ui, 'function EvidenceChip('));
$a('EvidenceChip surfaces document_type',   str_contains($ui, 'row.document_type'));
$a('EvidenceChip surfaces source',          str_contains($ui, 'row.source'));
$a('Evidence wrapper has testid',           str_contains($ui, 'data-testid={`je-trace-evidence-${node.related_event_id}`}'));
$a('Exception wrapper has testid',          str_contains($ui, 'data-testid={`je-trace-exceptions-${node.related_event_id}`}'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
