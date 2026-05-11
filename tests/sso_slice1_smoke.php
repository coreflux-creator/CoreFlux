<?php
/**
 * Smoke: SSO Slice 1 — storage + admin UI (no OIDC dance yet).
 *
 *   1) Migration 030_tenant_sso_domains.sql shape + idempotency.
 *   2) API /api/sso_config.php — GET/POST + RBAC + secret-preserve + clear.
 *   3) Admin UI SsoConfigAdmin.jsx wired into AdminModule.jsx routes + sidebar.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$parses = fn (string $p): bool => is_file($p)
    && (int) shell_exec('php -l ' . escapeshellarg($p) . ' >/dev/null 2>&1; echo $?') === 0;

echo "1) Migration 030_tenant_sso_domains.sql\n";
$migPath = __DIR__ . '/../core/migrations/030_tenant_sso_domains.sql';
$mig     = (string) file_get_contents($migPath);
$a('migration file exists',                            is_file($migPath));
$a('CREATE TABLE IF NOT EXISTS tenant_sso_domains',    str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_sso_domains'));
$a('provider_type ENUM',                               str_contains($mig, "ENUM('okta','entra','generic_oidc')"));
$a('issuer_url column',                                str_contains($mig, 'issuer_url               VARCHAR(255) NOT NULL'));
$a('client_id column',                                 str_contains($mig, 'client_id                VARCHAR(255) NOT NULL'));
$a('encrypted secret stored as VARBINARY',             str_contains($mig, 'client_secret_enc        VARBINARY(2048)'));
$a('client_secret_last4 for display confirmation',     str_contains($mig, 'client_secret_last4      CHAR(4)'));
$a('allowed_email_domains is JSON',                    str_contains($mig, 'allowed_email_domains    JSON'));
$a('is_enabled flag defaults 0',                       str_contains($mig, 'is_enabled               TINYINT(1)   NOT NULL DEFAULT 0'));
$a('sso_slug column',                                  str_contains($mig, 'sso_slug                 VARCHAR(64)  NOT NULL'));
$a('UNIQUE (tenant_id) — one row per tenant',          str_contains($mig, 'UNIQUE KEY uq_tsd_tenant     (tenant_id)'));
$a('UNIQUE (sso_slug) — global slug uniqueness',       str_contains($mig, 'UNIQUE KEY uq_tsd_slug       (sso_slug)'));
$a('idempotent (IF NOT EXISTS)',                       str_contains($mig, 'IF NOT EXISTS'));

echo "\n2) API /api/sso_config.php\n";
$apiPath = __DIR__ . '/../api/sso_config.php';
$api     = (string) file_get_contents($apiPath);
$a('API parses',                                       $parses($apiPath));
$a('imports core/encryption.php',                      str_contains($api, "require_once __DIR__ . '/../core/encryption.php'"));
$a('GET requires admin/manager OR master/tenant admin',str_contains($api, "in_array(\$role, ['admin','manager'], true)")
                                                       && str_contains($api, "in_array(\$g,    ['master_admin','tenant_admin'], true)"));
$a('GET response excludes raw secret',                 !preg_match('/client_secret_enc[^,]*=>/', $api));
$a('GET returns can_write flag',                       str_contains($api, "'can_write' => \$canWrite(\$user)"));
$a('GET decodes allowed_email_domains JSON to array',  str_contains($api, 'json_decode((string) $row[\'allowed_email_domains\']'));
$a('POST validates provider_type whitelist',           str_contains($api, "in_array(\$providerType, ['okta','entra','generic_oidc'], true)"));
$a('POST validates issuer_url is https://',            str_contains($api, "preg_match('#^https://#i', \$issuer)"));
$a('POST validates sso_slug regex',                    str_contains($api, "preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?\$/', \$slug)"));
$a('POST validates each email domain',                 str_contains($api, "allowed_email_domains contains an invalid domain"));
$a('POST encrypts client_secret via encryptField',     str_contains($api, '$encrypted = $hasFreshSecret ? encryptField($clientSecret) : null;'));
$a('POST persists last4 for display confirmation',     str_contains($api, '$last4     = $hasFreshSecret ? substr($clientSecret, -4) : null;'));
$a('POST preserves existing secret when blank',        str_contains($api, '// Preserve existing secret on update.'));
$a('POST rejects sso_slug collision with 409',         str_contains($api, "api_error('sso_slug already in use by another tenant', 409)"));
$a('?action=disable supported',                        str_contains($api, "\$method === 'POST' && \$action === 'disable'"));
$a('?action=clear_secret nukes secret + disables SSO', str_contains($api, 'SET client_secret_enc = NULL, client_secret_last4 = NULL, is_enabled = 0'));
$a('write gated to master_admin/tenant_admin',         substr_count($api, "in_array(\$g, ['master_admin','tenant_admin'], true)") >= 1);
$a('writes audit events on save/disable/clear',        str_contains($api, "auditWrite('tenant.sso_config.updated'")
                                                       && str_contains($api, "auditWrite('tenant.sso_config.disabled'")
                                                       && str_contains($api, "auditWrite('tenant.sso_config.secret_cleared'"));

echo "\n3) Admin UI: SsoConfigAdmin.jsx\n";
$uiPath = __DIR__ . '/../dashboard/src/pages/SsoConfigAdmin.jsx';
$ui     = (string) file_get_contents($uiPath);
$a('UI file exists',                                   is_file($uiPath));
foreach ([
    'admin-sso-config',
    'admin-sso-provider-type',
    'admin-sso-issuer-url',
    'admin-sso-client-id',
    'admin-sso-client-secret',
    'admin-sso-slug',
    'admin-sso-domains',
    'admin-sso-notes',
    'admin-sso-enabled',
    'admin-sso-save',
] as $tid) {
    $a("testid: {$tid}",                               str_contains($ui, "data-testid=\"{$tid}\""));
}
$a('shows ••••last4 confirmation',                     str_contains($ui, 'on file: ••••'));
$a('placeholder hints when slug not set',              str_contains($ui, "/api/sso/\${form.sso_slug || '{slug}'}/callback"));
$a('Clear-secret button only when secret on file',     str_contains($ui, 'config?.client_secret_last4 && ('));
$a('Save disabled for non-admins',                     str_contains($ui, 'disabled={!canWrite || busy}'));
$a('readonly notice for non-admins',                   str_contains($ui, 'admin-sso-readonly'));
$a('omits client_secret when blank (preserve)',        str_contains($ui, 'if (!payload.client_secret) delete payload.client_secret;'));

echo "\n4) AdminModule.jsx wiring\n";
$adminMod = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$a('imports SsoConfigAdmin',                           str_contains($adminMod, "import SsoConfigAdmin from './SsoConfigAdmin'"));
$a('routes /admin/sso',                                str_contains($adminMod, '<Route path="/sso"               element={<SsoConfigAdmin session={session} />} />'));
$a('sidebar has SSO link',                             str_contains($adminMod, "label: 'SSO'"));
$a('overview tile for SSO present',                    str_contains($adminMod, '"SSO configuration"'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
