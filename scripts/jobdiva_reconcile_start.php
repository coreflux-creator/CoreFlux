<?php
/**
 * Project one staged JobDiva Start through the canonical assignment contract.
 *
 * Usage: php scripts/jobdiva_reconcile_start.php <tenant-id> <start-id> <candidate-id>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(64);
}

require_once __DIR__ . '/../core/jobdiva/sync.php';

$tenantId = (int) ($argv[1] ?? 0);
$startId = jobdivaAssignmentIdentityNormaliseId((string) ($argv[2] ?? ''));
$candidateId = jobdivaAssignmentIdentityNormaliseId((string) ($argv[3] ?? ''));
if ($tenantId <= 0 || $startId === '' || $candidateId === '') {
    fwrite(STDERR, "A tenant ID, Start ID, and candidate ID are required.\n");
    exit(64);
}

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "No database connection.\n");
    exit(70);
}

$mappingStmt = $pdo->prepare(
    "SELECT id, payload_snapshot
       FROM external_entity_mappings
      WHERE tenant_id = :tenant_id
        AND source_system = 'jobdiva'
        AND internal_entity_type = 'jobdiva_assignment_review'
        AND external_id = :start_id
        AND sync_status = 'ok'
      LIMIT 1"
);
$mappingStmt->execute(['tenant_id' => $tenantId, 'start_id' => $startId]);
$mapping = $mappingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
if ($mapping === []) {
    fwrite(STDERR, "Start {$startId} is not staged for exact assignment review.\n");
    exit(66);
}

$payload = json_decode((string) ($mapping['payload_snapshot'] ?? ''), true);
$payloadCandidateId = is_array($payload)
    ? jobdivaAssignmentIdentityNormaliseId((string) jobdivaAssignmentIdentityPluck($payload, [
        'candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID',
        'employeeId', 'employee_id',
    ]))
    : '';
if ($payloadCandidateId !== $candidateId) {
    fwrite(STDERR, "Start {$startId} is not staged for candidate {$candidateId}.\n");
    exit(65);
}

$mappingId = (int) ($mapping['id'] ?? 0);
$result = jobdivaSyncReviewAssignmentContractsBatch(
    $tenantId,
    null,
    max(0, $mappingId - 1),
    1
);
if ((int) ($result['processed'] ?? 0) !== 1
    || (int) ($result['projected'] ?? 0) !== 1
    || (int) ($result['failed'] ?? 0) !== 0) {
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

$placement = mappingFindInternal($tenantId, 'jobdiva', 'placement', $startId);
$placementId = (int) ($placement['internal_entity_id'] ?? 0);
$statusStmt = $pdo->prepare(
    'SELECT status, deleted_at
       FROM placements
      WHERE tenant_id = :tenant_id AND id = :placement_id
      LIMIT 1'
);
$statusStmt->execute(['tenant_id' => $tenantId, 'placement_id' => $placementId]);
$state = $statusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$status = (string) ($state['status'] ?? '');
$deletedAt = trim((string) ($state['deleted_at'] ?? ''));
if ($placementId <= 0
    || !in_array($status, ['active', 'pending_start', 'on_hold'], true)
    || ($deletedAt !== '' && $deletedAt !== '0000-00-00 00:00:00')) {
    fwrite(STDERR, "Start {$startId} did not become a live CoreFlux placement.\n");
    exit(1);
}

echo json_encode([
    'ok' => true,
    'tenant_id' => $tenantId,
    'start_id' => $startId,
    'candidate_id' => $candidateId,
    'placement_id' => $placementId,
    'status' => $status,
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
