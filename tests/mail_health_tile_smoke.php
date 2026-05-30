<?php
/**
 * mail_health_tile_smoke.php
 *
 * Verifies the "Mail health" tile wiring:
 *   • /api/admin/mail_health.php endpoint (24h rollup, 7d daily, top
 *     purposes, recent failures, derived status banner).
 *   • dashboard/src/pages/MailHealthCard.jsx (tile UI w/ testids).
 *   • IntegrationsHub.jsx mounts the card under a Communications
 *     section.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Mail health tile smoke\n";
echo "======================\n\n";

$ROOT = dirname(__DIR__);

// --- /api/admin/mail_health.php ----------------------------------
echo "api/admin/mail_health.php\n";
$ep = $read("{$ROOT}/api/admin/mail_health.php");
$a('file exists',                              $ep !== '');
$a('api_require_auth + RBAC gate',             str_contains($ep, 'api_require_auth()')
                                            && str_contains($ep, "rbac_legacy_require(\$user, 'tenant_admin.integrations');"));
$a('detects RESEND_API_KEY without leaking',   str_contains($ep, "(string) getenv('RESEND_API_KEY')")
                                            && !str_contains($ep, "'resend_key'   => \$resendKey"));
$a('24h rollup query (status GROUP BY)',       str_contains($ep, "INTERVAL 24 HOUR")
                                            && str_contains($ep, 'GROUP BY status'));
$a('driver split (24h)',                       str_contains($ep, 'GROUP BY driver'));
$a('7-day daily series',                       str_contains($ep, "INTERVAL 7 DAY")
                                            && str_contains($ep, 'GROUP BY DATE(created_at)'));
$a('back-fills missing days in 7d series',     str_contains($ep, 'for ($i = 6; $i >= 0; $i--)'));
$a('top_purposes_24h',                         str_contains($ep, 'GROUP BY purpose')
                                            && str_contains($ep, "LIMIT 5"));
$a('recent_failures last 5',                   str_contains($ep, "IN ('failed','bounced','complaint')"));
$a('failure_pct computed',                     str_contains($ep, '$rollup[\'failure_pct\']'));
$a('status derivation (healthy/degraded/critical/silent)',
                                              str_contains($ep, "'healthy'")
                                            && str_contains($ep, "'degraded'")
                                            && str_contains($ep, "'critical'")
                                            && str_contains($ep, "'silent'"));
$a('table_missing flag for graceful degrade',  str_contains($ep, '$tableMissing'));
$a('rejects non-GET',                          str_contains($ep, "if (api_method() !== 'GET') api_error('Method not allowed', 405);"));
$a('returns table_missing in response',        str_contains($ep, "'table_missing'"));
$a('returns from_email in response',           str_contains($ep, "'from_email'"));
$a('returns daily_7d in response',             str_contains($ep, "'daily_7d'"));

$lint = shell_exec('php -l ' . escapeshellarg("{$ROOT}/api/admin/mail_health.php") . ' 2>&1');
$a('PHP -l passes',                            is_string($lint) && str_contains($lint, 'No syntax errors detected'));

// --- MailHealthCard.jsx ------------------------------------------
echo "\ndashboard/src/pages/MailHealthCard.jsx\n";
$card = $read("{$ROOT}/dashboard/src/pages/MailHealthCard.jsx");
$a('file exists',                              $card !== '');
$a('useApi hits /api/admin/mail_health.php',   str_contains($card, "useApi('/api/admin/mail_health.php')"));
$a('root testid',                              str_contains($card, 'data-testid="integration-card-mail-health"'));
$a('status pill testids for all states',       str_contains($card, 'mail-health-status-loading')
                                            && str_contains($card, 'mail-health-status-${status}'));
$a('mini tiles: sent/failed/failure-pct',      str_contains($card, 'testid="mail-health-sent"')
                                            && str_contains($card, 'testid="mail-health-failed"')
                                            && str_contains($card, 'testid="mail-health-failure-pct"'));
$a('resend flag testid',                       str_contains($card, 'data-testid="mail-health-resend-flag"'));
$a('default driver testid',                    str_contains($card, 'data-testid="mail-health-default-driver"'));
$a('driver split chips',                       str_contains($card, 'data-testid={`mail-health-driver-${d}`}'));
$a('7-day spark bars',                         str_contains($card, 'data-testid="mail-health-spark"')
                                            && str_contains($card, 'data-testid={`mail-health-spark-${d.day}`}'));
$a('top purposes list',                        str_contains($card, 'data-testid={`mail-health-purpose-${p.purpose}`}'));
$a('recent failures',                          str_contains($card, 'data-testid={`mail-health-failure-${f.id}`}'));
$a('hint surface',                             str_contains($card, 'data-testid="mail-health-hint"'));
$a('manage link to /admin/mail-settings',      str_contains($card, 'to="/admin/mail-settings"')
                                            && str_contains($card, 'data-testid="mail-health-manage"'));
$a('error+retry path',                         str_contains($card, 'data-testid="mail-health-error"')
                                            && str_contains($card, 'data-testid="mail-health-retry"'));

// --- IntegrationsHub mount ---------------------------------------
echo "\ndashboard/src/pages/IntegrationsHub.jsx\n";
$hub = $read("{$ROOT}/dashboard/src/pages/IntegrationsHub.jsx");
$a('imports MailHealthCard',                   str_contains($hub, "import MailHealthCard from './MailHealthCard'"));
$a('Communications section mounted',           str_contains($hub, '<Section title="Communications">'));
$a('MailHealthCard rendered inside grid',      str_contains($hub, '<MailHealthCard />'));

// --- Summary -----------------------------------------------------
echo "\n\n----------------------------------------\n";
echo "Mail health tile smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
