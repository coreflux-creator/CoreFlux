<?php
/**
 * Treasury Scenario Share Links.
 *
 * Tokenized read-only deep links to a saved scenario (or a compare pair)
 * so a CFO can email a board member / investor without granting tenant
 * access. The cleartext token never lives in the DB — only SHA-256(token)
 * is persisted.
 *
 *   POST   ?action=create
 *     {
 *       "kind":         "single" | "compare",
 *       "preset_a_id":  N,
 *       "preset_b_id":  N (required for compare),
 *       "label":        "Q1 board pack" (optional),
 *       "days_horizon": 90 (1..365),
 *       "expires_in_days": 7 (1..30, default 7)
 *     }
 *     → { id, token, url, expires_at }
 *
 *   GET    ?action=list
 *     → { links: [{id, kind, preset_a_id, preset_b_id, label, expires_at,
 *                 view_count, last_viewed_at, status: 'active'|'expired'|'revoked'}] }
 *
 *   POST   ?action=revoke         body { id }
 *     → { ok: true }
 *
 *   GET    ?action=view&token=X     (PUBLIC — no auth required)
 *     → resolves token, runs the projection(s) via the shared engine,
 *       returns the same shape the dashboard pages render. Audit-logs
 *       view_count + last_viewed_at + last_viewed_ip.
 *
 * RBAC for create / list / revoke = `treasury.payment.manage`.
 *
 * The view action does NOT call api_require_auth() — it is the only
 * public path on this endpoint. Token resolution is the only access
 * control.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/treasury/liquidity_projection.php';

$action = (string) (api_query('action') ?? '');
$pdo    = getDB();
$method = api_method();

// ─── Public read — token resolution only. No platform-user auth. ───
if ($action === 'view' && $method === 'GET') {
    $token = (string) (api_query('token') ?? '');
    if ($token === '' || strlen($token) < 32) api_error('Invalid share link', 404);

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, kind, preset_a_id, preset_b_id, label, days_horizon,
                expires_at, revoked_at
           FROM treasury_scenario_share_links
          WHERE token_hash = :h LIMIT 1'
    );
    $stmt->execute(['h' => $hash]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row)                                 api_error('Share link not found', 404);
    if ($row['revoked_at'] !== null)           api_error('This share link has been revoked', 410);
    if (strtotime((string) $row['expires_at']) < time()) api_error('This share link has expired', 410);

    $tid     = (int) $row['tenant_id'];
    $days    = max(1, min(365, (int) $row['days_horizon']));
    $today   = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$days} days"));

    // Resolve preset(s) inside the share's tenant scope.
    $loadPreset = static function (\PDO $pdo, int $tid, int $id): ?array {
        $s = $pdo->prepare(
            'SELECT id, name, description, events_json
               FROM treasury_scenario_presets
              WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $s->execute(['t' => $tid, 'id' => $id]);
        $r = $s->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $events = json_decode((string) $r['events_json'], true);
        return [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'description' => $r['description'],
            'events'      => is_array($events) ? $events : [],
        ];
    };

    $datasets = liquidityBaselineDatasets($tid, $today, $endDate);
    $buckets  = liquidityBucketDatasets($datasets);

    $applyPreset = static function (array $events, string $today, string $endDate): array {
        $in = []; $out = [];
        foreach ($events as $e) {
            $kind = (string) ($e['kind'] ?? '');
            $amt  = round((float) ($e['amount'] ?? 0), 2);
            $date = (string) ($e['date'] ?? '');
            if (!in_array($kind, ['inflow','outflow'], true)) continue;
            if ($amt <= 0) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if ($date < $today)   $date = $today;
            if ($date > $endDate) $date = $endDate;
            if ($kind === 'inflow') $in[$date]  = ($in[$date]  ?? 0.0) + $amt;
            else                    $out[$date] = ($out[$date] ?? 0.0) + $amt;
        }
        return [$in, $out];
    };

    $pa = $loadPreset($pdo, $tid, (int) $row['preset_a_id']);
    if (!$pa) api_error('Source scenario no longer exists', 410);

    [$aIn, $aOut] = $applyPreset($pa['events'], $today, $endDate);
    $baseline  = liquidityWalkProjection(
        $datasets['starting_cash'], $days, $today,
        $buckets['inflows_by_date'], $buckets['outflows_by_date']
    );
    $simA      = liquidityWalkProjection(
        $datasets['starting_cash'], $days, $today,
        $buckets['inflows_by_date'], $buckets['outflows_by_date'],
        $aIn, $aOut
    );
    $baselineProjection = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets);
    $projectionA = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets, [
        'extra_inflows_by_date' => $aIn,
        'extra_outflows_by_date' => $aOut,
    ]);

    // Audit: bump view counters. Best-effort — never block the read.
    try {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $upd = $pdo->prepare(
            'UPDATE treasury_scenario_share_links
                SET view_count = view_count + 1,
                    last_viewed_at = NOW(),
                    last_viewed_ip = :ip
              WHERE id = :id'
        );
        $upd->execute([
            'id' => (int) $row['id'],
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        ]);
    } catch (\Throwable $e) { error_log('[scenario_share] audit update failed: ' . $e->getMessage()); }

    $payload = [
        'kind'         => (string) $row['kind'],
        'label'        => $row['label'],
        'days_horizon' => $days,
        'expires_at'   => $row['expires_at'],
        'projection'   => $baselineProjection,
        'baseline'     => [
            'projection'           => $baselineProjection,
            'starting_cash'       => round($datasets['starting_cash'], 2),
            'lowest_balance'      => $baseline['lowest_balance'],
            'lowest_balance_date' => $baseline['lowest_balance_date'],
            'runway_days_to_zero' => $baseline['runway_days_to_zero'],
            'daily'               => $baseline['daily'],
        ],
        'scenario_a'   => [
            'projection'           => $projectionA,
            'name'                => $pa['name'],
            'description'         => $pa['description'],
            'events'              => $pa['events'],
            'lowest_balance'      => $simA['lowest_balance'],
            'lowest_balance_date' => $simA['lowest_balance_date'],
            'runway_days_to_zero' => $simA['runway_days_to_zero'],
            'daily'               => $simA['daily'],
        ],
    ];

    if ($row['kind'] === 'compare' && $row['preset_b_id']) {
        $pb = $loadPreset($pdo, $tid, (int) $row['preset_b_id']);
        if ($pb) {
            [$bIn, $bOut] = $applyPreset($pb['events'], $today, $endDate);
            $simB = liquidityWalkProjection(
                $datasets['starting_cash'], $days, $today,
                $buckets['inflows_by_date'], $buckets['outflows_by_date'],
                $bIn, $bOut
            );
            $projectionB = liquidityProjectionEvidence($tid, $today, $endDate, $days, $datasets, [
                'extra_inflows_by_date' => $bIn,
                'extra_outflows_by_date' => $bOut,
            ]);
            $payload['scenario_b'] = [
                'projection'           => $projectionB,
                'name'                => $pb['name'],
                'description'         => $pb['description'],
                'events'              => $pb['events'],
                'lowest_balance'      => $simB['lowest_balance'],
                'lowest_balance_date' => $simB['lowest_balance_date'],
                'runway_days_to_zero' => $simB['runway_days_to_zero'],
                'daily'               => $simB['daily'],
            ];
        }
    }

    api_ok($payload);
}

// ─── Authenticated paths from here on. ───
$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if ($action === 'create' && $method === 'POST') {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $body = api_json_body();
    $kind = (string) ($body['kind'] ?? '');
    if (!in_array($kind, ['single', 'compare'], true)) api_error("kind must be 'single' or 'compare'", 422);
    $presetA = (int) ($body['preset_a_id'] ?? 0);
    $presetB = (int) ($body['preset_b_id'] ?? 0) ?: null;
    if ($presetA <= 0) api_error('preset_a_id required', 422);
    if ($kind === 'compare' && !$presetB) api_error('preset_b_id required for compare links', 422);
    if ($kind === 'compare' && $presetB === $presetA) api_error('Compare links must reference two different scenarios', 422);

    // Confirm both presets exist + belong to this tenant — defends against
    // crafted IDs leaking another tenant's scenario via a share link.
    $check = $pdo->prepare('SELECT id FROM treasury_scenario_presets WHERE tenant_id = :t AND id = :id');
    $check->execute(['t' => $tid, 'id' => $presetA]);
    if (!$check->fetchColumn()) api_error('preset_a_id not found in tenant', 404);
    if ($presetB !== null) {
        $check->execute(['t' => $tid, 'id' => $presetB]);
        if (!$check->fetchColumn()) api_error('preset_b_id not found in tenant', 404);
    }

    $label        = trim((string) ($body['label'] ?? ''));
    if (strlen($label) > 200) api_error('label max 200 chars', 422);
    $days         = max(1, min(365, (int) ($body['days_horizon'] ?? 90)));
    $expiresInDays = max(1, min(30, (int) ($body['expires_in_days'] ?? 7)));
    $expiresAt    = date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"));

    // 30-byte token = 60-char hex. Cleartext only returned in this response.
    $token     = bin2hex(random_bytes(30));
    $tokenHash = hash('sha256', $token);

    $ins = $pdo->prepare(
        'INSERT INTO treasury_scenario_share_links
            (tenant_id, kind, preset_a_id, preset_b_id, token_hash,
             label, days_horizon, created_by_user_id, expires_at)
         VALUES (:t, :k, :a, :b, :h, :l, :d, :u, :e)'
    );
    $ins->execute([
        't' => $tid,
        'k' => $kind,
        'a' => $presetA,
        'b' => $presetB,
        'h' => $tokenHash,
        'l' => $label !== '' ? $label : null,
        'd' => $days,
        'u' => isset($user['id']) ? (int) $user['id'] : null,
        'e' => $expiresAt,
    ]);
    $id = (int) $pdo->lastInsertId();

    // Build the public URL using the request host so it works in dev,
    // staging, and prod without env wiring.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $url    = $scheme . '://' . $host . '/share/scenario?token=' . $token;

    api_ok([
        'id'         => $id,
        'token'      => $token,
        'url'        => $url,
        'expires_at' => $expiresAt,
    ]);
}

if ($action === 'list' && $method === 'GET') {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $stmt = $pdo->prepare(
        "SELECT id, kind, preset_a_id, preset_b_id, label, days_horizon,
                created_at, expires_at, revoked_at, view_count, last_viewed_at,
                CASE
                    WHEN revoked_at IS NOT NULL THEN 'revoked'
                    WHEN expires_at < NOW()     THEN 'expired'
                    ELSE 'active'
                END AS status
           FROM treasury_scenario_share_links
          WHERE tenant_id = :t
          ORDER BY created_at DESC"
    );
    $stmt->execute(['t' => $tid]);
    api_ok(['links' => $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []]);
}

if ($action === 'revoke' && $method === 'POST') {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $stmt = $pdo->prepare(
        'UPDATE treasury_scenario_share_links
            SET revoked_at = NOW()
          WHERE id = :id AND tenant_id = :t AND revoked_at IS NULL'
    );
    $stmt->execute(['id' => $id, 't' => $tid]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT 1 FROM treasury_scenario_share_links WHERE id = :id AND tenant_id = :t');
        $check->execute(['id' => $id, 't' => $tid]);
        if (!$check->fetchColumn()) api_error('Share link not found', 404);
    }
    api_ok(['ok' => true, 'id' => $id]);
}

api_error('Unknown action: ' . $action, 400);
