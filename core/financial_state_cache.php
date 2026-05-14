<?php
/**
 * core/financial_state_cache.php — Unified Financial State Cache (Phase 2).
 *
 * Fast-read projection of the financial state. Built on top of the strictly
 * clean event-driven ledger (post-Phase 2a Module Emission Discipline).
 *
 * Read path for the CFO Dashboard, Phase 2 AI rule evaluation, period close,
 * and the External Auditor view (P3). Eliminates the "every dashboard load
 * re-aggregates the whole event stream" performance ceiling.
 *
 * Public API (all functions take a tenant id first, all writes are
 * idempotent and tenant-scoped):
 *
 *   fscRead($tid, $scopeKey, $scopeValue, $metricKey = null)
 *       Single metric or all-for-scope. Auto-rebuilds if scope is dirty.
 *
 *   fscWrite($tid, $scopeKey, $scopeValue, $metricKey, $numeric, $json, $sourceHash, $ms)
 *       Upsert one row. Used by builders; not normally called directly.
 *
 *   fscMarkDirty($tid, $scopeKey, $scopeValue, $reason = null, $userId = null)
 *       Append a dirty-log row. Cheap. Called from accountingPostJe etc.
 *
 *   fscIsDirty($tid, $scopeKey, $scopeValue): bool
 *       Returns true if there are unprocessed dirty entries.
 *
 *   fscRebuild($tid, $scopeKey, $scopeValue): array
 *       Recompute all metrics for the scope, clear dirty log atomically.
 *       Returns ['metrics_written' => int, 'ms' => int].
 *
 *   fscBuildPeriodAccountBalances($tid, $periodId): array
 *   fscBuildPeriodKpis($tid, $periodId): array
 *       Concrete builders. Read from accounting_journal_entries + lines
 *       (posted only). Pure functions of the ledger; deterministic.
 *
 * All readers degrade gracefully if migration 045 hasn't run on this tenant
 * (table-missing → returns empty). Same pattern as event_registry.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const FSC_SCOPE_PERIOD = 'period_id';
const FSC_SCOPE_TENANT = 'tenant';
const FSC_SCOPE_ENTITY = 'entity_id';

/**
 * Read one metric or all metrics for a scope.
 *
 * @return array|null  Single-metric: ['numeric_value'=>..., 'json_value'=>..., 'computed_at'=>..., ...] or null.
 *                     All-for-scope: ['metric_key' => row, ...]
 */
function fscRead(int $tenantId, string $scopeKey, string $scopeValue, ?string $metricKey = null, bool $autoRebuild = true): ?array
{
    // Auto-rebuild if dirty. Lazy-on-read is the simplest invalidation
    // strategy that still gives operators fresh numbers without a cron.
    if ($autoRebuild && fscIsDirty($tenantId, $scopeKey, $scopeValue)) {
        try { fscRebuild($tenantId, $scopeKey, $scopeValue); }
        catch (\Throwable $_) { /* fall through to stale read */ }
    }

    $pdo = getDB();
    try {
        if ($metricKey !== null) {
            $stmt = $pdo->prepare(
                'SELECT metric_key, numeric_value, json_value, source_hash, computed_at, computed_ms
                   FROM financial_state_cache
                  WHERE tenant_id = :t AND scope_key = :sk AND scope_value = :sv AND metric_key = :mk
                  LIMIT 1'
            );
            $stmt->execute(['t' => $tenantId, 'sk' => $scopeKey, 'sv' => $scopeValue, 'mk' => $metricKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) return null;
            $row['numeric_value'] = $row['numeric_value'] !== null ? (float) $row['numeric_value'] : null;
            $row['json_value']    = $row['json_value']    !== null ? json_decode((string) $row['json_value'], true) : null;
            return $row;
        }
        $stmt = $pdo->prepare(
            'SELECT metric_key, numeric_value, json_value, source_hash, computed_at, computed_ms
               FROM financial_state_cache
              WHERE tenant_id = :t AND scope_key = :sk AND scope_value = :sv
              ORDER BY metric_key'
        );
        $stmt->execute(['t' => $tenantId, 'sk' => $scopeKey, 'sv' => $scopeValue]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['numeric_value'] = $row['numeric_value'] !== null ? (float) $row['numeric_value'] : null;
            $row['json_value']    = $row['json_value']    !== null ? json_decode((string) $row['json_value'], true) : null;
            $out[$row['metric_key']] = $row;
        }
        return $out;
    } catch (\Throwable $_) {
        // Table missing (migration 045 hasn't run). Degrade gracefully.
        return $metricKey !== null ? null : [];
    }
}

/**
 * Upsert one cache row. Idempotent. Same scope+metric_key replaces in place.
 */
function fscWrite(int $tenantId, string $scopeKey, string $scopeValue, string $metricKey,
                  ?float $numeric, ?array $json, ?string $sourceHash, ?int $computedMs = null): void
{
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO financial_state_cache
            (tenant_id, scope_key, scope_value, metric_key, numeric_value, json_value, source_hash, computed_at, computed_ms)
         VALUES
            (:t, :sk, :sv, :mk, :n, :j, :h, NOW(), :ms)
         ON DUPLICATE KEY UPDATE
            numeric_value = VALUES(numeric_value),
            json_value    = VALUES(json_value),
            source_hash   = VALUES(source_hash),
            computed_at   = NOW(),
            computed_ms   = VALUES(computed_ms)'
    )->execute([
        't'  => $tenantId,
        'sk' => $scopeKey,
        'sv' => $scopeValue,
        'mk' => $metricKey,
        'n'  => $numeric,
        'j'  => $json !== null ? json_encode($json) : null,
        'h'  => $sourceHash,
        'ms' => $computedMs,
    ]);
}

