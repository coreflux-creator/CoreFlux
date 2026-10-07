<?php
/** CoreOne v1 general journals use CoreFlux's existing event and ledger owners. */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../posting_engine/process.php';
require_once __DIR__ . '/control_accounts.php';

const COREONE_V1_PROTECTED_ACCOUNTS = ACCOUNTING_SOURCE_OWNED_CONTROL_CODES;
const COREONE_V1_DEFAULT_SCOPES = ['journals:write', 'reports:read'];
const COREONE_V1_ALLOWED_SCOPES = ['journals:write', 'reports:read', 'invoices:draft', 'bills:prepare'];

function coreoneV1IssueCredential(int $tenantId, int $entityId, string $label,
    int $days, ?int $actorUserId, ?array $scopes = null): array
{
    $label = trim($label);
    if ($tenantId <= 0 || $entityId <= 0 || $label === '' || strlen($label) > 120
        || $days < 1 || $days > 90) {
        throw new InvalidArgumentException('Choose an active entity, label and expiry of 1 to 90 days.');
    }
    $scopes ??= COREONE_V1_DEFAULT_SCOPES;
    if (!$scopes || !array_is_list($scopes) || count($scopes) > count(COREONE_V1_ALLOWED_SCOPES)) {
        throw new InvalidArgumentException('Choose one or more supported service scopes.');
    }
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !in_array($scope, COREONE_V1_ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException('Choose one or more supported service scopes.');
        }
    }
    if (count($scopes) !== count(array_unique($scopes))) {
        throw new InvalidArgumentException('Service scopes must be unique.');
    }
    accountingValidateActiveEntityId($tenantId, $entityId);
    $token = 'cfca_v1_' . bin2hex(random_bytes(32));
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO coreone_accounting_credentials
            (tenant_id, entity_id, label, scopes_json, token_hash, token_last4, expires_at, created_by_user_id)
         VALUES (:tenant_id, :entity_id, :label, :scopes_json, :token_hash, :last4,
                 DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY), :actor)'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId,
        'label' => $label, 'scopes_json' => json_encode($scopes),
        'token_hash' => hash('sha256', $token),
        'last4' => substr($token, -4), 'actor' => $actorUserId]);
    $id = (int) $pdo->lastInsertId();
    $expiry = $pdo->prepare('SELECT expires_at FROM coreone_accounting_credentials WHERE tenant_id = :t AND id = :id');
    $expiry->execute(['t' => $tenantId, 'id' => $id]);
    return ['id' => $id, 'tenant_id' => $tenantId, 'entity_id' => $entityId,
        'label' => $label, 'scopes' => $scopes,
        'expires_at' => $expiry->fetchColumn(), 'token' => $token];
}

function coreoneV1Authenticate(?string $authorization): ?array
{
    if (!preg_match('/^Bearer (cfca_v1_[a-f0-9]{64})$/D', trim((string) $authorization), $matches)) {
        return null;
    }
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT c.id, c.tenant_id, c.entity_id, c.label, c.scopes_json, e.base_currency
           FROM coreone_accounting_credentials c
           JOIN accounting_entities e ON e.tenant_id = c.tenant_id AND e.id = c.entity_id AND e.active = 1
          WHERE c.token_hash = :hash AND c.revoked_at IS NULL AND c.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute(['hash' => hash('sha256', $matches[1])]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credential) return null;
    $credential['id'] = (int) $credential['id'];
    $credential['tenant_id'] = (int) $credential['tenant_id'];
    $credential['entity_id'] = (int) $credential['entity_id'];
    $credential['scopes'] = json_decode((string) $credential['scopes_json'], true);
    if (!is_array($credential['scopes']) || !array_is_list($credential['scopes'])
        || !$credential['scopes']) return null;
    foreach ($credential['scopes'] as $scope) {
        if (!is_string($scope) || !in_array($scope, COREONE_V1_ALLOWED_SCOPES, true)) return null;
    }
    unset($credential['scopes_json']);
    $pdo->prepare(
        'UPDATE coreone_accounting_credentials SET last_used_at = NOW()
          WHERE tenant_id = :t AND id = :id AND revoked_at IS NULL AND expires_at > NOW()'
    )->execute(['t' => $credential['tenant_id'], 'id' => $credential['id']]);
    return $credential;
}

