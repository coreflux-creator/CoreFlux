<?php
/**
 * Money Movement share links — admin CRUD.
 *
 *   GET  /api/billing/money_movement_share_links.php
 *     → recent links for this tenant (last 25), masking the token.
 *
 *   POST /api/billing/money_movement_share_links.php
 *     body {as_of, label?, ttl_days?}  default ttl=30
 *     → mints a new link. RAW TOKEN returned ONCE in this response;
 *       only sha256 is persisted.
 *
 *   POST /api/billing/money_movement_share_links.php?action=revoke&id=N
 *     → soft-revoke (sets revoked_at).
 *
 * Public view endpoint is /api/billing/money_movement_view.php — no auth.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    RBAC::requirePermission($user, 'billing.view');
    $st = getDB()->prepare(
        'SELECT id, as_of, label, created_at, expires_at, revoked_at, view_count, last_viewed_at
           FROM billing_money_movement_share_links
          WHERE tenant_id = :t
       ORDER BY created_at DESC LIMIT 25'
    );
    $st->execute(['t' => $tid]);
    api_ok(['rows' => $st->fetchAll(\PDO::FETCH_ASSOC) ?: []]);
}

if ($method === 'POST' && $action === '') {
    RBAC::requirePermission($user, 'billing.invoice.create');
    $body = api_json_body();
    $asOf = (string) ($body['as_of'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);
    $ttl  = max(1, min(180, (int) ($body['ttl_days'] ?? 30)));
    $label = isset($body['label']) ? substr(trim((string) $body['label']), 0, 120) : null;

    $rawToken = bin2hex(random_bytes(24)); // 48-char URL token
    $hash     = hash('sha256', $rawToken);
    getDB()->prepare(
        'INSERT INTO billing_money_movement_share_links
           (tenant_id, as_of, token_sha256, created_by_user_id, expires_at, label)
         VALUES (:t, :a, :h, :u, DATE_ADD(NOW(), INTERVAL :ttl DAY), :l)'
    )->execute([
        't' => $tid, 'a' => $asOf, 'h' => $hash,
        'u' => (int) ($user['id'] ?? 0) ?: null,
        'ttl' => $ttl, 'l' => $label,
    ]);
    $id = (int) getDB()->lastInsertId();
    billingAudit('billing.money_movement.share_link_created', [
        'as_of' => $asOf, 'ttl_days' => $ttl, 'link_id' => $id,
    ]);
    api_ok([
        'ok'         => true,
        'id'         => $id,
        'raw_token'  => $rawToken,
        'public_url' => '/api/billing/money_movement_view.php?t=' . $rawToken,
        'expires_in_days' => $ttl,
    ]);
}

if ($method === 'POST' && $action === 'revoke') {
    RBAC::requirePermission($user, 'billing.invoice.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    getDB()->prepare(
        'UPDATE billing_money_movement_share_links
            SET revoked_at = NOW()
          WHERE tenant_id = :t AND id = :id AND revoked_at IS NULL'
    )->execute(['t' => $tid, 'id' => $id]);
    billingAudit('billing.money_movement.share_link_revoked', ['link_id' => $id]);
    api_ok(['ok' => true]);
}

api_error('Method/action not allowed', 405);
