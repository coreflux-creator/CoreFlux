<?php
/**
 * /api/admin/mail_suppressions.php
 *
 * Per-tenant recipient suppression management.
 *
 *   GET    list      → { total, rows[] }
 *   POST   add       { email, reason?, notes? }
 *   DELETE remove    { email } | { id }
 *
 * RBAC: tenant_admin.integrations (same gate as the rest of mail
 * settings — admins who can manage Resend can manage suppressions).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/mail/suppressions.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) ($ctx['tenant_id'] ?? 0);
$uid  = (int) ($user['id'] ?? 0);
rbac_legacy_require($user, 'tenant_admin.integrations');

if ($tid <= 0) api_error('No active tenant', 400);

$method = api_method();

if ($method === 'GET') {
    $limit  = max(1, min(500, (int) (api_query('limit')  ?? 50)));
    $offset = max(0, (int) (api_query('offset') ?? 0));
    $q      = trim((string) (api_query('q') ?? ''));
    $list   = cf_mail_list_suppressions($tid, $limit, $offset, $q);
    api_ok([
        'total'  => $list['total'],
        'limit'  => $limit,
        'offset' => $offset,
        'q'      => $q,
        'rows'   => $list['rows'],
    ]);
}

if ($method === 'POST') {
    $body  = api_json_body();
    $email = trim((string) ($body['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Valid `email` required', 422);
    }
    $reason = (string) ($body['reason'] ?? 'manual');
    if (!in_array($reason, ['manual', 'bounce', 'complaint', 'api'], true)) {
        $reason = 'manual';
    }
    $notes = isset($body['notes']) ? substr((string) $body['notes'], 0, 500) : null;
    $id = cf_mail_suppress($tid, $email, $reason, [
        'source'             => 'admin_ui',
        'notes'              => $notes,
        'created_by_user_id' => $uid,
    ]);
    if ($id === null) api_error('Could not suppress (table missing or DB error)', 500);
    api_ok([
        'id'    => $id,
        'email' => cf_mail_normalize_email($email),
        'reason'=> $reason,
    ]);
}

if ($method === 'DELETE') {
    // DELETE with body is awkward through our request helper, so we
    // accept both query-string and JSON body. id wins if present.
    $body  = [];
    try { $body = api_json_body(); } catch (\Throwable $e) { /* empty body OK */ }
    $email = trim((string) ($body['email'] ?? api_query('email') ?? ''));
    $id    = (int) ($body['id']    ?? api_query('id')    ?? 0);
    if ($email === '' && $id <= 0) {
        api_error('`email` or `id` required', 422);
    }
    // If id supplied, look up the email so we can use the helper.
    if ($id > 0 && $email === '') {
        try {
            $pdo = getDB();
            if ($pdo) {
                $st = $pdo->prepare(
                    'SELECT email_normalized FROM mail_recipient_suppressions
                      WHERE id = :id AND tenant_id = :t AND removed_at IS NULL'
                );
                $st->execute(['id' => $id, 't' => $tid]);
                $email = (string) ($st->fetchColumn() ?: '');
            }
        } catch (\Throwable $e) { /* swallow */ }
    }
    if ($email === '') api_error('Suppression not found', 404);
    $removed = cf_mail_unsuppress($tid, $email, [
        'removed_by_user_id' => $uid,
        'reason'             => 'admin_ui',
    ]);
    api_ok([
        'removed' => $removed,
        'email'   => cf_mail_normalize_email($email),
    ]);
}

api_error('Method not allowed', 405);
