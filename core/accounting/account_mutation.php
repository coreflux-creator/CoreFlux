<?php
/** Shared chart-of-accounts mutation rules for forms and CSV imports. */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/control_accounts.php';
require_once __DIR__ . '/system_accounts.php';

const ACCOUNTING_ACCOUNT_TYPES = [
    'asset', 'liability', 'equity', 'revenue', 'contra_revenue',
    'expense', 'cost_of_goods_sold', 'other_income', 'other_expense',
];

const ACCOUNTING_ACCOUNT_NORMAL_SIDE = [
    'asset' => 'debit', 'liability' => 'credit', 'equity' => 'credit',
    'revenue' => 'credit', 'contra_revenue' => 'debit',
    'expense' => 'debit', 'cost_of_goods_sold' => 'debit',
    'other_income' => 'credit', 'other_expense' => 'debit',
];

function accountingAccountBoolean(mixed $value, string $field): int
{
    if (is_bool($value)) return $value ? 1 : 0;
    if ($value === 1 || $value === 0) return $value;
    $text = strtolower(trim((string) $value));
    if (in_array($text, ['1', 'true', 'yes', 'y'], true)) return 1;
    if (in_array($text, ['0', 'false', 'no', 'n'], true)) return 0;
    throw new InvalidArgumentException("{$field} must be true or false.");
}

/** Blank optional CSV cells leave an existing account unchanged. */
function accountingAccountCsvChanges(array $row): array
{
    $changes = [];
    foreach ([
        'code', 'name', 'account_type', 'normal_side', 'parent_account_id',
        'is_postable', 'currency', 'cash_flow_tag', 'description', 'active',
    ] as $field) {
        if (!array_key_exists($field, $row)) continue;
        if ($row[$field] === '' || $row[$field] === null) continue;
        $changes[$field] = $row[$field];
    }
    return $changes;
}

/**
 * Validate and normalize a partial account change. Call again inside the
 * writer's transaction because account activity can change after preview.
 *
 * @return array<string, mixed> Only fields to write, with create defaults.
 */
