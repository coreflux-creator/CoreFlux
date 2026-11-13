<?php
/** Exact billing-link repair; no heuristic client lifecycle or identity cleanup. */
declare(strict_types=1);
require_once __DIR__.'/../../modules/placements/lib/ownership.php';
require_once __DIR__.'/../../modules/staffing/lib/client_source.php';

function jobdivaRepairPlacementClientLink(int $workspace, int $mappingId, ?int $actor = null): array
{
    $pdo = getDB();
    return companyRelationshipAtomic($pdo, static function () use ($pdo, $workspace, $mappingId, $actor): array {
        // Policies, source identity, native ownership, company aliases and the
        // final placement reference remain locked until this transaction ends.
        $scope = companyRelationshipScope($pdo, $workspace, 'placements');
        $owner = $scope['owner'];
        if (jobdivaPartyDirectoryScope($pdo, $owner, true)['owner'] !== $scope['company_owner']) {
            throw new RuntimeException('This shared placement cannot use a private company directory.', 409);
        }
        $q = $pdo->prepare("SELECT * FROM external_entity_mappings WHERE tenant_id=? AND id=? AND source_system='jobdiva' AND internal_entity_type='placement' FOR UPDATE");
        $q->execute([$workspace, $mappingId]);
        $mapping = $q->fetch(PDO::FETCH_ASSOC);
        if (!$mapping) throw new RuntimeException('The exact placement source link is no longer available.', 409);
        $id = (int) $mapping['internal_entity_id'];
        $q = $pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');
        $q->execute([$owner, $id]);
        $placement = $q->fetch(PDO::FETCH_ASSOC);
        if (!$placement) throw new RuntimeException('The mapped placement is unavailable in the current directory.', 409);
        $result = ['id'=>$id, 'status'=>'skipped'];
        if (in_array('client_id', placementOwnershipFields($placement['coreflux_overridden_fields'] ?? null), true)) {
            return $result + ['reason'=>'core_owned'];
        }
        $q = $pdo->prepare("SELECT id FROM external_entity_mappings WHERE source_system='jobdiva' AND internal_entity_type='placement' AND internal_entity_id=? AND id<>? FOR UPDATE");
        $q->execute([$id, $mappingId]);
        if ($q->fetchColumn()) throw new RuntimeException('Multiple source identities refer to this placement. Review its source links.', 409);
        if (($mapping['sync_status'] ?? '') !== 'ok') return $result + ['reason'=>'source_needs_review'];
        $payload = json_decode((string) $mapping['payload_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) throw new RuntimeException('Review the stored assignment evidence.', 409);
        $external = (string) $mapping['external_id'];
        if (jobdivaAssignmentRowId($payload) !== jobdivaAssignmentIdentityNormaliseId($external)
            || count(jobdivaAssignmentIdentityValues($payload, true)) !== 1) {
            throw new RuntimeException('The stored assignment does not match its exact source ID.', 409);
        }
        $stats = [];
        $payload = jobdivaPlacementPayloadWithMirrors($workspace, $payload, $stats, $external, true);
        $evidence = jobdivaProjectorAssignmentClientEvidence($payload);
        // Name-only or missing evidence never picks another CL for a company.
        if (empty($evidence['authoritative']) || $evidence['external_id'] === '') {
            return $result + ['reason'=>'exact_billing_identity_required'];
        }
        $companyId = jobdivaProjectorResolveBillingClientCompany($workspace, $payload, $actor);
        if (!$companyId) throw new RuntimeException('Resolve the exact assignment billing company before repairing its client link.', 409);
        $q = $pdo->prepare("SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='staffing_client' AND external_id=? FOR UPDATE");
        $q->execute([$workspace, $evidence['external_id']]);
        $clientId = (int) $q->fetchColumn();
        if ($clientId) {
            $q = $pdo->prepare('SELECT company_id FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');
            $q->execute([$owner, $clientId]);
            if ((int)$q->fetchColumn() !== $companyId) throw new RuntimeException('The retained client source link disagrees with the billing company. Review its identity.', 409);
        } else {
            $client = staffingClientEnsureForCompany($workspace, $companyId, $evidence['name'], [
                'created_by_user_id'=>$actor,
                'source_system'=>'jobdiva',
                'source_external_id'=>$evidence['external_id'],
            ]);
            $clientId = (int) $client['client_id'];
        }
        if ($clientId === (int) $placement['client_id']) return $result + ['reason'=>'already_linked'];
        $pdo->prepare('UPDATE placements SET client_id=?,updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$clientId, $owner, $id]);
        companySourceAudit($pdo, $workspace, $owner, 'placements', $id, 'placement.client_link_repaired', ['client_id'], $actor, [
            'source_system'=>'jobdiva', 'source_mapping_id'=>$mappingId, 'source_external_id'=>$external,
            'billing_source_external_id'=>$evidence['external_id'],
            'before'=>['client_id'=>$placement['client_id']], 'after'=>['client_id'=>$clientId],
        ]);
        return ['id'=>$id, 'status'=>'repaired', 'client_id'=>$clientId];
    });
}

function jobdivaMappingRepairStaffingClientLinks(int $tenantId, ?int $userId = null, int $limit = 500): array
{
    $summary = ['checked'=>0, 'repaired'=>0, 'skipped'=>0, 'failed'=>0, 'reasons'=>[], 'errors'=>[]];
    $limit = max(1, min(5000, $limit));
    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');
    // Candidate IDs only. Never carry an unlocked ownership or source snapshot
    // into a write; each candidate is re-read in the helper's transaction.
    $q = $pdo->prepare("SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' ORDER BY id LIMIT {$limit}");
    $q->execute([$tenantId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mappingId) {
        $summary['checked']++;
        try {
            $result = jobdivaRepairPlacementClientLink($tenantId, (int) $mappingId, $userId);
            $summary[$result['status']]++;
            if (isset($result['reason'])) $summary['reasons'][$result['reason']] = ($summary['reasons'][$result['reason']] ?? 0) + 1;
        } catch (Throwable $e) {
            $summary['failed']++;
            if (count($summary['errors']) < 10) $summary['errors'][] = 'source link '.$mappingId.': '.$e->getMessage();
        }
    }
    return $summary;
}
