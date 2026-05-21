<?php
/**
 * Per-purpose tenant mail sender overrides.
 *
 *   GET  /api/admin/mail_senders.php
 *        → { purposes: [ {key, label, module, description,
 *                         override: {from_name, reply_to, enabled, updated_at} | null,
 *                         resolved: {from, from_name, reply_to, enabled, source, display} } ],
 *            platform: { from_email, from_name } }
 *
 *   POST /api/admin/mail_senders.php
 *        body: { purpose: 'timesheets'|'ap'|'vendor_portal'|'cfo'|'payments',
 *                from_name?: string|null, reply_to?: string|null, enabled?: bool }
 *        → 200 { ok: true, resolved: {...} }
 *
 *   DELETE /api/admin/mail_senders.php?purpose=...
 *        → 200 { ok: true } — removes the override row, falling back to
 *          the tenant-wide + platform defaults.
 *
 * RBAC: `tenant.manage` (same gate as /api/mail_settings.php — tenant
 * admins are the audience).
 */
require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/tenant_mail.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'tenant.manage');
    $platformFrom = getenv('RESEND_FROM_EMAIL') ?: (defined('RESEND_FROM_EMAIL') ? constant('RESEND_FROM_EMAIL') : null);
    $platformName = getenv('RESEND_FROM_NAME')  ?: (defined('RESEND_FROM_NAME')  ? constant('RESEND_FROM_NAME')  : null);
    api_ok([
        'purposes' => cf_mail_senders_list($tid),
        'platform' => [
            'from_email' => $platformFrom,
            'from_name'  => $platformName,
        ],
    ]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'tenant.manage');
    $body    = api_json_body();
    $purpose = (string) ($body['purpose'] ?? '');
    if (!cf_mail_purpose_lookup($purpose)) {
        api_error('Unknown purpose. Valid: ' . implode(',', array_column(cf_mail_purpose_registry(), 'key')), 422);
    }

    $payload = [];
    if (array_key_exists('from_name', $body)) $payload['from_name'] = $body['from_name'];
    if (array_key_exists('reply_to',  $body)) $payload['reply_to']  = $body['reply_to'];
    if (array_key_exists('enabled',   $body)) $payload['enabled']   = (bool) $body['enabled'];

    try {
        cf_mail_senders_upsert($tid, $purpose, $payload, (int) ($user['id'] ?? 0) ?: null);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('Save failed: ' . $e->getMessage(), 500);
    }

    // Best-effort audit.
    try {
        getDB()->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:tenant_id, :actor, :event, :target_id, :meta, :ip, NOW())'
        )->execute([
            'tenant_id' => $tid,
            'actor'     => $user['id'] ?? null,
            'event'     => 'tenant.mail_senders.updated',
            'target_id' => null,
            'meta'      => json_encode(['purpose' => $purpose, 'fields' => array_keys($payload)]),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) { error_log('[mail_senders] audit failed: ' . $e->getMessage()); }

    api_ok([
        'ok'       => true,
        'resolved' => cf_tenant_mail_sender($tid, $purpose),
    ]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'tenant.manage');
    $purpose = (string) (api_query('purpose') ?? '');
    if (!cf_mail_purpose_lookup($purpose)) api_error('Unknown purpose', 422);
    try {
        $stmt = getDB()->prepare('DELETE FROM tenant_mail_senders WHERE tenant_id = :t AND purpose = :p');
        $stmt->execute(['t' => $tid, 'p' => $purpose]);
    } catch (\Throwable $e) {
        api_error('Delete failed: ' . $e->getMessage(), 500);
    }
    api_ok([
        'ok'       => true,
        'resolved' => cf_tenant_mail_sender($tid, $purpose),
    ]);
}

api_error('Method not allowed', 405);
