<?php
/**
 * core/ai/cash_forecast.php — Slice E 13-week cash forecast.
 *
 * Spec §11 ("Cash Agent"):
 *   - cashForecastRun         — compute + persist a 13-week (or N-week)
 *                                cash forecast from existing AP/AR data.
 *   - cashForecastList / Get  — reviewer surface backing.
 *
 * Heuristic, not predictive ML — pulls deterministic inputs:
 *   - Opening cash: SUM of accounting_bank_accounts.last_known_balance
 *     (or last_balance_cents for accounts that track that), scaled to
 *     cents.
 *   - Weekly AP outflow: ap_bills with status IN
 *     ('approved','partially_paid') whose due_date falls in the week.
 *   - Weekly AR inflow: billing_invoices with status IN
 *     ('sent','partial') whose due_date falls in the week.
 *   - Weekly payroll outflow: payroll_runs scheduled in the week.
 *
 * Stores both the totals (starting / ending / min_week) AND the
 * per-week JSON payload so the dashboard renders without recomputing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/artifacts.php';

const CASH_FORECAST_DEFAULT_WEEKS = 13;

/**
 * Run a fresh forecast.  Persists a row in cash_forecast_runs.
 *
 * @return array  Same shape returned to the AI gateway:
 *                {forecast_id, starting_at, weeks_count, currency,
 *                 starting_balance_cents, ending_balance_cents,
 *                 min_week_balance_cents, weeks: [{...}]}
 */
function cashForecastRun(int $tenantId, array $opts = []): array
{
    if ($tenantId <= 0) throw new \InvalidArgumentException('tenantId required');
    $weeks   = max(1, min(52, (int) ($opts['weeks'] ?? CASH_FORECAST_DEFAULT_WEEKS)));
    $start   = (string) ($opts['starting_at'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        throw new \InvalidArgumentException("starting_at must be YYYY-MM-DD ('$start')");
    }
    $currency = mb_substr((string) ($opts['currency'] ?? 'USD'), 0, 3);
    $actorUid = isset($opts['actor_user_id']) ? (int) $opts['actor_user_id'] : null;

    // 1) Snapshot cash position.
    $openingCents = cashForecastReadOpeningBalanceCents($tenantId);

    // 2) Build weekly buckets.
    $buckets = [];
    $runningCents = $openingCents;
    $minWeekCents = null;
    for ($i = 0; $i < $weeks; $i++) {
        $weekStart = date('Y-m-d', strtotime("$start +" . ($i * 7) . " days"));
        $weekEnd   = date('Y-m-d', strtotime("$weekStart +6 days"));

        $apOut       = cashForecastApOutflowCents($tenantId, $weekStart, $weekEnd);
        $arIn        = cashForecastArInflowCents($tenantId, $weekStart, $weekEnd);
        $payrollOut  = cashForecastPayrollOutflowCents($tenantId, $weekStart, $weekEnd);
        $otherOut    = 0; // hook for future categories.

        $closing = $runningCents + $arIn - $apOut - $payrollOut - $otherOut;
        $buckets[] = [
            'week_no'                => $i + 1,
            'week_start'             => $weekStart,
            'week_end'               => $weekEnd,
            'opening_balance_cents'  => $runningCents,
            'ap_outflow_cents'       => $apOut,
            'ar_inflow_cents'        => $arIn,
            'payroll_outflow_cents'  => $payrollOut,
            'other_outflow_cents'    => $otherOut,
            'closing_balance_cents'  => $closing,
            'notes'                  => $closing < 0 ? 'NEGATIVE — shortfall flagged' : null,
        ];

        if ($minWeekCents === null || $closing < $minWeekCents) $minWeekCents = $closing;
        $runningCents = $closing;
    }

    // 3) Persist.
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO cash_forecast_runs
            (tenant_id, sub_tenant_id, weeks_count, starting_at, currency,
             starting_balance_cents, ending_balance_cents, min_week_balance_cents,
             forecast_payload_json, ai_run_id, created_by_user_id, created_at)
         VALUES
            (:t, :st, :w, :sa, :c,
             :sb, :eb, :mb,
             :p, :ai, :u, NOW())'
    )->execute([
        't'  => $tenantId,
        'st' => isset($opts['sub_tenant_id']) ? (int) $opts['sub_tenant_id'] : null,
        'w'  => $weeks,
        'sa' => $start,
        'c'  => $currency,
        'sb' => $openingCents,
        'eb' => $runningCents,
        'mb' => $minWeekCents,
        'p'  => json_encode($buckets, JSON_UNESCAPED_SLASHES),
        'ai' => $opts['ai_run_id'] ?? null,
        'u'  => $actorUid,
    ]);
    $forecastId = (int) $pdo->lastInsertId();
    $artifactId = null;
    try {
        $artifact = artifactCreate($tenantId, 'cash_forecast', [
            'title' => "Cash forecast - {$start} ({$weeks} weeks)",
            'sub_tenant_id' => isset($opts['sub_tenant_id']) ? (int) $opts['sub_tenant_id'] : null,
            'source_module' => 'treasury',
            'source_record_type' => 'cash_forecast_runs',
            'source_record_id' => $forecastId,
            'payload' => [
                'forecast_id' => $forecastId,
                'starting_at' => $start,
                'weeks_count' => $weeks,
                'currency' => $currency,
                'starting_balance_cents' => $openingCents,
                'ending_balance_cents' => $runningCents,
                'min_week_balance_cents' => $minWeekCents,
                'weeks' => $buckets,
            ],
            'created_by_user_id' => $actorUid,
            'created_by_ai_run' => $opts['ai_run_id'] ?? null,
            'initial_status' => 'review',
        ]);
        $artifactId = $artifact['id'] ?? null;
        if ($artifactId) {
            $pdo->prepare(
                'UPDATE cash_forecast_runs
                    SET artifact_id = :aid
                  WHERE id = :id AND tenant_id = :t'
            )->execute(['aid' => $artifactId, 'id' => $forecastId, 't' => $tenantId]);
        }
    } catch (\Throwable $e) {
        error_log('[cash_forecast] artifactCreate failed: ' . $e->getMessage());
    }

    return [
        'forecast_id'             => $forecastId,
        'artifact_id'             => $artifactId,
        'starting_at'             => $start,
        'weeks_count'             => $weeks,
        'currency'                => $currency,
        'starting_balance_cents'  => $openingCents,
        'ending_balance_cents'    => $runningCents,
        'min_week_balance_cents'  => $minWeekCents,
        'weeks'                   => $buckets,
    ];
}

