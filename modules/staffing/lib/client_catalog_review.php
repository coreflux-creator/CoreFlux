<?php
/** Read-only catalog inventory. Lifecycle and duplicate identity choices require review. */
declare(strict_types=1);
require_once __DIR__.'/client_source.php';

function staffingClientCatalogReview(int $workspace): array
{
    $pdo = getDB();
    return companyRelationshipAtomic($pdo, static function () use ($pdo, $workspace): array {
        $scope = companyRelationshipScope($pdo, $workspace, 'placements');
        $owner = $scope['owner'];
        $queries = [
            'active_clients'=>["SELECT COUNT(*) FROM staffing_clients WHERE tenant_id=? AND status='active'", [$owner]],
            'clients_without_current_company'=>['SELECT COUNT(*) FROM staffing_clients sc LEFT JOIN companies c ON c.id=sc.company_id AND c.tenant_id=? AND c.deleted_at IS NULL WHERE sc.tenant_id=? AND c.id IS NULL', [$scope['company_owner'], $owner]],
            'companies_with_multiple_clients'=>['SELECT COUNT(*) FROM (SELECT company_id FROM staffing_clients WHERE tenant_id=? AND company_id IS NOT NULL GROUP BY company_id HAVING COUNT(*)>1) duplicates', [$owner]],
            'active_placements_needing_client_review'=>["SELECT COUNT(*) FROM placements p LEFT JOIN staffing_clients sc ON sc.tenant_id=p.tenant_id AND sc.id=p.client_id WHERE p.tenant_id=? AND p.deleted_at IS NULL AND p.status='active' AND (sc.id IS NULL OR sc.status<>'active')", [$owner]],
            'active_placeholder_names'=>["SELECT COUNT(*) FROM staffing_clients WHERE tenant_id=? AND status='active' AND name REGEXP '^JobDiva Company [0-9]+$'", [$owner]],
        ];
        $counts = [];
        foreach ($queries as $key=>[$sql,$params]) {
            $q=$pdo->prepare($sql);$q->execute($params);$counts[$key]=(int)$q->fetchColumn();
        }
        $q=$pdo->prepare("SELECT p.id AS placement_id,p.client_id,sc.name AS client_name,sc.status AS client_status FROM placements p LEFT JOIN staffing_clients sc ON sc.tenant_id=p.tenant_id AND sc.id=p.client_id WHERE p.tenant_id=? AND p.deleted_at IS NULL AND p.status='active' AND (sc.id IS NULL OR sc.status<>'active') ORDER BY p.id LIMIT 100");
        $q->execute([$owner]);
        return [
            'workspace_id'=>$workspace, 'placement_owner_id'=>$owner, 'company_owner_id'=>$scope['company_owner'],
            'counts'=>$counts, 'active_placement_client_issues'=>$q->fetchAll(PDO::FETCH_ASSOC),
            'review_required'=>array_sum(array_diff_key($counts,['active_clients'=>true]))>0,
            'guidance'=>'Review client status and company identity in Clients. Multiple retained client numbers are not duplicates by themselves; exact provider links determine assignment billing. This report changes no records.',
        ];
    });
}
