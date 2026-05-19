<?php
/**
 * Smoke: Finance Distribution & Polish sprint.
 *
 * Covers (in single file for compactness — every assertion is independent):
 *   A1  snapshot history table + write/read/wow helpers
 *   F1  tenant_mail_branding migration, helper, API, admin UI
 *   B1  share link mint/revoke + public token-gated view
 *   B2  PDF endpoints for money_movement + statement + period close
 *   C1  unified digest_schedules table + helper + API + admin UI
 *   C2  KPI annotations on Money Movement preview UI
 *   D1  send-statements batch API + Aging UI batch button + report modal
 *   D2  Money Movement archive API + UI
 *   E1  close_packet.php ?format=pdf branch
 */
declare(strict_types=1);

require_once __DIR__ . '/../modules/billing/lib/money_movement.php';
require_once __DIR__ . '/../core/digest_schedules.php';

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$parses = fn (string $p): bool => is_file($p)
    && (int) shell_exec('php -l ' . escapeshellarg($p) . ' >/dev/null 2>&1; echo $?') === 0;
$read = fn (string $p) => (string) file_get_contents($p);

/* ──────────────────────  A1: Snapshot history  ────────────────────── */
echo "A1) snapshot history table + helpers\n";
$mig = $read(__DIR__ . '/../modules/billing/migrations/010_money_movement_snapshots.sql');
$a('migration creates tenant_money_movement_snapshots',  str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_money_movement_snapshots'));
$a('snapshot stored as MEDIUMTEXT JSON',                 str_contains($mig, 'snapshot_json MEDIUMTEXT'));
$a('unique (tenant_id, as_of)',                          str_contains($mig, 'UNIQUE KEY uq_tmms (tenant_id, as_of)'));
foreach (['moneyMovementWriteSnapshot','moneyMovementGetPriorSnapshot','moneyMovementListSnapshots','moneyMovementReadSnapshot','moneyMovementWowDelta'] as $fn) {
    $a("fn: {$fn}", function_exists($fn));
}

$cur = ['cash_in' => ['total' => 1000], 'cash_out' => ['total' => 600]];
$pri = ['as_of' => '2026-02-02', 'cash_in' => ['total' => 800], 'cash_out' => ['total' => 700]];
$d   = moneyMovementWowDelta($cur, $pri);
$a('wow available with prior',                           !empty($d['available']));
$a('wow prior_as_of matches',                            ($d['prior_as_of'] ?? null) === '2026-02-02');
$a('wow net delta = +300 (was 100, now 400)',            abs(($d['net']['delta'] ?? 0) - 300) < 0.001);
$a('wow net pct = +300%',                                abs(($d['net']['pct']   ?? 0) - 300) < 0.5);
$a('wow with no prior is unavailable',                   empty(moneyMovementWowDelta($cur, null)['available']));
$a('wow zero-denom guard returns null pct',              moneyMovementWowDelta(['cash_in'=>['total'=>10],'cash_out'=>['total'=>0]], ['cash_in'=>['total'=>0],'cash_out'=>['total'=>0]])['net']['pct'] === null);

/* ──────────────────────  F1: Tenant mail branding  ────────────────────── */
echo "\nF1) tenant mail branding\n";
$mig = $read(__DIR__ . '/../core/migrations/032_tenant_mail_branding.sql');
$a('migration creates tenant_mail_branding',             str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_mail_branding'));
$a('accent_color CHAR(7)',                               str_contains($mig, 'accent_color       CHAR(7)'));
$a('show_powered_by defaults 1',                         str_contains($mig, 'show_powered_by    TINYINT(1)   NOT NULL DEFAULT 1'));

$brandLib = __DIR__ . '/../core/tenant_branding.php';
$a('tenant_branding.php parses',                         $parses($brandLib));
require_once $brandLib;
foreach (['cf_tenant_branding','cf_branding_header_html','cf_branding_footer_html'] as $fn) {
    $a("fn: {$fn}",                                       function_exists($fn));
}
$hdr = cf_branding_header_html(['logo_url' => 'https://x.test/logo.png', 'accent_color' => '#abcdef'], 'Hello');
$a('header renders logo img',                            str_contains($hdr, '<img src="https://x.test/logo.png"'));
$a('header uses accent in border-left + h2 colour',      str_contains($hdr, 'border-left:4px solid #abcdef') && str_contains($hdr, 'color:#abcdef'));
$a('header escapes title HTML',                          str_contains(cf_branding_header_html(['accent_color' => '#000000'], 'a<b>')['html'] ?? cf_branding_header_html(['accent_color' => '#000000'], 'a<b>'), 'a&lt;b&gt;'));
$ftr = cf_branding_footer_html(['signature_html' => 'Hi<br>there', 'show_powered_by' => true], 'Acme');
$a('footer renders signature_html',                      str_contains($ftr, 'Hi<br>there'));
$a('footer includes powered-by line when opted-in',      str_contains($ftr, 'powered by CoreFlux'));
$a('footer hides powered-by when opted-out',             !str_contains(cf_branding_footer_html(['show_powered_by' => false], 'Acme'), 'powered by CoreFlux'));
$a('signature script tags stripped',                     !str_contains(cf_branding_footer_html(['signature_html' => 'safe<script>alert(1)</script>', 'show_powered_by' => true], 'X'), '<script>'));

$apiPath = __DIR__ . '/../api/tenant_mail_branding.php';
$api     = $read($apiPath);
$a('branding API parses',                                $parses($apiPath));
$a('POST validates logo_url is https',                   str_contains($api, "preg_match('#^https://#i', \$logo)"));
$a('POST validates accent_color hex',                    str_contains($api, "preg_match('/^#[0-9a-f]{6}\$/i', \$accent)"));
$a('POST gated by admin role',                           str_contains($api, "Admin role required"));

$ui = $read(__DIR__ . '/../dashboard/src/pages/MailBrandingAdmin.jsx');
foreach (['admin-mail-branding','admin-mail-branding-logo','admin-mail-branding-accent','admin-mail-branding-signature','admin-mail-branding-powered-by','admin-mail-branding-save'] as $tid) {
    $a("testid: {$tid}",                                  str_contains($ui, $tid));
}

// Renderer wiring: Money Movement + statement honour branding
$mm = $read(__DIR__ . '/../modules/billing/lib/money_movement.php');
$a('MM lib imports tenant_branding',                     str_contains($mm, "require_once __DIR__ . '/../../../core/tenant_branding.php'"));
$a('MM render takes branding param + cf_branding_header',str_contains($mm, 'cf_branding_header_html($branding'));
$a('MM render emits footer via cf_branding_footer_html', str_contains($mm, 'cf_branding_footer_html($branding'));
$st = $read(__DIR__ . '/../modules/billing/lib/statement.php');
$a('Statement render imports tenant_branding',           str_contains($st, "require_once __DIR__ . '/../../../core/tenant_branding.php'"));
$a('Statement render uses cf_branding_header_html',      str_contains($st, 'cf_branding_header_html($branding'));

/* ──────────────────────  B1: Public share link  ────────────────────── */
echo "\nB1) money movement share link (public)\n";
$mig = $read(__DIR__ . '/../modules/billing/migrations/011_money_movement_share_links.sql');
$a('migration creates billing_money_movement_share_links', str_contains($mig, 'billing_money_movement_share_links'));
$a('token_sha256 unique',                                str_contains($mig, 'UNIQUE KEY uq_bmmsl_token (token_sha256)'));
$a('expires_at + revoked_at columns present',            str_contains($mig, 'expires_at') && str_contains($mig, 'revoked_at'));

$apiPath = __DIR__ . '/../modules/billing/api/money_movement_share_links.php';
$api     = $read($apiPath);
$a('share-links API parses',                             $parses($apiPath));
$a('mints 48-hex raw token (bin2hex random_bytes(24))',  str_contains($api, 'bin2hex(random_bytes(24))'));
$a('persists sha256 only',                               str_contains($api, "hash('sha256', \$rawToken)"));
$a('clamps ttl to 1..180 days',                          str_contains($api, 'max(1, min(180, (int) ($body[\'ttl_days\'] ?? 30)))'));
$a('returns public_url + raw_token in mint response',    str_contains($api, "'raw_token'") && str_contains($api, "'public_url'"));
$a('?action=revoke flips revoked_at',                    str_contains($api, "SET revoked_at = NOW()"));
$a('mint write gated by billing.invoice.create',         str_contains($api, "rbac_legacy_require(\$user, 'billing.invoice.create')"));

$viewPath = __DIR__ . '/../api/billing/money_movement_view.php';
$view     = $read($viewPath);
$a('public view parses',                                 $parses($viewPath));
$a('public view in /api/billing (direct file, no router auth)', is_file($viewPath));
$a('public view validates 48-hex token',                 str_contains($view, "preg_match('/^[a-f0-9]{48}\$/'"));
$a('public view matches by sha256',                      str_contains($view, "hash('sha256', \$raw)"));
$a('public view refuses revoked link',                   str_contains($view, 'has been revoked by the tenant'));
$a('public view refuses expired link',                   str_contains($view, 'This share link has expired'));
$a('public view bumps view_count + last_viewed_at',      str_contains($view, 'view_count = view_count + 1, last_viewed_at = NOW()'));
$a('public view writes audit log share_link_viewed',     str_contains($view, "'billing.money_movement.share_link_viewed'"));
$a('public view renders branded banner + content',       str_contains($view, 'data-testid="money-movement-share-banner"')
                                                         && str_contains($view, 'data-testid="money-movement-share-content"'));

/* ──────────────────────  B2: PDF endpoints  ────────────────────── */
echo "\nB2) PDF endpoints (money movement + statement + close packet)\n";
foreach ([
    __DIR__ . '/../modules/billing/api/money_movement_pdf.php',
    __DIR__ . '/../modules/billing/api/statement_pdf.php',
] as $p) {
    $a("parses: " . basename($p),                         $parses($p));
    $src = $read($p);
    $a("uses cf_render_html_to_pdf: " . basename($p),     str_contains($src, 'cf_render_html_to_pdf('));
    $a("emits application/pdf: " . basename($p),          str_contains($src, "header('Content-Type: application/pdf')"));
    $a("inline disposition by default: " . basename($p),  str_contains($src, "'inline'"));
}

$cp = $read(__DIR__ . '/../modules/accounting/api/close_packet.php');
$a('close_packet adds ?format=pdf branch',               str_contains($cp, "(api_query('format') ?? '') === 'pdf'"));
$a('close_packet PDF uses cf_render_html_to_pdf',        str_contains($cp, 'cf_render_html_to_pdf($page'));

/* ──────────────────────  C1: Unified digest scheduler  ────────────────────── */
echo "\nC1) unified digest scheduler\n";
$mig = $read(__DIR__ . '/../core/migrations/033_tenant_digest_schedules.sql');
$a('migration creates tenant_digest_schedules',          str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_digest_schedules'));
$a('composite PK (tenant_id, digest_key)',               str_contains($mig, 'PRIMARY KEY (tenant_id, digest_key)'));

foreach (['cf_digest_schedule_get','cf_digest_schedule_should_fire','cf_digest_schedule_set'] as $fn) {
    $a("fn: {$fn}",                                       function_exists($fn));
}
$a('default money_movement = Mon 13 UTC',                DIGEST_DEFAULTS['money_movement']['dow'] === 1 && DIGEST_DEFAULTS['money_movement']['hour'] === 13);
$a('default dunning daily',                              DIGEST_DEFAULTS['dunning']['cadence'] === 'daily');

// should_fire boundary tests (force the timestamp)
$mon13 = strtotime('2026-02-02 13:00:00 UTC');
$mon14 = strtotime('2026-02-02 14:00:00 UTC');
$tue13 = strtotime('2026-02-03 13:00:00 UTC');
$weekly = ['dow'=>1,'hour'=>13,'enabled'=>1,'cadence'=>'weekly'];
$daily  = ['dow'=>0,'hour'=>14,'enabled'=>1,'cadence'=>'daily'];
$a('weekly: fires when both match',                      cf_digest_schedule_should_fire($weekly, $mon13) === true);
$a('weekly: skips when hour off',                        cf_digest_schedule_should_fire($weekly, $mon14) === false);
$a('weekly: skips when day off',                         cf_digest_schedule_should_fire($weekly, $tue13) === false);
$a('weekly: skips when disabled',                        cf_digest_schedule_should_fire(['dow'=>1,'hour'=>13,'enabled'=>0,'cadence'=>'weekly'], $mon13) === false);
$a('daily: fires whenever hour matches',                 cf_digest_schedule_should_fire($daily, $mon14) === true && cf_digest_schedule_should_fire($daily, $tue13 + 3600) === true);
$a('daily: skips when hour off',                         cf_digest_schedule_should_fire($daily, $mon13) === false);

$apiPath = __DIR__ . '/../api/tenant_digest_schedules.php';
$api     = $read($apiPath);
$a('digest schedule API parses',                         $parses($apiPath));
$a('POST validates digest_key whitelist',                str_contains($api, "in_array(\$key, \$ALLOWED_KEYS, true)"));
$a('POST validates dow + hour ranges',                   str_contains($api, "api_error('dow must be 0..7'") && str_contains($api, "api_error('hour must be 0..23'"));

$ui = $read(__DIR__ . '/../dashboard/src/pages/DigestSchedulesAdmin.jsx');
foreach (['admin-digest-schedules','admin-digest-enabled-${key}','admin-digest-hour-${key}','admin-digest-save-${key}'] as $tid) {
    $a("testid pattern: {$tid}",                          str_contains($ui, $tid));
}
$cron = $read(__DIR__ . '/../scripts/money_movement_weekly.php');
$a('cron honours per-tenant schedule',                   str_contains($cron, "cf_digest_schedule_should_fire(\$schedule, time())"));
$a('cron writes snapshot history',                       str_contains($cron, 'moneyMovementWriteSnapshot($snapshot)'));

/* ──────────────────────  C2: KPI notes on Money Movement preview  ────────────────────── */
echo "\nC2) KPI annotations on Money Movement preview\n";
$ui = $read(__DIR__ . '/../modules/billing/ui/MoneyMovementPreview.jsx');
$a('imports KpiNote component',                          str_contains($ui, "import KpiNote from '../../../dashboard/src/components/KpiNote'"));
$a('loads notes via /api/kpi_notes.php',                 str_contains($ui, "api.get('/api/kpi_notes.php')"));
foreach (['money_movement_net','money_movement_cash_in','money_movement_cash_out','money_movement_runway'] as $k) {
    $a("KpiNote slot: {$k}",                              str_contains($ui, "noteKey=\"{$k}\""));
}
$a('KPI notes grid testid',                              str_contains($ui, 'data-testid="money-movement-kpi-notes"'));

/* ──────────────────────  D1: Batch statements  ────────────────────── */
echo "\nD1) batch statement send\n";
$apiPath = __DIR__ . '/../modules/billing/api/send_statements_batch.php';
$api     = $read($apiPath);
$a('batch API parses',                                   $parses($apiPath));
$a('iterates aging rows past-due > 0.005',               str_contains($api, '$past <= 0.005'));
$a('skips when no AR contact',                           str_contains($api, "'reason' => 'no AR contact on file'"));
$a('skips when no open invoices',                        str_contains($api, "'reason' => 'no open invoices at as_of'"));
$a('uses same idempotency key as singular send',         str_contains($api, "\"statement-{\$tid}-{\$slug}-\" . date('Y-m-d')"));
$a('dry-run returns would_send rows',                    str_contains($api, "'status' => 'would_send'"));
$a('write audited as batch_sent',                        str_contains($api, "'billing.statement.batch_sent'"));

$ui = $read(__DIR__ . '/../modules/billing/ui/AgingTable.jsx');
$a('Aging UI: batch preview button',                     str_contains($ui, 'data-testid="billing-aging-batch-preview"'));
$a('Aging UI: batch send button in modal',               str_contains($ui, 'data-testid="billing-aging-batch-send"'));
$a('Aging UI: batch modal rows table',                   str_contains($ui, 'data-testid="billing-aging-batch-rows"'));
$a('Aging UI: per-row statement PDF link',               str_contains($ui, 'data-testid="billing-aging-statement-pdf"'));

/* ──────────────────────  D2: Money Movement archive  ────────────────────── */
echo "\nD2) Money Movement archive\n";
$apiPath = __DIR__ . '/../modules/billing/api/money_movement_archive.php';
$api     = $read($apiPath);
$a('archive API parses',                                 $parses($apiPath));
$a('archive GET list returns rows from snapshot table',  str_contains($api, 'moneyMovementListSnapshots($tid, 12)'));
$a('archive GET as_of returns email + wow',              str_contains($api, "'wow'      => \$wow") && str_contains($api, "'email'    => moneyMovementRenderEmail"));
$a('archive 404 when snapshot missing',                  str_contains($api, "No snapshot for {\$asOf}."));

$ui = $read(__DIR__ . '/../modules/billing/ui/MoneyMovementArchive.jsx');
foreach (['money-movement-archive','money-movement-archive-empty','money-movement-archive-modal','money-movement-archive-html'] as $tid) {
    $a("testid: {$tid}",                                  str_contains($ui, $tid));
}
$bm = $read(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('BillingModule routes /money-movement/archive',       str_contains($bm, 'path="money-movement/archive" element={<MoneyMovementArchive />}'));
$a('BillingModule nav adds Archive entry',               str_contains($bm, "label: 'Archive'"));

/* ──────────────────────  Money Movement preview wires share + PDF  ────────────────────── */
echo "\nMoney Movement preview wires share + PDF buttons\n";
$ui = $read(__DIR__ . '/../modules/billing/ui/MoneyMovementPreview.jsx');
foreach (['money-movement-pdf','money-movement-share-link-mint','money-movement-share-link-new','money-movement-share-links-table'] as $tid) {
    $a("testid: {$tid}",                                  str_contains($ui, $tid));
}
$a('PDF link opens money_movement_pdf.php',              str_contains($ui, '/modules/billing/api/money_movement_pdf.php'));
$a('Share-link list loads from API',                     str_contains($ui, "api.get('/modules/billing/api/money_movement_share_links.php')"));

/* ──────────────────────  AdminModule nav additions  ────────────────────── */
echo "\nAdminModule wiring\n";
$adminMod = $read(__DIR__ . '/../dashboard/src/pages/AdminModule.jsx');
$a('imports MailBrandingAdmin',                          str_contains($adminMod, "import MailBrandingAdmin from './MailBrandingAdmin'"));
$a('imports DigestSchedulesAdmin',                       str_contains($adminMod, "import DigestSchedulesAdmin from './DigestSchedulesAdmin'"));
$a('routes /admin/mail-branding',                        str_contains($adminMod, 'path="/mail-branding"'));
$a('routes /admin/digest-schedules',                     str_contains($adminMod, 'path="/digest-schedules"'));
$a('overview tile for Email branding',                   str_contains($adminMod, '"Email branding"'));
$a('overview tile for Digest schedules',                 str_contains($adminMod, '"Digest schedules"'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
