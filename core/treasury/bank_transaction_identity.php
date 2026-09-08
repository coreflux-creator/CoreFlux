<?php
/**
 * Bank transaction identity helpers.
 *
 * A provider id identifies one representation of a transaction. The same
 * business event can receive another id after a Plaid Item reconnect, so full
 * history replays also occurrence-match on stable bank facts.
 */
declare(strict_types=1);

function bankTxnNormalizeDescription(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim((string) (preg_replace('/\s+/', ' ', $value) ?? $value));
}

function bankTxnDescriptionsEquivalent(string $left, string $right): bool
{
    $a = bankTxnNormalizeDescription($left);
    $b = bankTxnNormalizeDescription($right);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;

    // Bank exports often carry the full ACH narrative while Plaid exposes a
    // compact merchant label (for example "Batch Trkid"). Date and amount are
    // compared separately by every caller, so a meaningful containment match
    // is strong enough for full-history replay reconciliation.
    $short = strlen($a) <= strlen($b) ? $a : $b;
    $long  = $short === $a ? $b : $a;
    if (strlen($short) >= 8 && str_contains($long, $short)) return true;

    $shortTokens = array_values(array_filter(explode(' ', $short), static fn(string $v): bool => strlen($v) >= 3));
    if (count($shortTokens) < 2) return false;
    foreach ($shortTokens as $token) {
        if (!preg_match('/(^| )' . preg_quote($token, '/') . '( |$)/', $long)) return false;
    }
    return true;
}

function bankTxnAmountKey($amount): string
{
    return number_format(round((float) $amount, 2), 2, '.', '');
}

function bankTxnHasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $key = spl_object_id($pdo) . '|' . $table . '|' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    try {
        if ($driver === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) return $cache[$key] = true;
            }
            return $cache[$key] = false;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND COLUMN_NAME = :col'
        );
        $stmt->execute(['tbl' => $table, 'col' => $column]);
        return $cache[$key] = ((int) $stmt->fetchColumn() > 0);
    } catch (Throwable $_) {
        return $cache[$key] = false;
    }
}

function bankTxnAliasLineId(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    string $provider,
    string $externalId
): ?int {
    try {
        $stmt = $pdo->prepare(
            'SELECT statement_line_id
               FROM accounting_bank_transaction_aliases
              WHERE tenant_id = :t AND bank_account_id = :a
                AND provider = :p AND external_id = :e
              LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'a' => $bankAccountId, 'p' => $provider, 'e' => $externalId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    } catch (Throwable $_) {
        return null;
    }
}

function bankTxnRecordAlias(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    int $statementLineId,
    string $provider,
    string $externalId,
    ?string $providerItemId = null
): void {
    if ($statementLineId <= 0 || $externalId === '') return;
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = 'INSERT INTO accounting_bank_transaction_aliases
                        (tenant_id, bank_account_id, statement_line_id, provider, external_id, provider_item_id)
                    VALUES (:t, :a, :l, :p, :e, :i)
                    ON CONFLICT(tenant_id, bank_account_id, provider, external_id)
                    DO UPDATE SET statement_line_id = excluded.statement_line_id,
                                  provider_item_id = excluded.provider_item_id';
        } else {
            $sql = 'INSERT INTO accounting_bank_transaction_aliases
                        (tenant_id, bank_account_id, statement_line_id, provider, external_id, provider_item_id)
                    VALUES (:t, :a, :l, :p, :e, :i)
                    ON DUPLICATE KEY UPDATE
                        statement_line_id = VALUES(statement_line_id),
                        provider_item_id = VALUES(provider_item_id),
                        updated_at = NOW()';
        }
        $pdo->prepare($sql)->execute([
            't' => $tenantId, 'a' => $bankAccountId, 'l' => $statementLineId,
            'p' => $provider, 'e' => substr($externalId, 0, 160),
            'i' => $providerItemId !== null ? substr($providerItemId, 0, 160) : null,
        ]);
    } catch (Throwable $_) {
        // Migration may not have reached a host yet. The legacy FITID unique
        // key still protects same-connection replays.
    }
}

/**
 * Find one unclaimed existing occurrence for a full-history replay.
 *
 * @param array<int,bool> $claimedLineIds rows already consumed in this replay
 * @return array<string,mixed>|null
 */
function bankTxnFindReplayCandidate(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    string $postedDate,
    $amount,
    string $description,
    array $claimedLineIds = []
): ?array {
    $visible = bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', 'duplicate_of_line_id')
        ? ' AND duplicate_of_line_id IS NULL'
        : '';
    $stmt = $pdo->prepare(
        'SELECT id, description, fitid, match_status, matched_je_id, created_at
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :a
            AND posted_date = :d AND amount = :amt' . $visible . '
          ORDER BY id ASC'
    );
    $stmt->execute([
        't' => $tenantId, 'a' => $bankAccountId, 'd' => $postedDate,
        'amt' => bankTxnAmountKey($amount),
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if (isset($claimedLineIds[$id])) continue;
        if (bankTxnDescriptionsEquivalent((string) ($row['description'] ?? ''), $description)) return $row;
    }
    return null;
}

function bankTxnLineIdByFitid(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    string $fitid
): ?int {
    $stmt = $pdo->prepare(
        'SELECT id FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :a AND fitid = :f LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'a' => $bankAccountId, 'f' => $fitid]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}
