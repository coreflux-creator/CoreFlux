<?php
/**
 * Treasury Scenario Share Links smoke test.
 *
 * Asserts:
 *   - Migration 027 creates `treasury_scenario_share_links` with hashed
 *     token uniqueness, audit columns (view_count, last_viewed_at,
 *     last_viewed_ip), expires/revoke columns, tenant + active indexes.
 *   - api/treasury_scenario_share.php is a multi-action endpoint:
 *       view   → PUBLIC, no api_require_auth() on this path. Token-only
 *                gate, returns 404 for invalid token, 410 for revoked
 *                or expired. Audit-bumps view_count + last_viewed_at +
 *                last_viewed_ip on success (best-effort, never blocks).
 *       create → POST, RBAC manage. Validates kind, both presets exist
 *                in the tenant (cross-tenant leak guard), label cap,
 *                days_horizon clamp, expires_in_days clamp 1..30
 *                (default 7). Generates 30-byte hex token, stores
 *                SHA-256(token), returns BOTH cleartext token and
 *                public URL constructed from the request host.
 *       list   → GET, RBAC manage. Surfaces computed status
 *                ('active'|'expired'|'revoked').
 *       revoke → POST, RBAC manage. Sets revoked_at = NOW. 404 on
 *                missing row.
 *   - Module-namespaced kebab alias delegates.
 *   - App.jsx mounts /share/scenario as a public route alongside
 *     /vendor/portal.
 *   - ScenarioShare.jsx reads ?token= from URL, calls public action
 *     via fetch (NOT the authed api client which would bounce to
 *     /login), renders chart + tiles + event stack(s), handles
 *     missing token / loading / error states.
 *   - TreasuryScenarioCompare.jsx exposes the share-form workflow:
 *     open button, label input, expiry selector, create + copy
 *     buttons, result url with select-on-focus.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration — 027_scenario_share_links.sql\n";
$migPath = "{$ROOT}/core/migrations/027_scenario_share_links.sql";
$assert('migration file exists',                  is_readable($migPath));
$mig = (string) file_get_contents($migPath);
$assert('CREATE TABLE IF NOT EXISTS (idempotent)',
    strpos($mig, 'CREATE TABLE IF NOT EXISTS treasury_scenario_share_links') !== false);
$assert("kind ENUM('single','compare')",
    strpos($mig, "kind                ENUM('single','compare') NOT NULL") !== false);
$assert('token_hash CHAR(64) (SHA-256 hex)',
    strpos($mig, 'token_hash          CHAR(64) NOT NULL') !== false);
$assert('UNIQUE on token_hash',
    strpos($mig, 'UNIQUE KEY uk_token_hash (token_hash)') !== false);
$assert('audit columns present',
    strpos($mig, 'view_count') !== false
    && strpos($mig, 'last_viewed_at') !== false
    && strpos($mig, 'last_viewed_ip') !== false);
$assert('expires + revoke columns',
    strpos($mig, 'expires_at          TIMESTAMP NOT NULL') !== false
    && strpos($mig, 'revoked_at          TIMESTAMP NULL') !== false);
$assert('active-link lookup index',
    strpos($mig, 'INDEX idx_tssl_active (tenant_id, revoked_at, expires_at)') !== false);

echo "\nEndpoint — api/treasury_scenario_share.php\n";
$apiPath = "{$ROOT}/api/treasury_scenario_share.php";
$assert('endpoint exists',                        is_readable($apiPath));
$assert('parses',                                 $lint($apiPath));
$api = (string) file_get_contents($apiPath);
$assert('declares strict_types',                  strpos($api, 'declare(strict_types=1)') !== false);
$assert('imports shared liquidity engine',
    strpos($api, "require_once __DIR__ . '/../core/treasury/liquidity_projection.php'") !== false);

echo "\nview action — PUBLIC, token-only gate\n";
$assert('view action is BEFORE api_require_auth() (public path)',
    strpos($api, "// ─── Public read — token resolution only.") !== false
    && preg_match('/if \(\$action === \'view\'.*?api_require_auth/s', $api) === 1);
$assert('rejects short / missing tokens with 404',
    strpos($api, "api_error('Invalid share link', 404)") !== false);
$assert('hashes token for lookup (never compares cleartext)',
    strpos($api, "\$hash = hash('sha256', \$token);") !== false
    && strpos($api, "WHERE token_hash = :h") !== false);
$assert('410 on revoked link',
    strpos($api, "api_error('This share link has been revoked', 410)") !== false);
$assert('410 on expired link',
    strpos($api, "api_error('This share link has expired', 410)") !== false);
$assert('410 when source preset deleted',
    strpos($api, "api_error('Source scenario no longer exists', 410)") !== false);
$assert('audit-bumps view_count + last_viewed_at + last_viewed_ip',
    strpos($api, 'view_count = view_count + 1') !== false
    && strpos($api, 'last_viewed_at = NOW()') !== false
    && strpos($api, 'last_viewed_ip = :ip') !== false);
$assert('audit update wrapped in try/catch (never blocks read)',
    preg_match("/try \{\s*(?:\/\/[^\n]*\n\s*)?\\\$upd = \\\$pdo->prepare\([\s\S]+?catch \(\\\\Throwable/", $api) === 1);
$assert('compare-kind link projects scenario_b too',
    strpos($api, "if (\$row['kind'] === 'compare' && \$row['preset_b_id'])") !== false);
$assert('view action returns source detail and enriched daily rows',
    strpos($api, '$baselineSourceDetail = liquidityProjectionSourceDetail($datasets);') !== false
    && strpos($api, '$sourceDetailA = liquidityProjectionSourceDetail($datasets, [') !== false
    && strpos($api, '$sourceDetailB = liquidityProjectionSourceDetail($datasets, [') !== false
    && strpos($api, '$baselineDaily = liquidityAttachDailySourceDetail(') !== false
    && strpos($api, '$dailyA = liquidityAttachDailySourceDetail(') !== false
    && strpos($api, '$dailyB = liquidityAttachDailySourceDetail(') !== false
    && strpos($api, "'source_detail'        => \$sourceDetailA") !== false);

echo "\ncreate action — RBAC + cross-tenant leak guard\n";
$assert('create requires treasury.payment.manage',
    preg_match("/action === 'create'.*?treasury\.payment\.manage/s", $api) === 1);
$assert('kind whitelist (single|compare)',
    strpos($api, "in_array(\$kind, ['single', 'compare'], true)") !== false);
$assert('rejects compare with same preset on both sides',
    strpos($api, "Compare links must reference two different scenarios") !== false);
$assert('checks preset_a belongs to caller tenant',
    preg_match('/SELECT id FROM treasury_scenario_presets WHERE tenant_id = :t AND id = :id/', $api) === 1);
$assert('checks preset_b belongs to caller tenant when set',
    strpos($api, "if (\$presetB !== null)") !== false);
$assert('label length cap (200)',                strpos($api, "label max 200 chars") !== false);
$assert('expires_in_days clamped 1..30 (default 7)',
    strpos($api, '$expiresInDays = max(1, min(30, (int) ($body[\'expires_in_days\'] ?? 7)))') !== false);
$assert('30-byte token (60-char hex)',
    strpos($api, 'bin2hex(random_bytes(30))') !== false);
$assert('persists ONLY SHA-256 hash, returns cleartext to caller',
    strpos($api, "\$tokenHash = hash('sha256', \$token);") !== false
    && strpos($api, "'token'      => \$token,") !== false);
$assert('builds URL from request host (works dev/staging/prod)',
    strpos($api, "\$_SERVER['HTTP_HOST']") !== false
    && strpos($api, "/share/scenario?token=") !== false);
$assert('honors HTTPS scheme',
    strpos($api, "(!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off')") !== false);

echo "\nlist action\n";
$assert('list requires treasury.payment.manage',
    preg_match("/action === 'list'.*?treasury\.payment\.manage/s", $api) === 1);
$assert('list emits computed status (active|expired|revoked)',
    strpos($api, "WHEN revoked_at IS NOT NULL THEN 'revoked'") !== false
    && strpos($api, "WHEN expires_at < NOW()     THEN 'expired'") !== false
    && strpos($api, "ELSE 'active'") !== false);

echo "\nrevoke action\n";
$assert('revoke requires treasury.payment.manage',
    preg_match("/action === 'revoke'.*?treasury\.payment\.manage/s", $api) === 1);
$assert('revoke sets revoked_at and scopes by tenant',
    preg_match('/SET revoked_at = NOW\(\).*?WHERE id = :id AND tenant_id = :t AND revoked_at IS NULL/s', $api) === 1);
$assert('revoke 404 when row missing',
    strpos($api, "api_error('Share link not found', 404)") !== false);

echo "\nKebab alias — /modules/treasury/api/scenario_share.php\n";
$alias = "{$ROOT}/modules/treasury/api/scenario_share.php";
$assert('alias file exists',                      is_readable($alias));
$assert('alias delegates to platform endpoint',
    strpos((string) file_get_contents($alias), '/api/treasury_scenario_share.php') !== false);

echo "\nApp routing — /share/scenario public route\n";
$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert('imports ScenarioShare',                  strpos($app, "import ScenarioShare from './pages/ScenarioShare'") !== false);
$assert('mounts /share/scenario route',           strpos($app, '<Route path="/share/scenario"') !== false);

echo "\nUI — public ScenarioShare.jsx page\n";
$pgPath = "{$ROOT}/dashboard/src/pages/ScenarioShare.jsx";
$assert('page file exists',                       is_readable($pgPath));
$pg = (string) file_get_contents($pgPath);
$assert('uses useSearchParams to read ?token=',   strpos($pg, "useSearchParams") !== false
                                                  && strpos($pg, "params.get('token')") !== false);
$assert('uses raw fetch (NOT authed api client)', strpos($pg, "fetch(`/api/treasury_scenario_share.php?action=view&token=") !== false
                                                  && strpos($pg, "import { api") === false);
$assert('encodeURIComponent on token',            strpos($pg, 'encodeURIComponent(token)') !== false);
$assert('page root testid',                       strpos($pg, 'data-testid="scenario-share-page"') !== false);
$assert('loading testid',                         strpos($pg, 'data-testid="scenario-share-loading"') !== false);
$assert('error testid',                           strpos($pg, 'data-testid="scenario-share-error"') !== false);
$assert('chart testid',                           strpos($pg, 'data-testid="scenario-share-chart"') !== false);
$assert('tiles testid',                           strpos($pg, 'data-testid="scenario-share-tiles"') !== false);
$assert('events container testid',                strpos($pg, 'data-testid="scenario-share-events"') !== false);
$assert('source detail panel testids',
    strpos($pg, 'data-testid="scenario-share-source-detail"') !== false
    && strpos($pg, 'testid="scenario-share-source-baseline"') !== false
    && strpos($pg, 'testid="scenario-share-source-a"') !== false
    && strpos($pg, 'testid="scenario-share-source-b"') !== false
    && strpos($pg, 'function SourceDetailPanel(') !== false);
$assert('shows expiry warning copy in error state',
    strpos($pg, 'Share links expire 7 days after creation') !== false);
$assert('renders second scenario only when present',
    strpos($pg, 'isCompare && (') !== false);

echo "\nUI — Compare page share form\n";
$cmp = (string) file_get_contents("{$ROOT}/dashboard/src/pages/TreasuryScenarioCompare.jsx");
$assert('imports Share2 + Copy + Check icons',
    strpos($cmp, 'Share2') !== false && strpos($cmp, 'Copy') !== false && strpos($cmp, 'Check') !== false);
$assert('share-open button testid',               strpos($cmp, 'data-testid="scenario-compare-share-open"') !== false);
$assert('share panel testid',                     strpos($cmp, 'data-testid="scenario-compare-share-panel"') !== false);
$assert('share-label input testid',               strpos($cmp, 'data-testid="scenario-compare-share-label"') !== false);
$assert('share-expiry selector testid',           strpos($cmp, 'data-testid="scenario-compare-share-expiry"') !== false);
$assert('share-create button testid',             strpos($cmp, 'data-testid="scenario-compare-share-create"') !== false);
$assert('share-result url testid',                strpos($cmp, 'data-testid="scenario-compare-share-url"') !== false);
$assert('share-copy button testid',               strpos($cmp, 'data-testid="scenario-compare-share-copy"') !== false);
$assert('share-error testid',                     strpos($cmp, 'data-testid="scenario-compare-share-error"') !== false);
$assert('createShareLink POSTs to share endpoint',
    strpos($cmp, "api.post('/api/v1/treasury/scenario-share?action=create'") !== false
    && strpos($cmp, "kind: 'compare'") !== false);
$assert('createShareLink blocks self-comparison',
    strpos($cmp, '!a || !b || a.id === b.id') !== false);
$assert('copyShareUrl uses navigator.clipboard',
    strpos($cmp, 'navigator.clipboard.writeText(shareResult.url)') !== false);
$assert('share button only shows when comparison ready',
    strpos($cmp, 'data && a && b && a.id !== b.id && (') !== false);
$assert('share input is readOnly + select-on-focus',
    strpos($cmp, 'readOnly') !== false
    && strpos($cmp, 'onFocus={(e) => e.target.select()}') !== false);
$assert('expiry selector covers 1/3/7/14/30 day options',
    substr_count($cmp, 'Expires in') >= 5);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
