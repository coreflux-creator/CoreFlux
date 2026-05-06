<?php
/**
 * Generic approval-tokens primitive (Sprint 4 / A2).
 *
 * Lets any module issue a one-time-use signed URL that an external party
 * (manager, client, vendor) can click to approve/reject without having
 * to log in. Generalizes the existing time-module tokenized-approval
 * pattern.
 *
 *   approvalTokenIssue($tenantId, $subjectType, $subjectId, $userId|null,
 *                      $email|null, $actions, $ttlHours, $opts) → [raw_token, row]
 *   approvalTokenLookup($rawToken)         → row or null
 *   approvalTokenConsume($rawToken, $action, $ip) → row (throws on invalid)
 *
 * VERTICAL-AGNOSTIC. Tokens are sha256-hashed at rest; raw token never
 * persists in the database.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Issue a token. Returns ['token' => RAW, 'row' => persisted_record].
 *
 * @param array $actions e.g. ['approve','reject','comment'] — the actions the
 *                       recipient is permitted to perform with this token
 */
function approvalTokenIssue(
    int $tenantId,
    string $subjectType,
    int $subjectId,
    ?int $actorUserId,
    ?string $actorEmail,
    array $actions,
    int $ttlHours = 72,
    array $opts = []
): array {
    if (!$actions) throw new \InvalidArgumentException('actions required');
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $exp  = (new DateTimeImmutable("+{$ttlHours} hours"))->format('Y-m-d H:i:s');

    $pdo->prepare(
        "INSERT INTO approval_tokens
           (tenant_id, token_hash, subject_type, subject_id, workflow_instance_id,
            actor_user_id, actor_email, actions_json, issued_by_user_id,
            issued_at, expires_at)
         VALUES
           (:t, :h, :st, :si, :wi, :au, :ae, :a, :ib, NOW(), :ex)"
    )->execute([
        't'  => $tenantId,
        'h'  => $hash,
        'st' => $subjectType,
        'si' => $subjectId,
        'wi' => isset($opts['workflow_instance_id']) ? (int) $opts['workflow_instance_id'] : null,
        'au' => $actorUserId,
        'ae' => $actorEmail,
        'a'  => json_encode(array_values($actions), JSON_UNESCAPED_SLASHES),
        'ib' => $opts['issued_by_user_id'] ?? null,
        'ex' => $exp,
    ]);
    $id = (int) $pdo->lastInsertId();
    return [
        'token'      => $raw,
        'token_id'   => $id,
        'expires_at' => $exp,
        'subject'    => "{$subjectType}#{$subjectId}",
    ];
}

/**
 * Lookup by raw token. Returns the row (without leaking the hash) or
 * null. Does not consume.
 */
function approvalTokenLookup(string $rawToken): ?array {
    $pdo = getDB();
    if (!$pdo) return null;
    $hash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare(
        "SELECT id, tenant_id, subject_type, subject_id, workflow_instance_id,
                actor_user_id, actor_email, actions_json, issued_at, expires_at,
                consumed_at, consumed_via_action
           FROM approval_tokens
          WHERE token_hash = :h LIMIT 1"
    );
    $stmt->execute(['h' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;
    $row['actions'] = json_decode((string) $row['actions_json'], true) ?: [];
    return $row;
}

/**
 * Consume a token for one action. Idempotent — calling twice returns the
 * same row but does not let the action fire again.
 *
 * @return array  { row, allowed:bool, reason:?string }
 */
function approvalTokenConsume(string $rawToken, string $action, ?string $ip = null): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $row = approvalTokenLookup($rawToken);
    if (!$row) return ['row' => null, 'allowed' => false, 'reason' => 'token_not_found'];
    if ($row['consumed_at'])                  return ['row' => $row, 'allowed' => false, 'reason' => 'already_consumed'];
    if (strtotime((string) $row['expires_at']) < time()) return ['row' => $row, 'allowed' => false, 'reason' => 'expired'];
    if (!in_array($action, $row['actions'], true)) return ['row' => $row, 'allowed' => false, 'reason' => 'action_not_permitted'];

    $upd = $pdo->prepare(
        "UPDATE approval_tokens
            SET consumed_at = NOW(),
                consumed_via_action = :a,
                consumed_ip = :ip
          WHERE id = :id AND consumed_at IS NULL"
    );
    $upd->execute(['a' => $action, 'ip' => $ip, 'id' => (int) $row['id']]);
    if ($upd->rowCount() === 0) {
        // Race — re-read.
        $row = approvalTokenLookup($rawToken);
        return ['row' => $row, 'allowed' => false, 'reason' => 'already_consumed'];
    }
    $row['consumed_at']         = date('Y-m-d H:i:s');
    $row['consumed_via_action'] = $action;
    return ['row' => $row, 'allowed' => true, 'reason' => null];
}