function accountingReviewAccountChange(int $tenantId, ?array $current, array $changes): array
{
    if ($tenantId <= 0) throw new InvalidArgumentException('A workspace is required.');
    $allowed = [
        'code', 'name', 'account_type', 'normal_side', 'parent_account_id',
        'is_postable', 'currency', 'cash_flow_tag', 'description', 'active',
    ];
    $unknown = array_diff(array_keys($changes), $allowed);
    if ($unknown) throw new InvalidArgumentException('Unsupported account field: ' . reset($unknown));
    if (!$changes) throw new InvalidArgumentException('No account changes were provided.');

    foreach (['code' => 40, 'name' => 255, 'description' => 500, 'cash_flow_tag' => 60] as $field => $limit) {
        if (!array_key_exists($field, $changes)) continue;
        $value = trim((string) ($changes[$field] ?? ''));
        if (in_array($field, ['code', 'name'], true) && $value === '') {
            throw new InvalidArgumentException("{$field} is required.");
        }
        if (strlen($value) > $limit) throw new InvalidArgumentException("{$field} must be {$limit} characters or fewer.");
        $changes[$field] = $value === '' ? null : $value;
    }
    if (array_key_exists('account_type', $changes)
        && !in_array($changes['account_type'], ACCOUNTING_ACCOUNT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid account type.');
    }
    if (array_key_exists('normal_side', $changes)
        && !in_array($changes['normal_side'], ['debit', 'credit'], true)) {
        throw new InvalidArgumentException('Normal side must be debit or credit.');
    }
    if (array_key_exists('currency', $changes)) {
        $currency = strtoupper(trim((string) ($changes['currency'] ?? '')));
        if ($currency !== '' && !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter code.');
        }
        $changes['currency'] = $currency === '' ? null : $currency;
    }
    foreach (['active', 'is_postable'] as $field) {
        if (array_key_exists($field, $changes)) {
            $changes[$field] = accountingAccountBoolean($changes[$field], $field);
        }
    }
    if (array_key_exists('parent_account_id', $changes)) {
        $raw = trim((string) ($changes['parent_account_id'] ?? ''));
        if ($raw !== '' && (!ctype_digit($raw) || (int) $raw <= 0)) {
            throw new InvalidArgumentException('Parent account ID must be a positive whole number.');
        }
        $changes['parent_account_id'] = $raw === '' ? null : (int) $raw;
    }

    if ($current === null) {
        foreach (['code', 'name', 'account_type'] as $field) {
            if (empty($changes[$field])) throw new InvalidArgumentException("{$field} is required.");
        }
        foreach (ACCOUNTING_SYSTEM_ACCOUNTS as $systemAccount) {
            if ($changes['code'] === $systemAccount['code']) {
                throw new InvalidArgumentException('That code is reserved for a seeded system account.');
            }
        }
        $changes += [
            'normal_side' => ACCOUNTING_ACCOUNT_NORMAL_SIDE[$changes['account_type']],
            'parent_account_id' => null, 'is_postable' => 1,
            'currency' => null, 'cash_flow_tag' => null,
            'description' => null, 'active' => 1,
        ];
    } elseif (array_key_exists('code', $changes) && $changes['code'] !== (string) $current['code']) {
        throw new InvalidArgumentException('Account code is a stable ledger identifier; create a replacement account instead of renumbering it.');
    }

    $accountId = (int) ($current['id'] ?? 0);
    $type = (string) ($changes['account_type'] ?? $current['account_type'] ?? '');
    $parentId = $changes['parent_account_id'] ?? $current['parent_account_id'] ?? null;
    $parentChanged = array_key_exists('parent_account_id', $changes)
        && (int) ($changes['parent_account_id'] ?? 0) !== (int) ($current['parent_account_id'] ?? 0);
    $typeChanged = $current !== null && array_key_exists('account_type', $changes)
        && $type !== (string) $current['account_type'];
    $pdo = getDB();

    if ($parentId && ($current === null || $parentChanged || $typeChanged)) {
        $parent = $pdo->prepare(
            'SELECT id, account_type, parent_account_id, active FROM accounting_accounts
              WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $parent->execute(['t' => $tenantId, 'id' => (int) $parentId]);
        $row = $parent->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['active'] !== 1) {
            throw new InvalidArgumentException('Choose an active parent account in this workspace.');
        }
        if ((string) $row['account_type'] !== $type) {
            throw new InvalidArgumentException('Parent account must have the same account type.');
        }
        $seen = [];
        while ($row) {
            $cursorId = (int) $row['id'];
            if ($cursorId === $accountId || isset($seen[$cursorId])) {
                throw new InvalidArgumentException('Parent selection would create a cycle.');
            }
            $seen[$cursorId] = true;
            if (count($seen) > 500) throw new InvalidArgumentException('Account hierarchy is too deep.');
            $nextId = (int) ($row['parent_account_id'] ?? 0);
            if ($nextId <= 0) break;
            $parent->execute(['t' => $tenantId, 'id' => $nextId]);
            $row = $parent->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) throw new InvalidArgumentException('Parent hierarchy contains a missing account.');
        }
    }

    if ($current !== null) {
        if ($typeChanged) {
            $children = $pdo->prepare(
                'SELECT COUNT(*) FROM accounting_accounts WHERE tenant_id = :t AND parent_account_id = :id'
            );
            $children->execute(['t' => $tenantId, 'id' => $accountId]);
            if ((int) $children->fetchColumn() > 0) {
                throw new InvalidArgumentException('Move child accounts before changing this account type.');
            }
        }
        $classificationChanged = $typeChanged;
        foreach (['normal_side', 'currency'] as $field) {
            if (array_key_exists($field, $changes)
                && ($changes[$field] ?? null) !== ($current[$field] ?? null)) {
                $classificationChanged = true;
            }
        }
        $disablePosting = array_key_exists('is_postable', $changes)
            && $changes['is_postable'] === 0 && (int) $current['is_postable'] === 1;
        $system = (int) ($current['is_system_account'] ?? 0) === 1
            || in_array((string) $current['code'], ACCOUNTING_SOURCE_OWNED_CONTROL_CODES, true);
        if ($system && ($classificationChanged || $disablePosting
            || (array_key_exists('active', $changes) && $changes['active'] === 0))) {
            throw new InvalidArgumentException('System and control accounts must keep their accounting classification and remain active and postable.');
        }
        if ($classificationChanged || $disablePosting) {
            $used = $pdo->prepare(
                'SELECT COUNT(*) FROM accounting_journal_entry_lines l
                  JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = :t
                 WHERE l.account_id = :id'
            );
            $used->execute(['t' => $tenantId, 'id' => $accountId]);
            if ((int) $used->fetchColumn() > 0) {
                throw new InvalidArgumentException('Accounts with journal activity cannot change classification, currency, or postability.');
            }
            $bank = $pdo->prepare(
                'SELECT COUNT(*) FROM accounting_bank_accounts
                  WHERE tenant_id = :t AND gl_account_code = :code'
            );
            $bank->execute(['t' => $tenantId, 'code' => (string) $current['code']]);
            if ((int) $bank->fetchColumn() > 0) {
                throw new InvalidArgumentException('Unlink this account from bank feeds before changing its classification or postability.');
            }
        }
    }
    return $changes;
}
