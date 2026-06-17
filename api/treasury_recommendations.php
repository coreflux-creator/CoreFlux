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
    if ($action === 'decisions') {
        $limit = max(1, min(200, (int) (api_query('limit') ?? 50)));
        api_ok([
            'rows' => treasuryRecommendationDecisionHistory($tid, $limit),
            'limit' => $limit,
            'decision_ledger' => 'treasury_recommendation_decisions',
        ]);
    }
    if ($action === 'exceptions') {
        $status = (string) (api_query('status') ?? 'open');
        $limit = max(1, min(200, (int) (api_query('limit') ?? 100)));
        api_ok([
            'rows' => treasuryRecommendationExceptions($tid, $status, $limit),
            'limit' => $limit,
            'exception_ledger' => 'treasury_recommendation_exceptions',
        ]);
    }

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
    $decisionsByRecommendation = treasuryRecommendationLatestDecisions(
        $tid,
        array_map(static fn(array $row): string => (string) $row['id'], $recommendations),
        array_values(array_filter(array_map(static fn(array $row): ?int => $row['payment']['id'] ?? null, $recommendations)))
    );
    $handoffsByRecommendation = treasuryRecommendationLatestHandoffs(
        $tid,
        array_map(static fn(array $row): string => (string) $row['id'], $recommendations),
        array_values(array_filter(array_map(static fn(array $row): ?int => $row['payment']['id'] ?? null, $recommendations)))
    );
    $exceptionsByRecommendation = treasuryRecommendationLatestExceptions(
        $tid,
        array_map(static fn(array $row): string => (string) $row['id'], $recommendations),
        array_values(array_filter(array_map(static fn(array $row): ?int => $row['payment']['id'] ?? null, $recommendations)))
    );
    foreach ($recommendations as &$recommendation) {
        $decision = $decisionsByRecommendation[$recommendation['id']]
            ?? $decisionsByRecommendation['payment:' . ($recommendation['payment']['id'] ?? 0)]
            ?? null;
        $handoff = $handoffsByRecommendation[$recommendation['id']]
            ?? $handoffsByRecommendation['payment:' . ($recommendation['payment']['id'] ?? 0)]
            ?? null;
        $exception = $exceptionsByRecommendation[$recommendation['id']]
            ?? $exceptionsByRecommendation['payment:' . ($recommendation['payment']['id'] ?? 0)]
            ?? null;
        $recommendation['latest_decision'] = $decision;
        $recommendation['latest_handoff'] = $handoff;
        $recommendation['latest_exception'] = $exception;
        $recommendation['freshness_control'] = treasuryRecommendationItemFreshness($recommendation, $reservePolicy, $today);
    }
    unset($recommendation);
    $summary = treasuryRecommendationSummary($recommendations);
    $reviewQueue = treasuryRecommendationReviewQueue($recommendations);
    $reviewControl = treasuryRecommendationReviewControl($recommendations, $reservePolicy, $today);

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
        'summary' => $summary,
        'review_queue' => $reviewQueue,
        'review_control' => $reviewControl,
        'auditability' => [
            'decision_events' => ['treasury.recommendation.accepted', 'treasury.recommendation.dismissed'],
            'decision_ledger' => 'treasury_recommendation_decisions',
            'handoff_event' => 'treasury.recommendation.handoff_logged',
            'handoff_ledger' => 'treasury_recommendation_handoffs',
            'exception_events' => [
                'treasury.recommendation.exception_opened',
                'treasury.recommendation.exception_assigned',
                'treasury.recommendation.exception_resolved',
            ],
            'exception_ledger' => 'treasury_recommendation_exceptions',
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
    $decision = treasuryRecommendationRecordDecision($tid, $actorUserId, $recommendationId, $paymentId, $action, $decisionNote, $evidence);

    platformAuditLogWrite($tid, $actorUserId, $event, $paymentId ?: null, [
        'recommendation_id' => $recommendationId,
        'payment_id' => $paymentId,
        'decision_id' => $decision['id'],
        'evidence_hash' => $decision['evidence_hash'],
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
        'decision' => $decision,
    ]);
}

