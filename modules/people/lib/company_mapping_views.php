<?php
/** Read source identities in the authenticated workspace, following company-number aliases. */
declare(strict_types=1);
require_once __DIR__.'/company_identity.php';
require_once __DIR__.'/../../../core/jobdiva/party_scope.php';

function companyMappingViews(PDO $pdo,int $workspace,int $record): array
{
    $owner=jobdivaPartyDirectoryScope($pdo,$workspace)['owner'];
    $identity=companyIdentityResolve($pdo,$owner,$record);
    if(!$identity)throw new RuntimeException('Company not found in this directory.',404);
    // Never return raw payloads or error strings: they can contain private source data.
    $q=$pdo->prepare('SELECT m.id,m.tenant_id,m.source_system,m.internal_entity_type,m.external_id,m.internal_entity_id,m.direction,m.sync_status,m.last_synced_at,m.last_seen_at,n.record_number AS source_company_number
        FROM external_entity_mappings m JOIN core_record_numbers n ON n.entity_type="company" AND n.record_id=m.internal_entity_id AND n.tenant_id=?
        WHERE m.tenant_id=? AND m.internal_entity_type="company" AND n.canonical_record_id=? ORDER BY m.source_system,m.external_id,m.id');
    $q->execute([$owner,$workspace,$identity['id']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as&$row){$row['id']=(int)$row['id'];$row['internal_entity_id']=(int)$row['internal_entity_id'];$row['canonical_internal_id']=$identity['id'];$row['company_number']=$identity['company_number'];}unset($row);
    return ['entity_type'=>'company','internal_id'=>$record,'canonical_internal_id'=>$identity['id'],'company_number'=>$identity['company_number'],'mappings'=>$rows];
}
