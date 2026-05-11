<?php
/**
 * Tenant mail branding admin API.
 *
 *   GET  /api/tenant_mail_branding.php  → current row (or defaults)
 *   POST /api/tenant_mail_branding.php  body{logo_url?, accent_color?, signature_html?, show_powered_by?}
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/tenant_branding.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

$canWrite = function (array $u): bool {
    $g = (string) ($u['global_role'] ?? '');
    $r = (string) ($u['role']        ?? '');
    return in_array($g, ['master_admin','tenant_admin'], true) || in_array($r, ['admin'], true);
};

if ($method === 'GET') {
    $b = cf_tenant_branding($tid);
    api_ok(['branding' => $b, 'can_write' => $canWrite($user)]);
}

if ($method === 'POST') {
    if (!$canWrite($user)) api_error('Admin role required', 403);
    $body = api_json_body();

    $logo = isset($body['logo_url']) ? trim((string) $body['logo_url']) : null;
    if ($logo !== null && $logo !== '' && !preg_match('#^https://#i', $logo)) {
        api_error('logo_url must be a valid https:// URL', 422);
    }
    $accent = isset($body['accent_color']) ? trim((string) $body['accent_color']) : null;
    if ($accent !== null && $accent !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $accent)) {
        api_error('accent_color must be #rrggbb', 422);
    }
    $sig    = isset($body['signature_html']) ? substr((string) $body['signature_html'], 0, 800) : null;
    $povBy  = !empty($body['show_powered_by']) ? 1 : 0;
    if (!isset($body['show_powered_by'])) $povBy = 1; // default opt-in

    getDB()->prepare(
        'INSERT INTO tenant_mail_branding
            (tenant_id, logo_url, accent_color, signature_html, show_powered_by, updated_by_user_id)
         VALUES (:t, :l, :a, :s, :p, :u)
         ON DUPLICATE KEY UPDATE
            logo_url           = VALUES(logo_url),
            accent_color       = VALUES(accent_color),
            signature_html     = VALUES(signature_html),
            show_powered_by    = VALUES(show_powered_by),
            updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        't' => $tid,
        'l' => $logo  === '' ? null : $logo,
        'a' => $accent === '' ? null : $accent,
        's' => $sig   === '' ? null : $sig,
        'p' => $povBy,
        'u' => (int) ($user['id'] ?? 0) ?: null,
    ]);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
