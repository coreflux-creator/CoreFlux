<?php
/**
 * Platform API — tenant mail settings (Model B self-service).
 *
 *   GET /api/mail_settings.php
 *        → { reply_to, from_name_override, platform_from_email,
 *            platform_from_name, preview: { display } }
 *
 *   PUT /api/mail_settings.php
 *        body: { reply_to?: string|null, from_name_override?: string|null }
 *        → 200 { ok: true }
 *
 * Reads/writes the current tenant row. Any tenant admin (or master_admin)
 * with the existing 'tenant.manage' permission can update. Reply-To may be
 * any valid email address (does not require DNS). From override is just a
 * display name like "Acme Staffing Timesheets".
 *
 * Model C (custom verified From domain) will land in a later drop and
 * extend this endpoint non-breakingly.
 */
require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/tenant_mail.php';
require_once __DIR__ . '/../core/audit.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'tenant.manage');
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT mail_reply_to, mail_from_name_override FROM tenants WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $tid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['mail_reply_to' => null, 'mail_from_name_override' => null];

    $resolved = cf_tenant_mail_sender($tid, 'core');
    api_ok([
        'reply_to'              => $row['mail_reply_to'],
        'from_name_override'    => $row['mail_from_name_override'],
        'platform_from_email'   => $resolved['from'],
        'platform_from_name'    => getenv('RESEND_FROM_NAME')  ?: (defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : null),
        'effective_from_name'   => $resolved['from_name'],
        'preview' => [
            'display'   => ($resolved['from_name'] ? "{$resolved['from_name']} <{$resolved['from']}>" : $resolved['from']),
            'reply_to'  => $resolved['reply_to'],
            'model'     => $resolved['model'],
        ],
    ]);
}

if ($method === 'PUT' || $method === 'POST') {
    rbac_legacy_require($user, 'tenant.manage');
    $body = api_json_body();

    $update = [];
    if (array_key_exists('reply_to', $body)) {
        $v = $body['reply_to'];
        if ($v === null || $v === '') {
            $update['mail_reply_to'] = null;
        } else {
            if (!is_string($v) || !filter_var(trim($v), FILTER_VALIDATE_EMAIL)) {
                api_error('reply_to must be a valid email address', 422);
            }
            if (strlen($v) > 255) api_error('reply_to too long', 422);
            $update['mail_reply_to'] = trim($v);
        }
    }
    if (array_key_exists('from_name_override', $body)) {
        $v = $body['from_name_override'];
        if ($v === null || $v === '') {
            $update['mail_from_name_override'] = null;
        } else {
            if (!is_string($v)) api_error('from_name_override must be a string', 422);
            $clean = trim($v);
            if (strlen($clean) > 120) api_error('from_name_override too long (max 120)', 422);
            // Forbid line-breaks and bracket chars to prevent header injection via display name
            if (preg_match('/[\r\n<>]/', $clean)) api_error('from_name_override has forbidden characters', 422);
            $update['mail_from_name_override'] = $clean;
        }
    }
    if (!$update) api_error('No fields to update', 422);

    $pdo = getDB();
    $sets  = [];
    $binds = ['id' => $tid];
    foreach ($update as $k => $v) {
        $sets[] = "{$k} = :{$k}";
        $binds[$k] = $v;
    }
    $stmt = $pdo->prepare('UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($binds);

    // Audit (best-effort)
    try {
        platformAuditLogWrite(
            $tid,
            (int) ($user['id'] ?? 0) ?: null,
            'tenant.mail_settings.updated',
            $tid,
            ['fields' => array_keys($update)],
            [
                'source' => 'mail',
                'object_type' => 'tenant_mail_settings',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (\Throwable $e) { error_log('[mail_settings] audit failed: ' . $e->getMessage()); }

    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
