<?php
/**
 * core/approval_policy.php — SoD threshold engine for payment_instructions.
 *
 * Tenants configure rules that gate mpApprove(): amount thresholds →
 * required role, N-of-M co-approver chain, cool-off window before the
 * worker auto-advances. Resolved per-payment at approval time.
 *
 * Public surface:
 *   approvalPolicyList(int $tid, string $integration = 'mercury'): array
 *   approvalPolicyUpsert(int $tid, array $data, ?int $actor): array
 *   approvalPolicyDelete(int $tid, int $id, ?int $actor): bool
 *   approvalPolicyResolve(int $tid, int $amountCents, ?int $recipientId,
 *                         ?string $accountId, string $integration='mercury'): ?array
 *   approvalRecordAck(int $tid, int $instructionId, int $userId,
 *                     ?int $policyId, ?string $note): int
 *   approvalListAcksFor(int $tid, int $instructionId): array
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const APPROVAL_POLICY_INTEGRATIONS = ['mercury'];
const APPROVAL_POLICY_ROLES        = [
    null, '', 'master_admin', 'cfo', 'treasury_admin', 'controller',
    'accountant', 'ap_clerk',
];

function approvalPolicyList(int $tid, string $integration = 'mercury'): array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, name, integration, enabled,
                    min_amount_cents, max_amount_cents,
                    required_approver_role, min_approvers, cool_off_minutes,
                    applies_to_recipient_id, applies_to_account_id,
                    sort_order, notes, created_at, updated_at
               FROM tenant_approval_policies
              WHERE tenant_id = :t AND integration = :i
              ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['t' => $tid, 'i' => $integration]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']            = (int) $r['id'];
            $r['enabled']       = (int) $r['enabled'] === 1;
            $r['min_amount_cents'] = $r['min_amount_cents'] !== null ? (int) $r['min_amount_cents'] : null;
            $r['max_amount_cents'] = $r['max_amount_cents'] !== null ? (int) $r['max_amount_cents'] : null;
            $r['min_approvers']    = (int) $r['min_approvers'];
            $r['cool_off_minutes'] = (int) $r['cool_off_minutes'];
            $r['applies_to_recipient_id'] = $r['applies_to_recipient_id'] !== null
                ? (int) $r['applies_to_recipient_id'] : null;
            $r['sort_order'] = (int) $r['sort_order'];
        }
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

function approvalPolicyUpsert(int $tid, array $data, ?int $actor): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') throw new \InvalidArgumentException('name required');
    $integration = trim((string) ($data['integration'] ?? 'mercury'));
    if (!in_array($integration, APPROVAL_POLICY_INTEGRATIONS, true)) {
        throw new \InvalidArgumentException('integration not supported: ' . $integration);
    }
    $minA = isset($data['min_amount_cents']) && $data['min_amount_cents'] !== ''
        ? (int) $data['min_amount_cents'] : null;
    $maxA = isset($data['max_amount_cents']) && $data['max_amount_cents'] !== ''
        ? (int) $data['max_amount_cents'] : null;
    if ($minA !== null && $maxA !== null && $minA > $maxA) {
        throw new \InvalidArgumentException('min_amount_cents must be <= max_amount_cents');
    }
    $role = isset($data['required_approver_role']) && $data['required_approver_role'] !== ''
        ? (string) $data['required_approver_role'] : null;
    if (!in_array($role, APPROVAL_POLICY_ROLES, true)) {
        throw new \InvalidArgumentException('required_approver_role unknown: ' . $role);
    }
    $minApprovers = max(1, min(5, (int) ($data['min_approvers'] ?? 1)));
    $coolOff      = max(0, (int) ($data['cool_off_minutes'] ?? 0));

    $pdo = getDB();
    if (!empty($data['id'])) {
        $pdo->prepare(
            'UPDATE tenant_approval_policies
                SET name = :n, integration = :i, enabled = :en,
                    min_amount_cents = :mn, max_amount_cents = :mx,
                    required_approver_role = :rr, min_approvers = :ma,
                    cool_off_minutes = :co,
                    applies_to_recipient_id = :ar, applies_to_account_id = :aa,
                    sort_order = :so, notes = :nt
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'n'  => $name, 'i' => $integration,
            'en' => isset($data['enabled']) ? (int) (bool) $data['enabled'] : 1,
            'mn' => $minA, 'mx' => $maxA,
            'rr' => $role, 'ma' => $minApprovers, 'co' => $coolOff,
            'ar' => !empty($data['applies_to_recipient_id']) ? (int) $data['applies_to_recipient_id'] : null,
            'aa' => !empty($data['applies_to_account_id'])   ? (string) $data['applies_to_account_id'] : null,
            'so' => (int) ($data['sort_order'] ?? 100),
            'nt' => $data['notes'] ?? null,
            'id' => (int) $data['id'], 't' => $tid,
        ]);
        $id = (int) $data['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO tenant_approval_policies
                (tenant_id, name, integration, enabled,
                 min_amount_cents, max_amount_cents,
                 required_approver_role, min_approvers, cool_off_minutes,
                 applies_to_recipient_id, applies_to_account_id,
                 sort_order, notes)
             VALUES (:t, :n, :i, :en, :mn, :mx, :rr, :ma, :co, :ar, :aa, :so, :nt)'
        )->execute([
            't'  => $tid, 'n' => $name, 'i' => $integration,
            'en' => isset($data['enabled']) ? (int) (bool) $data['enabled'] : 1,
            'mn' => $minA, 'mx' => $maxA,
            'rr' => $role, 'ma' => $minApprovers, 'co' => $coolOff,
            'ar' => !empty($data['applies_to_recipient_id']) ? (int) $data['applies_to_recipient_id'] : null,
            'aa' => !empty($data['applies_to_account_id'])   ? (string) $data['applies_to_account_id'] : null,
            'so' => (int) ($data['sort_order'] ?? 100),
            'nt' => $data['notes'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }
    return approvalPolicyGet($tid, $id);
}

function approvalPolicyGet(int $tid, int $id): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM tenant_approval_policies WHERE tenant_id = :t AND id = :id LIMIT 1');
    $stmt->execute(['t' => $tid, 'id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('policy not found');
    $row['id']      = (int) $row['id'];
    $row['enabled'] = (int) $row['enabled'] === 1;
    return $row;
}

function approvalPolicyDelete(int $tid, int $id, ?int $actor): bool
{
    $pdo = getDB();
    $stmt = $pdo->prepare('DELETE FROM tenant_approval_policies WHERE tenant_id = :t AND id = :id');
    $stmt->execute(['t' => $tid, 'id' => $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Pick the most-specific applicable policy for this payment. Returns null
 * when no policy applies — caller falls back to the legacy single-approver
 * default (creator ≠ approver + treasury.payment.approve permission).
 *
 * Specificity order:
 *   1. recipient match  + (account match OR null)
 *   2. account match    + recipient null
 *   3. broad rule       (both null)
 * Ties broken by sort_order ASC then id ASC.
 */