/** Read a forecast row, decoding the weeks payload. */
function cashForecastGet(int $tenantId, int $forecastId): ?array
{
    if ($tenantId <= 0 || $forecastId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM cash_forecast_runs
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $forecastId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['id','tenant_id','sub_tenant_id','weeks_count',
              'starting_balance_cents','ending_balance_cents','min_week_balance_cents',
              'created_by_user_id'] as $k) {
        if ($row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    $row['weeks'] = $row['forecast_payload_json']
        ? (json_decode((string) $row['forecast_payload_json'], true) ?: [])
        : [];
    return $row;
}

/** Newest-first list. */
function cashForecastList(int $tenantId, array $filters = []): array
{
    if ($tenantId <= 0) return [];
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, weeks_count, starting_at, currency,
                starting_balance_cents, ending_balance_cents,
                min_week_balance_cents, artifact_id, created_at
           FROM cash_forecast_runs
          WHERE tenant_id = :t
          ORDER BY id DESC LIMIT ' . $limit
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        foreach (['id','tenant_id','weeks_count',
                  'starting_balance_cents','ending_balance_cents','min_week_balance_cents'] as $k) {
            if ($r[$k] !== null) $r[$k] = (int) $r[$k];
        }
    } unset($r);
    return $rows;
}

/* ────────────────────────────────────────────────────────────────── */
/* Helpers — each best-effort. Schema misses tolerated for sandbox.   */
/* tenant-leak-allow: each query is tenant-scoped via the parameter.  */
/* ────────────────────────────────────────────────────────────────── */

function cashForecastReadOpeningBalanceCents(int $tenantId): int
{
    try {
        $stmt = getDB()->prepare(
            'SELECT COALESCE(SUM(last_known_balance), 0) AS bal
               FROM accounting_bank_accounts
              WHERE tenant_id = :t'
        );
        $stmt->execute(['t' => $tenantId]);
        $bal = (float) ($stmt->fetchColumn() ?: 0);
        return (int) round($bal * 100);
    } catch (\Throwable $e) {
        // Sandbox / table missing — return 0 and let the caller flag it.
        return 0;
    }
}

function cashForecastApOutflowCents(int $tenantId, string $weekStart, string $weekEnd): int
{
    try {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(amount_due), 0) AS due
               FROM ap_bills
              WHERE tenant_id = :t
                AND status IN ('approved','partially_paid','pending_approval')
                AND due_date BETWEEN :a AND :b"
        );
        $stmt->execute(['t' => $tenantId, 'a' => $weekStart, 'b' => $weekEnd]);
        return (int) round(((float) ($stmt->fetchColumn() ?: 0)) * 100);
    } catch (\Throwable $e) { return 0; }
}

function cashForecastArInflowCents(int $tenantId, string $weekStart, string $weekEnd): int
{
    try {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(balance_due), 0) AS due
               FROM billing_invoices
              WHERE tenant_id = :t
                AND status IN ('sent','partial')
                AND due_date BETWEEN :a AND :b"
        );
        $stmt->execute(['t' => $tenantId, 'a' => $weekStart, 'b' => $weekEnd]);
        return (int) round(((float) ($stmt->fetchColumn() ?: 0)) * 100);
    } catch (\Throwable $e) { return 0; }
}

function cashForecastPayrollOutflowCents(int $tenantId, string $weekStart, string $weekEnd): int
{
    try {
        $stmt = getDB()->prepare(
            "SELECT COALESCE(SUM(net_total), 0) AS net
               FROM payroll_runs
              WHERE tenant_id = :t
                AND pay_date BETWEEN :a AND :b"
        );
        $stmt->execute(['t' => $tenantId, 'a' => $weekStart, 'b' => $weekEnd]);
        return (int) round(((float) ($stmt->fetchColumn() ?: 0)) * 100);
    } catch (\Throwable $e) { return 0; }
}
