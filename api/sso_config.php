<?php
/**
 * Tenant SSO configuration — Slice 1 (storage + admin UI).
 *
 *   GET  /api/sso_config.php                  → current tenant's row (no secret)
 *   POST /api/sso_config.php                  body {provider_type, issuer_url, client_id, client_secret?, allowed_email_domains, is_enabled, sso_slug, notes}
 *   POST /api/sso_config.php?action=disable
 *   POST /api/sso_config.php?action=clear_secret
 *
 * Slice 1 has NO OIDC redirect / callback handler — that ships in Slice 2.
 * This endpoint only persists creds so a tenant admin can stage their IdP
 * configuration before the actual OIDC flow is rolled out.
 *
 * Write access is restricted to master_admin or tenant_admin global roles.
 * Read access additionally allows the regular tenant 'admin' / 'manager' role
 * but the response NEVER includes the decrypted client_secret — only the
 * last-4 confirmation digit set so the UI can show "secret on file: ••••cd12".
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/audit.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canRead = function (array $u): bool {
    $role = (string) ($u['role']        ?? '');
    $g    = (string) ($u['global_role'] ?? '');
    return in_array($role, ['admin','manager'], true)
        || in_array($g,    ['master_admin','tenant_admin'], true);
};
$canWrite = function (array $u): bool {
    $g = (string) ($u['global_role'] ?? '');
    return in_array($g, ['master_admin','tenant_admin'], true);
};

if ($method === 'GET') {
    if (!$canRead($user)) api_error('Admin role required', 403);
    $row = null;
    try {
        $st = getDB()->prepare(
            'SELECT id, tenant_id, provider_type, issuer_url, client_id,
                    client_secret_last4, allowed_email_domains, is_enabled, sso_slug,
                    notes, updated_at
               FROM tenant_sso_domains WHERE tenant_id = :t LIMIT 1'
        );
        $st->execute(['t' => $tid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { /* migration not applied yet */ }
    if ($row && !empty($row['allowed_email_domains'])) {
        $decoded = json_decode((string) $row['allowed_email_domains'], true);
        $row['allowed_email_domains'] = is_array($decoded) ? $decoded : [];
    } else if ($row) {
        $row['allowed_email_domains'] = [];
    }
    api_ok([
        'config'    => $row,
        'can_write' => $canWrite($user),
        'callback_url_hint' => '/api/sso/{sso_slug}/callback   (live in Slice 2)',
    ]);
}

