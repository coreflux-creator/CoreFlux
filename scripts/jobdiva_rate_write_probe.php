<?php
/**
 * Diagnose a JobDiva placement-rate write without changing production data.
 *
 * Usage: php scripts/jobdiva_rate_write_probe.php <placement-id> <start-id> <bill-rate> <pay-rate>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

require_once __DIR__ . '/../core/jobdiva/sync.php';

$placementId = (int) ($argv[1] ?? 0);
$startId = trim((string) ($argv[2] ?? ''));
$billRate = (float) ($argv[3] ?? 0);
$payRate = (float) ($argv[4] ?? 0);
if ($placementId <= 0 || $startId === '' || $billRate <= 0 || $payRate <= 0) {
    fwrite(STDERR, "Invalid probe arguments.\n");
    exit(64);
}

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "No database connection.\n");
    exit(70);
}

$placementStmt = $pdo->prepare(
    'SELECT tenant_id, external_id, person_id, end_client_company_id,
            end_client_name, start_date, end_date, status, engagement_type
       FROM placements
      WHERE id = :id
      LIMIT 1'
);
$placementStmt->execute(['id' => $placementId]);
$placement = $placementStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if ($placement === []) {
    fwrite(STDERR, "Placement not found.\n");
    exit(66);
}

$tenantId = (int) ($placement['tenant_id'] ?? 0);
$mappingStmt = $pdo->prepare(
    "SELECT id, sync_status, updated_at, payload_snapshot
       FROM external_entity_mappings
      WHERE tenant_id = :tenant_id
        AND source_system = 'jobdiva'
        AND internal_entity_type = 'placement'
        AND external_id = :external_id
      ORDER BY id DESC
      LIMIT 1"
);
$mappingStmt->execute(['tenant_id' => $tenantId, 'external_id' => $startId]);
$mapping = $mappingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$storedPayload = json_decode((string) ($mapping['payload_snapshot'] ?? ''), true);
if (!is_array($storedPayload)) $storedPayload = [];
$storedContract = is_array($storedPayload['_jd_contract'] ?? null)
    ? $storedPayload['_jd_contract']
    : [];

$columns = $pdo->query('SHOW COLUMNS FROM placement_rates')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$readRates = static function () use ($pdo, $tenantId, $placementId): array {
    $stmt = $pdo->prepare(
        'SELECT id, effective_from, effective_to, bill_rate, pay_rate, approved_at,
                created_by_user_id
           FROM placement_rates
          WHERE tenant_id = :tenant_id AND placement_id = :placement_id
          ORDER BY id ASC'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'placement_id' => $placementId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$result = [
    'placement' => [
        'id' => $placementId,
        'external_id' => (string) ($placement['external_id'] ?? ''),
        'start_date' => (string) ($placement['start_date'] ?? ''),
        'engagement_type' => (string) ($placement['engagement_type'] ?? ''),
    ],
    'mapping' => [
        'id' => (int) ($mapping['id'] ?? 0),
        'sync_status' => (string) ($mapping['sync_status'] ?? ''),
        'updated_at' => (string) ($mapping['updated_at'] ?? ''),
        'has_contract' => $storedContract !== [],
        'contract_rates' => [
            'bill_rate' => $storedContract['bill_rate'] ?? null,
            'bill_rate_in_vms' => $storedContract['bill_rate_in_vms'] ?? null,
            'net_bill_rate' => $storedContract['net_bill_rate'] ?? null,
            'pay_rate' => $storedContract['pay_rate'] ?? null,
            'pay_rate_to_vendor' => $storedContract['pay_rate_to_vendor'] ?? null,
        ],
    ],
    'rate_columns' => $columns,
    'before' => $readRates(),
];

$probePayload = $storedPayload;
$probePayload['_jd_contract'] = array_replace($storedContract, [
    'contract_version' => 1,
    'source' => 'EmployeeAssignmentRecordsDetail',
    'start_id' => $startId,
    'start_date' => (string) ($placement['start_date'] ?? ''),
    'engagement_type' => (string) ($placement['engagement_type'] ?? 'w2'),
    'bill_rate' => $billRate,
    'pay_rate' => $payRate,
]);
$probePayload['__cf_force_source_contract'] = true;

try {
    $pdo->beginTransaction();
    $result['writer_returned'] = jobdivaSyncUpsertPlacementRates(
        $tenantId,
        $placementId,
        (string) ($placement['start_date'] ?? ''),
        $probePayload
    );
    $result['during_transaction'] = $readRates();
} catch (Throwable $e) {
    $result['writer_error'] = $e->getMessage();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$result['after_rollback'] = $readRates();

$projectorPayload = jobdivaAssignmentMarkVerified(
    $probePayload,
    $startId,
    'EmployeeAssignmentRecordsDetail:rate_probe'
);
$projectorPayload['__cf_jobdiva_expected_start_id'] = $startId;
try {
    $pdo->beginTransaction();
    $result['projector'] = jobdivaProjectorProjectPlacement(
        $tenantId,
        $projectorPayload,
        null,
        [
            'payload_is_enriched' => true,
            'external_id' => $startId,
            'existing_placement_id' => $placementId,
            'person_id' => (int) ($placement['person_id'] ?? 0),
            'end_client_company_id' => (int) ($placement['end_client_company_id'] ?? 0),
            'force_source_contract' => true,
        ]
    );
    $result['after_projector'] = jobdivaPlacementProjectionAuditSnapshot($tenantId, $placementId);
} catch (Throwable $e) {
    $result['projector_error'] = $e->getMessage();
    $result['after_projector_error'] = jobdivaPlacementProjectionAuditSnapshot($tenantId, $placementId);
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
