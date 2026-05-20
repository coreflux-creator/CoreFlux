<?php
/**
 * /api/reports_ai_explain.php — row-level AI assistance for report rows.
 *
 *   POST /api/reports_ai_explain.php  { entity_type, entity_id, question? }
 *
 * Returns:
 *   { answer, confidence, recommended_flag?, source }
 *
 * For a placement row, the API gathers the placement context (rates,
 * lifetime margin, hours, recruiter, status), then asks the LLM to
 * evaluate it under a fixed staffing-finance system prompt and suggest
 * whether to flag it for review. The rendered response surfaces a
 * one-click "Flag for review" button on the React side.
 *
 * Falls back to a deterministic local heuristic if the AI is disabled.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/ai_service.php';

$ctx     = api_require_auth();
$role    = $ctx['role'] ?? 'employee';
// Sub-tenant scope: this endpoint reads placements/people/recruiters which
// are SHARED catalogs. Pin module scope so effectiveTenantIdForRequest()
// resolves to the master parent when the active user is on a shared-mode sub.
setRequestModuleScope('staffing');
$tenantId = (int) (effectiveTenantIdForRequest() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);
if (!in_array($role, ['master_admin','tenant_admin','admin','manager'], true)) {
    api_error('Forbidden — manager+ required', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
api_require_fields($body, ['entity_type', 'entity_id']);
$entityType = (string) $body['entity_type'];
$entityId   = (int)    $body['entity_id'];
$question   = trim((string) ($body['question'] ?? ''));

if ($entityType !== 'placement' && $entityType !== 'recruiter') {
    api_error('entity_type must be placement or recruiter in this build', 422);
}

if ($entityType === 'recruiter') {
    /* ---------- recruiter context ---------- */
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email
           FROM users u
          WHERE u.id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $entityId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rec) api_error('Recruiter not found', 404);

    // Aggregate the recruiter's book.
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT p.id) AS placement_count,
                SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) AS active_count
           FROM placements p
           JOIN placement_commissions pc
             ON pc.placement_id = p.id AND pc.role = 'recruiter'
          WHERE p.tenant_id = :t AND pc.user_id = :rid"
    );
    $stmt->execute(['t' => $tenantId, 'rid' => $entityId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['placement_count' => 0, 'active_count' => 0];

    // Last 90d billable hours + margin contribution.
    $today    = new DateTimeImmutable('today');
    $cutoff90 = $today->modify('-90 days')->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(te.hours),0) AS hrs,
                COALESCE(SUM(te.hours *
                  (SELECT pr.bill_rate - pr.pay_rate FROM placement_rates pr
                    WHERE pr.placement_id = te.placement_id
                      AND pr.approved_at IS NOT NULL
                      AND pr.effective_from <= te.work_date
                      AND (pr.effective_to IS NULL OR pr.effective_to >= te.work_date)
                  ORDER BY pr.effective_from DESC LIMIT 1)),0) AS margin
           FROM time_entries te
          WHERE te.tenant_id = :t AND te.status = 'approved'
            AND te.category IN ('regular_billable','OT_billable')
            AND te.work_date >= :s
            AND te.placement_id IN (
              SELECT placement_id FROM placement_commissions
               WHERE tenant_id = :t2 AND user_id = :rid AND role = 'recruiter')"
    );
    $stmt->execute(['t' => $tenantId, 't2' => $tenantId, 'rid' => $entityId, 's' => $cutoff90]);
    $perf = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['hrs' => 0, 'margin' => 0];

    // Team median margin/hr (90d) — used as the comparison baseline.
    $stmt = $pdo->prepare(
        "SELECT pc.user_id,
                SUM(te.hours) AS h,
                SUM(te.hours *
                  (SELECT pr.bill_rate - pr.pay_rate FROM placement_rates pr
                    WHERE pr.placement_id = te.placement_id
                      AND pr.approved_at IS NOT NULL
                      AND pr.effective_from <= te.work_date
                      AND (pr.effective_to IS NULL OR pr.effective_to >= te.work_date)
                  ORDER BY pr.effective_from DESC LIMIT 1)) AS m
           FROM time_entries te
           JOIN placement_commissions pc
             ON pc.placement_id = te.placement_id AND pc.role = 'recruiter'
          WHERE te.tenant_id = :t AND te.status = 'approved'
            AND te.category IN ('regular_billable','OT_billable')
            AND te.work_date >= :s
       GROUP BY pc.user_id
       HAVING h > 0"
    );
    $stmt->execute(['t' => $tenantId, 's' => $cutoff90]);
    $teamRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $perHr = [];
    foreach ($teamRows as $tr) $perHr[] = ((float) $tr['m']) / max(1.0, (float) $tr['h']);
    sort($perHr);
    $teamMedian = $perHr ? round($perHr[(int) floor(count($perHr) / 2)], 2) : 0;

    $myMarginPerHr = ((float) $perf['hrs']) > 0
        ? round(((float) $perf['margin']) / (float) $perf['hrs'], 2)
        : 0;

    $context = [
        'recruiter'                => $rec['name'],
        'placements_total'         => (int) $book['placement_count'],
        'placements_active'        => (int) $book['active_count'],
        'period_hours_90d'         => round((float) $perf['hrs'], 2),
        'period_margin_90d'        => round((float) $perf['margin'], 2),
        'avg_margin_per_hour_90d'  => $myMarginPerHr,
        'team_median_margin_per_hour_90d' => $teamMedian,
        'gap_to_median'            => round($myMarginPerHr - $teamMedian, 2),
    ];

    // Heuristic signals for recruiters.
    $signals = [];
    if ($context['placements_active'] === 0)        $signals[] = ['missing_data','Recruiter has zero active placements in the last 90 days.'];
    if ($teamMedian > 0 && $myMarginPerHr < $teamMedian * 0.7)
                                                    $signals[] = ['low_margin',"Avg margin/hr is \${$myMarginPerHr} — meaningfully below team median \${$teamMedian}."];
    if (($context['period_hours_90d'] ?? 0) < 50)   $signals[] = ['stale_unsigned_timesheet','<50 billable hours across the recruiter book in the last 90 days.'];
    $recommendedFlag = $signals[0] ?? null;

    $envelope = null;
    try {
        $envelope = aiAsk([
            'feature_class'     => 'narrative',
            'feature_key'       => 'reports.recruiter_explain',
            'kind'              => 'narrative',
            'system'            => 'You are a staffing-business CFO co-pilot. Given a recruiter and their '
                                  . 'book of business, write 2-3 short bullets that (1) summarise their '
                                  . 'production, (2) call out anything that should trigger a flag for review, '
                                  . '(3) suggest one concrete coaching action. Be plain and specific.',
            'prompt'            => $question !== ''
                                  ? $question
                                  : 'Should this recruiter be flagged for review?',
            'context'           => $context,
            'max_output_tokens' => 350,
        ]);
    } catch (Throwable $e) {
        error_log('reports_ai_explain (recruiter) LLM disabled: ' . $e->getMessage());
    }

    if ($envelope && !empty($envelope['content'])) {
        api_ok([
            'answer'           => $envelope['content'],
            'confidence'       => $envelope['confidence'] ?? null,
            'source'           => 'llm:' . ($envelope['model'] ?? 'unknown'),
            'recommended_flag' => $recommendedFlag ? [
                'reason_code' => $recommendedFlag[0],
                'rationale'   => $recommendedFlag[1],
                'severity'    => 'warn',
            ] : null,
            'context'          => $context,
        ]);
    }

    $lines = ["{$context['recruiter']} ran \${$context['period_margin_90d']} margin on {$context['period_hours_90d']} hrs in 90d ({$context['placements_active']} active placements)."];
    if ($recommendedFlag) {
        $lines[] = "Heads up: {$recommendedFlag[1]}";
        $lines[] = 'Recommended: 1:1 review of pipeline + rate guidance.';
    } else {
        $lines[] = 'No automatic concerns surfaced — performing in line with the team.';
    }
    api_ok([
        'answer'           => implode(' ', $lines),
        'confidence'       => 0.6,
        'source'           => 'heuristic',
        'recommended_flag' => $recommendedFlag ? [
            'reason_code' => $recommendedFlag[0],
            'rationale'   => $recommendedFlag[1],
            'severity'    => 'warn',
        ] : null,
        'context'          => $context,
    ]);
}

