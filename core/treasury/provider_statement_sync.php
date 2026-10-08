<?php
/** Guard provider updates to statement lines already decided in the ledger. */
declare(strict_types=1);

require_once __DIR__ . '/bank_transaction_identity.php';

/**
 * @param array<string,mixed> $facts
 * @return array{found:bool,line_id:?int,updated:bool,review_required:bool}
 */
function treasuryProviderSyncExistingLine(
    PDO $pdo,
    int $tenantId,
    string $kind,
    int $accountId,
    ?int $lineId,
    string $fitid,
    array $facts
): array {
    if ($kind === 'deposit') {
        $table = 'accounting_bank_statement_lines';
        $accountColumn = 'bank_account_id';
        $columns = ['posted_date', 'description', 'amount', 'bank_reference'];
    } elseif ($kind === 'liability') {
        $table = 'treasury_liability_statement_lines';
        $accountColumn = 'liability_account_id';
        $columns = ['posted_date', 'description', 'amount', 'merchant_name', 'category', 'bank_reference'];
    } else {
        throw new InvalidArgumentException('Unsupported statement line type');
    }
    foreach ($columns as $column) {
        if (!array_key_exists($column, $facts)) throw new InvalidArgumentException('Missing provider fact: ' . $column);
    }

    $where = $lineId !== null ? 'id = :identity' : 'fitid = :identity';
    $select = $pdo->prepare(
        'SELECT id, match_status, ' . implode(', ', $columns) . " FROM {$table}
          WHERE tenant_id = :tenant_id AND {$accountColumn} = :account_id AND {$where} LIMIT 1"
    );
    $select->execute([
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
        'identity' => $lineId ?? $fitid,
    ]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);
    if (!$existing) return ['found' => false, 'line_id' => null, 'updated' => false, 'review_required' => false];

    $changed = false;
    foreach ($columns as $column) {
        $different = $column === 'amount'
            ? bankTxnAmountKey($existing[$column]) !== bankTxnAmountKey($facts[$column])
            : trim((string) ($existing[$column] ?? '')) !== trim((string) ($facts[$column] ?? ''));
        if ($different) { $changed = true; break; }
    }
    $id = (int) $existing['id'];
    if (!$changed) return ['found' => true, 'line_id' => $id, 'updated' => false, 'review_required' => false];
    if (($existing['match_status'] ?? '') !== 'unmatched') {
        return ['found' => true, 'line_id' => $id, 'updated' => false, 'review_required' => true];
    }

    $set = implode(', ', array_map(static fn(string $column): string => $column . ' = :' . $column, $columns));
    $update = $pdo->prepare(
        "UPDATE {$table} SET {$set}
          WHERE tenant_id = :tenant_id AND {$accountColumn} = :account_id
            AND id = :line_id AND match_status = 'unmatched'"
    );
    $params = [
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
        'line_id' => $id,
    ];
    foreach ($columns as $column) $params[$column] = $facts[$column];
    $update->execute($params);
    return [
        'found' => true,
        'line_id' => $id,
        'updated' => $update->rowCount() > 0,
        'review_required' => $update->rowCount() === 0,
    ];
}

/** @return array{line_id:?int,ignored:bool,review_required:bool} */
function treasuryProviderIgnoreRemovedLine(
    PDO $pdo,
    int $tenantId,
    string $kind,
    int $accountId,
    ?int $lineId,
    string $fitid
): array {
    if ($kind === 'deposit') {
        $table = 'accounting_bank_statement_lines';
        $accountColumn = 'bank_account_id';
    } elseif ($kind === 'liability') {
        $table = 'treasury_liability_statement_lines';
        $accountColumn = 'liability_account_id';
    } else {
        throw new InvalidArgumentException('Unsupported statement line type');
    }

    $where = $lineId !== null ? 'id = :identity' : 'fitid = :identity';
    $select = $pdo->prepare(
        "SELECT id, match_status FROM {$table}
          WHERE tenant_id = :tenant_id AND {$accountColumn} = :account_id AND {$where} LIMIT 1"
    );
    $select->execute([
        'tenant_id' => $tenantId,
        'account_id' => $accountId,
        'identity' => $lineId ?? $fitid,
    ]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['line_id' => null, 'ignored' => false, 'review_required' => false];

    $id = (int) $row['id'];
    if (($row['match_status'] ?? '') === 'ignored') {
        return ['line_id' => $id, 'ignored' => false, 'review_required' => false];
    }
    if (($row['match_status'] ?? '') !== 'unmatched') {
        return ['line_id' => $id, 'ignored' => false, 'review_required' => true];
    }

    $update = $pdo->prepare(
        "UPDATE {$table} SET match_status = 'ignored'
          WHERE tenant_id = :tenant_id AND {$accountColumn} = :account_id
            AND id = :line_id AND match_status = 'unmatched'"
    );
    $update->execute(['tenant_id' => $tenantId, 'account_id' => $accountId, 'line_id' => $id]);
    return [
        'line_id' => $id,
        'ignored' => $update->rowCount() > 0,
        'review_required' => $update->rowCount() === 0,
    ];
}
