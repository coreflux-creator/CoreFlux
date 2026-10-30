<?php
/** The starter AP policy resolves current administrators without routing to the creator. */
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
$pdo->exec('INSERT INTO users VALUES (1,1), (2,1), (3,0), (4,1), (5,1), (6,1)');
$pdo->exec("INSERT INTO tenant_memberships VALUES
    (1,1,'tenant_admin','active'), (1,2,'tenant_admin','active'),
    (1,3,'tenant_admin','active'), (2,4,'tenant_admin','active'),
    (1,5,'employee','active'), (1,6,'tenant_admin','suspended')");
$setChain = $pdo->prepare('UPDATE ap_approval_policies SET chain_json = :chain WHERE id = 1');
$starter = json_encode([['step' => 1, 'include_active_tenant_admins' => true,
    'quorum' => 1, 'label' => 'Finance approval']], JSON_THROW_ON_ERROR);
$insert = $pdo->prepare(
    'INSERT INTO ap_approval_policies (id, tenant_id, active, priority, chain_json, name)
     VALUES (1, 1, 1, 100, :chain, :name)'
);
$insert->execute(['chain' => $starter, 'name' => 'Initial finance approval']);

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};

$bill = ['id' => 10, 'total' => 25, 'created_by_user_id' => 1];
$route = apEvaluateApprovalPolicy(1, $bill);
$assert($route['matched'] && $route['chain'][0]['approver_user_ids'] === [2],
    'Another active administrator must review an admin-created bill');
$assert(!isset($route['routing_error']), 'A second reviewer makes the route ready');

$bill['created_by_user_id'] = 2;
$route = apEvaluateApprovalPolicy(1, $bill);
$assert($route['chain'][0]['approver_user_ids'] === [1],
    'The starter route follows the active reviewer, not a fixed first admin');

$bill['created_by_user_id'] = null;
$route = apEvaluateApprovalPolicy(1, $bill);
$assert($route['chain'][0]['approver_user_ids'] === [1, 2],
    'Machine-prepared bills can route to either active administrator');

unset($bill['created_by_user_id']);
try {
    apRouteBillForApproval(1, $bill, 1);
    throw new RuntimeException('Bill with unknown creator entered approval workflow');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'creator is required'),
        'Unknown bill creator must block routing before creating records');
}

$pdo->exec('UPDATE users SET is_active = 0 WHERE id = 2');
$bill['created_by_user_id'] = 1;
$route = apEvaluateApprovalPolicy(1, $bill);
$assert($route['matched'] && $route['chain'] === [] && str_contains(
    (string) ($route['routing_error'] ?? ''), 'Add another active tenant administrator'),
    'A sole creator must receive a setup message instead of a dead-end route');
try {
    apRouteBillForApproval(1, $bill, 1);
    throw new RuntimeException('Unroutable bill unexpectedly entered approval workflow');
} catch (RuntimeException $error) {
    $assert(str_contains($error->getMessage(), 'Add another active tenant administrator'),
        'Routing must stop before creating approval records');
}

$pdo->exec('UPDATE users SET is_active = 1 WHERE id = 2');
$explicit = json_encode([['step' => 1, 'approver_user_ids' => [1, 2, 3, 4, 6],
    'quorum' => 1, 'label' => 'Finance approval']], JSON_THROW_ON_ERROR);
$setChain->execute(['chain' => $explicit]);
$route = apEvaluateApprovalPolicy(1, $bill);
$assert($route['chain'][0]['approver_user_ids'] === [2],
    'Explicit policies must exclude the creator, inactive users and other-tenant members');

$foreign = json_encode([['step' => 1, 'approver_user_ids' => [4],
    'quorum' => 1, 'label' => 'Foreign reviewer']], JSON_THROW_ON_ERROR);
$setChain->execute(['chain' => $foreign]);
$route = apEvaluateApprovalPolicy(1, $bill);
$assert($route['chain'] === [] && str_contains((string) ($route['routing_error'] ?? ''), 'active tenant approvers'),
    'A reviewer from another tenant cannot receive this bill');

$quorum = json_encode([['step' => 1, 'approver_user_ids' => [1, 2],
    'quorum' => 2, 'label' => 'Two reviewers']], JSON_THROW_ON_ERROR);
$setChain->execute(['chain' => $quorum]);
$route = apEvaluateApprovalPolicy(1, $bill);
$assert(str_contains((string) ($route['routing_error'] ?? ''), 'too few active tenant approvers'),
    'A quorum above the eligible reviewer count must be refused');

echo "AP starter approvers: {$checks} checks passed.\n";