/* ---------- gather placement context ---------- */
$stmt = $pdo->prepare(
    "SELECT p.id, p.engagement_type, p.start_date, p.end_date, p.status,
            p.worksite_state, p.end_client_name,
            CONCAT(COALESCE(pe.preferred_name, pe.first_name, ''), ' ',
                   COALESCE(pe.last_name, '')) AS candidate_name,
            (SELECT bill_rate FROM placement_rates pr
              WHERE pr.placement_id = p.id AND pr.approved_at IS NOT NULL AND pr.effective_to IS NULL
           ORDER BY pr.effective_from DESC LIMIT 1) AS bill_rate,
            (SELECT pay_rate FROM placement_rates pr
              WHERE pr.placement_id = p.id AND pr.approved_at IS NOT NULL AND pr.effective_to IS NULL
           ORDER BY pr.effective_from DESC LIMIT 1) AS pay_rate,
            (SELECT u.name FROM placement_commissions pc
               JOIN users u ON u.id = pc.user_id
              WHERE pc.placement_id = p.id AND pc.role = 'recruiter'
           ORDER BY pc.id LIMIT 1) AS recruiter_name,
            COALESCE((SELECT SUM(te.hours) FROM time_entries te
              WHERE te.placement_id = p.id AND te.status = 'approved'
                AND te.category IN ('regular_billable','OT_billable')),0) AS lifetime_hours,
            COALESCE((SELECT MAX(te.work_date) FROM time_entries te
              WHERE te.placement_id = p.id AND te.status = 'approved'),NULL) AS last_time_entry
       FROM placements p
       LEFT JOIN people pe ON pe.id = p.person_id
      WHERE p.id = :id AND p.tenant_id = :t LIMIT 1"
);
$stmt->execute(['id' => $entityId, 't' => $tenantId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) api_error('Placement not found', 404);

$bill = (float) ($row['bill_rate'] ?? 0);
$pay  = (float) ($row['pay_rate']  ?? 0);
$marginPerHr = $bill - $pay;
$marginPct   = $bill > 0 ? round($marginPerHr / $bill * 100, 1) : 0;

$context = [
    'candidate'        => trim((string) $row['candidate_name']) ?: '—',
    'client'           => $row['end_client_name'],
    'recruiter'        => $row['recruiter_name'] ?? '—',
    'engagement_type'  => $row['engagement_type'],
    'state'            => $row['worksite_state'],
    'status'           => $row['status'],
    'start_date'       => $row['start_date'],
    'end_date'         => $row['end_date'],
    'bill_rate'        => $bill,
    'pay_rate'         => $pay,
    'margin_per_hour'  => round($marginPerHr, 2),
    'margin_pct'       => $marginPct,
    'lifetime_hours'   => (float) $row['lifetime_hours'],
    'lifetime_margin'  => round($marginPerHr * (float) $row['lifetime_hours'], 2),
    'last_time_entry'  => $row['last_time_entry'],
];

/* ---------- deterministic heuristic (used as input, and as offline fallback) ---------- */
$today      = new DateTimeImmutable('today');
$staleDays  = $context['last_time_entry']
    ? (int) $today->diff(new DateTimeImmutable($context['last_time_entry']))->format('%a')
    : 9999;
$signals = [];
if ($marginPerHr < 10 && $marginPerHr >= 0)  $signals[] = ['low_margin',           "Margin only \${$marginPerHr}/hr ({$marginPct}%) — typical staffing target is 20%+"];
if ($marginPerHr < 0)                        $signals[] = ['low_margin',           'NEGATIVE margin — bill rate is below pay rate, urgent review'];
if ($staleDays > 21 && $context['status'] === 'active')
                                             $signals[] = ['stale_unsigned_timesheet', "No time entry in {$staleDays} days — placement may be inactive"];
if (!$bill || !$pay)                         $signals[] = ['missing_data',         'Active placement is missing an approved bill or pay rate'];
if (!$context['recruiter'] || $context['recruiter'] === '—')
                                             $signals[] = ['missing_data',         'No recruiter assigned via placement_commissions'];

$recommendedFlag = $signals[0] ?? null;

/* ---------- LLM pass (optional) ---------- */
$envelope = null;
try {
    $envelope = aiAsk([
        'feature_class'     => 'narrative',
        'feature_key'       => 'reports.placement_explain',
        'kind'              => 'narrative',
        'system'            => 'You are a staffing-business CFO co-pilot. Given the structured placement '
                              . 'data, write 2-3 short bullet sentences that (1) summarise the placement '
                              . 'health, (2) call out anything that should trigger a flag for review, '
                              . '(3) suggest one concrete next action. Be plain, no jargon, no fluff.',
        'prompt'            => $question !== ''
                              ? $question
                              : 'Should this placement be flagged for review? Why or why not?',
        'context'           => $context,
        'max_output_tokens' => 350,
    ]);
} catch (Throwable $e) {
    // Fall through — heuristic answer below.
    error_log('reports_ai_explain LLM disabled: ' . $e->getMessage());
}

/* ---------- final response ---------- */
if ($envelope && !empty($envelope['content'])) {
    api_ok([
        'answer'           => $envelope['content'],
        'confidence'       => $envelope['confidence'] ?? null,
        'source'           => 'llm:' . ($envelope['model'] ?? 'unknown'),
        'recommended_flag' => $recommendedFlag ? [
            'reason_code' => $recommendedFlag[0],
            'rationale'   => $recommendedFlag[1],
            'severity'    => $marginPerHr < 0 ? 'critical' : 'warn',
        ] : null,
        'context'          => $context,
    ]);
}

// Heuristic-only fallback
$lines = ["Margin {$marginPct}% (\${$marginPerHr}/hr) on {$context['engagement_type']} placement at {$context['client']}."];
if ($recommendedFlag) {
    $lines[] = "Heads up: {$recommendedFlag[1]}.";
    $lines[] = $marginPerHr < 0
        ? "Recommended action: open the placement, re-quote bill rate or terminate."
        : "Recommended action: confirm rates and timesheet status with the recruiter.";
} else {
    $lines[] = 'Looks healthy — no automatic concerns surfaced.';
}
api_ok([
    'answer'           => implode(' ', $lines),
    'confidence'       => 0.6,
    'source'           => 'heuristic',
    'recommended_flag' => $recommendedFlag ? [
        'reason_code' => $recommendedFlag[0],
        'rationale'   => $recommendedFlag[1],
        'severity'    => $marginPerHr < 0 ? 'critical' : 'warn',
    ] : null,
    'context'          => $context,
]);
