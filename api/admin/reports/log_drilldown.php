<?php
/**
 * /api/admin/reports/log_drilldown.php
 *
 * Reports Overhaul follow-up — drill-through audit trail.
 *
 * Fire-and-forget log every time a user opens GlDetailDrilldown (or
 * MetricDrilldown) from a report. Returns 204 on success. The
 * frontend treats failure as soft (errors are logged but the drill
 * still opens).
 *
 *   POST /api/admin/reports/log_drilldown.php
 *     body: { report_key, account_code?, period_from?, period_to?, label? }
 *       → 204 No Content
 *
 *   GET  /api/admin/reports/log_drilldown.php?report_key=rpt-pnl&limit=10
 *       → { recent: [{ account_code, period_from, period_to, label, opened_at }, ...] }
 *
 * RBAC: any authenticated tenant member with reports.view scope —
 * we don't gate writes more strictly than the read endpoints they
 * drilled through (gl_detail.php = accounting.coa.view).
 *
 * Table: report_drilldown_log (migration 081).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$uid  = (int) ($user['id'] ?? 0);

$pdo = getDB();

if (api_method() === 'POST') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $body = api_json_body();

    $reportKey   = trim((string) ($body['report_key'] ?? ''));
    $accountCode = trim((string) ($body['account_code'] ?? '')) ?: null;
    $periodFrom  = trim((string) ($body['period_from']  ?? '')) ?: null;
    $periodTo    = trim((string) ($body['period_to']    ?? '')) ?: null;
    $label       = trim((string) ($body['label']        ?? '')) ?: null;

    if ($reportKey === '')              api_error('report_key required', 422);
    if (strlen($reportKey) > 40)        api_error('report_key too long',  422);
    if ($accountCode !== null && strlen($accountCode) > 40)
                                        api_error('account_code too long', 422);
    if ($label !== null && strlen($label) > 255) $label = substr($label, 0, 255);
    foreach (['periodFrom' => $periodFrom, 'periodTo' => $periodTo] as $name => $val) {
        if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            api_error("{$name} must be YYYY-MM-DD", 422);
        }
    }

    try {
        $st = $pdo->prepare("
            INSERT INTO report_drilldown_log
                (tenant_id, user_id, report_key, account_code,
                 period_from, period_to, label, opened_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $st->execute([$tid, $uid, $reportKey, $accountCode,
                      $periodFrom, $periodTo, $label]);
    } catch (\Throwable $e) {
        // Soft-fail: drill logging never blocks the actual drill.
        error_log('[log_drilldown POST] ' . $e->getMessage());
        api_error('Could not log drill', 500);
    }

    http_response_code(204);
    exit;
}

if (api_method() === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $reportKey = trim((string) (api_query('report_key') ?? ''));
    $limit     = max(1, min(50, (int) (api_query('limit') ?? 10)));

    if ($reportKey === '') api_error('report_key required', 422);

    try {
        // De-dup by (account_code, period_from, period_to) so the
        // "recent drills" surface shows distinct scopes, with the
        // most recent open time per scope.
        $st = $pdo->prepare("
            SELECT account_code, period_from, period_to, label,
                   MAX(opened_at) AS opened_at,
                   COUNT(*) AS open_count
              FROM report_drilldown_log
             WHERE tenant_id = ? AND user_id = ? AND report_key = ?
          GROUP BY account_code, period_from, period_to, label
          ORDER BY MAX(opened_at) DESC
             LIMIT {$limit}
        ");
        $st->execute([$tid, $uid, $reportKey]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('[log_drilldown GET] ' . $e->getMessage());
        $rows = [];
    }

    api_ok([
        'report_key' => $reportKey,
        'recent'     => $rows,
    ]);
}

api_error('Method not allowed', 405);
