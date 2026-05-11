<?php
/**
 * Admin healthcheck — runs every "is the new stuff working?" check in
 * a single round-trip and returns a flat list of {key, label, status,
 * detail, duration_ms} rows so the UI can render green/red dots.
 *
 *   GET /api/admin_healthcheck.php       → run all checks
 *   GET /api/admin_healthcheck.php?only=mail_branding,oidc_discovery  → subset
 *
 * Status values:
 *   ok       — passed
 *   warn     — passed but with caveat (e.g. table exists but empty)
 *   fail     — failed
 *   skipped  — pre-requisite not met (e.g. SSO not configured)
 *
 * Read-only — no writes anywhere. Safe to spam.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

$canRun = function (array $u): bool {
    $g = (string) ($u['global_role'] ?? '');
    $r = (string) ($u['role']        ?? '');
    return in_array($g, ['master_admin','tenant_admin'], true) || in_array($r, ['admin','manager'], true);
};
if (!$canRun($user)) api_error('Admin/manager role required', 403);

$only = isset($_GET['only']) ? array_filter(array_map('trim', explode(',', (string) $_GET['only']))) : [];

$checks = [
    ['db_connection',           'Database connection',            'admin_hc_db_connection'],
    ['snapshot_history_table',  'Snapshot history table (A1)',    'admin_hc_table_exists', 'tenant_money_movement_snapshots'],
    ['mail_branding_table',     'Mail branding table (F1)',       'admin_hc_table_exists', 'tenant_mail_branding'],
    ['digest_schedules_table',  'Digest schedules table (C1)',    'admin_hc_table_exists', 'tenant_digest_schedules'],
    ['share_links_table',       'Money Movement share links (B1)','admin_hc_table_exists', 'billing_money_movement_share_links'],
    ['oidc_session_state',      'OIDC session state (SSO Slice 2)','admin_hc_table_exists','oidc_session_state'],
    ['sso_domains_table',       'Tenant SSO domains (SSO Slice 1)','admin_hc_table_exists','tenant_sso_domains'],
    ['client_contacts_table',   'Billing client contacts',         'admin_hc_table_exists','billing_client_contacts'],
    ['dunning_log_table',       'Dunning log table',               'admin_hc_table_exists','billing_dunning_log'],
    ['time_entries_person_id',  'time_entries.person_id column (Time module)', 'admin_hc_column_exists', ['time_entries', 'person_id']],
    ['time_entries_placement',  'time_entries.placement_id column','admin_hc_column_exists', ['time_entries', 'placement_id']],
    ['mail_branding_endpoint',  'Mail branding API responds',      'admin_hc_branding_endpoint'],
    ['digest_schedule_helper',  'Digest schedule helper resolves', 'admin_hc_digest_helper'],
    ['snapshot_renders',        'Money Movement snapshot renders', 'admin_hc_snapshot_renders'],
    ['statement_renders',       'AR statement renderer works',     'admin_hc_statement_renders'],
    ['pdf_renderer_available',  'PDF renderer binary available',   'admin_hc_pdf_binary'],
    ['mail_bootstrap',          'Mail service bootstraps',         'admin_hc_mail_bootstrap'],
    ['emergent_llm_key',        'Universal LLM key configured',    'admin_hc_emergent_key'],
    ['cron_money_movement',     'Money Movement cron script ok',   'admin_hc_cron_script', 'scripts/money_movement_weekly.php'],
    ['cron_dunning',            'Dunning daily cron script ok',    'admin_hc_cron_script', 'scripts/dunning_daily.php'],
    ['cron_ap_weekly_queue',    'AP weekly queue cron script ok',  'admin_hc_cron_script', 'scripts/ap_weekly_queue_sunday.php'],
    ['oidc_discovery',          'OIDC discovery for SSO tenant',   'admin_hc_oidc_discovery'],
    ['vite_bundle_present',     'Vite bundle present in spa-assets','admin_hc_vite_bundle'],
    ['deploy_version_matches',  'Deploy version stamp matches bundle','admin_hc_deploy_version'],
];

$results = [];
foreach ($checks as $check) {
    [$key, $label, $fn] = [$check[0], $check[1], $check[2]];
    $arg = $check[3] ?? null;
    if (!empty($only) && !in_array($key, $only, true)) continue;
    $start = microtime(true);
    try {
        $res = $arg !== null ? $fn($tid, $arg) : $fn($tid);
        if (!is_array($res)) $res = ['status' => $res ? 'ok' : 'fail', 'detail' => ''];
    } catch (\Throwable $e) {
        $res = ['status' => 'fail', 'detail' => $e->getMessage()];
    }
    $results[] = [
        'key'         => $key,
        'label'       => $label,
        'status'      => $res['status'] ?? 'fail',
        'detail'      => $res['detail'] ?? '',
        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    ];
}
$tally = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skipped' => 0];
foreach ($results as $r) $tally[$r['status']] = ($tally[$r['status']] ?? 0) + 1;
api_ok(['results' => $results, 'tally' => $tally, 'ran_at' => date('c')]);

/* ────────────────────────────  Check functions  ──────────────────────────── */

