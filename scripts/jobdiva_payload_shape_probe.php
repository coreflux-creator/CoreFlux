<?php
/** Read-only size and top-level shape diagnostics for stored JobDiva mirrors. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

require_once __DIR__ . '/../core/jobdiva/sync.php';

$tenantId = max(1, (int) ($argv[1] ?? 2));
$limit = max(1, min(25, (int) ($argv[2] ?? 15)));
$pdo = getDB();
$stmt = $pdo->prepare(
    "SELECT internal_entity_type, external_id,
            OCTET_LENGTH(payload_snapshot) AS payload_bytes,
            JSON_KEYS(payload_snapshot) AS top_level_keys
       FROM external_entity_mappings
      WHERE tenant_id = :tenant_id
        AND source_system = 'jobdiva'
        AND internal_entity_type IN (
            'jobdiva_assignment', 'placement', 'jobdiva_job',
            'jobdiva_candidate', 'jobdiva_contact', 'company'
        )
        AND sync_status = 'ok'
        AND payload_snapshot IS NOT NULL
      ORDER BY OCTET_LENGTH(payload_snapshot) DESC, id DESC
      LIMIT {$limit}"
);
$stmt->execute(['tenant_id' => $tenantId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as &$row) {
    $keys = json_decode((string) ($row['top_level_keys'] ?? ''), true);
    $row['top_level_keys'] = is_array($keys) ? $keys : [];
    $row['payload_bytes'] = (int) ($row['payload_bytes'] ?? 0);
}
unset($row);
echo json_encode([
    'tenant_id' => $tenantId,
    'largest_mirrors' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
