<?php
/** Source-reported approval evidence. This never substitutes for Core approval. */
declare(strict_types=1);

function quantaApprovalTimestamp(mixed $value, string $field): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/D', $value)) {
        throw new InvalidArgumentException("Quanta {$field} must be an explicit ISO-8601 timestamp");
    }
    try { $date = new DateTimeImmutable($value); }
    catch (Throwable $e) { throw new InvalidArgumentException("Quanta {$field} is invalid", 0, $e); }
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException("Quanta {$field} is invalid");
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
}

function quantaTimeApprovalNormalize(array $raw, string $status): ?array
{
    $sheet = $raw['timesheet_id'] ?? null;
    if ($sheet === '') $sheet = null;
    if ($sheet !== null && (!is_string($sheet) || !preg_match('/^[!-~]{1,128}$/D', $sheet))) {
        throw new InvalidArgumentException('Quanta timesheet ID is invalid');
    }
    $email = $raw['approved_by_email'] ?? null;
    if ($email === '') $email = null;
    if ($email !== null && (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Quanta approval email is invalid');
    }
    $approvedAt = quantaApprovalTimestamp($raw['approved_at'] ?? null, 'approved_at');
    $entryUpdatedAt = quantaApprovalTimestamp($raw['updated_at'] ?? null, 'updated_at');
    if ($sheet === null && $email === null && $approvedAt === null && $entryUpdatedAt === null) return null;
    return ['version' => 1, 'timesheet_id' => $sheet, 'timesheet_status' => $status,
        'approved_at' => $approvedAt, 'approved_by_email' => $email, 'entry_updated_at' => $entryUpdatedAt];
}

function quantaTimeApprovalFields(array $entry): array
{
    $proof = $entry['source_approval'] ?? null;
    $json = $proof === null ? null : json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return ['source_approval_json' => $json, 'source_approval_hash' => $json === null ? null : hash('sha256', $json)];
}

function quantaTimeApprovalMatches(array $entry, array $prior): bool
{
    return ($prior['source_approval_hash'] ?? null) === quantaTimeApprovalFields($entry)['source_approval_hash'];
}

/** Append a receipt in the same transaction as its import; retries preserve history. */
function quantaTimeApprovalRecord(PDO $pdo, int $tenantId, int $importId, int $actorId, array $access): void
{
    if (!$pdo->inTransaction() || min($tenantId, $importId, $actorId) <= 0) throw new InvalidArgumentException('Approval evidence requires a scoped import transaction');
    quantaWorkspaceAssertCurrent($pdo, $tenantId, $access, true);
    $stmt = $pdo->prepare('SELECT source_approval_json,source_approval_hash,source_hash,source_workspace_id,source_workspace_binding_hash
        FROM quanta_time_imports WHERE tenant_id=:tenant AND id=:id FOR UPDATE');
    $stmt->execute(['tenant' => $tenantId, 'id' => $importId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$source) throw new RuntimeException('Quanta import is unavailable for this company');
    if ($source['source_approval_json'] === null && $source['source_approval_hash'] === null) return;
    if (!is_string($source['source_approval_json']) || !is_string($source['source_approval_hash'])
        || !hash_equals(hash('sha256', $source['source_approval_json']), $source['source_approval_hash'])
        || $source['source_workspace_id'] !== $access['context']['workspace']['id']) {
        throw new RuntimeException('Quanta approval evidence or original workspace is inconsistent');
    }
    // Preserve the original import attribution separately; this receipt belongs
    // to the verified connection that supplied this particular observation.
    $source['source_workspace_binding_hash'] = $access['binding_hash'];
    $pdo->prepare('INSERT INTO quanta_time_approval_evidence
        (tenant_id,quanta_time_import_id,source_hash,source_approval_hash,source_approval_json,source_workspace_id,source_workspace_binding_hash,observed_by_user_id)
        VALUES (:tenant,:import,:source_hash,:source_approval_hash,:source_approval_json,:source_workspace_id,:source_workspace_binding_hash,:actor)
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([
            'tenant' => $tenantId, 'import' => $importId, ...$source, 'actor' => $actorId,
        ]);
}
