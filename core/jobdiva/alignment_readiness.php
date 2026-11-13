<?php
/** Readiness uses each current directory and never converts failed reads to zero. */
declare(strict_types=1);
require_once __DIR__.'/../../modules/people/lib/company_relationships.php';
require_once __DIR__.'/stored_review.php';

function jobdivaAlignmentScope(PDO $pdo, int $workspace): array
{
    $scope = ['workspace' => $workspace];
    foreach (['placements', 'people', 'staffing'] as $module) {
        $scope[$module] = companyRelationshipScope($pdo, $workspace, $module, false)['owner'];
    }
    $scope['companies'] = jobdivaPartyDirectoryScope($pdo, $workspace)['owner'];
    // Imported time and provider identities stay in their original workspace.
    $scope['time'] = $workspace;
    return $scope;
}

function jobdivaAlignmentReadiness(PDO $pdo, int $workspace): array
{
    $scope = jobdivaAlignmentScope($pdo, $workspace);
    $owner = (int) $scope['placements'];
    $people = (int) $scope['people'];
    $staffing = (int) $scope['staffing'];
    $companies = (int) $scope['companies'];
    $base = "FROM external_entity_mappings m
        JOIN placements p ON p.id=m.internal_entity_id AND p.tenant_id={$owner}
        WHERE m.tenant_id=:t AND m.source_system='jobdiva' AND m.internal_entity_type='placement'
        AND (p.deleted_at IS NULL OR p.deleted_at='0000-00-00 00:00:00')";
    $checks = [
        'missing_person' => "NOT EXISTS (SELECT 1 FROM people pe WHERE pe.id=p.person_id AND pe.tenant_id={$people} AND pe.deleted_at IS NULL)",
        'missing_staffing_job' => "NOT EXISTS (SELECT 1 FROM staffing_jobs j WHERE j.id=p.staffing_job_id AND j.tenant_id={$staffing})",
        'missing_end_client_company' => "NOT EXISTS (SELECT 1 FROM companies c WHERE c.id=p.end_client_company_id AND c.tenant_id={$companies} AND c.deleted_at IS NULL)",
        'missing_staffing_client' => "NOT EXISTS (SELECT 1 FROM staffing_clients sc WHERE sc.id=p.client_id AND sc.tenant_id={$staffing})",
        'client_company_mismatch' => "EXISTS (SELECT 1 FROM staffing_clients sc WHERE sc.id=p.client_id AND sc.tenant_id={$staffing}
            AND NOT EXISTS (SELECT 1 FROM companies c WHERE c.id=sc.company_id AND c.tenant_id={$companies} AND c.deleted_at IS NULL))",
        'missing_rate_row' => 'NOT EXISTS (SELECT 1 FROM placement_rates pr WHERE pr.placement_id=p.id AND pr.tenant_id=p.tenant_id)',
        'active_missing_approved_rate' => "p.status='active' AND NOT EXISTS (SELECT 1 FROM placement_rates pr WHERE pr.placement_id=p.id AND pr.tenant_id=p.tenant_id
            AND pr.approved_at IS NOT NULL AND pr.effective_from<=p.start_date AND (pr.effective_to IS NULL OR pr.effective_to>=p.start_date))",
    ];
    $counts = [];
    foreach ($checks as $key => $predicate) {
        $q = $pdo->prepare("SELECT COUNT(DISTINCT p.id) {$base} AND ({$predicate})");
        $q->execute(['t' => $workspace]);
        $counts[$key] = (int) $q->fetchColumn();
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM external_entity_mappings m
        WHERE m.tenant_id=? AND m.source_system='jobdiva' AND m.internal_entity_type='placement'
        AND NOT EXISTS (SELECT 1 FROM placements p WHERE p.id=m.internal_entity_id AND p.tenant_id=?)");
    $q->execute([$workspace, $owner]);
    $counts['missing_placement'] = (int) $q->fetchColumn();
    return $counts;
}

