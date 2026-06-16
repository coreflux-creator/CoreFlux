<?php
/**
 * Treasury Recommendations API.
 *
 * Advisory reserve planning and payment timing recommendations. The endpoint
 * never executes or approves money movement; accepted/dismissed recommendations
 * are written to the platform audit log and the existing Treasury payment
 * workflow remains the system of record for submit, approval, and execution.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/audit.php';
require_once __DIR__ . '/../core/treasury/liquidity_projection.php';
require_once __DIR__ . '/../modules/treasury/lib/policy.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tid = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) (api_query('action') ?? '');
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;

if ($method === 'GET') {
    rbac_legacy_require($user, 'treasury.payment.view');
    $today = date('Y-m-d');
    $storedPolicy = treasuryPolicyGet($tid);
    $forecastDays = max(1, min(365, (int) (api_query('forecast_days') ?? $storedPolicy['forecast_days'] ?? 30)));
    $endDate = date('Y-m-d', strtotime("+{$forecastDays} days"));
    $entityId = (int) (api_query('entity_id') ?? 0) ?: null;
    $currency = strtoupper(substr((string) (api_query('currency') ?? $storedPolicy['currency'] ?? 'USD'), 0, 3)) ?: 'USD';

    $reservePolicy = treasuryRecommendationReservePolicy($storedPolicy, $currency);
    $materialityThreshold = (float) $reservePolicy['materiality_threshold'];
    $datasets = liquidityBaselineDatasets($tid, $today, $endDate, $entityId);
    $buckets = liquidityBucketDatasets($datasets);
    $walk = liquidityWalkProjection(
        (float) $datasets['starting_cash'],
        $forecastDays,
        $today,
        $buckets['inflows_by_date'],
        $buckets['outflows_by_date']
    );
    $projectionEvidence = liquidityProjectionEvidence($tid, $today, $endDate, $forecastDays, $datasets);
    $variance = treasuryRecommendationVarianceContext($tid, $today);
    $payments = treasuryRecommendationOpenPayments($tid, $today, $endDate, $entityId, $currency);

    $requiredReserves = (float) $reservePolicy['required_reserves'];
    $startingCash = round((float) $datasets['starting_cash'], 2);
    $projectedEndingCash = $walk['daily'] ? round((float) end($walk['daily'])['closing'], 2) : $startingCash;
    $lowestProjectedCash = round((float) $walk['lowest_balance'], 2);
    $availableNow = round($startingCash - $requiredReserves, 2);
    $projectedAvailable = round($projectedEndingCash - $requiredReserves, 2);
    $lowestAvailable = round($lowestProjectedCash - $requiredReserves, 2);

    $recommendations = [];
    foreach ($payments as $payment) {
        $recommendations[] = treasuryRecommendationForPayment(
            $payment,
            $reservePolicy,
            [
                'as_of_date' => $today,
                'forecast_end_date' => $endDate,
                'forecast_days' => $forecastDays,
                'starting_cash' => $startingCash,
                'projected_ending_cash' => $projectedEndingCash,
                'lowest_projected_cash' => $lowestProjectedCash,
                'available_now_after_reserves' => $availableNow,
                'projected_available_after_reserves' => $projectedAvailable,
                'lowest_available_after_reserves' => $lowestAvailable,
                'materiality_threshold' => $materialityThreshold,
                'variance_context' => $variance,
                'projection' => $projectionEvidence,
            ]
        );
    }

    api_ok([
        'as_of_date' => $today,
        'forecast_days' => $forecastDays,
        'forecast_end_date' => $endDate,
        'currency' => $currency,
        'reserve_policy' => $reservePolicy,
        'cash_envelope' => [
            'starting_cash' => $startingCash,
            'projected_ending_cash' => $projectedEndingCash,
            'lowest_projected_cash' => $lowestProjectedCash,
            'available_now_after_reserves' => $availableNow,
            'projected_available_after_reserves' => $projectedAvailable,
            'lowest_available_after_reserves' => $lowestAvailable,
            'materiality_threshold' => $materialityThreshold,
            'risk_level' => treasuryRecommendationRiskLevel($lowestAvailable, $projectedAvailable),
        ],
        'recommendations' => $recommendations,
        'auditability' => [
            'decision_events' => ['treasury.recommendation.accepted', 'treasury.recommendation.dismissed'],
            'money_movement_gate' => 'Treasury recommendations are advisory. Payments still require submit, approval, and execution through treasury_payments.php.',
            'policy_version' => $reservePolicy['policy_version'],
            'effective_date' => $reservePolicy['effective_date'],
            'approval_permission' => $reservePolicy['approval_permission'],
            'execution_permission' => $reservePolicy['execution_permission'],
        ],
        'evidence' => [
            'projection' => $projectionEvidence,
            'variance_context' => $variance,
            'source_population' => [
                'open_payments' => count($payments),
                'bank_accounts' => (int) ($datasets['bank_count'] ?? 0),
                'open_ap' => count($datasets['ap'] ?? []),
                'open_ar' => count($datasets['ar'] ?? []),
            ],
        ],
    ]);
}

if ($method === 'POST' && in_array($action, ['accept', 'dismiss'], true)) {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $body = api_json_body();
    $recommendationId = trim((string) ($body['recommendation_id'] ?? ''));
    if ($recommendationId === '') api_error('recommendation_id required', 400);
    $paymentId = isset($body['payment_id']) ? max(0, (int) $body['payment_id']) : null;
    $decisionNote = trim((string) ($body['decision_note'] ?? ''));
    $evidence = is_array($body['evidence'] ?? null) ? $body['evidence'] : [];
    $event = $action === 'accept' ? 'treasury.recommendation.accepted' : 'treasury.recommendation.dismissed';

    platformAuditLogWrite($tid, $actorUserId, $event, $paymentId ?: null, [
        'recommendation_id' => $recommendationId,
        'payment_id' => $paymentId,
        'decision_note' => $decisionNote,
        'recommendation_action' => $evidence['recommendation_action'] ?? null,
        'reserve_policy' => $evidence['reserve_policy'] ?? null,
        'cash_envelope' => $evidence['cash_envelope'] ?? null,
        'approval_gate' => $evidence['approval_gate'] ?? null,
        'source' => 'treasury_recommendations',
    ], [
        'object_type' => 'treasury_recommendation',
        'source' => 'treasury_recommendations',
        'after' => [
            'recommendation_id' => $recommendationId,
            'decision' => $action,
            'payment_id' => $paymentId,
            'decision_note' => $decisionNote,
            'evidence' => $evidence,
        ],
    ]);

    api_ok([
        'ok' => true,
        'action' => $action,
        'audit_event' => $event,
        'recommendation_id' => $recommendationId,
        'payment_id' => $paymentId,
    ]);
}

api_error('Method not allowed', 405);

function treasuryRecommendationReservePolicy(array $storedPolicy, string $currency): array
{
    $overrides = [];
    $read = static function (string $key) use ($storedPolicy, &$overrides): float {
        $queryValue = api_query($key);
        if ($queryValue !== null && $queryValue !== '') {
            $overrides[] = $key;
            return treasuryPolicyMoney($queryValue);
        }
        return treasuryPolicyMoney($storedPolicy[$key] ?? 0);
    };

    $minimumCashReserve = $read('minimum_cash_reserve');
    $payrollReserve = $read('payroll_reserve');
    $taxReserve = $read('tax_reserve');
    $apReserve = $read('ap_reserve');
    $operatingReserve = $read('operating_reserve');
    $materialityThreshold = $read('materiality_threshold');
    $requiredReserves = round($minimumCashReserve + $payrollReserve + $taxReserve + $apReserve + $operatingReserve, 2);

    return [
        'currency' => $currency,
        'policy_version' => (int) ($storedPolicy['policy_version'] ?? 0),
        'effective_date' => (string) ($storedPolicy['effective_date'] ?? date('Y-m-d')),
        'review_cadence_days' => (int) ($storedPolicy['review_cadence_days'] ?? 30),
        'source' => (string) ($storedPolicy['source'] ?? 'system_default'),
        'overrides_applied' => $overrides,
        'inputs' => [
            'minimum_cash_reserve' => $minimumCashReserve,
            'payroll_reserve' => $payrollReserve,
            'tax_reserve' => $taxReserve,
            'ap_reserve' => $apReserve,
            'operating_reserve' => $operatingReserve,
        ],
        'materiality_threshold' => $materialityThreshold,
        'required_reserves' => $requiredReserves,
        'formula' => 'required_reserves = minimum_cash_reserve + payroll_reserve + tax_reserve + ap_reserve + operating_reserve',
        'approval_resource' => (string) ($storedPolicy['approval_resource'] ?? 'treasury.payment'),
        'approval_permission' => (string) ($storedPolicy['approval_permission'] ?? 'treasury.approve_payment'),
        'execution_permission' => (string) ($storedPolicy['execution_permission'] ?? 'treasury.execute_payment'),
    ];
}

function treasuryRecommendationOpenPayments(
    int $tenantId,
    string $startDate,
    string $endDate,
    ?int $entityId,
    string $currency
): array {
    $pdo = getDB();
    $sql = "SELECT id, payment_number, entity_id, payee_type, payee_id, payee_name,
                   amount, currency, payment_date, payment_method, bank_account_id,
                   memo, status, workflow_instance_id, created_by_user_id, created_at
              FROM treasury_payments
             WHERE tenant_id = :t
               AND status IN ('draft','pending_approval','approved','scheduled')
               AND payment_date BETWEEN :s AND :e
               AND COALESCE(currency, 'USD') = :cur"
        . ($entityId ? ' AND entity_id = :ent' : '')
        . ' ORDER BY payment_date ASC, amount DESC, id ASC LIMIT 250';
    $params = ['t' => $tenantId, 's' => $startDate, 'e' => $endDate, 'cur' => $currency];
    if ($entityId) $params['ent'] = $entityId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function treasuryRecommendationForPayment(array $payment, array $reservePolicy, array $context): array
{
    $amount = round((float) $payment['amount'], 2);
    $status = (string) ($payment['status'] ?? 'draft');
    $availableNow = round((float) $context['available_now_after_reserves'], 2);
    $lowestAvailable = round((float) $context['lowest_available_after_reserves'], 2);
    $availableAfterPayment = round($availableNow - $amount, 2);
    $lowestAfterPayment = round($lowestAvailable - $amount, 2);
    $material = $amount >= (float) $context['materiality_threshold'];

    if ($availableAfterPayment < 0 || $lowestAfterPayment < 0) {
        $action = $amount <= max(0.0, $availableNow) ? 'split' : 'defer_or_escalate';
        $rationale = 'Payment would breach reserve policy or forecasted liquidity floor.';
    } elseif ($lowestAvailable < $amount * 0.25) {
        $action = 'hold_for_review';
        $rationale = 'Payment fits current reserves but forecast headroom is thin.';
    } elseif (in_array($status, ['approved', 'scheduled'], true)) {
        $action = 'pay_now';
        $rationale = 'Payment is already workflow-approved and remains inside reserve policy.';
    } else {
        $action = 'submit_for_approval';
        $rationale = 'Payment appears affordable but still requires the normal approval workflow.';
    }

    $approvalRequired = $material || !in_array($status, ['approved', 'scheduled'], true) || $action !== 'pay_now';
    $recommendationId = 'treasury_payment:' . (int) $payment['id'] . ':' . substr(hash('sha256', json_encode([
        'payment_id' => (int) $payment['id'],
        'amount' => $amount,
        'status' => $status,
        'policy' => $reservePolicy,
        'as_of' => $context['as_of_date'],
    ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: ''), 0, 16);

    return [
        'id' => $recommendationId,
        'recommendation_action' => $action,
        'rationale' => $rationale,
        'payment' => [
            'id' => (int) $payment['id'],
            'payment_number' => (string) ($payment['payment_number'] ?? ''),
            'payee_name' => (string) ($payment['payee_name'] ?? ''),
            'payee_type' => (string) ($payment['payee_type'] ?? ''),
            'amount' => $amount,
            'currency' => (string) ($payment['currency'] ?? 'USD'),
            'payment_date' => (string) ($payment['payment_date'] ?? ''),
            'payment_method' => (string) ($payment['payment_method'] ?? ''),
            'bank_account_id' => isset($payment['bank_account_id']) ? (int) $payment['bank_account_id'] : null,
            'status' => $status,
            'workflow_instance_id' => isset($payment['workflow_instance_id']) ? (int) $payment['workflow_instance_id'] : null,
        ],
        'cash_impact' => [
            'available_now_after_reserves' => $availableNow,
            'available_after_payment' => $availableAfterPayment,
            'lowest_available_after_payment' => $lowestAfterPayment,
            'projected_ending_cash' => (float) $context['projected_ending_cash'],
            'lowest_projected_cash' => (float) $context['lowest_projected_cash'],
        ],
        'approval_gate' => [
            'approval_required' => $approvalRequired,
            'material_recommendation' => $material,
            'workflow_resource' => (string) ($reservePolicy['approval_resource'] ?? 'treasury.payment'),
            'approval_permission' => (string) ($reservePolicy['approval_permission'] ?? 'treasury.approve_payment'),
            'execution_permission' => (string) ($reservePolicy['execution_permission'] ?? 'treasury.execute_payment'),
            'policy_version' => (int) ($reservePolicy['policy_version'] ?? 0),
            'next_workflow_step' => treasuryRecommendationNextWorkflowStep($status, $action),
            'money_movement_blocked_until_workflow_complete' => !in_array($status, ['approved', 'scheduled'], true),
        ],
        'evidence' => [
            'reserve_policy' => $reservePolicy,
            'forecast_window' => [
                'as_of_date' => $context['as_of_date'],
                'forecast_end_date' => $context['forecast_end_date'],
                'forecast_days' => $context['forecast_days'],
            ],
            'materiality_threshold' => (float) $context['materiality_threshold'],
            'variance_context' => $context['variance_context'],
            'projection' => $context['projection'],
        ],
    ];
}

function treasuryRecommendationNextWorkflowStep(string $status, string $action): string
{
    if ($action === 'defer_or_escalate') return 'escalate_before_payment_workflow';
    if ($action === 'split') return 'review_split_before_submit';
    if ($status === 'draft') return 'submit';
    if ($status === 'pending_approval') return 'approve_or_reject';
    if (in_array($status, ['approved', 'scheduled'], true)) return 'execute_when_ready';
    return 'review';
}

function treasuryRecommendationRiskLevel(float $lowestAvailable, float $projectedAvailable): string
{
    if ($lowestAvailable < 0) return 'critical';
    if ($projectedAvailable < 0 || $lowestAvailable < 10000) return 'watch';
    return 'stable';
}

function treasuryRecommendationVarianceContext(int $tenantId, string $today): array
{
    $startDate = date('Y-m-d', strtotime('-30 days'));
    try {
        $actuals = treasuryRecommendationVarianceActuals($tenantId, $startDate, $today);
    } catch (\Throwable $_) {
        $actuals = [];
    }
    $actualNet = 0.0;
    foreach ($actuals as $row) {
        $actualNet += (float) ($row['net'] ?? 0);
    }
    return [
        'basis' => 'last_30_days_posted_cash_activity',
        'start_date' => $startDate,
        'end_date' => $today,
        'actual_net_cash_movement' => round($actualNet, 2),
        'observed_cash_activity_days' => count(array_filter($actuals, static fn(array $row): bool => abs((float) ($row['net'] ?? 0)) > 0.0001)),
    ];
}

/**
 * Local copy of the variance actuals query so recommendations can include
 * evidence without invoking another HTTP endpoint.
 *
 * @return array<string,array{cash_in:float,cash_out:float,net:float}>
 */
function treasuryRecommendationVarianceActuals(int $tenantId, string $startDate, string $endDate): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT je.posting_date AS date,
                COALESCE(SUM(CASE WHEN jl.debit > 0 THEN jl.debit ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN jl.credit > 0 THEN jl.credit ELSE 0 END), 0) AS cash_out,
                COALESCE(SUM(jl.debit - jl.credit), 0) AS net
           FROM accounting_bank_accounts ba
           JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.account_code = ba.gl_account_code
           JOIN accounting_journal_lines jl ON jl.account_id = aa.id AND jl.tenant_id = aa.tenant_id
           JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
          WHERE ba.tenant_id = :t
            AND ba.status = 'active'
            AND je.posting_date BETWEEN :s AND :e
          GROUP BY je.posting_date
          ORDER BY je.posting_date"
    );
    $stmt->execute(['t' => $tenantId, 's' => $startDate, 'e' => $endDate]);
    $out = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $date = (string) $row['date'];
        $out[$date] = [
            'cash_in' => round((float) $row['cash_in'], 2),
            'cash_out' => round((float) $row['cash_out'], 2),
            'net' => round((float) $row['net'], 2),
        ];
    }
    return $out;
}
