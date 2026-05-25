<?php
/**
 * core/sweep_rules.php — cash-allocation sweep rule CRUD.
 *
 * Operator-defined recipes for "keep $X in the operating account, sweep
 * the excess into the high-yield account every Friday." Definition
 * layer only — the worker that *executes* these (creating Mercury
 * transfer payment instructions when the source balance > target_min +
 * sweep_above) is a follow-up; the schema is execution-engine-agnostic
 * so dropping it in later won't need another migration.
 *
 * Public surface:
 *   sweepRuleList(int $tid): array
 *   sweepRuleGet(int $tid, int $id): array
 *   sweepRuleUpsert(int $tid, array $data, ?int $actor): array
 *   sweepRuleDelete(int $tid, int $id, ?int $actor): bool
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const SWEEP_RULE_FREQUENCIES = [
    'daily',
    'weekly_mon', 'weekly_tue', 'weekly_wed', 'weekly_thu', 'weekly_fri',
    'monthly_1',  'monthly_15',
];

function sweepRuleList(int $tid): array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, name, enabled, source_account_id, destination_account_id,
                    target_min_balance_cents, sweep_above_cents, frequency,
                    require_approval_policy_id,
                    last_run_at, last_outcome, last_run_amount_cents,
                    sort_order, notes, created_at, updated_at
               FROM tenant_sweep_rules
              WHERE tenant_id = :t
              ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['t' => $tid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['enabled'] = (int) $r['enabled'] === 1;
            foreach ([
                'target_min_balance_cents','sweep_above_cents',
                'last_run_amount_cents','require_approval_policy_id',
            ] as $k) {
                if ($r[$k] !== null) $r[$k] = (int) $r[$k];
            }
            $r['sort_order'] = (int) $r['sort_order'];
        }
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

function sweepRuleGet(int $tid, int $id): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT * FROM tenant_sweep_rules WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tid, 'id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('sweep rule not found');
    $row['id']      = (int) $row['id'];
    $row['enabled'] = (int) $row['enabled'] === 1;
    return $row;
}

function sweepRuleUpsert(int $tid, array $data, ?int $actor): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('name required');

    $src  = trim((string) ($data['source_account_id']      ?? ''));
    $dst  = trim((string) ($data['destination_account_id'] ?? ''));
    if ($src === '' || $dst === '') {
        throw new \InvalidArgumentException('source_account_id and destination_account_id required');
    }
    if ($src === $dst) {
        throw new \InvalidArgumentException('source and destination must be distinct accounts');
    }

    $freq = (string) ($data['frequency'] ?? 'weekly_fri');
    if (!in_array($freq, SWEEP_RULE_FREQUENCIES, true)) {
        throw new \InvalidArgumentException('frequency unknown: ' . $freq);
    }

    $minBal = isset($data['target_min_balance_cents']) && $data['target_min_balance_cents'] !== ''
        ? (int) $data['target_min_balance_cents'] : null;
    $above  = isset($data['sweep_above_cents']) && $data['sweep_above_cents'] !== ''
        ? (int) $data['sweep_above_cents'] : null;
    if ($minBal !== null && $minBal < 0) {
        throw new \InvalidArgumentException('target_min_balance_cents cannot be negative');
    }
    if ($above !== null && $above < 0) {
        throw new \InvalidArgumentException('sweep_above_cents cannot be negative');
    }

    $pdo = getDB();
    if (!empty($data['id'])) {
        $pdo->prepare(
            'UPDATE tenant_sweep_rules
                SET name = :n, enabled = :en,
                    source_account_id = :src, destination_account_id = :dst,
                    target_min_balance_cents = :mn, sweep_above_cents = :ab,
                    frequency = :f,
                    require_approval_policy_id = :pol,
                    sort_order = :so, notes = :nt
              WHERE tenant_id = :t AND id = :id'
        )->execute([
            'n'  => $name,
            'en' => isset($data['enabled']) ? (int) (bool) $data['enabled'] : 1,
            'src'=> $src, 'dst' => $dst,
            'mn' => $minBal, 'ab' => $above, 'f' => $freq,
            'pol'=> !empty($data['require_approval_policy_id']) ? (int) $data['require_approval_policy_id'] : null,
            'so' => (int) ($data['sort_order'] ?? 100),
            'nt' => $data['notes'] ?? null,
            't'  => $tid, 'id' => (int) $data['id'],
        ]);
        $id = (int) $data['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO tenant_sweep_rules
                (tenant_id, name, enabled, source_account_id, destination_account_id,
                 target_min_balance_cents, sweep_above_cents, frequency,
                 require_approval_policy_id, sort_order, notes)
             VALUES (:t, :n, :en, :src, :dst, :mn, :ab, :f, :pol, :so, :nt)'
        )->execute([
            't'  => $tid, 'n' => $name,
            'en' => isset($data['enabled']) ? (int) (bool) $data['enabled'] : 1,
            'src'=> $src, 'dst' => $dst,
            'mn' => $minBal, 'ab' => $above, 'f' => $freq,
            'pol'=> !empty($data['require_approval_policy_id']) ? (int) $data['require_approval_policy_id'] : null,
            'so' => (int) ($data['sort_order'] ?? 100),
            'nt' => $data['notes'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }
    return sweepRuleGet($tid, $id);
}

function sweepRuleDelete(int $tid, int $id, ?int $actor): bool
{
    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM tenant_sweep_rules WHERE tenant_id = :t AND id = :id');
    $stmt->execute(['t' => $tid, 'id' => $id]);
    return $stmt->rowCount() > 0;
}