function coreoneV1HasScope(array $credential, string $scope): bool
{
    return in_array($scope, (array) ($credential['scopes'] ?? []), true);
}

function coreoneV1NormalizeJournal(array $credential, array $body): array
{
    if (array_diff(array_keys($body), ['schema_version', 'source_record_id',
        'event_date', 'memo', 'currency', 'lines'])) {
        throw new InvalidArgumentException('The v1 journal request has unsupported fields.');
    }
    if (($body['schema_version'] ?? null) !== 1) {
        throw new InvalidArgumentException('schema_version must be 1.');
    }
    $sourceId = $body['source_record_id'] ?? null;
    if (!is_string($sourceId) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9:_./-]{0,119}$#D', $sourceId)) {
        throw new InvalidArgumentException('source_record_id must be a stable ID of at most 120 characters.');
    }
    $date = $body['event_date'] ?? null;
    $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('event_date must be a valid YYYY-MM-DD date.');
    }
    $memo = $body['memo'] ?? null;
    if (!is_string($memo) || trim($memo) === '' || strlen($memo) > 500) {
        throw new InvalidArgumentException('memo is required and must be at most 500 characters.');
    }
    $currency = $body['currency'] ?? null;
    if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)
        || $currency !== (string) $credential['base_currency']) {
        throw new InvalidArgumentException('currency must match the entity base currency.');
    }
    $rawLines = $body['lines'] ?? null;
    if (!is_array($rawLines) || !array_is_list($rawLines)
        || count($rawLines) < 2 || count($rawLines) > 100) {
        throw new InvalidArgumentException('lines must contain 2 to 100 journal lines.');
    }

    $pdo = getDB();
    $accountStmt = $pdo->prepare(
        'SELECT a.id, ba.id AS bank_account_id
           FROM accounting_accounts a
           LEFT JOIN accounting_bank_accounts ba
             ON ba.tenant_id = a.tenant_id AND ba.gl_account_code = a.code
          WHERE a.tenant_id = :tenant_id AND a.code = :code
            AND a.active = 1 AND a.is_postable = 1 LIMIT 1'
    );
    $lines = [];
    $debitCents = 0;
    $creditCents = 0;
    foreach ($rawLines as $index => $raw) {
        if (!is_array($raw) || !is_string($raw['account_code'] ?? null)) {
            throw new InvalidArgumentException('Each line needs an account_code.');
        }
        if (array_diff(array_keys($raw), ['account_code', 'debit', 'credit', 'description', 'dims'])) {
            throw new InvalidArgumentException("Line {$index} has unsupported fields.");
        }
        $code = trim($raw['account_code']);
        if ($code === '' || strlen($code) > 40 || in_array($code, COREONE_V1_PROTECTED_ACCOUNTS, true)) {
            throw new InvalidArgumentException("Line {$index} uses an unavailable control account.");
        }
        $accountStmt->execute(['tenant_id' => (int) $credential['tenant_id'], 'code' => $code]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new InvalidArgumentException("Line {$index} names no active, postable account in this workspace.");
        }
        if ($account['bank_account_id'] !== null) {
            throw new InvalidArgumentException("Line {$index} uses a bank-linked control account.");
        }
        $debit = coreoneV1Cents($raw['debit'] ?? 0, "lines[{$index}].debit");
        $credit = coreoneV1Cents($raw['credit'] ?? 0, "lines[{$index}].credit");
        if (($debit > 0) === ($credit > 0)) {
            throw new InvalidArgumentException("Line {$index} needs exactly one positive debit or credit.");
        }
        $description = $raw['description'] ?? null;
        if ($description !== null && (!is_string($description) || strlen($description) > 500)) {
            throw new InvalidArgumentException("Line {$index} has an invalid description.");
        }
        $dims = $raw['dims'] ?? [];
        if (!is_array($dims) || ($dims !== [] && array_is_list($dims))
            || (isset($dims['legal_entity'])
                && (!ctype_digit((string) $dims['legal_entity'])
                    || (int) $dims['legal_entity'] !== (int) $credential['entity_id']))) {
            throw new InvalidArgumentException("Line {$index} has invalid legal-entity dimensions.");
        }
        foreach ($dims as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)
                || !is_scalar($value)
                || (is_string($value) && preg_match('/^(payload|event)\./', $value))) {
                throw new InvalidArgumentException("Line {$index} has an invalid dimension value.");
            }
        }
        $debitCents += $debit;
        $creditCents += $credit;
        $lines[] = [
            'account_code' => $code, 'debit' => $debit / 100, 'credit' => $credit / 100,
            'description' => $description, 'dims' => $dims,
        ];
    }
    if ($debitCents <= 0 || $debitCents !== $creditCents) {
        throw new InvalidArgumentException('Journal debits and credits must balance to the cent.');
    }
    return [
        'entity_id' => (int) $credential['entity_id'],
        'event_type' => 'coreone.journal.posted',
        'source_module' => 'coreone',
        'source_record_id' => $sourceId,
        'event_date' => $date,
        'payload' => ['external_journal_id' => $sourceId, 'currency' => $currency,
            'memo' => trim($memo), 'lines' => $lines],
    ];
}

