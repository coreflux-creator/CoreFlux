<?php
/** Run the read-only business integrity audit for one tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../core/business_integrity.php';

$tenantId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php scripts/audit_business_integrity.php TENANT_ID\n");
    exit(2);
}

$report = businessIntegrityAudit($tenantId);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($report['ok'] ? 0 : 1);