function admin_hc_db_connection(int $tid): array {
    $st = getDB()->query('SELECT 1');
    $ok = $st && (int) $st->fetchColumn() === 1;
    return ['status' => $ok ? 'ok' : 'fail', 'detail' => $ok ? 'reachable' : 'no row'];
}

function admin_hc_table_exists(int $tid, string $table): array {
    $st = getDB()->prepare("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = :t");
    $st->execute(['t' => $table]);
    if ((int) $st->fetchColumn() !== 1) return ['status' => 'fail', 'detail' => "table {$table} does not exist (run migrations)"];
    $rc = (int) getDB()->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    return ['status' => $rc === 0 ? 'warn' : 'ok', 'detail' => "rows: {$rc}"];
}

/**
 * Verify a specific (table, column) pair exists. Catches schema drift where
 * the migration runner recorded a migration as applied but the column never
 * actually got added (lazy-created tables, partial failures, etc).
 *
 * @param array{0:string,1:string} $args [$table, $column]
 */
function admin_hc_column_exists(int $tid, array $args): array {
    [$table, $col] = $args;
    $st = getDB()->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name   = :t AND column_name = :c"
    );
    $st->execute(['t' => $table, 'c' => $col]);
    if ((int) $st->fetchColumn() === 1) return ['status' => 'ok', 'detail' => "{$table}.{$col} present"];
    // Distinguish "table is missing" from "column is missing on existing table"
    $ts = getDB()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t");
    $ts->execute(['t' => $table]);
    if ((int) $ts->fetchColumn() === 0) {
        return ['status' => 'skipped', 'detail' => "{$table} table not present on this tenant"];
    }
    return ['status' => 'fail', 'detail' => "{$table} exists but missing column {$col} — re-run module migration"];
}

function admin_hc_branding_endpoint(int $tid): array {
    require_once __DIR__ . '/../core/tenant_branding.php';
    $b = cf_tenant_branding($tid);
    if (!is_array($b) || !isset($b['accent_color'])) return ['status' => 'fail', 'detail' => 'branding helper returned unexpected shape'];
    $custom = ($b['accent_color'] !== '#0f172a') || !empty($b['logo_url']) || !empty($b['signature_html']);
    return ['status' => 'ok', 'detail' => $custom ? 'tenant has custom branding' : 'using defaults'];
}

function admin_hc_digest_helper(int $tid): array {
    require_once __DIR__ . '/../core/digest_schedules.php';
    $s = cf_digest_schedule_get($tid, 'money_movement');
    if (empty($s) || !isset($s['dow'], $s['hour'])) return ['status' => 'fail', 'detail' => 'schedule helper returned no shape'];
    return ['status' => 'ok', 'detail' => sprintf('source=%s dow=%d hour=%dZ', $s['source'] ?? '?', (int) $s['dow'], (int) $s['hour'])];
}

function admin_hc_snapshot_renders(int $tid): array {
    require_once __DIR__ . '/../modules/billing/lib/money_movement.php';
    $snap = moneyMovementSnapshot($tid, date('Y-m-d'));
    if (!isset($snap['cash_in'], $snap['cash_out'])) return ['status' => 'fail', 'detail' => 'snapshot missing keys'];
    $email = moneyMovementRenderEmail($snap, 'CoreFlux');
    $okHtml = is_array($email) && isset($email['html']) && strlen($email['html']) > 200;
    return ['status' => $okHtml ? 'ok' : 'fail', 'detail' => $okHtml ? sprintf('rendered %d bytes html', strlen($email['html'])) : 'render output too small'];
}

function admin_hc_statement_renders(int $tid): array {
    require_once __DIR__ . '/../modules/billing/lib/statement.php';
    $sample = [['id' => 1, 'invoice_number' => 'HC-1', 'due_date' => date('Y-m-d'), 'amount_due' => 100, 'days_overdue' => 0]];
    $email = billingStatementRenderEmail('CoreFlux', 'Sample Co', $sample, billingStatementBucket($sample), date('Y-m-d'), null, $tid);
    $ok = isset($email['subject']) && str_contains($email['subject'], '1 open invoice');
    return ['status' => $ok ? 'ok' : 'fail', 'detail' => $ok ? 'render shape valid' : 'subject malformed'];
}

function admin_hc_pdf_binary(int $tid): array {
    require_once __DIR__ . '/../core/pdf_renderer.php';
    if (!function_exists('_cf_pdf_find_renderer')) return ['status' => 'warn', 'detail' => 'renderer module loaded but finder helper missing'];
    $bin = _cf_pdf_find_renderer();
    if ($bin === null) return ['status' => 'fail', 'detail' => 'no chromium/wkhtmltopdf on host — PDF endpoints will 503'];
    return ['status' => 'ok', 'detail' => basename((string) $bin)];
}

