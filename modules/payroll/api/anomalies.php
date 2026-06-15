<?php
/**
 * Payroll — AI cross-check anomaly findings (read + ack).
 *
 *   GET ?run_id=<n>          → findings for a specific run
 *   GET ?dashboard=1         → tenant-wide unacknowledged feed (alert badge)
 *   POST   { run_id, ai? }   → run anomaly detection on a computed run
 *   PATCH  ?id=<n>           → acknowledge a single finding
 *
 * Findings are persisted in payroll_anomaly_findings. Detection logic lives
 * in /modules/payroll/lib/anomalies.php so the runs.php compute hook can
 * reuse it inline.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/anomalies.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'payroll.anomalies.view');
        if ((int) (api_query('dashboard') ?? 0) === 1) {
            $rows = payrollAnomaliesListUnacked((int) (api_query('limit') ?? 25));
            api_ok([
                'unacknowledged' => $rows,
                'count'          => count($rows),
                'critical_count' => count(array_filter($rows, fn($r) => $r['severity'] === 'critical')),
            ]);
        }
        $runId = (int) (api_query('run_id') ?? 0);
        if (!$runId) api_error('run_id required', 422);
        $rows = payrollAnomaliesListByRun($runId);
        api_ok(['findings' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        rbac_legacy_require($user, 'payroll.anomalies.detect');
        $body  = api_json_body();
        api_require_fields($body, ['run_id']);
        $runId = (int) $body['run_id'];
        $ai    = !empty($body['ai']);
        if ($ai) rbac_legacy_require($user, 'ai.use');
        try {
            $summary = payrollAnomaliesDetect($runId, $ai);
            api_ok($summary, 201);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
    }

    case 'PATCH':
    case 'PUT': {
        rbac_legacy_require($user, 'payroll.anomalies.acknowledge');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $rows = payrollAnomaliesAcknowledge($id, (int) ($ctx['user']['id'] ?? 0));
        if ($rows === 0) api_error('Not found', 404);
        api_ok(['ok' => true]);
    }
}

api_error('Method not allowed', 405);
