<?php
/** Audited, fact-bound decisions about similar bank lines. */
declare(strict_types=1);

require_once __DIR__ . '/bank_transaction_identity.php';

function bankTxnReviewTableExists(PDO $pdo): bool
{
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return (bool) $pdo->query(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'treasury_bank_line_reviews'"
            )->fetchColumn();
        }
        $stmt = $pdo->query(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'treasury_bank_line_reviews' LIMIT 1"
        );
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $_) {
        return false;
    }
}

/** @return array<string,array<string,mixed>> */
function bankTxnLatestReviews(PDO $pdo, int $tenantId, int $bankAccountId): array
{
    if (!bankTxnReviewTableExists($pdo)) return [];
    $stmt = $pdo->prepare(
        'SELECT id, first_line_id, second_line_id, fingerprint, decision, reason,
                evidence_ref, decided_by_user_id, decided_at
           FROM treasury_bank_line_reviews
          WHERE tenant_id = :tenant_id AND bank_account_id = :account_id
          ORDER BY id DESC'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'account_id' => $bankAccountId]);
    $latest = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int) $row['first_line_id'] . ':' . (int) $row['second_line_id'];
        if (!isset($latest[$key])) $latest[$key] = $row;
    }
    return $latest;
}

/** @return array<int,string> */
function bankTxnReviewOptionalColumns(PDO $pdo): array
{
    $columns = [];
    foreach (['external_id', 'source_system', 'bank_reference'] as $column) {
        if (bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', $column)) $columns[] = $column;
    }
    return $columns;
}

/** @param array<string,mixed> $first @param array<string,mixed> $second */
function bankTxnReviewFingerprint(array $first, array $second): string
{
    if ((int) $first['id'] > (int) $second['id']) [$first, $second] = [$second, $first];
    $facts = [];
    foreach ([$first, $second] as $row) {
        $facts[] = [
            'id' => (int) $row['id'],
            'posted_date' => (string) $row['posted_date'],
            'amount' => bankTxnAmountKey($row['amount']),
            'description' => (string) $row['description'],
            'fitid' => (string) ($row['fitid'] ?? ''),
            'external_id' => (string) ($row['external_id'] ?? ''),
            'source_system' => (string) ($row['source_system'] ?? ''),
            'bank_reference' => (string) ($row['bank_reference'] ?? ''),
            'match_status' => (string) ($row['match_status'] ?? ''),
            'matched_je_id' => (int) ($row['matched_je_id'] ?? 0),
        ];
    }
    return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function bankTxnReviewLineSummary(array $row): array
{
    return [
        'line_id' => (int) $row['id'],
        'description' => (string) $row['description'],
        'fitid' => (string) ($row['fitid'] ?? ''),
        'external_id' => (string) ($row['external_id'] ?? ''),
        'source_system' => (string) ($row['source_system'] ?? ''),
        'bank_reference' => (string) ($row['bank_reference'] ?? ''),
        'match_status' => (string) ($row['match_status'] ?? ''),
        'matched_je_id' => (int) ($row['matched_je_id'] ?? 0) ?: null,
    ];
}

/**
 * Record a distinct/reopened decision without changing a statement line or JE.
 * @return array<string,mixed>
 */
function bankTxnDecideReview(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    int $firstLineId,
    int $secondLineId,
    string $expectedFingerprint,
    string $decision,
    string $reason,
    ?string $evidenceRef,
    int $actorUserId
): array {
    if (!in_array($decision, ['distinct', 'reopened'], true)) {
        throw new InvalidArgumentException('Choose a supported review decision');
    }
    $reason = trim($reason);
    $evidenceRef = trim((string) $evidenceRef) ?: null;
    if (strlen($reason) < 10 || strlen($reason) > 500) {
        throw new InvalidArgumentException('Give a review reason between 10 and 500 characters');
    }
    if ($evidenceRef !== null && strlen($evidenceRef) > 255) {
        throw new InvalidArgumentException('Evidence reference is too long');
    }
    if ($firstLineId <= 0 || $secondLineId <= 0 || $firstLineId === $secondLineId || $actorUserId <= 0) {
        throw new InvalidArgumentException('Two bank lines and a reviewer are required');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint)) {
        throw new InvalidArgumentException('Review fingerprint is required');
    }
    if (!bankTxnReviewTableExists($pdo)) throw new DomainException('Bank-line review migration is not installed');
    if ($pdo->inTransaction()) throw new LogicException('Bank-line review requires its own transaction');

    [$firstLineId, $secondLineId] = [min($firstLineId, $secondLineId), max($firstLineId, $secondLineId)];
    $pdo->beginTransaction();
    try {
        $columns = bankTxnReviewOptionalColumns($pdo);
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT id, posted_date, description, amount, fitid, match_status,
                    matched_je_id' . ($columns ? ', ' . implode(', ', $columns) : '') . '
               FROM accounting_bank_statement_lines
              WHERE tenant_id = :tenant_id AND bank_account_id = :account_id
                AND id IN (:first_id, :second_id) AND duplicate_of_line_id IS NULL
              ORDER BY id' . $lock
        );
        $stmt->execute([
            'tenant_id' => $tenantId, 'account_id' => $bankAccountId,
            'first_id' => $firstLineId, 'second_id' => $secondLineId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) throw new DomainException('Bank lines are no longer available in this account');
        if ((string) $rows[0]['posted_date'] !== (string) $rows[1]['posted_date']
            || bankTxnAmountKey($rows[0]['amount']) !== bankTxnAmountKey($rows[1]['amount'])
            || !bankTxnDescriptionsEquivalent((string) $rows[0]['description'], (string) $rows[1]['description'])) {
            throw new DomainException('Bank lines are no longer a similar pair');
        }
        if ($decision === 'distinct' && (int) ($rows[0]['matched_je_id'] ?? 0) > 0
            && (int) $rows[0]['matched_je_id'] === (int) ($rows[1]['matched_je_id'] ?? 0)) {
            throw new DomainException('Both bank lines share one journal. Correct the source match or posting before marking them distinct.');
        }
        $fingerprint = bankTxnReviewFingerprint($rows[0], $rows[1]);
        if (!hash_equals($fingerprint, $expectedFingerprint)) {
            throw new DomainException('Bank-line facts changed; refresh and review again');
        }

        $latest = $pdo->prepare(
            'SELECT id, decision, fingerprint FROM treasury_bank_line_reviews
              WHERE tenant_id = :tenant_id AND bank_account_id = :account_id
                AND first_line_id = :first_id AND second_line_id = :second_id
              ORDER BY id DESC LIMIT 1' . $lock
        );
        $latest->execute([
            'tenant_id' => $tenantId, 'account_id' => $bankAccountId,
            'first_id' => $firstLineId, 'second_id' => $secondLineId,
        ]);
        $previous = $latest->fetch(PDO::FETCH_ASSOC);
        if ($decision === 'reopened' && (!$previous || $previous['decision'] !== 'distinct'
            || !hash_equals((string) $previous['fingerprint'], $fingerprint))) {
            throw new DomainException('Only a current distinct review can be reopened');
        }
        if ($previous && $previous['decision'] === $decision
            && hash_equals((string) $previous['fingerprint'], $fingerprint)) {
            $pdo->commit();
            return ['id' => (int) $previous['id'], 'decision' => $decision, 'idempotent_replay' => true];
        }

        $insert = $pdo->prepare(
            'INSERT INTO treasury_bank_line_reviews
                (tenant_id, bank_account_id, first_line_id, second_line_id,
                 fingerprint, decision, reason, evidence_ref, decided_by_user_id)
             VALUES (:tenant_id, :account_id, :first_id, :second_id,
                     :fingerprint, :decision, :reason, :evidence_ref, :actor_id)'
        );
        $insert->execute([
            'tenant_id' => $tenantId, 'account_id' => $bankAccountId,
            'first_id' => $firstLineId, 'second_id' => $secondLineId,
            'fingerprint' => $fingerprint, 'decision' => $decision,
            'reason' => $reason, 'evidence_ref' => $evidenceRef,
            'actor_id' => $actorUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return ['id' => $id, 'decision' => $decision, 'idempotent_replay' => false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
