<?php
/**
 * Simulation runs admin endpoint — feeds the /sim SPA dashboard.
 *
 *   GET    /api/admin/simulation_runs                      List recent runs
 *      [?tenant=ID&scenario=NAME&status=passed|failed&limit=50]
 *
 *   GET    /api/admin/simulation_runs?id=N&detail=1        Drill-in: run +
 *                                                          assertions + replay
 *                                                          + discipline log
 *
 *   GET    /api/admin/simulation_runs?action=scenarios     List available
 *                                                          scenarios from disk
 *
 *   GET    /api/admin/simulation_runs?action=discipline    Recent discipline
 *                                                          log fires
 *
 * No POST — the runner is CLI-only. The SPA dashboard is read-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../sim/lib/scenario.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

$method = api_method();
$action = $_GET['action'] ?? null;

// ── POST ?action=run — spawn the runner against the sim tenant ──────
// Synchronous up to 60s. Returns { run_id, status, summary }.
// Refuses non-sim tenants (the runner itself also refuses, but checking
// here lets us return a clean 422 vs the runner's exit code 4).
if ($method === 'POST' && $action === 'run') {
    $body         = api_json_body();
    $scenarioName = (string) ($body['scenario'] ?? '');
    $seed         = isset($body['seed']) ? (int) $body['seed'] : null;
    $runTenantId  = isset($body['tenant_id']) ? (int) $body['tenant_id'] : $tid;

    if ($scenarioName === '' || !preg_match('/^[a-z0-9_]+$/', $scenarioName)) {
        api_error('Invalid scenario name', 422);
    }
    try {
        $pdo = getDB();
        $st  = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
        $st->execute(['id' => $runTenantId]);
        if ((int) $st->fetchColumn() !== 1) {
            api_error('Target tenant is not flagged is_simulation=1', 422);
        }
    } catch (\Throwable $e) {
        api_error('Cannot verify sim tenant: ' . $e->getMessage(), 500);
    }

    // Build + spawn (synchronous). Cap at 60s — any scenario longer
    // than that should be CLI-driven.
    $cmd = sprintf(
        'php %s --scenario=%s%s --tenant=%d 2>&1',
        escapeshellarg(realpath(__DIR__ . '/../../sim/runner.php')),
        escapeshellarg($scenarioName),
        $seed !== null ? ' --seed=' . (int) $seed : '',
        $runTenantId
    );

    $startedAt = microtime(true);
    @set_time_limit(75);
    $output = (string) shell_exec($cmd);
    $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

    // Parse run_id from stdout ("run_id=N").
    $runId = null;
    if (preg_match('/run_id=(\d+)/', $output, $m)) $runId = (int) $m[1];

    // Find the just-inserted run for this (tenant, scenario) — falls
    // back to the most recent row if stdout didn't surface the id.
    try {
        $stmt = $pdo->prepare(
            'SELECT id, status, events_emitted, je_posted,
                    assertions_run, assertions_failed, duration_ms, summary
               FROM simulation_runs
              WHERE tenant_id = :t AND scenario_name = :s
                ' . ($runId ? 'AND id = :rid' : '') . '
              ORDER BY started_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(array_merge(
            ['t' => $runTenantId, 's' => $scenarioName],
            $runId ? ['rid' => $runId] : []
        ));
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $row = null;
    }

    if ($row) {
        $row['summary'] = is_string($row['summary']) ? json_decode($row['summary'], true) : $row['summary'];
    }
    api_ok([
        'run_id'      => $runId ?? ($row['id'] ?? null),
        'run'         => $row,
        'spawned_ms'  => $durationMs,
        'stdout_tail' => substr($output, -1500),
    ]);
}

if ($method !== 'GET') api_error('Method not allowed', 405);

// ── List scenarios from disk (no DB) ─────────────────────────────────
if ($action === 'scenarios') {
    try {
        api_ok(['scenarios' => simListScenarios()]);
    } catch (\Throwable $e) {
        api_ok(['scenarios' => [], 'error' => $e->getMessage()]);
    }
}

// ── Recent discipline-log fires (across all tenants for master admin,
//    scoped tenant otherwise) ─────────────────────────────────────────
if ($action === 'discipline') {
    try {
        $stmt = scopedQuery(
            'SELECT id, source_module, event_type, context, created_at, created_by_user_id
               FROM module_emission_discipline_log
              WHERE tenant_id = :tenant_id
              ORDER BY created_at DESC, id DESC
              LIMIT 100',
            []
        );
        foreach ($stmt as &$r) {
            $r['context'] = is_string($r['context']) ? json_decode($r['context'], true) : $r['context'];
        }
        api_ok(['rows' => $stmt]);
    } catch (\Throwable $e) {
        api_ok(['rows' => [], 'migration_pending' => true]);
    }
}

// ── Run detail ──────────────────────────────────────────────────────
$id = (int) ($_GET['id'] ?? 0);
if ($id && !empty($_GET['detail'])) {
    try {
        $run = scopedFind(
            'SELECT id, tenant_id, scenario_name, seed, status,
                    events_emitted, je_posted, assertions_run, assertions_failed,
                    summary, started_at, finished_at, duration_ms
               FROM simulation_runs
              WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $id]
        );
        if (!$run) api_error('Run not found', 404);
        $run['summary'] = is_string($run['summary']) ? json_decode($run['summary'], true) : $run['summary'];

        $assertions = scopedQuery(
            'SELECT id, name, ok, severity, details, created_at
               FROM simulation_assertions
              WHERE run_id = :rid
              ORDER BY id ASC',
            ['rid' => $id]
        );
        foreach ($assertions as &$a) {
            $a['details'] = is_string($a['details']) ? json_decode($a['details'], true) : $a['details'];
        }
        $failures = scopedQuery(
            'SELECT id, invariant, message, context, created_at
               FROM simulation_failures
              WHERE run_id = :rid
              ORDER BY id ASC',
            ['rid' => $id]
        );
        foreach ($failures as &$f) {
            $f['context'] = is_string($f['context']) ? json_decode($f['context'], true) : $f['context'];
        }
        $replay = scopedQuery(
            'SELECT event_index, event_type, payload_hash, je_id, je_hash, occurred_at
               FROM replay_logs
              WHERE run_id = :rid
              ORDER BY event_index ASC',
            ['rid' => $id]
        );
        api_ok([
            'run'        => $run,
            'assertions' => $assertions,
            'failures'   => $failures,
            'replay'     => $replay,
        ]);
    } catch (\Throwable $e) {
        api_error('Failed to load run: ' . $e->getMessage(), 500);
    }
}

// ── List runs (default) ─────────────────────────────────────────────
$where  = ['tenant_id = :tenant_id'];
$params = [];

if (!empty($_GET['scenario'])) { $where[] = 'scenario_name = :s'; $params['s']  = $_GET['scenario']; }
if (!empty($_GET['status']))   { $where[] = 'status = :st';        $params['st'] = $_GET['status']; }

$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

try {
    $rows = scopedQuery(
        'SELECT id, scenario_name, seed, status,
                events_emitted, je_posted, assertions_run, assertions_failed,
                started_at, finished_at, duration_ms
           FROM simulation_runs
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY started_at DESC, id DESC
          LIMIT ' . $limit,
        $params
    );

    // KPI roll-up for the dashboard header
    $kpiStmt = scopedQuery(
        'SELECT
            COUNT(*) AS total_runs,
            SUM(status = "passed")              AS passed,
            SUM(status = "failed")              AS failed,
            SUM(assertions_failed)              AS assertion_failures,
            COALESCE(AVG(duration_ms), 0)       AS avg_duration_ms
          FROM simulation_runs
         WHERE tenant_id = :tenant_id
           AND started_at > NOW() - INTERVAL 30 DAY',
        []
    );
    $kpi = $kpiStmt[0] ?? ['total_runs' => 0];

    api_ok(['rows' => $rows, 'kpi' => $kpi]);
} catch (\Throwable $e) {
    api_ok(['rows' => [], 'kpi' => ['total_runs' => 0], 'migration_pending' => true]);
}