/** Report only: absence of a live assignment invites a decision, never deactivation. */
function jobdivaAlignmentPeople(PDO $pdo, int $workspace, array $scope): array
{
    $q = $pdo->prepare("SELECT DISTINCT pe.id,pe.first_name,pe.last_name,pe.status
        FROM external_entity_mappings m JOIN people pe ON pe.id=m.internal_entity_id AND pe.tenant_id=?
        WHERE m.tenant_id=? AND m.source_system='jobdiva' AND m.internal_entity_type='person'
          AND m.sync_status='ok' AND pe.deleted_at IS NULL AND pe.status IN ('active','bench')
          AND EXISTS (SELECT 1 FROM placements p WHERE p.person_id=pe.id)
          AND NOT EXISTS (SELECT 1 FROM placements p WHERE p.person_id=pe.id
              AND (p.deleted_at IS NULL OR p.deleted_at='0000-00-00 00:00:00') AND p.status IN ('draft','pending_start','active','on_hold'))
        ORDER BY pe.id");
    $q->execute([$scope['people'], $workspace]);
    return array_map(static fn(array $row): array => ['id'=>(int)$row['id'], 'name'=>trim($row['first_name'].' '.$row['last_name']), 'status'=>$row['status']], $q->fetchAll(PDO::FETCH_ASSOC));
}

function jobdivaAlignmentSyncConfig(PDO $pdo, int $workspace): array
{
    $q = $pdo->prepare('SELECT sync_config FROM jobdiva_connections WHERE tenant_id=?');
    $q->execute([$workspace]);
    $value = $q->fetchColumn();
    $stored = $value === false || $value === null || $value === '' ? [] : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($stored)) throw new RuntimeException('Review the JobDiva connection configuration.');
    $result = [];
    foreach (JOBDIVA_SYNC_ENTITIES as $entity) {
        $entry = $stored[$entity] ?? JOBDIVA_SYNC_DEFAULTS[$entity];
        if (!is_array($entry) || !in_array($entry['source']??null, ['jobdiva','coreflux'], true)
            || !in_array($entry['direction']??null, ['off','pull','push','two_way'], true)) {
            throw new RuntimeException('Review the JobDiva connection configuration.');
        }
        $result[$entity] = $entry;
    }
    return $result;
}

/** Shared native directories do not transfer another workspace's provider identity. */
function jobdivaAlignmentExcludeOtherSources(PDO $pdo, int $workspace, array $rows): array
{
    $excluded = [];
    foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT DISTINCT m.internal_entity_id FROM external_entity_mappings m
            WHERE m.source_system='jobdiva' AND m.internal_entity_type='placement' AND m.tenant_id<>?
              AND m.internal_entity_id IN ({$slots})
              AND NOT EXISTS (SELECT 1 FROM external_entity_mappings own WHERE own.tenant_id=?
                AND own.source_system='jobdiva' AND own.internal_entity_type='placement' AND own.internal_entity_id=m.internal_entity_id)");
        $q->execute([$workspace, ...$ids, $workspace]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $excluded[(int)$id] = true;
    }
    return array_values(array_filter($rows, static fn(array $row): bool => !isset($excluded[(int)$row['id']])));
}

function jobdivaAlignmentCompanies(PDO $pdo, int $workspace, array $scope): array
{
    $q = $pdo->prepare("SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='company'");
    $q->execute([$workspace]);
    $sourceIds = $q->fetchAll(PDO::FETCH_COLUMN);
    $missing = 0; $canonical = [];
    foreach ($sourceIds as $sourceId) {
        $identity = (int)$sourceId > 0 ? companyIdentityResolve($pdo, $scope['companies'], (int)$sourceId) : null;
        if ($identity === null) { $missing++; continue; }
        $canonical[$identity['id']] = true;
    }
    $withoutClient = 0;
    $q = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM company_roles WHERE company_id=? AND role='client')
        AND NOT EXISTS (SELECT 1 FROM staffing_clients WHERE tenant_id=? AND company_id=?)");
    foreach (array_keys($canonical) as $id) {
        $q->execute([$id, $scope['staffing'], $id]);
        $withoutClient += (int)$q->fetchColumn();
    }
    return ['missing_company'=>$missing, 'current_companies'=>count($canonical), 'clients_without_staffing_client'=>$withoutClient];
}