function admin_hc_mail_bootstrap(int $tid): array {
    require_once __DIR__ . '/../core/mail_bootstrap.php';
    try {
        $svc = cf_mail_bootstrap();
        return ['status' => $svc ? 'ok' : 'fail', 'detail' => $svc ? 'service bootstrapped' : 'returned null'];
    } catch (\Throwable $e) { return ['status' => 'fail', 'detail' => $e->getMessage()]; }
}

function admin_hc_emergent_key(int $tid): array {
    $key = getenv('EMERGENT_LLM_KEY') ?: (defined('EMERGENT_LLM_KEY') ? EMERGENT_LLM_KEY : '');
    if (!$key) return ['status' => 'warn', 'detail' => 'not set — AI features will fall back to no-op'];
    return ['status' => 'ok', 'detail' => 'set (' . substr($key, 0, 6) . '…' . substr($key, -4) . ')'];
}

function admin_hc_cron_script(int $tid, string $relPath): array {
    $abs = __DIR__ . '/../' . $relPath;
    if (!is_file($abs)) return ['status' => 'fail', 'detail' => "missing: {$relPath}"];
    $out = []; $rc = 0; @exec('php -l ' . escapeshellarg($abs) . ' 2>&1', $out, $rc);
    return ['status' => $rc === 0 ? 'ok' : 'fail', 'detail' => $rc === 0 ? 'syntax ok' : implode(' ', $out)];
}

function admin_hc_oidc_discovery(int $tid): array {
    try {
        $st = getDB()->prepare('SELECT issuer_url, is_enabled FROM tenant_sso_domains WHERE tenant_id = :t LIMIT 1');
        $st->execute(['t' => $tid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return ['status' => 'skipped', 'detail' => 'tenant_sso_domains migration not applied']; }
    if (!$row)                       return ['status' => 'skipped', 'detail' => 'no SSO configured for this tenant'];
    if (empty($row['is_enabled']))   return ['status' => 'skipped', 'detail' => 'SSO disabled for this tenant'];

    require_once __DIR__ . '/../core/oidc.php';
    try {
        $doc = oidcDiscovery((string) $row['issuer_url']);
        $ok  = !empty($doc['authorization_endpoint']) && !empty($doc['token_endpoint']) && !empty($doc['jwks_uri']);
        return ['status' => $ok ? 'ok' : 'fail', 'detail' => $ok ? 'discovery doc complete' : 'discovery doc missing required endpoints'];
    } catch (\Throwable $e) {
        return ['status' => 'fail', 'detail' => 'discovery fetch failed: ' . $e->getMessage()];
    }
}

function admin_hc_vite_bundle(int $tid): array {
    $verFile = __DIR__ . '/../.deploy-version';
    if (!is_file($verFile)) return ['status' => 'warn', 'detail' => 'no .deploy-version file'];
    $text = (string) file_get_contents($verFile);
    if (!preg_match_all('#spa-assets/(index-[A-Za-z0-9_-]+\.(?:js|css))#', $text, $m)) {
        return ['status' => 'warn', 'detail' => 'no expected_bundle entries'];
    }
    $missing = [];
    foreach ($m[1] as $f) {
        if (!is_file(__DIR__ . '/../spa-assets/' . $f)) $missing[] = $f;
    }
    if ($missing) return ['status' => 'fail', 'detail' => 'missing: ' . implode(', ', $missing)];
    return ['status' => 'ok', 'detail' => count($m[1]) . ' expected bundle file(s) present'];
}

function admin_hc_deploy_version(int $tid): array {
    // Verify the latest mtime JS in spa-assets matches the one declared in .deploy-version.
    $verFile = __DIR__ . '/../.deploy-version';
    if (!is_file($verFile)) return ['status' => 'warn', 'detail' => 'no .deploy-version file'];
    $text = (string) file_get_contents($verFile);
    if (!preg_match('#spa-assets/(index-[A-Za-z0-9_-]+\.js)#', $text, $m)) {
        return ['status' => 'warn', 'detail' => 'no expected JS bundle in deploy-version'];
    }
    $expected = $m[1];
    $dir = __DIR__ . '/../spa-assets';
    $jsFiles = glob($dir . '/index-*.js') ?: [];
    if (!$jsFiles) return ['status' => 'fail', 'detail' => 'no index-*.js in spa-assets'];
    usort($jsFiles, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $actual = basename($jsFiles[0]);
    return $actual === $expected
        ? ['status' => 'ok',   'detail' => $expected]
        : ['status' => 'warn', 'detail' => "newest on disk is {$actual} but .deploy-version expects {$expected}"];
}