function coreoneV1Cents(mixed $value, string $field): int
{
    if (!is_int($value) && !is_float($value) && !is_string($value)) {
        throw new InvalidArgumentException("{$field} must be a nonnegative amount with at most two decimals.");
    }
    $raw = (string) $value;
    if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $raw)) {
        throw new InvalidArgumentException("{$field} must be a nonnegative amount with at most two decimals.");
    }
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function coreoneV1PostJournal(array $credential, array $body): array
{
    $event = coreoneV1NormalizeJournal($credential, $body);
    $pdo = getDB();
    $owns = cf_tx_begin($pdo);
    try {
        $result = accountingProcessEvent((int) $credential['tenant_id'], $event);
        if (($result['status'] ?? '') !== 'posted') {
            throw new RuntimeException('Journal could not be posted under the current rule and period.');
        }
        cf_tx_commit($pdo, $owns);
        return $result;
    } catch (Throwable $e) {
        cf_tx_rollback($pdo, $owns);
        throw $e;
    }
}

function coreoneV1GetJournal(array $credential, string $sourceId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT e.id AS event_id, e.status, e.journal_entry_id, e.event_date,
                e.payload, e.posted_at, je.je_number,
                je.reversed_by_je_id AS reversal_journal_entry_id
           FROM accounting_events e
           LEFT JOIN accounting_journal_entries je
             ON je.tenant_id = e.tenant_id AND je.entity_id = e.entity_id
            AND je.id = e.journal_entry_id
          WHERE e.tenant_id = :t AND e.entity_id = :entity
            AND e.source_module = "coreone" AND e.event_type = "coreone.journal.posted"
            AND e.source_record_id = :source_id LIMIT 1'
    );
    $stmt->execute(['t' => (int) $credential['tenant_id'], 'entity' => (int) $credential['entity_id'],
        'source_id' => $sourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['event_id'] = (int) $row['event_id'];
    $row['journal_entry_id'] = $row['journal_entry_id'] === null ? null : (int) $row['journal_entry_id'];
    $row['reversal_journal_entry_id'] = $row['reversal_journal_entry_id'] === null
        ? null : (int) $row['reversal_journal_entry_id'];
    $row['payload'] = json_decode((string) $row['payload'], true);
    return $row;
}

function coreoneV1ReverseJournal(array $credential, string $sourceId, string $reason): array
{
    $reason = trim($reason);
    if ($sourceId === '' || strlen($sourceId) > 120 || $reason === '' || strlen($reason) > 500) {
        throw new InvalidArgumentException('Choose a journal and give a correction reason of at most 500 characters.');
    }
    $tenantId = (int) $credential['tenant_id'];
    $pdo = getDB();
    $owns = cf_tx_begin($pdo);
    try {
        $stmt = $pdo->prepare(
            'SELECT id, status, journal_entry_id FROM accounting_events
              WHERE tenant_id = :t AND entity_id = :entity
                AND source_module = "coreone" AND event_type = "coreone.journal.posted"
                AND source_record_id = :source_id FOR UPDATE'
        );
        $stmt->execute(['t' => $tenantId, 'entity' => (int) $credential['entity_id'],
            'source_id' => $sourceId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$event) throw new RuntimeException('CoreOne journal not found for this entity.');
        $journalStmt = $pdo->prepare(
            'SELECT id, status, source_module, reversed_by_je_id FROM accounting_journal_entries
              WHERE tenant_id = :t AND entity_id = :entity AND id = :id FOR UPDATE'
        );
        $journalStmt->execute(['t' => $tenantId, 'entity' => (int) $credential['entity_id'],
            'id' => (int) $event['journal_entry_id']]);
        $journal = $journalStmt->fetch(PDO::FETCH_ASSOC);
        if (!$journal || $journal['source_module'] !== 'coreone') {
            throw new RuntimeException('The event and journal ownership do not agree.');
        }
        if ($event['status'] === 'reversed' && $journal['status'] === 'reversed'
            && (int) $journal['reversed_by_je_id'] > 0) {
            cf_tx_commit($pdo, $owns);
            return ['status' => 'reversed', 'event_id' => (int) $event['id'],
                'original_journal_entry_id' => (int) $journal['id'],
                'reversal_journal_entry_id' => (int) $journal['reversed_by_je_id'],
                'idempotent_replay' => true];
        }
        if ($event['status'] !== 'posted' || $journal['status'] !== 'posted') {
            throw new RuntimeException('This journal is not active and posted.');
        }
        $linkStmt = $pdo->prepare(
            'SELECT id FROM accounting_subledger_links WHERE tenant_id = :t
                AND source_module = "coreone" AND source_record_id = :source_id
                AND accounting_event_id = :event_id AND journal_entry_id = :je_id
                AND link_kind = "primary" LIMIT 1'
        );
        $linkStmt->execute(['t' => $tenantId, 'source_id' => $sourceId,
            'event_id' => (int) $event['id'], 'je_id' => (int) $journal['id']]);
        if (!$linkStmt->fetchColumn()) {
            throw new RuntimeException('The journal has no matching CoreOne source link.');
        }
        $bankStmt = $pdo->prepare(
            'SELECT id FROM accounting_bank_statement_lines WHERE tenant_id = :t
                AND matched_je_id = :je_id AND match_status = "matched" LIMIT 1 FOR UPDATE'
        );
        $bankStmt->execute(['t' => $tenantId, 'je_id' => (int) $journal['id']]);
        if ($bankStmt->fetchColumn()) {
            throw new RuntimeException('Unmatch the bank line before reversing this journal.');
        }
        if (accountingTableHasColumn($pdo, 'treasury_liability_statement_lines', 'matched_je_id')) {
            $liabilityStmt = $pdo->prepare(
                'SELECT id FROM treasury_liability_statement_lines WHERE tenant_id = :t
                    AND matched_je_id = :je_id AND match_status = "matched" LIMIT 1 FOR UPDATE'
            );
            $liabilityStmt->execute(['t' => $tenantId, 'je_id' => (int) $journal['id']]);
            if ($liabilityStmt->fetchColumn()) {
                throw new RuntimeException('Unmatch the liability statement line before reversing this journal.');
            }
        }
        $reversal = accountingReverseJe($tenantId, (int) $journal['id'], $reason);
        if (!empty($reversal['idempotent_replay'])) {
            throw new RuntimeException('The journal was reversed concurrently.');
        }
        $update = $pdo->prepare(
            'UPDATE accounting_events SET status = "reversed"
              WHERE tenant_id = :t AND id = :id AND status = "posted"'
        );
        $update->execute(['t' => $tenantId, 'id' => (int) $event['id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Event changed while reversing.');
        $pdo->prepare(
            'INSERT INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, accounting_event_id, link_kind)
             VALUES (:t, "coreone", :source_id, :je_id, :event_id, "reversal")'
        )->execute(['t' => $tenantId, 'source_id' => $sourceId,
            'je_id' => (int) $reversal['je_id'], 'event_id' => (int) $event['id']]);
        cf_tx_commit($pdo, $owns);
        return ['status' => 'reversed', 'event_id' => (int) $event['id'],
            'original_journal_entry_id' => (int) $journal['id'],
            'reversal_journal_entry_id' => (int) $reversal['je_id'],
            'idempotent_replay' => false];
    } catch (Throwable $e) {
        cf_tx_rollback($pdo, $owns);
        throw $e;
    }
}
