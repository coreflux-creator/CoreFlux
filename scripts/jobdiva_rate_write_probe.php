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

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "No database connection.\n");
    exit(70);
}

if (($argv[1] ?? '') === '--roster') {
    $tenantId = (int) ($argv[2] ?? 2);
    $stmt = $pdo->prepare(
        "SELECT p.id, p.external_id, p.title, p.status, p.start_date, p.end_date,
                pe.first_name, pe.last_name,
                m.external_id AS mapped_start_id, m.sync_status AS mapping_status,
                m.last_error AS mapping_error, m.payload_snapshot,
                COUNT(pr.id) AS rate_rows
           FROM placements p
           LEFT JOIN people pe
             ON pe.tenant_id = p.tenant_id AND pe.id = p.person_id
           LEFT JOIN external_entity_mappings m
             ON m.tenant_id = p.tenant_id
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND m.internal_entity_id = p.id
           LEFT JOIN placement_rates pr
             ON pr.tenant_id = p.tenant_id AND pr.placement_id = p.id
          WHERE p.tenant_id = :tenant_id
            AND p.deleted_at IS NULL
            AND p.status IN ('active', 'pending_start', 'on_hold')
          GROUP BY p.id, p.external_id, p.title, p.status, p.start_date, p.end_date,
                   pe.first_name, pe.last_name, m.external_id, m.sync_status,
                   m.last_error, m.payload_snapshot
          ORDER BY p.start_date DESC, p.id DESC"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        $contract = is_array($payload['_jd_contract'] ?? null) ? $payload['_jd_contract'] : [];
        unset($row['payload_snapshot']);
        $row['contract_status'] = $contract['placement_status'] ?? null;
        $row['contract_start_id'] = $contract['start_id'] ?? null;
        $row['contract_end_date'] = $contract['end_date'] ?? null;
        $row['contract_billing_approved'] = $contract['approved'] ?? null;
        $row['contract_billing_closed'] = $contract['closed'] ?? null;
        $row['contract_salary_approved'] = $contract['salary_approved'] ?? null;
        $row['contract_salary_closed'] = $contract['salary_closed'] ?? null;
        $row['contract_salary_status'] = $contract['salary_status'] ?? null;
        $row['contract_bill_rate'] = $contract['bill_rate'] ?? null;
        $row['contract_pay_rate'] = $contract['pay_rate'] ?? null;
        if (($contract['salary_approved'] ?? null) !== true) {
            $detailRows = is_array($payload['_jd_assignment_detail'] ?? null)
                ? $payload['_jd_assignment_detail']
                : [];
            $matchingRows = jobdivaAssignmentContractRowsForStart(
                $detailRows,
                $payload,
                (string) ($row['mapped_start_id'] ?? '')
            );
            foreach (['BILLING', 'SALARY'] as $section) {
                $facts = [];
                $entries = jobdivaAssignmentContractEntries(
                    jobdivaAssignmentContractSectionRows($matchingRows, $section)
                );
                foreach ($entries as $entry) {
                    $key = (string) ($entry['path'] ?? $entry['key'] ?? '');
                    $value = $entry['value'] ?? null;
                    if ($key === '' || (!is_scalar($value) && $value !== null)) continue;
                    $normalised = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $key));
                    if (!preg_match('/approved|closed|actual|status|start|end|effective|active|terminate|pay|salary|bill/i', $normalised)) {
                        continue;
                    }
                    $facts[$key] = $value;
                }
                $row[strtolower($section) . '_lifecycle'] = $facts;
            }
        }
        $rows[] = $row;
    }
    echo json_encode([
        'tenant_id' => $tenantId,
        'count' => count($rows),
        'mapping_status_counts' => array_count_values(array_map(
            static fn(array $row): string => (string) ($row['mapping_status'] ?? 'unmapped'),
            $rows
        )),
        'rows' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$placementId = (int) ($argv[1] ?? 0);
$startId = trim((string) ($argv[2] ?? ''));
$billRate = (float) ($argv[3] ?? 0);
$payRate = (float) ($argv[4] ?? 0);
if ($placementId <= 0 || $startId === '' || $billRate <= 0 || $payRate <= 0) {
    fwrite(STDERR, "Invalid probe arguments.\n");
    exit(64);
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
