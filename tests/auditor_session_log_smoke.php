<?php
/**
 * Auditor session log smoke (2026-02).
 *
 *   1. /api/admin/auditor_tokens.php ?action=log returns stats + top_paths + events.
 *   2. Authorisation respects _auditorTenantAllowed (tenant_admin can't peek
 *      across tenants).
 *   3. AuditorTokensAdmin.jsx exposes the activity toggle + SessionLogPanel.
 *
 *   php -d zend.assertions=1 /app/tests/auditor_session_log_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// Backend ---------------------------------------------------------------------
$src = (string) file_get_contents($ROOT . '/api/admin/auditor_tokens.php');
$a('endpoint exposes ?action=log',
    str_contains($src, "\$action === 'log'"));
$a('log branch requires id param',
    (bool) preg_match("/action === 'log'.*?api_error\('id required'/s", $src));
$a('log branch enforces _auditorTenantAllowed',
    (bool) preg_match("/action === 'log'.*?_auditorTenantAllowed/s", $src));
$a('returns aggregate stats payload',
    str_contains($src, "'unique_paths'") && str_contains($src, "'redeems'") && str_contains($src, "'unique_ips'"));
$a('returns top_paths (limit 15)',
    str_contains($src, "'top_paths'") && (bool) preg_match('/LIMIT\s+15/', $src));
$a('returns events list (limit 200)',
    str_contains($src, "'events'") && (bool) preg_match('/LIMIT\s+200/', $src));
$a('log queries are tenant-leak-allow annotated',
    substr_count($src, 'tenant-leak-allow') >= 4);

// Frontend --------------------------------------------------------------------
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/AuditorTokensAdmin.jsx');
$a('row exposes auditor-activity-${t.id} toggle',
    str_contains($ui, 'auditor-activity-${t.id}'));
$a('inline log panel mounts when expanded',
    str_contains($ui, 'auditor-log-panel-${tokenId}'));
$a('SessionLogPanel renders stats strip',
    str_contains($ui, 'auditor-log-stats-${tokenId}'));
$a('SessionLogPanel renders top-paths table',
    str_contains($ui, 'auditor-log-top-${tokenId}'));
$a('SessionLogPanel renders event list',
    str_contains($ui, 'auditor-log-events-${tokenId}'));
$a('events list is scrollable (max-height with overflowY)',
    (bool) preg_match('/maxHeight:\s*320[\s\S]*overflowY:\s*[\'"]auto[\'"]/', $ui));
$a('uses /api/admin/auditor_tokens.php?action=log',
    str_contains($ui, "?action=log&id="));

// php -l sanity ---------------------------------------------------------------
$rc = 0; $out = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/admin/auditor_tokens.php') . ' 2>&1', $out, $rc);
$a('php -l api/admin/auditor_tokens.php', $rc === 0);

echo "\n=========================================\n";
echo "Auditor session log smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
