<?php
/** AP approval tiers must use the total on a real ap_bills row. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/ap/lib/approval_router.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE ap_approval_policies (
    id INTEGER PRIMARY KEY, tenant_id INTEGER, active INTEGER, priority INTEGER,
    entity_id INTEGER, vendor_type TEXT, min_amount REAL, max_amount REAL,
    min_risk_level TEXT, gl_account_code TEXT, chain_json TEXT, name TEXT
)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER)');
$pdo->exec('CREATE TABLE tenant_memberships (
    tenant_id INTEGER, user_id INTEGER, persona_type TEXT, status TEXT
)');
$pdo->exec('INSERT INTO users VALUES (5,1)');
$pdo->exec("INSERT INTO tenant_memberships VALUES (1,5,'tenant_admin','active')");
$chain = json_encode([['step' => 1, 'approver_user_ids' => [5], 'quorum' => 1]], JSON_THROW_ON_ERROR);
$insert = $pdo->prepare(
    'INSERT INTO ap_approval_policies
        (id, tenant_id, active, priority, entity_id, min_amount, max_amount, chain_json, name)
     VALUES (1, 1, 1, 100, 1, 20, 30, :chain, :name)'
);
$insert->execute(['chain' => $chain, 'name' => 'Ordinary bill tier']);

$bill = ['id' => 7, 'entity_id' => 1, 'total' => 25.00];
$matched = apEvaluateApprovalPolicy(1, $bill);
if (!$matched['matched'] || $matched['policy_id'] !== 1) {
    throw new RuntimeException('A $25 bill row did not match its $20-$30 approval tier.');
}
$bill['total'] = 15.00;
if (apEvaluateApprovalPolicy(1, $bill)['matched']) {
    throw new RuntimeException('A $15 bill incorrectly matched the $20-$30 approval tier.');
}
$bill['total'] = 40.00;
if (apEvaluateApprovalPolicy(1, $bill)['matched']) {
    throw new RuntimeException('A $40 bill incorrectly matched the $20-$30 approval tier.');
}

echo "AP approval amount: 3 checks passed.\n";
