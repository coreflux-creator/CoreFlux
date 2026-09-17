<?php
/** Seed the default accounting accounts and posting rules for active tenants. */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../accounting/system_accounts.php';
require_once __DIR__ . '/../posting_engine/seed_defaults.php';

$pdo = getDB();
$tenantIds = $pdo->query(
    'SELECT id FROM tenants WHERE is_active = 1 ORDER BY id'
)->fetchAll(\PDO::FETCH_COLUMN) ?: [];

$seeded = 0;
foreach ($tenantIds as $tenantIdValue) {
    $tenantId = (int) $tenantIdValue;
    accountingSeedSystemAccounts($tenantId);
    $result = postingRulesSeedDefaults($tenantId);
    $seeded++;
    echo sprintf(
        "Seeded posting rules for tenant %d (%d rules, %d templates).\n",
        $tenantId,
        (int) ($result['rules_inserted'] ?? 0),
        (int) ($result['templates_inserted'] ?? 0)
    );
}

echo "Posting-rule seed complete for {$seeded} active tenant(s).\n";
