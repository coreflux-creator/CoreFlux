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
$page = (string) file_get_contents("{$ROOT}/dashboard/src/pages/TreasuryRecommendations.jsx");
$module = (string) file_get_contents("{$ROOT}/modules/treasury/ui/TreasuryModule.jsx");
$manifest = (string) file_get_contents("{$ROOT}/modules/treasury/manifest.php");

echo "Files parse\n";
$a('endpoint parses', $lint('api/treasury_recommendations.php'));
$a('module alias points to endpoint', str_contains($alias, "/../../../api/treasury_recommendations.php"));

echo "\nReserve policy and evidence\n";
foreach ([
    "api_query('minimum_cash_reserve')",
    "api_query('payroll_reserve')",
    "api_query('tax_reserve')",
    "api_query('ap_reserve')",
    "api_query('operating_reserve')",
    "api_query('materiality_threshold')",
] as $needle) {
    $a("endpoint reads {$needle}", str_contains($endpoint, $needle));
}
$a('reserve formula is explicit',
    str_contains($endpoint, 'required_reserves = minimum_cash_reserve + payroll_reserve + tax_reserve + ap_reserve + operating_reserve'));
$a('recommendations use shared liquidity projection evidence',
    str_contains($endpoint, 'liquidityProjectionEvidence(')
    && str_contains($endpoint, 'liquidityWalkProjection(')
    && str_contains($endpoint, 'variance_context'));
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

echo "\nWorkflow gating and auditability\n";
$a('endpoint is advisory and preserves money movement workflow',
    str_contains($endpoint, 'never executes or approves money movement')
    && str_contains($endpoint, 'Payments still require submit, approval, and execution'));
$a('GET requires payment view permission',
    str_contains($endpoint, "rbac_legacy_require(\$user, 'treasury.payment.view')"));
$a('decision POST requires payment manage permission',
    str_contains($endpoint, "rbac_legacy_require(\$user, 'treasury.payment.manage')"));
$a('approval gate names workflow resource and permissions',
    str_contains($endpoint, "'workflow_resource' => 'treasury.payment'")
    && str_contains($endpoint, "'approval_permission' => 'treasury.approve_payment'")
    && str_contains($endpoint, "'execution_permission' => 'treasury.execute_payment'")
    && str_contains($endpoint, "'money_movement_blocked_until_workflow_complete'"));
$a('accept/dismiss decisions write canonical audit',
    str_contains($endpoint, "treasury.recommendation.accepted")
    && str_contains($endpoint, "treasury.recommendation.dismissed")
    && str_contains($endpoint, 'platformAuditLogWrite(')
    && str_contains($endpoint, "'object_type' => 'treasury_recommendation'")
    && str_contains($endpoint, "'source' => 'treasury_recommendations'"));
$a('manifest declares recommendation audit events',
    str_contains($manifest, "'treasury.recommendation.accepted'")
    && str_contains($manifest, "'treasury.recommendation.dismissed'"));

echo "\nUI workbench\n";
$a('Treasury module exposes Recommendations tab and route',
    str_contains($module, "TreasuryRecommendations")
    && str_contains($module, 'to="recommendations"')
    && str_contains($module, 'path="recommendations"'));
$a('UI reads recommendations endpoint',
    str_contains($page, "/api/treasury_recommendations.php?"));
$a('UI renders reserve policy inputs',
    str_contains($page, 'data-testid="treasury-reserve-policy-inputs"')
    && str_contains($page, 'Minimum cash')
    && str_contains($page, 'Payroll reserve')
    && str_contains($page, 'Tax reserve')
    && str_contains($page, 'AP reserve')
    && str_contains($page, 'Operating reserve'));
$a('UI renders cash envelope, gates, evidence, and audit decisions',
    str_contains($page, 'data-testid="treasury-recommendations-envelope"')
    && str_contains($page, 'data-testid="treasury-recommendations-auditability"')
    && str_contains($page, 'data-testid={`treasury-recommendation-gate-${payment.id}`}')
    && str_contains($page, 'data-testid={`treasury-recommendation-evidence-${payment.id}`}')
    && str_contains($page, 'action=${action}')
    && str_contains($page, "recommendation_id: row.id"));

echo "\nTreasury recommendations alignment smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
