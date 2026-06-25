<?php
/**
 * Smoke test for the new /admin/ai-settings surface.
 *
 *   - CLI script (scripts/ai_toggle.php) exposes on / off / status /
 *     full-content-logging / feature commands.
 *   - Backend endpoint (api/admin/ai_settings.php) honours auth, returns
 *     payload shape, accepts partial updates.
 *   - SPA page (pages/AiSettingsAdmin.jsx) wires master + feature toggles
 *     with the proper data-testid hooks.
 *   - AdminModule.jsx routes /admin/ai-settings, adds the ActionCard +
 *     sidebar nav entry.
 *   - Codebase-wide sentries (tenant-leak, auth-gate) still green.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// ── CLI script ─────────────────────────────────────────────────────────
$cli = file_get_contents('/app/scripts/ai_toggle.php');
$a('CLI exists',                                  $cli !== false);
$a('CLI supports on subcommand',                  str_contains($cli, "\$cmd === 'on'"));
$a('CLI supports off subcommand',                 str_contains($cli, "\$cmd === 'off'"));
$a('CLI supports status subcommand',              str_contains($cli, "\$cmd === 'status'"));
$a('CLI supports full-content-logging subcommand',str_contains($cli, "\$cmd === 'full-content-logging'"));
$a('CLI supports feature subcommand',             str_contains($cli, "\$cmd === 'feature'"));
$a('CLI resolves tenant by id OR subdomain',      str_contains($cli, 'ctype_digit($ref)') && str_contains($cli, 'subdomain = :sd'));

// CLI usage runs without fatal (exit 2 == usage)
exec(PHP_BINARY . ' /app/scripts/ai_toggle.php 2>&1', $out, $rc);
$a('CLI prints usage and exits 2 on no-args',     $rc === 2 && implode("\n", $out) !== '');

// ── Backend endpoint ───────────────────────────────────────────────────
$api = file_get_contents('/app/api/admin/ai_settings.php');
$a('endpoint exists',                             $api !== false);
$a('endpoint calls api_require_auth',             str_contains($api, 'api_require_auth()'));
$a('endpoint rejects non-admin roles',            str_contains($api, "['master_admin', 'tenant_admin']"));
$a('endpoint constrains tenant_admin to own tid', str_contains($api, "tenant_admin may only edit own tenant"));
$a('endpoint exposes AI_KNOWN_FEATURE_CLASSES',   str_contains($api, "AI_KNOWN_FEATURE_CLASSES"));
$a('endpoint upserts ai_tenant_features',         str_contains($api, 'INSERT INTO ai_tenant_features')
                                                  && str_contains($api, 'ON DUPLICATE KEY UPDATE'));
$a('endpoint writes ai_enabled in dynamic UPDATE',str_contains($api, 'ai_enabled = :ai_enabled'));
$a('endpoint writes ai_full_content_logging',     str_contains($api, 'ai_full_content_logging = :ai_full_content_logging'));
$a('endpoint emits admin.ai_settings.updated audit', str_contains($api, "admin.ai_settings.updated"));
$a('endpoint is transactional',                   str_contains($api, '$pdo->beginTransaction()') && str_contains($api, '$pdo->commit()') && str_contains($api, '$pdo->rollBack()'));

// Endpoint must syntax-parse cleanly
exec(PHP_BINARY . ' -l /app/api/admin/ai_settings.php 2>&1', $out2, $rc2);
$a('endpoint passes php -l',                      $rc2 === 0);

// ── SPA page ───────────────────────────────────────────────────────────
$jsx = file_get_contents('/app/dashboard/src/pages/AiSettingsAdmin.jsx');
$a('SPA page exists',                             $jsx !== false);
$a('SPA fetches from /api/admin/ai_settings.php', str_contains($jsx, '/api/admin/ai_settings.php'));
$a('SPA has master-toggle test id',               str_contains($jsx, "data-testid=\"ai-settings-master-toggle\""));
$a('SPA has full-content-logging test id',        str_contains($jsx, "data-testid=\"ai-settings-full-content-logging\""));
$a('SPA has save button test id',                 str_contains($jsx, "data-testid=\"ai-settings-save-btn\""));
$a('SPA has saved indicator test id',             str_contains($jsx, "data-testid=\"ai-settings-saved-indicator\""));
$a('SPA renders a checkbox per known feature class', str_contains($jsx, 'ai-settings-feature-${cls}-toggle'));
$a('SPA disables feature toggles when master is off', str_contains($jsx, 'disabled={!draft.ai_enabled}'));
$a('SPA copy explains opt-in default',            str_contains($jsx, 'opt-in'));

// ── AdminModule wiring ────────────────────────────────────────────────
$admin = file_get_contents('/app/dashboard/src/pages/AdminModule.jsx');
$a('AdminModule imports AiSettingsAdmin',         str_contains($admin, "import AiSettingsAdmin from './AiSettingsAdmin'"));
$a('AdminModule routes /ai-settings',             str_contains($admin, '<Route path="/ai-settings"'));
$a('AdminModule renders the ActionCard',          str_contains($admin, 'title="AI settings"') && str_contains($admin, 'href="/admin/ai-settings"'));
$a('AdminModule sidebar links AI Settings',       str_contains($admin, "label: 'AI Settings'") && str_contains($admin, "to: '/admin/ai-settings'"));

// ── Sentries still green ──────────────────────────────────────────────
exec(PHP_BINARY . ' -d extension=pdo_sqlite -d extension=sqlite3 -d zend.assertions=1 /app/tests/tenant_leak_static_analyzer_smoke.php 2>&1', $outA, $rcA);
$a('tenant-leak sentry still green after new endpoint', $rcA === 0);
exec(PHP_BINARY . ' -d extension=pdo_sqlite -d extension=sqlite3 -d zend.assertions=1 /app/tests/auth_gate_static_analyzer_smoke.php 2>&1', $outB, $rcB);
$a('auth-gate sentry still green after new endpoint',   $rcB === 0);

echo "\n=========================================\n";
echo "AI settings admin smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