/**
 * Append a dirty-log entry. Cheap — single INSERT, no SELECT.
 * Safe to spam from event handlers; rebuilder collapses duplicates.
 */
function fscMarkDirty(int $tenantId, string $scopeKey, string $scopeValue, ?string $reason = null, ?int $userId = null): void
{
    try {
        getDB()->prepare(
            'INSERT INTO financial_state_cache_dirty
                (tenant_id, scope_key, scope_value, reason, marked_by_user_id)
             VALUES (:t, :sk, :sv, :r, :u)'
        )->execute([
            't'  => $tenantId,
            'sk' => $scopeKey,
            'sv' => $scopeValue,
            'r'  => $reason,
            'u'  => $userId,
        ]);
    } catch (\Throwable $_) {
        // Migration 045 not run on this tenant yet — silent. Real callers
        // (accountingPostJe) wrap us in their own try/catch so a missing
        // table can never block a JE post.
    }
}

/**
 * Does this scope have unprocessed dirty entries?
 */
function fscIsDirty(int $tenantId, string $scopeKey, string $scopeValue): bool
{
    try {
        $stmt = getDB()->prepare(
            'SELECT 1 FROM financial_state_cache_dirty
              WHERE tenant_id = :t AND scope_key = :sk AND scope_value = :sv
              LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'sk' => $scopeKey, 'sv' => $scopeValue]);
        return $stmt->fetchColumn() !== false;
    } catch (\Throwable $_) {
        return false;
    }
}

/**
 * Rebuild all metrics for a scope from the source of truth (posted JEs)
 * and atomically clear the dirty log. Currently dispatches on scope_key:
 *
 *   period_id → fscBuildPeriodAccountBalances + fscBuildPeriodKpis
 *
 * Future: tenant-wide YTD scope, entity-scoped balances, etc. Add new
 * builders here.
 */
function fscRebuild(int $tenantId, string $scopeKey, string $scopeValue): array
{
    $start = microtime(true);
    $written = 0;

    switch ($scopeKey) {
        case FSC_SCOPE_PERIOD:
            $written += fscBuildPeriodAccountBalances($tenantId, (int) $scopeValue);
            $written += fscBuildPeriodKpis($tenantId, (int) $scopeValue);
            break;
        case FSC_SCOPE_TENANT:
            // Reserved — implement when YTD-tenant scope is needed.
            break;
        default:
            throw new \InvalidArgumentException("fscRebuild: unsupported scope_key '{$scopeKey}'");
    }

    // Clear the dirty log AFTER successful rebuild. If the rebuilders
    // threw, we leave the dirty entries in place so the next read tries again.
    try {
        getDB()->prepare(
            'DELETE FROM financial_state_cache_dirty
              WHERE tenant_id = :t AND scope_key = :sk AND scope_value = :sv'
        )->execute(['t' => $tenantId, 'sk' => $scopeKey, 'sv' => $scopeValue]);
    } catch (\Throwable $_) { /* table missing — fine */ }

    return [
        'metrics_written' => $written,
        'ms'              => (int) round((microtime(true) - $start) * 1000),
    ];
}

/**
 * Build per-account debit/credit/balance for one period. Reads from
 * posted journal entries only (status='posted', excludes draft + void).
 * Reversed JEs ARE included — their reversal partners cancel them
 * mathematically, so the net is correct.
 *
 * Writes one row per (account_id) under metric_key 'account_balance.{id}'
 * with json_value: { debit, credit, balance, je_count }.
 *
 * Returns the number of rows written.
 */