if ($method === 'POST' && $action === 'handoff_log') {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $body = api_json_body();
    $recommendationId = trim((string) ($body['recommendation_id'] ?? ''));
    if ($recommendationId === '') api_error('recommendation_id required', 400);
    $paymentId = isset($body['payment_id']) ? max(0, (int) $body['payment_id']) : null;
    $handoffAction = (string) ($body['handoff_action'] ?? '');
    if (!in_array($handoffAction, ['submit', 'approve', 'reject', 'execute'], true)) {
        api_error('handoff_action must be submit, approve, reject, or execute', 400);
    }
    $result = (string) ($body['result'] ?? '');
    if (!in_array($result, ['success', 'failure'], true)) {
        api_error('result must be success or failure', 400);
    }

    $handoff = treasuryRecommendationRecordHandoff($tid, $actorUserId, $recommendationId, $paymentId, $handoffAction, $result, $body);
    platformAuditLogWrite($tid, $actorUserId, 'treasury.recommendation.handoff_logged', $paymentId ?: null, [
        'recommendation_id' => $recommendationId,
        'payment_id' => $paymentId,
        'handoff_id' => $handoff['id'],
        'handoff_action' => $handoffAction,
        'result' => $result,
        'payment_status_before' => $handoff['payment_status_before'],
        'payment_status_after' => $handoff['payment_status_after'],
        'source' => 'treasury_recommendations',
    ], [
        'object_type' => 'treasury_recommendation_handoff',
        'source' => 'treasury_recommendations',
        'after' => $handoff,
    ]);

    api_ok([
        'ok' => true,
        'handoff' => $handoff,
        'audit_event' => 'treasury.recommendation.handoff_logged',
    ]);
}

