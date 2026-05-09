<?php
/**
 * AI Agent Roadmap Phase A.5 smoke — Treasury / CFO splits.
 *
 * Existing CFO and Treasury Analyst agents are kept for backwards
 * compatibility. Phase A.5 ADDS two specialised voices:
 *
 *   - cfo_variance       — period-over-period variance commentary
 *                          (paired with the new bucket-diff renderer).
 *   - treasury_payments  — pending-payment queue health.
 *
 * Asserts:
 *   - Both new agents are present in AI_AGENTS with domain + modules tags.
 *   - cfo_variance domain = ['strategy'], modules include accounting +
 *     treasury (it's a synthesis voice).
 *   - treasury_payments domain = ['treasury'], modules = ['treasury'].
 *   - Each new agent has a unique feature_key.
 *   - context builders exist with SHOW TABLES guards + qualitative buckets.
 *   - cfo_variance context layers on top of bookkeeper + treasury +
 *     reconciliation (so the diff renderer has a richer surface than the
 *     plain CFO context, which only stitches books + treasury).
 *   - treasury_payments context reads treasury_payments status counts +
 *     disputed AP bill count, all bucketed via aiAgentBucketCount.
 *   - AIAgents.jsx icon map covers the 2 new agents.
 *   - Existing legacy keys (cfo, treasury_analyst) are STILL present
 *     (backwards-compat — Phase A.5 is additive, not destructive).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Registry — Phase A.5 splits (additive)\n";
$lib = (string) file_get_contents("{$ROOT}/core/ai_agents.php");

foreach (['cfo','treasury_analyst'] as $legacy) {
    $assert("legacy '{$legacy}' agent retained (additive split, not destructive)",
        preg_match("/'{$legacy}'\\s*=>\\s*\\[/", $lib) === 1);
}

foreach (['cfo_variance','treasury_payments'] as $key) {
    $assert("new agent '{$key}' present",
        preg_match("/'{$key}'\\s*=>\\s*\\[/", $lib) === 1);
    $assert("'{$key}' has unique feature_key 'agent.{$key}.review'",
        strpos($lib, "'agent.{$key}.review'") !== false);
}

$assert("'cfo_variance' domain = ['strategy']",
    preg_match("/'cfo_variance'.*?'domain'\\s*=>\\s*\\['strategy'\\]/s", $lib) === 1);
$assert("'cfo_variance' modules cover accounting + treasury",
    preg_match("/'cfo_variance'.*?'modules'\\s*=>\\s*\\['accounting',\\s*'treasury'\\]/s", $lib) === 1);
$assert("'treasury_payments' domain = ['treasury']",
    preg_match("/'treasury_payments'.*?'domain'\\s*=>\\s*\\['treasury'\\]/s", $lib) === 1);
$assert("'treasury_payments' modules = ['treasury']",
    preg_match("/'treasury_payments'.*?'modules'\\s*=>\\s*\\['treasury'\\]/s", $lib) === 1);
$assert("'cfo_variance' kind = 'summary' (variance is a delta digest)",
    preg_match("/'cfo_variance'.*?'kind'\\s*=>\\s*'summary'/s", $lib) === 1);
$assert("'treasury_payments' kind = 'summary'",
    preg_match("/'treasury_payments'.*?'kind'\\s*=>\\s*'summary'/s", $lib) === 1);

echo "\nContext builders — 2 new functions\n";
foreach (['CFOVariance','TreasuryPayments'] as $fn) {
    $assert("aiAgentContext{$fn} declared",
        strpos($lib, "function aiAgentContext{$fn}(int \$tenantId)") !== false);
}
$assert("aiAgentContextCFOVariance layers reconciliation on top of CFO context",
    preg_match("/aiAgentContextCFOVariance.*?aiAgentContextBookkeeper.*?aiAgentContextTreasury.*?aiAgentContextReconciliation/s", $lib) === 1);
$assert('aiAgentContextTreasuryPayments guards on SHOW TABLES treasury_payments',
    preg_match("/aiAgentContextTreasuryPayments.*?SHOW TABLES LIKE 'treasury_payments'/s", $lib) === 1);
$assert('aiAgentContextTreasuryPayments guards on SHOW TABLES ap_bills',
    preg_match("/aiAgentContextTreasuryPayments.*?SHOW TABLES LIKE 'ap_bills'/s", $lib) === 1);
$assert('treasury_payments context reads all four queue statuses',
    preg_match("/aiAgentContextTreasuryPayments.*?'draft','pending_approval','approved','scheduled'/s", $lib) === 1);
$assert('treasury_payments context bucketizes counts (no raw numbers)',
    preg_match("/aiAgentContextTreasuryPayments.*?aiAgentBucketCount/s", $lib) === 1);
$assert('disputed bill count bucketized',
    preg_match("/aiAgentContextTreasuryPayments.*?disputed_bill_count_bucket.*?aiAgentBucketCount/s", $lib) === 1);

echo "\nUI — AIAgents.jsx icon map\n";
$pg = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AIAgents.jsx");
$assert('icon map covers cfo_variance',           strpos($pg, 'cfo_variance:') !== false);
$assert('icon map covers treasury_payments',      strpos($pg, 'treasury_payments:') !== false);
$assert('TrendingUp icon imported',               strpos($pg, 'TrendingUp') !== false);
$assert('Send icon imported',                     strpos($pg, 'Send') !== false);
$assert('digest copy reflects 10 agents (4 base + 4 tax + 2 splits)',
    strpos($pg, 'all ten agents') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
