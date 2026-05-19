<?php
/**
 * Time API — tokenized client approval (SPEC §5.5).
 *
 *   POST /api/time/approval_tokens?action=issue
 *        body: {period_id, placement_id, entry_ids: [...], ttl_days?: int}
 *        → creates token, sends email, returns {token_id, email_status}
 *
 *   GET  /api/time/approval_tokens?action=verify&t=<raw>      (PUBLIC — no auth)
 *        → returns {status, placement, entries[], expires_at, total_hours}
 *          intended for the public approval page preview.
 *
 *   POST /api/time/approval_tokens?action=respond             (PUBLIC — no auth)
 *        body: {t: raw, action: 'approve'|'reject', note?: string}
 *        → flips response, flips entries to approved/rejected.
 *
 *   POST /api/time/approval_tokens?action=revoke&id=N
 *        → gated by time.tokenized_email.revoke
 *
 *   GET  /api/time/approval_tokens?placement_id=N&period_id=N (authed list)
 *
 * SPEC: /app/modules/time/SPEC.md §3.6, §5.5
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/time.php';
require_once __DIR__ . '/../lib/approval_tokens.php';

$method = api_method();
$action = $_GET['action'] ?? '';

// -----------------------------------------------------------------------------
// PUBLIC endpoints (no auth — the token IS the credential)
// -----------------------------------------------------------------------------
if ($method === 'GET' && $action === 'verify') {
    $raw = (string) ($_GET['t'] ?? '');
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) api_error('Invalid token', 400);
    $row = timeTokenFindByRaw($raw);
    if (!$row) api_error('Token not found', 404);

    $now = date('Y-m-d H:i:s');
    if ($row['response'] === 'pending' && $row['expires_at'] < $now) {
        $pdo = getDB();
        $pdo->prepare('UPDATE time_approval_tokens SET response = "expired" WHERE id = :id')->execute(['id' => $row['id']]);
        $row['response'] = 'expired';
    }

    $placement = null;
    $entries = [];
    $entryIds = json_decode((string) $row['entries_json'], true)['entry_ids'] ?? [];
    $pdo = getDB();
    if ($pdo) {
        $pstmt = $pdo->prepare('SELECT p.title, p.end_client_name, pe.first_name, pe.last_name
                                FROM placements p
                                LEFT JOIN people pe ON pe.id = (SELECT person_id FROM placements WHERE id = p.id LIMIT 1)
                                WHERE p.id = :id AND p.tenant_id = :tid');
        $pstmt->execute(['id' => $row['placement_id'], 'tid' => $row['tenant_id']]);
        $placement = $pstmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if (!empty($entryIds)) {
            $in = implode(',', array_map('intval', $entryIds));
            $estmt = $pdo->query("SELECT id, work_date, category, hours, description
                                  FROM time_entries
                                  WHERE tenant_id = {$row['tenant_id']}
                                    AND id IN ({$in})
                                  ORDER BY work_date, id");
            $entries = $estmt ? $estmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        }
    }

    api_ok([
        'status'              => $row['response'],
        'expires_at'          => $row['expires_at'],
        'total_hours'         => (float) $row['entries_total_hours'],
        'client_approver_email' => $row['client_approver_email'],
        'placement'           => $placement,
        'entries'             => $entries,
    ]);
}

if ($method === 'POST' && $action === 'respond') {
    $body   = api_json_body();
    $raw    = (string) ($body['t'] ?? '');
    $choice = (string) ($body['action'] ?? '');
    $note   = isset($body['note']) ? substr((string) $body['note'], 0, 500) : null;
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) api_error('Invalid token', 400);
    if (!in_array($choice, ['approve', 'reject'], true)) api_error('action must be approve or reject', 400);

    $row = timeTokenFindByRaw($raw);
    if (!$row) api_error('Token not found', 404);
    if ($row['response'] !== 'pending') api_error('Token already used: ' . $row['response'], 409);
    if ($row['expires_at'] < date('Y-m-d H:i:s')) {
        $pdo = getDB();
        $pdo->prepare('UPDATE time_approval_tokens SET response = "expired" WHERE id = :id')->execute(['id' => $row['id']]);
        api_error('Token expired', 410);
    }

    $pdo = getDB();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $entryIds = json_decode((string) $row['entries_json'], true)['entry_ids'] ?? [];
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE time_approval_tokens
             SET response = :r, responded_at = NOW(), responder_ip = :ip,
                 responder_user_agent = :ua, responder_note = :note
             WHERE id = :id AND response = "pending"'
        );
        $stmt->execute([
            'r' => $choice === 'approve' ? 'approved' : 'rejected',
            'ip' => $ip, 'ua' => $ua, 'note' => $note, 'id' => $row['id'],
        ]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            api_error('Token already used', 409);
        }

        if (!empty($entryIds)) {
            $in = implode(',', array_map('intval', $entryIds));
            if ($choice === 'approve') {
                $pdo->exec(
                    "UPDATE time_entries
                     SET status = 'approved', approved_at = NOW(),
                         approved_via = 'tokenized_client_email',
                         client_approver_email = " . $pdo->quote($row['client_approver_email']) . "
                     WHERE tenant_id = {$row['tenant_id']}
                       AND placement_id = {$row['placement_id']}
                       AND period_id = {$row['period_id']}
                       AND id IN ({$in})
                       AND status = 'pending_review'"
                );
            } else {
                $pdo->exec(
                    "UPDATE time_entries
                     SET status = 'rejected',
                         rejected_reason = " . $pdo->quote($note ?? 'Client rejected via tokenized email') . "
                     WHERE tenant_id = {$row['tenant_id']}
                       AND id IN ({$in})
                       AND status = 'pending_review'"
                );
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Public audit: use raw PDO (no tenant scope available here beyond the row).
    try {
        $pdo->prepare('INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
                       VALUES (:tenant_id, NULL, :event, :target_id, :meta_json, :ip, NOW())')
            ->execute([
                'tenant_id' => $row['tenant_id'],
                'event'     => 'time.entry.approved.via_token',
                'target_id' => $row['id'],
                'meta_json' => json_encode([
                    'token_id'    => $row['id'],
                    'placement_id'=> $row['placement_id'],
                    'period_id'   => $row['period_id'],
                    'entry_ids'   => $entryIds,
                    'choice'      => $choice,
                    'email'       => $row['client_approver_email'],
                ]),
                'ip'        => $ip,
            ]);
    } catch (\Throwable $e) { error_log('[time.token.respond] audit failed: ' . $e->getMessage()); }

    api_ok([
        'ok'       => true,
        'response' => $choice === 'approve' ? 'approved' : 'rejected',
    ]);
}

// -----------------------------------------------------------------------------
// AUTHED endpoints
// -----------------------------------------------------------------------------
$ctx  = api_require_auth();
$user = $ctx['user'];

if ($method === 'GET') {
    rbac_legacy_require($user, 'time.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['placement_id'])) { $where[] = 'placement_id = :plid'; $params['plid'] = (int) $_GET['placement_id']; }
    if (!empty($_GET['period_id']))    { $where[] = 'period_id    = :per';  $params['per']  = (int) $_GET['period_id']; }
    $rows = scopedQuery(
        'SELECT id, placement_id, period_id, client_approver_email, issued_at, expires_at,
                response, responded_at, email_status, entries_total_hours
         FROM time_approval_tokens WHERE ' . implode(' AND ', $where) .
        ' ORDER BY issued_at DESC LIMIT 200',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'issue') {
    rbac_legacy_require($user, 'time.tokenized_email.issue');
    $body = api_json_body();
    api_require_fields($body, ['placement_id', 'period_id', 'entry_ids']);

    $placementId = (int) $body['placement_id'];
    $periodId    = (int) $body['period_id'];
    $entryIds    = array_values(array_filter(array_map('intval', (array) $body['entry_ids'])));
    $ttlDays     = max(1, min(30, (int) ($body['ttl_days'] ?? 7)));
    if (empty($entryIds)) api_error('entry_ids required', 422);

    $placement = scopedFind(
        'SELECT p.id, p.title, p.end_client_name, p.person_id, p.client_approver_name,
                p.client_approver_email, p.tokenized_email_approval_enabled,
                pe.first_name, pe.last_name
         FROM placements p
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
         WHERE p.tenant_id = :tenant_id AND p.id = :id',
        ['id' => $placementId]
    );
    if (!$placement) api_error('Placement not found', 404);
    if ((int) ($placement['tokenized_email_approval_enabled'] ?? 0) !== 1) {
        api_error('Tokenized email approval is disabled for this placement. Enable it in Placement → Approval tab.', 422);
    }
    if (empty($placement['client_approver_email']) || !filter_var($placement['client_approver_email'], FILTER_VALIDATE_EMAIL)) {
        api_error('Placement has no valid client_approver_email. Set it in Placement → Approval tab.', 422);
    }

    $entries = timeTokenCollectEntries($placementId, $periodId, $entryIds);
    if (count($entries) !== count($entryIds)) {
        api_error('Some entries are not pending_review, or not on this placement/period.', 422, [
            'expected' => count($entryIds), 'found' => count($entries),
        ]);
    }
    $totalHours = 0.0;
    foreach ($entries as $e) { $totalHours += (float) $e['hours']; }

    $gen  = timeTokenGenerate();
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlDays * 86400);

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO time_approval_tokens
          (tenant_id, placement_id, period_id, client_approver_email,
           token, token_hash, entries_json, entries_total_hours,
           issued_by_user_id, expires_at, response, email_status)
         VALUES
          (:tenant_id, :plid, :per, :email,
           :tok, :hash, :ej, :hrs,
           :uid, :exp, "pending", "queued")'
    );
    $stmt->bindValue('tenant_id', $ctx['tenant_id'], \PDO::PARAM_INT);
    $stmt->bindValue('plid',  $placementId, \PDO::PARAM_INT);
    $stmt->bindValue('per',   $periodId,    \PDO::PARAM_INT);
    $stmt->bindValue('email', $placement['client_approver_email']);
    $stmt->bindValue('tok',   $gen['token']);
    $stmt->bindValue('hash',  $gen['hash'], \PDO::PARAM_LOB);
    $stmt->bindValue('ej',    json_encode(['entry_ids' => $entryIds, 'count' => count($entryIds)]));
    $stmt->bindValue('hrs',   round($totalHours, 2));
    $stmt->bindValue('uid',   $user['id'] ?? null, \PDO::PARAM_INT);
    $stmt->bindValue('exp',   $expiresAt);
    $stmt->execute();
    $tokenId = (int) $pdo->lastInsertId();

    // Build email body + URLs
    $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : (getenv('APP_URL') ?: 'https://www.corefluxapp.com');
    $approveUrl = "{$baseUrl}/time_approve.php?t={$gen['token']}&a=approve";
    $rejectUrl  = "{$baseUrl}/time_approve.php?t={$gen['token']}&a=reject";
    $tokenRow = array_merge(['placement_id' => $placementId, 'expires_at' => $expiresAt], $placement);
    $body = timeTokenBuildEmailBody($tokenRow, $entries, $placement, $approveUrl, $rejectUrl);

    // Send via MailService — sender resolved per tenant (Model B)
    $sender  = cf_tenant_mail_sender((int) $ctx['tenant_id'], 'time');
    $svc     = cf_mail_bootstrap();
    $sendRes = $svc->send(
        (int) $ctx['tenant_id'],
        'time',
        'client_approval_request',
        [$placement['client_approver_email']],
        $body['subject'],
        $body['text'],
        $body['html'],
        [],
        [
            'from'            => $sender['from'],
            'from_name'       => $sender['from_name'],
            'reply_to'        => $sender['reply_to'],
            'idempotency_key' => 'time-token-' . $tokenId,
        ]
    );

    $emailStatus = ($sendRes['status'] ?? 'failed') === 'sent' ? 'sent' : 'failed';
    $pdo->prepare(
        'UPDATE time_approval_tokens
         SET email_status = :s, provider_message_id = :pmid, email_error = :err
         WHERE id = :id'
    )->execute([
        's'    => $emailStatus,
        'pmid' => $sendRes['provider_message_id'] ?? null,
        'err'  => $sendRes['error'] ?? null,
        'id'   => $tokenId,
    ]);

    timeAudit('time.tokenized_email.issued', [
        'token_id' => $tokenId, 'placement_id' => $placementId, 'period_id' => $periodId,
        'entry_count' => count($entryIds), 'total_hours' => round($totalHours, 2),
        'email_status' => $emailStatus, 'driver' => $sendRes['driver'] ?? null,
    ], $tokenId);

    if ($emailStatus === 'failed') {
        api_ok([
            'token_id'      => $tokenId,
            'email_status'  => $emailStatus,
            'email_error'   => $sendRes['error'] ?? 'unknown',
            'message'       => 'Token created but email delivery failed. You can resend or revoke.',
        ], 202);
    }
    api_ok([
        'token_id'     => $tokenId,
        'email_status' => $emailStatus,
        'expires_at'   => $expiresAt,
    ], 201);
}

if ($method === 'POST' && $action === 'revoke') {
    rbac_legacy_require($user, 'time.tokenized_email.revoke');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $row = scopedFind('SELECT id, response FROM time_approval_tokens WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    if ($row['response'] !== 'pending') api_error('Token already ' . $row['response'], 409);

    $pdo = getDB();
    $pdo->prepare('UPDATE time_approval_tokens SET response = "revoked", revoked_at = NOW(), revoked_by_user_id = :uid WHERE id = :id AND tenant_id = :tid AND response = "pending"')
        ->execute(['uid' => $user['id'] ?? null, 'id' => $id, 'tid' => $ctx['tenant_id']]);
    timeAudit('time.tokenized_email.revoked', ['token_id' => $id], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
