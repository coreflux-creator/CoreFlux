<?php
/**
 * core/accounting/account_lifecycle.php
 * -------------------------------------
 * Lifecycle operations on `accounting_accounts` rows — delete and
 * deactivate.  Lives outside `account_mapping_service.php` because
 * mapping is a separate concern.
 *
 * Safety contract for `accountingAccountDelete()`:
 *   - Refuses (hard error) if the row has ANY posted journal lines
 *     (`accounting_journal_entry_lines.account_id` references) — that
 *     would leave the ledger inconsistent.
 *   - Refuses if any active `accounting_bank_accounts` row points its
 *     `gl_account_code` at this account — deleting would orphan the
 *     bank-link feed.  Operator must unlink the bank account first
 *     (or use soft-deactivate).
 *   - Otherwise hard-deletes the row, cascade-deleting dependent
 *     `accounting_account_mappings` so external integrations stay
 *     consistent.
 *
 * Soft path (`accountingAccountDeactivate()`):
 *   - Always permitted; just flips `active = 0`.  The row still shows
 *     in historical reports but is hidden from active-account pickers.
 *
 * Both are tenant-scoped via the explicit $tenantId parameter — no
 * implicit session lookup — so the API layer can pre-authorise the
 * caller.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

class AccountingAccountDeleteBlockedException extends \RuntimeException
{
    /** @var array<string, int> */
    public array $reasons = [];
}

/**
 * Hard-delete an accounting_accounts row if safe.
 *
 * @return array{deleted: int, mappings_removed: int}
 * @throws AccountingAccountDeleteBlockedException when references exist.
 * @throws \InvalidArgumentException for not-found / cross-tenant attempts.
 */
function accountingAccountDelete(int $tenantId, int $accountId): array
{
    if ($tenantId <= 0 || $accountId <= 0) {
        throw new \InvalidArgumentException('tenant_id + account_id required');
    }
    $pdo = getDB();

    // Tenant-bound row lookup.
    $row = $pdo->prepare(
        "SELECT id, code, name FROM accounting_accounts
          WHERE id = :id AND tenant_id = :t LIMIT 1"
    );
    $row->execute(['id' => $accountId, 't' => $tenantId]);
    $acct = $row->fetch(\PDO::FETCH_ASSOC);
    if (!$acct) {
        throw new \InvalidArgumentException("account #{$accountId} not found in tenant #{$tenantId}");
    }

    // Reference checks.
    $reasons = [];

    // tenant-leak-allow: account_id is tenant-bound by the lookup above;
    // the join below makes the tenant scope explicit so the static
    // analyzer can prove safety.
    $jl = $pdo->prepare(
        "SELECT COUNT(*)
           FROM accounting_journal_entry_lines ajel
           JOIN accounting_journal_entries aje
                ON aje.id = ajel.je_id AND aje.tenant_id = :t
          WHERE ajel.account_id = :id"
    );
    $jl->execute(['id' => $accountId, 't' => $tenantId]);
    $jlCount = (int) $jl->fetchColumn();
    if ($jlCount > 0) $reasons['journal_lines'] = $jlCount;

    // Bank accounts join via `gl_account_code` (string).  Only active
    // bank links block delete — soft-archived ones can be safely
    // detached.  We scope by tenant_id to avoid cross-tenant noise.
    $ba = $pdo->prepare(
        "SELECT COUNT(*) FROM accounting_bank_accounts
          WHERE tenant_id = :t AND gl_account_code = :c
            AND COALESCE(status, '') NOT IN ('archived', 'removed')"
    );
    $ba->execute(['t' => $tenantId, 'c' => (string) $acct['code']]);
    $baCount = (int) $ba->fetchColumn();
    if ($baCount > 0) $reasons['active_bank_accounts'] = $baCount;

    if (!empty($reasons)) {
        $err = new AccountingAccountDeleteBlockedException(
            sprintf(
                'Cannot delete account "%s" (#%d): %s. ' .
                'Use the Deactivate action to hide it from pickers without losing ledger history.',
                (string) $acct['name'], $accountId,
                implode(', ', array_map(fn($k, $v) => "{$v} {$k}", array_keys($reasons), $reasons))
            )
        );
        $err->reasons = $reasons;
        throw $err;
    }

    // Safe to delete.  Remove mappings first (no FK so we don't get a
    // cascade for free), then the account.
    // tenant-leak-allow: coreflux_account_id is tenant-bound by the
    // lookup at top of function.  We additionally scope by tenant
    // below for paranoia.
    $mDel = $pdo->prepare(
        "DELETE FROM accounting_account_mappings
          WHERE coreflux_account_id = :id AND tenant_id = :t"
    );
    $mDel->execute(['id' => $accountId, 't' => $tenantId]);
    $mappingsRemoved = $mDel->rowCount();

    $aDel = $pdo->prepare(
        "DELETE FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1"
    );
    $aDel->execute(['id' => $accountId, 't' => $tenantId]);

    return [
        'deleted'           => (int) $aDel->rowCount(),
        'mappings_removed'  => $mappingsRemoved,
    ];
}

/**
 * Soft-archive — flip `active = 0` so the row stops appearing in
 * active-account pickers but ledger history remains intact.
 *
 * @return array{deactivated: int}
 */
function accountingAccountDeactivate(int $tenantId, int $accountId): array
{
    if ($tenantId <= 0 || $accountId <= 0) {
        throw new \InvalidArgumentException('tenant_id + account_id required');
    }
    $pdo = getDB();
    $up = $pdo->prepare(
        "UPDATE accounting_accounts
            SET active = 0, updated_at = NOW()
          WHERE id = :id AND tenant_id = :t LIMIT 1"
    );
    $up->execute(['id' => $accountId, 't' => $tenantId]);
    return ['deactivated' => (int) $up->rowCount()];
}
