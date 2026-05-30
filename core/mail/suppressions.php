<?php
/**
 * /app/core/mail/suppressions.php
 *
 * Tenant-scoped recipient suppression list. mailerSend() consults
 * cf_mail_is_suppressed() before delivery and silently drops any
 * recipient on the list. Resend bounce / complaint webhooks
 * auto-suppress via cf_mail_suppress(). Admins can manage the list
 * from Mail Settings.
 *
 * Design notes:
 *   - Email addresses are stored normalized (lower-cased, trimmed)
 *     and looked up by exact string match. Plus-addressing is
 *     preserved (foo+ar@bar.com ≠ foo@bar.com) so partial-recipient
 *     bounces don't silence the whole inbox.
 *   - Active suppressions are rows with removed_at IS NULL. Re-
 *     suppression after manual removal creates a new row, keeping
 *     full audit history.
 *   - All helpers degrade gracefully if the table doesn't exist —
 *     callers must NOT block mail delivery on a missing table.
 */
declare(strict_types=1);

function cf_mail_normalize_email(string $email): string {
    return strtolower(trim($email));
}

/**
 * Returns true iff the recipient is on the tenant's active
 * suppression list. Safe to call from hot mail paths — single
 * indexed primary lookup.
 */
function cf_mail_is_suppressed(int $tenantId, string $email): bool {
    if ($tenantId <= 0 || $email === '') return false;
    try {
        $pdo = getDB();
        if (!$pdo) return false;
        $st = $pdo->prepare(
            'SELECT 1 FROM mail_recipient_suppressions
              WHERE tenant_id = :t
                AND email_normalized = :e
                AND removed_at IS NULL
              LIMIT 1'
        );
        $st->execute([
            't' => $tenantId,
            'e' => cf_mail_normalize_email($email),
        ]);
        return (bool) $st->fetchColumn();
    } catch (\Throwable $e) {
        // Missing table or DB hiccup must NEVER block delivery.
        return false;
    }
}

/**
 * Idempotently add (or reactivate) a suppression row.
 *
 * @param int    $tenantId
 * @param string $email
 * @param string $reason   'bounce'|'complaint'|'manual'|'api'
 * @param array  $opts     source, last_webhook_event_id, notes, created_by_user_id
 * @return ?int            inserted/reactivated row id, or null on failure
 */