if ($method === 'POST' && in_array($action, ['open_exception', 'assign_exception', 'resolve_exception'], true)) {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $body = api_json_body();
    $exception = treasuryRecommendationHandleExceptionAction($tid, $actorUserId, $action, $body);
    $event = [
        'open_exception' => 'treasury.recommendation.exception_opened',
        'assign_exception' => 'treasury.recommendation.exception_assigned',
        'resolve_exception' => 'treasury.recommendation.exception_resolved',
    ][$action];
    platformAuditLogWrite($tid, $actorUserId, $event, $exception['payment_id'] ?: null, [
        'exception_id' => $exception['id'],
        'recommendation_id' => $exception['recommendation_id'],
        'payment_id' => $exception['payment_id'],
        'status' => $exception['status'],
        'owner_user_id' => $exception['owner_user_id'],
        'source' => 'treasury_recommendations',
    ], [
        'object_type' => 'treasury_recommendation_exception',
        'source' => 'treasury_recommendations',
        'after' => $exception,
    ]);

    api_ok([
        'ok' => true,
        'action' => $action,
        'audit_event' => $event,
        'exception' => $exception,
        'money_movement_gate' => 'Exception resolution records human disposition only; payment movement remains gated by treasury_payments.php.',
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
            'created_at' => (string) ($payment['created_at'] ?? ''),
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

function treasuryRecommendationSummary(array $recommendations): array
{
    $actions = [
        'pay_now' => 0,
        'submit_for_approval' => 0,
        'hold_for_review' => 0,
        'split' => 0,
        'defer_or_escalate' => 0,
    ];
    $decisions = [
        'accepted' => 0,
        'dismissed' => 0,
        'undecided' => 0,
    ];
    $reviewQueueCount = 0;

    foreach ($recommendations as $row) {
        $action = (string) ($row['recommendation_action'] ?? '');
        if (array_key_exists($action, $actions)) $actions[$action]++;
        $decision = (string) ($row['latest_decision']['decision'] ?? '');
        if ($decision === 'accept') {
            $decisions['accepted']++;
        } elseif ($decision === 'dismiss') {
            $decisions['dismissed']++;
        } else {
            $decisions['undecided']++;
        }
        if (treasuryRecommendationNeedsReview($row)) $reviewQueueCount++;
    }

    return [
        'total' => count($recommendations),
        'actions' => $actions,
        'decisions' => $decisions,
        'review_queue_count' => $reviewQueueCount,
    ];
}

function treasuryRecommendationReviewQueue(array $recommendations): array
{
    $queue = [];
    foreach ($recommendations as $row) {
        if (!treasuryRecommendationNeedsReview($row)) continue;
        $queue[] = [
            'id' => $row['id'] ?? null,
            'recommendation_action' => $row['recommendation_action'] ?? null,
            'rationale' => $row['rationale'] ?? null,
            'payment' => $row['payment'] ?? [],
            'approval_gate' => $row['approval_gate'] ?? [],
            'cash_impact' => $row['cash_impact'] ?? [],
            'latest_decision' => $row['latest_decision'] ?? null,
            'latest_exception' => $row['latest_exception'] ?? null,
            'freshness_control' => $row['freshness_control'] ?? null,
            'handoff' => [
                'next_workflow_step' => $row['approval_gate']['next_workflow_step'] ?? 'review',
                'workflow_resource' => $row['approval_gate']['workflow_resource'] ?? 'treasury.payment',
                'approval_permission' => $row['approval_gate']['approval_permission'] ?? 'treasury.approve_payment',
                'execution_permission' => $row['approval_gate']['execution_permission'] ?? 'treasury.execute_payment',
            ],
        ];
    }
    return $queue;
}

function treasuryRecommendationReviewControl(array $recommendations, array $reservePolicy, string $today): array
{
    $counts = [
        'review_items' => 0,
        'attention_items' => 0,
        'stale_review_items' => 0,
        'open_exceptions' => 0,
        'unowned_exceptions' => 0,
        'terminal_exceptions' => 0,
    ];

    foreach ($recommendations as $row) {
        if (treasuryRecommendationNeedsReview($row)) {
            $counts['review_items']++;
            $status = (string) ($row['freshness_control']['review_status'] ?? 'current');
            if (in_array($status, ['attention', 'stale'], true)) $counts['attention_items']++;
            if ($status === 'stale') $counts['stale_review_items']++;
        }
        $exception = $row['latest_exception'] ?? null;
        if (is_array($exception)) {
            if (in_array((string) ($exception['status'] ?? ''), ['resolved', 'dismissed'], true)) {
                $counts['terminal_exceptions']++;
            } else {
                $counts['open_exceptions']++;
                if (empty($exception['owner_user_id'])) $counts['unowned_exceptions']++;
            }
        }
    }

    return [
        'policy_review' => treasuryRecommendationPolicyReviewStatus($reservePolicy, $today),
        'counts' => $counts,
        'cadence_source' => 'tenant_treasury_policy.review_cadence_days',
        'ownership_source' => 'treasury_recommendation_exceptions.owner_user_id',
        'stale_rule' => 'Review items become stale when the payment is due/past due or the review item age reaches its derived review SLA.',
        'money_movement_gate' => 'Review control metadata is advisory; payment submit, approval, and execution remain gated by Treasury workflow.',
    ];
}

function treasuryRecommendationPolicyReviewStatus(array $reservePolicy, string $today): array
{
    $cadenceDays = max(1, (int) ($reservePolicy['review_cadence_days'] ?? 30));
    $effectiveDate = (string) ($reservePolicy['effective_date'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) $effectiveDate = $today;
    $dueDate = date('Y-m-d', strtotime($effectiveDate . " +{$cadenceDays} days"));
    $daysUntilDue = treasuryRecommendationDateDiffDays($today, $dueDate);
    $status = 'current';
    if ($daysUntilDue < 0) {
        $status = 'overdue';
    } elseif ($daysUntilDue <= min(7, $cadenceDays)) {
        $status = 'due_soon';
    }

    return [
        'effective_date' => $effectiveDate,
        'review_cadence_days' => $cadenceDays,
        'next_review_due_date' => $dueDate,
        'days_until_due' => $daysUntilDue,
        'status' => $status,
    ];
}

function treasuryRecommendationItemFreshness(array $row, array $reservePolicy, string $today): array
{
    $cadenceDays = max(1, (int) ($reservePolicy['review_cadence_days'] ?? 30));
    $reviewSlaDays = max(1, min(14, (int) ceil($cadenceDays / 4)));
    $payment = $row['payment'] ?? [];
    $paymentDate = (string) ($payment['payment_date'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) $paymentDate = $today;
    $createdDate = substr((string) ($payment['created_at'] ?? $today), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdDate)) $createdDate = $today;
    $exception = $row['latest_exception'] ?? null;
    $openedDate = is_array($exception) ? substr((string) ($exception['opened_at'] ?? ''), 0, 10) : '';
    $ageStartDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $openedDate) ? $openedDate : $createdDate;
    $reviewAgeDays = max(0, treasuryRecommendationDateDiffDays($ageStartDate, $today));
    $paymentDueInDays = treasuryRecommendationDateDiffDays($today, $paymentDate);
    $needsReview = treasuryRecommendationNeedsReview($row);

    $status = 'current';
    if ($needsReview && ($paymentDueInDays <= 0 || $reviewAgeDays >= $reviewSlaDays)) {
        $status = 'stale';
    } elseif ($needsReview && ($paymentDueInDays <= 3 || $reviewAgeDays >= max(1, $reviewSlaDays - 1))) {
        $status = 'attention';
    }

    return [
        'as_of_date' => $today,
        'review_status' => $status,
        'review_sla_days' => $reviewSlaDays,
        'review_age_days' => $reviewAgeDays,
        'payment_due_in_days' => $paymentDueInDays,
        'age_start_date' => $ageStartDate,
        'requires_owner' => $needsReview && $status === 'stale' && (!is_array($exception) || empty($exception['owner_user_id'])),
    ];
}

function treasuryRecommendationDateDiffDays(string $from, string $to): int
{
    try {
        $fromDate = new \DateTimeImmutable($from);
        $toDate = new \DateTimeImmutable($to);
        return (int) $fromDate->diff($toDate)->format('%r%a');
    } catch (\Throwable $_) {
        return 0;
    }
}

function treasuryRecommendationExceptionSeverity(array $row): string
{
    if ((float) ($row['cash_impact']['lowest_available_after_payment'] ?? 0) < 0) return 'critical';
    if (($row['recommendation_action'] ?? '') === 'defer_or_escalate') return 'high';
    if (($row['recommendation_action'] ?? '') === 'split') return 'high';
    if (!empty($row['approval_gate']['material_recommendation'])) return 'medium';
    return 'medium';
}

function treasuryRecommendationNeedsReview(array $row): bool
{
    $action = (string) ($row['recommendation_action'] ?? '');
    return in_array($action, ['defer_or_escalate', 'split', 'hold_for_review'], true)
        || !empty($row['approval_gate']['material_recommendation'])
        || ((float) ($row['cash_impact']['lowest_available_after_payment'] ?? 0) < 0);
}

function treasuryRecommendationRecordDecision(
    int $tenantId,
    ?int $actorUserId,
    string $recommendationId,
    ?int $paymentId,
    string $decision,
    string $decisionNote,
    array $evidence
): array {
    $pdo = getDB();
    $evidenceJson = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $evidenceHash = hash('sha256', $evidenceJson ?: '{}');
    $policyVersion = isset($evidence['reserve_policy']['policy_version'])
        ? (int) $evidence['reserve_policy']['policy_version']
        : (isset($evidence['approval_gate']['policy_version']) ? (int) $evidence['approval_gate']['policy_version'] : null);
    $recommendationAction = isset($evidence['recommendation_action'])
        ? substr((string) $evidence['recommendation_action'], 0, 80)
        : null;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO treasury_recommendation_decisions
                (tenant_id, recommendation_id, payment_id, decision, recommendation_action,
                 policy_version, evidence_hash, evidence_json, decision_note, actor_user_id)
             VALUES
                (:tenant_id, :recommendation_id, :payment_id, :decision, :recommendation_action,
                 :policy_version, :evidence_hash, :evidence_json, :decision_note, :actor_user_id)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'recommendation_id' => $recommendationId,
            'payment_id' => $paymentId ?: null,
            'decision' => $decision,
            'recommendation_action' => $recommendationAction,
            'policy_version' => $policyVersion,
            'evidence_hash' => $evidenceHash,
            'evidence_json' => $evidenceJson ?: '{}',
            'decision_note' => $decisionNote,
            'actor_user_id' => $actorUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[treasury.recommendations] decision ledger write failed: ' . $e->getMessage());
        $id = 0;
    }

    return [
        'id' => $id,
        'recommendation_id' => $recommendationId,
        'payment_id' => $paymentId,
        'decision' => $decision,
        'recommendation_action' => $recommendationAction,
        'policy_version' => $policyVersion,
        'evidence_hash' => $evidenceHash,
        'decision_note' => $decisionNote,
        'actor_user_id' => $actorUserId,
    ];
}

function treasuryRecommendationLatestDecisions(int $tenantId, array $recommendationIds, array $paymentIds): array
{
    $recommendationIds = array_values(array_unique(array_filter(array_map('strval', $recommendationIds))));
    $paymentIds = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
    if (!$recommendationIds && !$paymentIds) return [];

    $pdo = getDB();
    try {
        $where = [];
        $params = ['tenant_id' => $tenantId];
        if ($recommendationIds) {
            $ph = [];
            foreach ($recommendationIds as $i => $id) {
                $key = 'rid' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'recommendation_id IN (' . implode(',', $ph) . ')';
        }
        if ($paymentIds) {
            $ph = [];
            foreach ($paymentIds as $i => $id) {
                $key = 'pid' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'payment_id IN (' . implode(',', $ph) . ')';
        }
        $stmt = $pdo->prepare(
            'SELECT id, recommendation_id, payment_id, decision, recommendation_action,
                    policy_version, evidence_hash, decision_note, actor_user_id, decided_at
               FROM treasury_recommendation_decisions
              WHERE tenant_id = :tenant_id AND (' . implode(' OR ', $where) . ')
              ORDER BY decided_at DESC, id DESC'
        );
        $stmt->execute($params);
    } catch (\Throwable $_) {
        return [];
    }

    $out = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $decision = [
            'id' => (int) $row['id'],
            'recommendation_id' => (string) $row['recommendation_id'],
            'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
            'decision' => (string) $row['decision'],
            'recommendation_action' => $row['recommendation_action'],
            'policy_version' => $row['policy_version'] !== null ? (int) $row['policy_version'] : null,
            'evidence_hash' => (string) $row['evidence_hash'],
            'decision_note' => $row['decision_note'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            'decided_at' => $row['decided_at'],
        ];
        if (!isset($out[$decision['recommendation_id']])) $out[$decision['recommendation_id']] = $decision;
        if ($decision['payment_id'] !== null && !isset($out['payment:' . $decision['payment_id']])) {
            $out['payment:' . $decision['payment_id']] = $decision;
        }
    }
    return $out;
}

function treasuryRecommendationRecordHandoff(
    int $tenantId,
    ?int $actorUserId,
    string $recommendationId,
    ?int $paymentId,
    string $handoffAction,
    string $result,
    array $body
): array {
    $pdo = getDB();
    $statusBefore = isset($body['payment_status_before']) ? substr((string) $body['payment_status_before'], 0, 40) : null;
    $statusAfter = isset($body['payment_status_after']) ? substr((string) $body['payment_status_after'], 0, 40) : null;
    $workflowResponse = is_array($body['workflow_response'] ?? null) ? $body['workflow_response'] : [];
    $workflowResponseJson = json_encode($workflowResponse, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '{}';
    $errorText = isset($body['error_text']) ? substr((string) $body['error_text'], 0, 1000) : null;

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO treasury_recommendation_handoffs
                (tenant_id, recommendation_id, payment_id, handoff_action, result,
                 payment_status_before, payment_status_after, workflow_response_json,
                 error_text, actor_user_id)
             VALUES
                (:tenant_id, :recommendation_id, :payment_id, :handoff_action, :result,
                 :payment_status_before, :payment_status_after, :workflow_response_json,
                 :error_text, :actor_user_id)'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'recommendation_id' => $recommendationId,
            'payment_id' => $paymentId ?: null,
            'handoff_action' => $handoffAction,
            'result' => $result,
            'payment_status_before' => $statusBefore,
            'payment_status_after' => $statusAfter,
            'workflow_response_json' => $workflowResponseJson,
            'error_text' => $errorText,
            'actor_user_id' => $actorUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[treasury.recommendations] handoff ledger write failed: ' . $e->getMessage());
        $id = 0;
    }

    return [
        'id' => $id,
        'recommendation_id' => $recommendationId,
        'payment_id' => $paymentId,
        'handoff_action' => $handoffAction,
        'result' => $result,
        'payment_status_before' => $statusBefore,
        'payment_status_after' => $statusAfter,
        'workflow_response' => $workflowResponse,
        'error_text' => $errorText,
        'actor_user_id' => $actorUserId,
    ];
}

function treasuryRecommendationLatestHandoffs(int $tenantId, array $recommendationIds, array $paymentIds): array
{
    $recommendationIds = array_values(array_unique(array_filter(array_map('strval', $recommendationIds))));
    $paymentIds = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
    if (!$recommendationIds && !$paymentIds) return [];

    $pdo = getDB();
    try {
        $where = [];
        $params = ['tenant_id' => $tenantId];
        if ($recommendationIds) {
            $ph = [];
            foreach ($recommendationIds as $i => $id) {
                $key = 'hrid' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'recommendation_id IN (' . implode(',', $ph) . ')';
        }
        if ($paymentIds) {
            $ph = [];
            foreach ($paymentIds as $i => $id) {
                $key = 'hpid' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'payment_id IN (' . implode(',', $ph) . ')';
        }
        $stmt = $pdo->prepare(
            'SELECT id, recommendation_id, payment_id, handoff_action, result,
                    payment_status_before, payment_status_after, error_text,
                    actor_user_id, attempted_at
               FROM treasury_recommendation_handoffs
              WHERE tenant_id = :tenant_id AND (' . implode(' OR ', $where) . ')
              ORDER BY attempted_at DESC, id DESC'
        );
        $stmt->execute($params);
    } catch (\Throwable $_) {
        return [];
    }

    $out = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $handoff = [
            'id' => (int) $row['id'],
            'recommendation_id' => (string) $row['recommendation_id'],
            'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
            'handoff_action' => (string) $row['handoff_action'],
            'result' => (string) $row['result'],
            'payment_status_before' => $row['payment_status_before'],
            'payment_status_after' => $row['payment_status_after'],
            'error_text' => $row['error_text'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            'attempted_at' => $row['attempted_at'],
        ];
        if (!isset($out[$handoff['recommendation_id']])) $out[$handoff['recommendation_id']] = $handoff;
        if ($handoff['payment_id'] !== null && !isset($out['payment:' . $handoff['payment_id']])) {
            $out['payment:' . $handoff['payment_id']] = $handoff;
        }
    }
    return $out;
}

function treasuryRecommendationHandleExceptionAction(int $tenantId, ?int $actorUserId, string $action, array $body): array
{
    if ($action === 'open_exception') {
        $recommendationId = trim((string) ($body['recommendation_id'] ?? ''));
        if ($recommendationId === '') api_error('recommendation_id required', 400);
        $paymentId = isset($body['payment_id']) ? max(0, (int) $body['payment_id']) : null;
        $recommendationAction = substr(trim((string) ($body['recommendation_action'] ?? 'review')), 0, 80) ?: 'review';
        $severity = (string) ($body['severity'] ?? 'medium');
        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) $severity = 'medium';
        $reason = substr(trim((string) ($body['reason'] ?? 'Treasury recommendation requires review')), 0, 1000);
        $policyVersion = isset($body['policy_version']) ? max(0, (int) $body['policy_version']) : null;
        $ownerUserId = isset($body['owner_user_id']) ? max(0, (int) $body['owner_user_id']) : null;
        $status = $ownerUserId ? 'assigned' : 'open';

        $pdo = getDB();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO treasury_recommendation_exceptions
                    (tenant_id, recommendation_id, payment_id, recommendation_action,
                     severity, status, reason, policy_version, owner_user_id,
                     opened_by_user_id, assigned_at)
                 VALUES
                    (:tenant_id, :recommendation_id, :payment_id, :recommendation_action,
                     :severity, :status, :reason, :policy_version, :owner_user_id,
                     :opened_by_user_id, ' . ($ownerUserId ? 'NOW()' : 'NULL') . ')'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'recommendation_id' => $recommendationId,
                'payment_id' => $paymentId ?: null,
                'recommendation_action' => $recommendationAction,
                'severity' => $severity,
                'status' => $status,
                'reason' => $reason,
                'policy_version' => $policyVersion,
                'owner_user_id' => $ownerUserId ?: null,
                'opened_by_user_id' => $actorUserId,
            ]);
            return treasuryRecommendationExceptionById($tenantId, (int) $pdo->lastInsertId()) ?? [
                'id' => 0,
                'recommendation_id' => $recommendationId,
                'payment_id' => $paymentId,
                'status' => $status,
                'owner_user_id' => $ownerUserId,
            ];
        } catch (\Throwable $e) {
            error_log('[treasury.recommendations] exception open failed: ' . $e->getMessage());
            api_error('Could not open recommendation exception', 500);
        }
    }

    $exceptionId = max(0, (int) ($body['exception_id'] ?? 0));
    if ($exceptionId <= 0) api_error('exception_id required', 400);
    $pdo = getDB();
    $before = treasuryRecommendationExceptionById($tenantId, $exceptionId);
    if (!$before) api_error('Exception not found', 404);

    if ($action === 'assign_exception') {
        $ownerUserId = max(0, (int) ($body['owner_user_id'] ?? 0));
        if ($ownerUserId <= 0) api_error('owner_user_id required', 400);
        $pdo->prepare(
            'UPDATE treasury_recommendation_exceptions
                SET owner_user_id = :owner_user_id, status = "assigned", assigned_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :id'
        )->execute(['owner_user_id' => $ownerUserId, 'tenant_id' => $tenantId, 'id' => $exceptionId]);
        return treasuryRecommendationExceptionById($tenantId, $exceptionId) ?? $before;
    }

    $resolutionNote = substr(trim((string) ($body['resolution_note'] ?? '')), 0, 1000);
    if ($resolutionNote === '') api_error('resolution_note required', 400);
    $status = ((string) ($body['status'] ?? 'resolved')) === 'dismissed' ? 'dismissed' : 'resolved';
    $pdo->prepare(
        'UPDATE treasury_recommendation_exceptions
            SET status = :status, resolution_note = :resolution_note,
                resolved_by_user_id = :resolved_by_user_id, resolved_at = NOW()
          WHERE tenant_id = :tenant_id AND id = :id'
    )->execute([
        'status' => $status,
        'resolution_note' => $resolutionNote,
        'resolved_by_user_id' => $actorUserId,
        'tenant_id' => $tenantId,
        'id' => $exceptionId,
    ]);
    return treasuryRecommendationExceptionById($tenantId, $exceptionId) ?? $before;
}

function treasuryRecommendationExceptionById(int $tenantId, int $exceptionId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, recommendation_id, payment_id, recommendation_action, severity,
                status, reason, policy_version, owner_user_id, opened_by_user_id,
                resolved_by_user_id, resolution_note, opened_at, assigned_at,
                resolved_at, updated_at
           FROM treasury_recommendation_exceptions
          WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'id' => $exceptionId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ? treasuryRecommendationHydrateException($row) : null;
}

function treasuryRecommendationExceptions(int $tenantId, string $status, int $limit): array
{
    $allowed = ['open', 'assigned', 'resolved', 'dismissed', 'all'];
    if (!in_array($status, $allowed, true)) $status = 'open';
    $sql = 'SELECT id, recommendation_id, payment_id, recommendation_action, severity,
                   status, reason, policy_version, owner_user_id, opened_by_user_id,
                   resolved_by_user_id, resolution_note, opened_at, assigned_at,
                   resolved_at, updated_at
              FROM treasury_recommendation_exceptions
             WHERE tenant_id = :tenant_id'
        . ($status === 'all' ? '' : ' AND status = :status')
        . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit;
    $params = ['tenant_id' => $tenantId];
    if ($status !== 'all') $params['status'] = $status;
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $rows[] = treasuryRecommendationHydrateException($row);
    }
    return $rows;
}

function treasuryRecommendationLatestExceptions(int $tenantId, array $recommendationIds, array $paymentIds): array
{
    $recommendationIds = array_values(array_unique(array_filter(array_map('strval', $recommendationIds))));
    $paymentIds = array_values(array_unique(array_filter(array_map('intval', $paymentIds))));
    if (!$recommendationIds && !$paymentIds) return [];
    try {
        $where = [];
        $params = ['tenant_id' => $tenantId];
        if ($recommendationIds) {
            $ph = [];
            foreach ($recommendationIds as $i => $id) {
                $key = 'erid' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'recommendation_id IN (' . implode(',', $ph) . ')';
        }
        if ($paymentIds) {
            $ph = [];
            foreach ($paymentIds as $i => $id) {
                $key = 'epid' . $i;
                $ph[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'payment_id IN (' . implode(',', $ph) . ')';
        }
        $stmt = getDB()->prepare(
            'SELECT id, recommendation_id, payment_id, recommendation_action, severity,
                    status, reason, policy_version, owner_user_id, opened_by_user_id,
                    resolved_by_user_id, resolution_note, opened_at, assigned_at,
                    resolved_at, updated_at
               FROM treasury_recommendation_exceptions
              WHERE tenant_id = :tenant_id AND (' . implode(' OR ', $where) . ')
              ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute($params);
    } catch (\Throwable $_) {
        return [];
    }
    $out = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $exception = treasuryRecommendationHydrateException($row);
        if (!isset($out[$exception['recommendation_id']])) $out[$exception['recommendation_id']] = $exception;
        if ($exception['payment_id'] !== null && !isset($out['payment:' . $exception['payment_id']])) {
            $out['payment:' . $exception['payment_id']] = $exception;
        }
    }
    return $out;
}

function treasuryRecommendationHydrateException(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'recommendation_id' => (string) $row['recommendation_id'],
        'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
        'recommendation_action' => (string) $row['recommendation_action'],
        'severity' => (string) $row['severity'],
        'status' => (string) $row['status'],
        'reason' => $row['reason'],
        'policy_version' => $row['policy_version'] !== null ? (int) $row['policy_version'] : null,
        'owner_user_id' => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
        'opened_by_user_id' => $row['opened_by_user_id'] !== null ? (int) $row['opened_by_user_id'] : null,
        'resolved_by_user_id' => $row['resolved_by_user_id'] !== null ? (int) $row['resolved_by_user_id'] : null,
        'resolution_note' => $row['resolution_note'],
        'opened_at' => $row['opened_at'],
        'assigned_at' => $row['assigned_at'],
        'resolved_at' => $row['resolved_at'],
        'updated_at' => $row['updated_at'],
    ];
}

function treasuryRecommendationDecisionHistory(int $tenantId, int $limit): array
{
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, recommendation_id, payment_id, decision, recommendation_action,
                    policy_version, evidence_hash, decision_note, actor_user_id, decided_at
               FROM treasury_recommendation_decisions
              WHERE tenant_id = :tenant_id
              ORDER BY decided_at DESC, id DESC
              LIMIT ' . $limit
        );
        $stmt->execute(['tenant_id' => $tenantId]);
    } catch (\Throwable $_) {
        return [];
    }

    $rows = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $rows[] = [
            'id' => (int) $row['id'],
            'recommendation_id' => (string) $row['recommendation_id'],
            'payment_id' => $row['payment_id'] !== null ? (int) $row['payment_id'] : null,
            'decision' => (string) $row['decision'],
            'recommendation_action' => $row['recommendation_action'],
            'policy_version' => $row['policy_version'] !== null ? (int) $row['policy_version'] : null,
            'evidence_hash' => (string) $row['evidence_hash'],
            'decision_note' => $row['decision_note'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            'decided_at' => $row['decided_at'],
        ];
    }
    return $rows;
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
