<?php
/** Pure//Pay connection liveness probe. Suggested schedule: every 30 min. */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/purepay_service.php';

$pdo = getDB();
try {
    $rows = $pdo->query("SELECT tenant_id FROM purepay_connections WHERE status IN ('active','error') ORDER BY tenant_id")
        ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    fwrite(STDERR, "purepay_health_probe: migration 130 not applied; skipping ({$e->getMessage()})\n");
    exit(0);
}
$ok=0; $failed=0;
foreach ($rows as $row) {
    $tenantId = (int) $row['tenant_id'];
    try {
        purepayProbeConnection($tenantId);
        $ok++;
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "tenant {$tenantId}: {$e->getMessage()}\n");
    }
}
fwrite(STDOUT, "purepay_health_probe done: ok={$ok} failed={$failed}\n");
exit($failed > 0 ? 1 : 0);