function cf_mail_suppress(int $tenantId, string $email, string $reason = 'manual', array $opts = []): ?int {
    if ($tenantId <= 0 || $email === '') return null;
    $norm = cf_mail_normalize_email($email);
    if (!filter_var($norm, FILTER_VALIDATE_EMAIL)) return null;
    try {
        $pdo = getDB();
        if (!$pdo) return null;

        // Active row already present?
        $st = $pdo->prepare(
            'SELECT id FROM mail_recipient_suppressions
              WHERE tenant_id = :t AND email_normalized = :e
                AND removed_at IS NULL
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'e' => $norm]);
        $existing = $st->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }

        $st = $pdo->prepare(
            'INSERT INTO mail_recipient_suppressions
                (tenant_id, email_normalized, reason, source,
                 last_webhook_event_id, notes, created_by_user_id)
             VALUES (:t, :e, :r, :s, :w, :n, :u)'
        );
        $st->execute([
            't' => $tenantId,
            'e' => $norm,
            'r' => $reason,
            's' => (string) ($opts['source']     ?? 'system'),
            'w' => isset($opts['last_webhook_event_id']) ? (int) $opts['last_webhook_event_id'] : null,
            'n' => isset($opts['notes']) ? (string) $opts['notes'] : null,
            'u' => isset($opts['created_by_user_id']) ? (int) $opts['created_by_user_id'] : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Soft-delete the active suppression for this (tenant, email).
 * Returns true iff something was removed.
 */
function cf_mail_unsuppress(int $tenantId, string $email, array $opts = []): bool {
    if ($tenantId <= 0 || $email === '') return false;
    try {
        $pdo = getDB();
        if (!$pdo) return false;
        $st = $pdo->prepare(
            'UPDATE mail_recipient_suppressions
                SET removed_at = UTC_TIMESTAMP(),
                    removed_by_user_id = :u,
                    removed_reason = :r
              WHERE tenant_id = :t
                AND email_normalized = :e
                AND removed_at IS NULL'
        );
        $st->execute([
            't' => $tenantId,
            'e' => cf_mail_normalize_email($email),
            'u' => isset($opts['removed_by_user_id']) ? (int) $opts['removed_by_user_id'] : null,
            'r' => isset($opts['reason']) ? substr((string) $opts['reason'], 0, 120) : 'admin_unsuppress',
        ]);
        return $st->rowCount() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Filter a recipient list against the suppression table. Returns an
 * array with `delivered` (recipients that survived the filter) and
 * `suppressed` (the dropped ones with reason).
 */
function cf_mail_filter_suppressed(int $tenantId, array $recipients): array {
    $delivered  = [];
    $suppressed = [];
    if ($tenantId <= 0 || empty($recipients)) {
        return ['delivered' => array_values($recipients), 'suppressed' => []];
    }
    try {
        $pdo = getDB();
        if (!$pdo) {
            return ['delivered' => array_values($recipients), 'suppressed' => []];
        }
        // Bulk lookup so we don't N+1 the hot path.
        $norm = array_values(array_unique(array_map('cf_mail_normalize_email', $recipients)));
        $place = implode(',', array_fill(0, count($norm), '?'));
        $params = array_merge([$tenantId], $norm);
        $st = $pdo->prepare(
            "SELECT email_normalized, reason
               FROM mail_recipient_suppressions
              WHERE tenant_id = ?
                AND removed_at IS NULL
                AND email_normalized IN ({$place})"
        );
        $st->execute($params);
        $byEmail = [];
        while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
            $byEmail[(string) $r['email_normalized']] = (string) $r['reason'];
        }
        foreach ($recipients as $email) {
            $key = cf_mail_normalize_email((string) $email);
            if (isset($byEmail[$key])) {
                $suppressed[] = ['email' => $email, 'reason' => $byEmail[$key]];
            } else {
                $delivered[] = $email;
            }
        }
        return ['delivered' => $delivered, 'suppressed' => $suppressed];
    } catch (\Throwable $e) {
        return ['delivered' => array_values($recipients), 'suppressed' => []];
    }
}

/**
 * List active suppressions for a tenant. Supports limit/offset +
 * optional search across email/notes for the UI table.
 */
function cf_mail_list_suppressions(int $tenantId, int $limit = 50, int $offset = 0, string $q = ''): array {
    $out = ['total' => 0, 'rows' => []];
    if ($tenantId <= 0) return $out;
    try {
        $pdo = getDB();
        if (!$pdo) return $out;
        $params = ['t' => $tenantId];
        $where  = 'tenant_id = :t AND removed_at IS NULL';
        if ($q !== '') {
            $where .= ' AND (email_normalized LIKE :q OR notes LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $stC = $pdo->prepare("SELECT COUNT(*) FROM mail_recipient_suppressions WHERE {$where}");
        $stC->execute($params);
        $out['total'] = (int) $stC->fetchColumn();

        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $st = $pdo->prepare(
            "SELECT id, email_normalized AS email, reason, source,
                    notes, created_at, last_webhook_event_id
               FROM mail_recipient_suppressions
              WHERE {$where}
           ORDER BY id DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute($params);
        while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
            $r['id'] = (int) $r['id'];
            $r['last_webhook_event_id'] = $r['last_webhook_event_id'] !== null ? (int) $r['last_webhook_event_id'] : null;
            $out['rows'][] = $r;
        }
    } catch (\Throwable $e) {
        // Missing table — leave the empty shell so the UI can show
        // "No suppressions yet" instead of 500.
    }
    return $out;
}
