<?php
/**
 * Treasury recommendations alignment smoke.
 *
 * Locks reserve-policy inputs, payment timing evidence, human approval gates,
 * and accept/dismiss auditability for Treasury recommendations.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { echo "  OK  {$msg}\n"; $pass++; }
    else     { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$endpoint = (string) file_get_contents("{$ROOT}/api/treasury_recommendations.php");
$alias = (string) file_get_contents("{$ROOT}/modules/treasury/api/recommendations.php");
$policyApi = (string) file_get_contents("{$ROOT}/api/treasury_policy.php");
$policyLib = (string) file_get_contents("{$ROOT}/modules/treasury/lib/policy.php");
$policyAlias = (string) file_get_contents("{$ROOT}/modules/treasury/api/policy.php");
$policyMig = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/008_treasury_policy.sql");
$decisionMig = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/009_treasury_recommendation_decisions.sql");
$handoffMig = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/010_treasury_recommendation_handoffs.sql");
$page = (string) file_get_contents("{$ROOT}/dashboard/src/pages/TreasuryRecommendations.jsx");
$overview = (string) file_get_contents("{$ROOT}/modules/treasury/ui/TreasuryOverview.jsx");
$module = (string) file_get_contents("{$ROOT}/modules/treasury/ui/TreasuryModule.jsx");
$manifest = (string) file_get_contents("{$ROOT}/modules/treasury/manifest.php");

echo "Files parse\n";
$a('endpoint parses', $lint('api/treasury_recommendations.php'));
$a('policy endpoint parses', $lint('api/treasury_policy.php'));
$a('policy helper parses', $lint('modules/treasury/lib/policy.php'));
$a('module alias points to endpoint', str_contains($alias, "/../../../api/treasury_recommendations.php"));
$a('policy alias points to endpoint', str_contains($policyAlias, "/../../../api/treasury_policy.php"));

echo "\nDurable policy\n";
$a('migration creates tenant_treasury_policy',
    str_contains($policyMig, 'CREATE TABLE IF NOT EXISTS tenant_treasury_policy')
    && str_contains($policyMig, 'policy_version INT UNSIGNED NOT NULL DEFAULT 1')
    && str_contains($policyMig, 'effective_date DATE NOT NULL')
    && str_contains($policyMig, 'review_cadence_days INT UNSIGNED NOT NULL DEFAULT 30'));
$a('policy API gates read/write separately',
    str_contains($policyApi, "rbac_legacy_require(\$user, 'treasury.payment.view')")
    && str_contains($policyApi, "rbac_legacy_require(\$user, 'treasury.manage_forecast')"));
$a('policy save increments version and audits before/after',
    str_contains($policyLib, "\$policy['policy_version'] = max(1, (int) (\$before['policy_version'] ?? 0) + 1)")
    && str_contains($policyLib, "treasury.policy.updated")
    && str_contains($policyLib, "'object_type' => 'treasury_policy'")
    && str_contains($policyLib, "'before' => \$before")
    && str_contains($policyLib, "'after' => \$after"));

echo "\nDecision ledger\n";
$a('migration creates recommendation decision ledger',
    str_contains($decisionMig, 'CREATE TABLE IF NOT EXISTS treasury_recommendation_decisions')
    && str_contains($decisionMig, 'recommendation_id VARCHAR(160) NOT NULL')
    && str_contains($decisionMig, "decision ENUM('accept','dismiss') NOT NULL")
    && str_contains($decisionMig, 'evidence_hash CHAR(64) NOT NULL')
    && str_contains($decisionMig, 'evidence_json MEDIUMTEXT NULL')
    && str_contains($decisionMig, 'idx_trd_tenant_recommendation')
    && str_contains($decisionMig, 'idx_trd_tenant_payment'));
$a('POST writes queryable decision ledger with evidence hash',
    str_contains($endpoint, 'function treasuryRecommendationRecordDecision(')
    && str_contains($endpoint, 'INSERT INTO treasury_recommendation_decisions')
    && str_contains($endpoint, "hash('sha256', \$evidenceJson ?: '{}')")
    && str_contains($endpoint, "'evidence_hash' => \$evidenceHash"));
$a('GET returns latest backend decision per recommendation/payment',
    str_contains($endpoint, 'function treasuryRecommendationLatestDecisions(')
    && str_contains($endpoint, "\$recommendation['latest_decision'] = \$decision")
    && str_contains($endpoint, "'decision_ledger' => 'treasury_recommendation_decisions'")
    && str_contains($endpoint, "'payment:' . \$decision['payment_id']"));
$a('GET exposes decision history endpoint',
    str_contains($endpoint, "if (\$action === 'decisions')")
    && str_contains($endpoint, 'function treasuryRecommendationDecisionHistory(')
    && str_contains($endpoint, "'rows' => treasuryRecommendationDecisionHistory(\$tid, \$limit)"));

echo "\nHandoff ledger\n";
$a('migration creates recommendation handoff ledger',
    str_contains($handoffMig, 'CREATE TABLE IF NOT EXISTS treasury_recommendation_handoffs')
    && str_contains($handoffMig, "handoff_action ENUM('submit','approve','reject','execute') NOT NULL")
    && str_contains($handoffMig, "result ENUM('success','failure') NOT NULL")
    && str_contains($handoffMig, 'payment_status_before VARCHAR(40) NULL')
    && str_contains($handoffMig, 'payment_status_after VARCHAR(40) NULL')
    && str_contains($handoffMig, 'workflow_response_json MEDIUMTEXT NULL')
    && str_contains($handoffMig, 'idx_trh_tenant_recommendation'));
$a('POST logs handoff attempts separately from payment workflow',
    str_contains($endpoint, "\$action === 'handoff_log'")
    && str_contains($endpoint, 'function treasuryRecommendationRecordHandoff(')
    && str_contains($endpoint, 'INSERT INTO treasury_recommendation_handoffs')
    && str_contains($endpoint, "'treasury.recommendation.handoff_logged'")
    && str_contains($endpoint, "'object_type' => 'treasury_recommendation_handoff'"));
$a('GET returns latest handoff per recommendation/payment',
    str_contains($endpoint, 'function treasuryRecommendationLatestHandoffs(')
    && str_contains($endpoint, "\$recommendation['latest_handoff'] = \$handoff")
    && str_contains($endpoint, "'handoff_ledger' => 'treasury_recommendation_handoffs'")
    && str_contains($endpoint, "'payment:' . \$handoff['payment_id']"));

echo "\nReserve policy and evidence\n";
foreach ([
    "'minimum_cash_reserve'",
    "'payroll_reserve'",
    "'tax_reserve'",
    "'ap_reserve'",
    "'operating_reserve'",
    "'materiality_threshold'",
] as $needle) {
    $a("endpoint supports override key {$needle}", str_contains($endpoint, $needle));
}
$a('reserve formula is explicit',
    str_contains($endpoint, 'required_reserves = minimum_cash_reserve + payroll_reserve + tax_reserve + ap_reserve + operating_reserve'));
$a('recommendations use shared liquidity projection evidence',
    str_contains($endpoint, 'liquidityProjectionEvidence(')
    && str_contains($endpoint, 'liquidityWalkProjection(')
    && str_contains($endpoint, 'variance_context'));
$a('recommendations load saved policy and stamp version evidence',
    str_contains($endpoint, 'treasuryPolicyGet($tid)')
    && str_contains($endpoint, "'policy_version' => (int) (\$storedPolicy['policy_version'] ?? 0)")
    && str_contains($endpoint, "'effective_date' => (string) (\$storedPolicy['effective_date'] ?? date('Y-m-d'))")
    && str_contains($endpoint, "'overrides_applied' => \$overrides"));
$a('payment timing actions are deterministic',
    str_contains($endpoint, "'pay_now'")
    && str_contains($endpoint, "'submit_for_approval'")
    && str_contains($endpoint, "'hold_for_review'")
    && str_contains($endpoint, "'split'")
    && str_contains($endpoint, "'defer_or_escalate'"));
$a('recommendation evidence carries payment, cash impact, reserve policy, projection, variance',
    str_contains($endpoint, "'payment' => [")
    && str_contains($endpoint, "'cash_impact' => [")
    && str_contains($endpoint, "'reserve_policy' => \$reservePolicy")
    && str_contains($endpoint, "'projection' => \$context['projection']")
    && str_contains($endpoint, "'variance_context' => \$context['variance_context']"));
$a('recommendations return summary and review queue',
    str_contains($endpoint, 'function treasuryRecommendationSummary(')
    && str_contains($endpoint, 'function treasuryRecommendationReviewQueue(')
    && str_contains($endpoint, 'function treasuryRecommendationNeedsReview(')
    && str_contains($endpoint, "'summary' => \$summary")
    && str_contains($endpoint, "'review_queue' => \$reviewQueue")
    && str_contains($endpoint, "'next_workflow_step' => \$row['approval_gate']['next_workflow_step'] ?? 'review'"));

echo "\nWorkflow gating and auditability\n";
$a('endpoint is advisory and preserves money movement workflow',
    str_contains($endpoint, 'never executes or approves money movement')
    && str_contains($endpoint, 'Payments still require submit, approval, and execution'));
$a('GET requires payment view permission',
    str_contains($endpoint, "rbac_legacy_require(\$user, 'treasury.payment.view')"));
$a('decision POST requires payment manage permission',
    str_contains($endpoint, "rbac_legacy_require(\$user, 'treasury.payment.manage')"));
$a('approval gate names workflow resource and permissions',
    str_contains($endpoint, "'workflow_resource' => (string) (\$reservePolicy['approval_resource'] ?? 'treasury.payment')")
    && str_contains($endpoint, "'approval_permission' => (string) (\$reservePolicy['approval_permission'] ?? 'treasury.approve_payment')")
    && str_contains($endpoint, "'execution_permission' => (string) (\$reservePolicy['execution_permission'] ?? 'treasury.execute_payment')")
    && str_contains($endpoint, "'money_movement_blocked_until_workflow_complete'"));
$a('accept/dismiss decisions write canonical audit',
    str_contains($endpoint, "treasury.recommendation.accepted")
    && str_contains($endpoint, "treasury.recommendation.dismissed")
    && str_contains($endpoint, 'platformAuditLogWrite(')
    && str_contains($endpoint, "'decision_id' => \$decision['id']")
    && str_contains($endpoint, "'evidence_hash' => \$decision['evidence_hash']")
    && str_contains($endpoint, "'object_type' => 'treasury_recommendation'")
    && str_contains($endpoint, "'source' => 'treasury_recommendations'"));
$a('manifest declares recommendation audit events',
    str_contains($manifest, "'treasury.recommendation.accepted'")
    && str_contains($manifest, "'treasury.recommendation.dismissed'")
    && str_contains($manifest, "'treasury.recommendation.handoff_logged'")
    && str_contains($manifest, "'treasury.policy.updated'"));

echo "\nUI workbench\n";
$a('Treasury module exposes Recommendations tab and route',
    str_contains($module, "TreasuryRecommendations")
    && str_contains($module, 'to="recommendations"')
    && str_contains($module, 'path="recommendations"'));
$a('UI reads recommendations endpoint',
    str_contains($page, "/api/treasury_recommendations.php?")
    && str_contains($page, "/api/treasury_policy.php"));
$a('UI renders reserve policy inputs',
    str_contains($page, 'data-testid="treasury-reserve-policy-inputs"')
    && str_contains($page, 'Minimum cash')
    && str_contains($page, 'Payroll reserve')
    && str_contains($page, 'Tax reserve')
    && str_contains($page, 'AP reserve')
    && str_contains($page, 'Operating reserve')
    && str_contains($page, 'Review cadence days')
    && str_contains($page, 'Effective date')
    && str_contains($page, 'data-testid="treasury-policy-save"')
    && str_contains($page, 'data-testid="treasury-policy-version"'));
$a('UI renders cash envelope, gates, evidence, and audit decisions',
    str_contains($page, 'data-testid="treasury-recommendations-envelope"')
    && str_contains($page, 'data-testid="treasury-recommendations-auditability"')
    && str_contains($page, 'data-testid={`treasury-recommendation-gate-${payment.id}`}')
    && str_contains($page, 'data-testid={`treasury-recommendation-evidence-${payment.id}`}')
    && str_contains($page, 'action=${action}')
    && str_contains($page, "recommendation_id: row.id")
    && str_contains($page, 'row.latest_decision')
    && str_contains($page, 'recommendations.reload()')
    && str_contains($page, 'Decision evidence hash'));
$a('UI renders summary, review queue, handoff, and history panels',
    str_contains($page, 'data-testid="treasury-recommendations-summary"')
    && str_contains($page, 'data-testid="treasury-recommendations-review-queue"')
    && str_contains($page, 'data-testid="treasury-recommendations-decision-history"')
    && str_contains($page, "/api/treasury_recommendations.php?action=decisions&limit=25")
    && str_contains($page, 'Workflow handoff')
    && str_contains($page, 'decisionHistory.reload()'));
$a('UI handoff buttons require accepted decisions and call payment workflow API',
    str_contains($page, 'const accepted = decisionLabel === \'accept\'')
    && str_contains($page, 'recommendationHandoffActions(row)')
    && str_contains($page, '/api/treasury_payments.php?action=${action}&id=${paymentId}')
    && str_contains($page, "/api/treasury_recommendations.php?action=handoff_log")
    && str_contains($page, "await logHandoff(row, action, 'success'")
    && str_contains($page, "await logHandoff(row, action, 'failure'")
    && str_contains($page, 'payment_status_before: beforeStatus')
    && str_contains($page, 'payment_status_after: afterStatus')
    && str_contains($page, 'Latest handoff')
    && str_contains($page, "status === 'draft' && action === 'submit_for_approval'")
    && str_contains($page, "status === 'pending_approval'")
    && str_contains($page, "['approved', 'scheduled'].includes(status) && action === 'pay_now'")
    && str_contains($page, 'data-testid={`treasury-recommendation-handoff-${action.action}-${payment.id}`}'));
$a('UI does not bypass payment workflow with direct status writes',
    !str_contains($page, 'UPDATE treasury_payments')
    && !str_contains($page, 'status="executed"')
    && !str_contains($page, 'status = "approved"')
    && !str_contains($page, "status: 'approved'"));
$a('Treasury overview renders recommendation queue summary',
    str_contains($overview, "/api/treasury_recommendations.php?forecast_days=30")
    && str_contains($overview, 'data-testid="treasury-overview-recommendation-summary"')
    && str_contains($overview, 'data-testid="treasury-overview-recommendations-link"')
    && str_contains($overview, 'treasury-recommendations-review-count')
    && str_contains($overview, 'treasury-recommendations-decided'));

echo "\nTreasury recommendations alignment smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