function approvalPolicyResolve(
    int $tid,
    int $amountCents,
    ?int $recipientId,
    ?string $accountId,
    string $integration = 'mercury'
): ?array {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT * FROM tenant_approval_policies
              WHERE tenant_id = :t AND integration = :i AND enabled = 1
                AND (min_amount_cents IS NULL OR min_amount_cents <= :amt_min)
                AND (max_amount_cents IS NULL OR max_amount_cents >= :amt_max)
                AND (applies_to_recipient_id IS NULL OR applies_to_recipient_id = :r)
                AND (applies_to_account_id   IS NULL OR applies_to_account_id   = :ac)
              ORDER BY
                (applies_to_recipient_id IS NOT NULL) DESC,
                (applies_to_account_id   IS NOT NULL) DESC,
                sort_order ASC, id ASC
              LIMIT 1'
        );
        $stmt->execute([
            't' => $tid, 'i' => $integration,
            'amt_min' => $amountCents, 'amt_max' => $amountCents,
            'r' => $recipientId, 'ac' => $accountId,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['id']               = (int) $row['id'];
        $row['enabled']          = (int) $row['enabled'] === 1;
        $row['min_amount_cents'] = $row['min_amount_cents'] !== null ? (int) $row['min_amount_cents'] : null;
        $row['max_amount_cents'] = $row['max_amount_cents'] !== null ? (int) $row['max_amount_cents'] : null;
        $row['min_approvers']    = (int) $row['min_approvers'];
        $row['cool_off_minutes'] = (int) $row['cool_off_minutes'];
        return $row;
    } catch (\Throwable $e) {
        return null;
    }
}

function approvalRecordAck(int $tid, int $instructionId, int $userId,
                           ?int $policyId, ?string $note): int
{
    $pdo = getDB();
    try {
        $pdo->prepare(
            'INSERT INTO payment_instruction_approvals
                (tenant_id, instruction_id, user_id, note, policy_id)
             VALUES (:t, :i, :u, :n, :p)'
        )->execute([
            't' => $tid, 'i' => $instructionId, 'u' => $userId,
            'n' => $note !== null ? mb_substr($note, 0, 500) : null,
            'p' => $policyId,
        ]);
    } catch (\PDOException $e) {
        // Duplicate (user already acked this instruction) — fall through to count.
        if (!str_contains($e->getMessage(), 'Duplicate')) throw $e;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM payment_instruction_approvals
          WHERE tenant_id = :t AND instruction_id = :i'
    );
    $stmt->execute(['t' => $tid, 'i' => $instructionId]);
    return (int) $stmt->fetchColumn();
}

function approvalListAcksFor(int $tid, int $instructionId): array
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, note, policy_id, created_at
               FROM payment_instruction_approvals
              WHERE tenant_id = :t AND instruction_id = :i
              ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['t' => $tid, 'i' => $instructionId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['user_id'] = (int) $r['user_id'];
            if ($r['policy_id'] !== null) $r['policy_id'] = (int) $r['policy_id'];
        }
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}
