<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/lib/invariants.php';

$opts = getopt('', ['tenant:']);
$tenantId = (int) ($opts['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/check_source_links.php --tenant=ID\n");
    exit(2);
}
$pdo = getDB();
$check = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$check->execute(['id' => $tenantId]);
if ((int) $check->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to inspect a non-simulation tenant.\n");
    exit(3);
}
setRequestTenantId($tenantId);
$result = simInvariantPostedSourceLinks($pdo, $tenantId);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($result['ok'] ? 0 : 1);
