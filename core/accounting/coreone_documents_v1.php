<?php
/** Atomic CoreOne source-to-document reservation; financial documents stay in their modules. */
declare(strict_types=1);

require_once __DIR__ . '/coreone_v1.php';

final class CoreOneDocumentConflictException extends RuntimeException {}

function coreoneV1DocumentDecimal4(mixed $value, string $field, bool $positive = false): string
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        throw new InvalidArgumentException("{$field} must be a nonnegative decimal with at most four places.");
    }
    $raw = (string) $value;
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,4})?$/D', $raw)) {
        throw new InvalidArgumentException("{$field} must be a nonnegative decimal with at most four places.");
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $scaled = (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
    if ($positive && $scaled === 0) {
        throw new InvalidArgumentException("{$field} must be greater than zero.");
    }
    return sprintf('%d.%04d', intdiv($scaled, 10000), $scaled % 10000);
}

function coreoneV1SubmitDocument(array $credential, string $sourceType, string $sourceId,
    array $intent, callable $create, callable $load): array
{
    if (!in_array($sourceType, ['billing.invoice', 'ap.bill'], true)) {
        throw new LogicException('Unsupported CoreOne document type.');
    }
    $tenantId = (int) $credential['tenant_id'];
    $entityId = (int) $credential['entity_id'];
    $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $pdo = getDB();
    $owns = !$pdo->inTransaction();
    $savepoint = $owns ? null : 'coreone_doc_' . bin2hex(random_bytes(4));
    if ($owns) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT ' . $savepoint);
    try {
        try {
            $reserve = $pdo->prepare(
                'INSERT INTO coreone_document_requests
                    (tenant_id, entity_id, source_type, source_record_id, intent_hash, target_id, credential_id)
                 VALUES (:t, :e, :source_type, :source_id, :intent_hash, 0, :credential_id)'
            );
            $reserve->execute(['t' => $tenantId, 'e' => $entityId,
                'source_type' => $sourceType, 'source_id' => $sourceId,
                'intent_hash' => $hash, 'credential_id' => (int) $credential['id']]);
            $created = true;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) throw $e;
            $created = false;
        }
        if ($created) {
            $targetId = (int) $create();
            if ($targetId <= 0) throw new RuntimeException('Document service did not return an ID.');
            $link = $pdo->prepare(
                'UPDATE coreone_document_requests SET target_id = :target_id
                  WHERE tenant_id = :t AND entity_id = :e AND source_type = :source_type
                    AND source_record_id = :source_id AND target_id = 0'
            );
            $link->execute(['target_id' => $targetId, 't' => $tenantId, 'e' => $entityId,
                'source_type' => $sourceType, 'source_id' => $sourceId]);
            if ($link->rowCount() !== 1) throw new RuntimeException('Document source mapping changed unexpectedly.');
        } else {
            $priorStmt = $pdo->prepare(
                'SELECT entity_id, intent_hash FROM coreone_document_requests
                  WHERE tenant_id = :t AND source_type = :source_type
                    AND source_record_id = :source_id FOR UPDATE'
            );
            $priorStmt->execute(['t' => $tenantId, 'source_type' => $sourceType, 'source_id' => $sourceId]);
            $prior = $priorStmt->fetch(PDO::FETCH_ASSOC);
            if (!$prior || (int) $prior['entity_id'] !== $entityId
                || !hash_equals((string) $prior['intent_hash'], $hash)) {
                throw new CoreOneDocumentConflictException('Source ID already exists with different document details.');
            }
        }
        $record = $load($sourceId);
        if (!is_array($record)) throw new RuntimeException('Document source has no matching module record.');
        if ($owns) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        return ['record' => $record, 'idempotent_replay' => !$created];
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$owns && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        throw $e;
    }
}