function fscBuildPeriodAccountBalances(int $tenantId, int $periodId): int
{
    $pdo = getDB();
    $start = microtime(true);

    $stmt = $pdo->prepare(
        'SELECT l.account_id,
                COALESCE(SUM(l.debit),  0) AS debit_total,
                COALESCE(SUM(l.credit), 0) AS credit_total,
                COUNT(DISTINCT l.je_id)    AS je_count,
                MAX(je.updated_at)         AS last_je_updated
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries     je ON je.id = l.je_id
          WHERE je.tenant_id = :t
            AND je.period_id = :p
            AND je.status    = "posted"
          GROUP BY l.account_id'
    );
    $stmt->execute(['t' => $tenantId, 'p' => $periodId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Need account normal_side to compute the directional balance.
    $accStmt = $pdo->prepare(
        'SELECT id, code, name, account_type, normal_side
           FROM accounting_accounts WHERE tenant_id = :t'
    );
    $accStmt->execute(['t' => $tenantId]);
    $byId = [];
    foreach ($accStmt->fetchAll(\PDO::FETCH_ASSOC) as $a) {
        $byId[(int) $a['id']] = $a;
    }

    $written = 0;
    $ms = (int) round((microtime(true) - $start) * 1000);
    foreach ($rows as $r) {
        $accId  = (int) $r['account_id'];
        $debit  = (float) $r['debit_total'];
        $credit = (float) $r['credit_total'];
        $acc    = $byId[$accId] ?? null;
        $side   = $acc['normal_side'] ?? 'debit';
        // Directional balance: debit-side accounts (asset/expense) → debit - credit;
        // credit-side accounts (liability/equity/revenue) → credit - debit.
        $balance = $side === 'credit' ? ($credit - $debit) : ($debit - $credit);

        $hash = hash('sha256', sprintf('%d|%d|%.2f|%.2f|%s',
            $accId, (int) $r['je_count'], $debit, $credit, (string) $r['last_je_updated']));

        fscWrite(
            $tenantId, FSC_SCOPE_PERIOD, (string) $periodId,
            "account_balance.{$accId}",
            round($balance, 4),
            [
                'account_id'   => $accId,
                'code'         => $acc['code']         ?? null,
                'name'         => $acc['name']         ?? null,
                'account_type' => $acc['account_type'] ?? null,
                'debit'        => round($debit,  4),
                'credit'       => round($credit, 4),
                'je_count'     => (int) $r['je_count'],
            ],
            $hash,
            $ms
        );
        $written++;
    }
    return $written;
}

/**
 * Roll up per-account balances into headline KPIs for one period:
 * revenue.posted, expense.posted, net_income, asset_balance, liability_balance.
 *
 * Reads from financial_state_cache rows just written by
 * fscBuildPeriodAccountBalances — must run after, never before.
 *
 * Returns the number of KPI rows written.
 */
function fscBuildPeriodKpis(int $tenantId, int $periodId): int
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT metric_key, numeric_value, json_value
           FROM financial_state_cache
          WHERE tenant_id = :t AND scope_key = :sk AND scope_value = :sv
            AND metric_key LIKE "account_balance.%"'
    );
    $stmt->execute([
        't'  => $tenantId,
        'sk' => FSC_SCOPE_PERIOD,
        'sv' => (string) $periodId,
    ]);

    $totals = [
        'asset'     => 0.0,
        'liability' => 0.0,
        'equity'    => 0.0,
        'revenue'   => 0.0,
        'expense'   => 0.0,
    ];
    $accountCount = 0;
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $j = json_decode((string) $row['json_value'], true) ?: [];
        $type = (string) ($j['account_type'] ?? '');
        if (!isset($totals[$type])) continue;
        $totals[$type] += (float) $row['numeric_value'];
        $accountCount++;
    }

    $netIncome = round($totals['revenue'] - $totals['expense'], 2);

    $kpis = [
        'revenue.posted'         => $totals['revenue'],
        'expense.posted'         => $totals['expense'],
        'net_income'             => $netIncome,
        'asset_balance'          => $totals['asset'],
        'liability_balance'      => $totals['liability'],
        'equity_balance'         => $totals['equity'],
    ];

    $hash = hash('sha256', json_encode(array_merge($totals, ['_count' => $accountCount])));

    foreach ($kpis as $key => $val) {
        fscWrite(
            $tenantId, FSC_SCOPE_PERIOD, (string) $periodId,
            $key,
            round((float) $val, 4),
            ['source_account_count' => $accountCount],
            $hash,
            null
        );
    }
    return count($kpis);
}