if ($method === 'POST' && $action === '') {
    if (!$canWrite($user)) api_error('master_admin or tenant_admin required', 403);
    $body = api_json_body();

    $providerType = (string) ($body['provider_type'] ?? 'generic_oidc');
    if (!in_array($providerType, ['okta','entra','generic_oidc'], true)) {
        api_error('provider_type must be one of okta|entra|generic_oidc', 422);
    }
    $issuer = trim((string) ($body['issuer_url'] ?? ''));
    if (!filter_var($issuer, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $issuer)) {
        api_error('issuer_url must be a valid https:// URL', 422);
    }
    $clientId = trim((string) ($body['client_id'] ?? ''));
    if ($clientId === '') api_error('client_id required', 422);
    $slug = strtolower(trim((string) ($body['sso_slug'] ?? '')));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
        api_error('sso_slug must be 1-64 lowercase letters, digits, or single dashes', 422);
    }

    $domains = $body['allowed_email_domains'] ?? [];
    if (is_string($domains)) {
        // accept comma-separated for convenience
        $domains = array_values(array_filter(array_map('trim', explode(',', $domains))));
    }
    if (!is_array($domains)) api_error('allowed_email_domains must be an array', 422);
    foreach ($domains as $d) {
        if (!is_string($d) || !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $d)) {
            api_error("allowed_email_domains contains an invalid domain: {$d}", 422);
        }
    }
    $isEnabled = !empty($body['is_enabled']) ? 1 : 0;
    $notes     = isset($body['notes']) ? substr(trim((string) $body['notes']), 0, 500) : null;

    // Client secret is optional on update — only re-encrypt if a fresh
    // value comes in. UI should send empty string to mean "leave alone".
    $clientSecret = isset($body['client_secret']) ? (string) $body['client_secret'] : '';
    $hasFreshSecret = $clientSecret !== '';
    $encrypted = $hasFreshSecret ? encryptField($clientSecret) : null;
    $last4     = $hasFreshSecret ? substr($clientSecret, -4) : null;

    $pdo = getDB();

    // Slug must be globally unique (across tenants) — reject collisions early.
    $st = $pdo->prepare('SELECT tenant_id FROM tenant_sso_domains WHERE sso_slug = :s AND tenant_id <> :t LIMIT 1');
    $st->execute(['s' => $slug, 't' => $tid]);
    if ($st->fetch(\PDO::FETCH_ASSOC)) api_error('sso_slug already in use by another tenant', 409);

    if ($hasFreshSecret) {
        $pdo->prepare(
            'INSERT INTO tenant_sso_domains
                (tenant_id, provider_type, issuer_url, client_id, client_secret_enc, client_secret_last4,
                 allowed_email_domains, is_enabled, sso_slug, notes, updated_by_user_id)
             VALUES (:t, :p, :i, :ci, :cs, :l4, :d, :e, :s, :n, :u)
             ON DUPLICATE KEY UPDATE
                provider_type         = VALUES(provider_type),
                issuer_url            = VALUES(issuer_url),
                client_id             = VALUES(client_id),
                client_secret_enc     = VALUES(client_secret_enc),
                client_secret_last4   = VALUES(client_secret_last4),
                allowed_email_domains = VALUES(allowed_email_domains),
                is_enabled            = VALUES(is_enabled),
                sso_slug              = VALUES(sso_slug),
                notes                 = VALUES(notes),
                updated_by_user_id    = VALUES(updated_by_user_id)'
        )->execute([
            't' => $tid, 'p' => $providerType, 'i' => $issuer, 'ci' => $clientId,
            'cs' => $encrypted, 'l4' => $last4,
            'd' => json_encode($domains), 'e' => $isEnabled, 's' => $slug,
            'n' => $notes, 'u' => (int) ($user['id'] ?? 0) ?: null,
        ]);
    } else {
        // Preserve existing secret on update.
        $pdo->prepare(
            'INSERT INTO tenant_sso_domains
                (tenant_id, provider_type, issuer_url, client_id,
                 allowed_email_domains, is_enabled, sso_slug, notes, updated_by_user_id)
             VALUES (:t, :p, :i, :ci, :d, :e, :s, :n, :u)
             ON DUPLICATE KEY UPDATE
                provider_type         = VALUES(provider_type),
                issuer_url            = VALUES(issuer_url),
                client_id             = VALUES(client_id),
                allowed_email_domains = VALUES(allowed_email_domains),
                is_enabled            = VALUES(is_enabled),
                sso_slug              = VALUES(sso_slug),
                notes                 = VALUES(notes),
                updated_by_user_id    = VALUES(updated_by_user_id)'
        )->execute([
            't' => $tid, 'p' => $providerType, 'i' => $issuer, 'ci' => $clientId,
            'd' => json_encode($domains), 'e' => $isEnabled, 's' => $slug,
            'n' => $notes, 'u' => (int) ($user['id'] ?? 0) ?: null,
        ]);
    }

    auditWrite('tenant.sso_config.updated', [
        'tenant_id' => $tid, 'provider_type' => $providerType,
        'issuer_url' => $issuer, 'sso_slug' => $slug, 'is_enabled' => $isEnabled,
        'secret_changed' => $hasFreshSecret,
    ]);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'disable') {
    if (!$canWrite($user)) api_error('master_admin or tenant_admin required', 403);
    getDB()->prepare('UPDATE tenant_sso_domains SET is_enabled = 0, updated_by_user_id = :u WHERE tenant_id = :t')
           ->execute(['t' => $tid, 'u' => (int) ($user['id'] ?? 0) ?: null]);
    auditWrite('tenant.sso_config.disabled', ['tenant_id' => $tid]);
    api_ok(['ok' => true, 'is_enabled' => 0]);
}

if ($method === 'POST' && $action === 'clear_secret') {
    if (!$canWrite($user)) api_error('master_admin or tenant_admin required', 403);
    getDB()->prepare(
        'UPDATE tenant_sso_domains
            SET client_secret_enc = NULL, client_secret_last4 = NULL, is_enabled = 0,
                updated_by_user_id = :u
          WHERE tenant_id = :t'
    )->execute(['t' => $tid, 'u' => (int) ($user['id'] ?? 0) ?: null]);
    auditWrite('tenant.sso_config.secret_cleared', ['tenant_id' => $tid]);
    api_ok(['ok' => true]);
}

api_error('Method/action not allowed', 405);

/**
 * Best-effort audit write — tolerates audit_log schema variance.
 */
function auditWrite(string $event, array $meta): void {
    try {
        platformAuditLogWrite(
            (int) ($meta['tenant_id'] ?? 0) ?: null,
            (int) ($_SESSION['user_id'] ?? 0) ?: null,
            $event,
            null,
            $meta,
            [
                'source' => 'sso',
                'object_type' => 'tenant_sso_config',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (\Throwable $_) { /* audit table missing — swallow */ }
}
