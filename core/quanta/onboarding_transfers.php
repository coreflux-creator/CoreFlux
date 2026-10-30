<?php
/** Durable private-file identity and reconciliation; no delete or approval operations. */
declare(strict_types=1);

function quantaOnboardingTransferGet(PDO $pdo,int $tenant,string $id,bool $lock=false): ?array {
    if($lock&&!$pdo->inTransaction())throw new LogicException('Transfer lock requires a transaction');
    $s=$pdo->prepare('SELECT * FROM quanta_onboarding_document_transfers WHERE tenant_id=:t AND import_id=:i'.($lock?' FOR UPDATE':''));
    $s->execute(['t'=>$tenant,'i'=>$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
}

/** The caller holds the same connection/directory/case locks as onboarding intake. */
function quantaOnboardingTransferReserve(PDO $pdo,array $scope,array $case,array $receipt,array $access,\Core\StorageService $storage): array {
    if(!$pdo->inTransaction())throw new LogicException('Transfer reservation requires the preflight transaction');
    $doc=quantaOnboardingDocumentMetadata($receipt);$workspace=$access['context']['workspace']['id'];
    $id=quantaOnboardingImportId($workspace,$receipt['id']);
    $expected=['case_id'=>$case['id'],'people_tenant_id'=>$case['people_tenant_id'],'person_id'=>$case['person_id'],
        'task_id'=>$receipt['task_id'],'workspace_id'=>$workspace,'provider_receipt_id'=>$receipt['id'],
        'receipt_hash'=>onboardingHash($receipt),'descriptor_hash'=>onboardingHash($doc),'binding_hash'=>$access['binding_hash'],
        'storage_identity'=>$storage->location_fingerprint()];
    $row=quantaOnboardingTransferGet($pdo,$scope['tenant_id'],$id,true);
    if($row){
        foreach($expected as$k=>$value)if((string)$row[$k]!== (string)$value)throw new OnboardingConflict('The retained document belongs to an earlier source, connection or storage configuration. Reconcile it before retrying.');
        return $row;
    }
    $filename=$doc['id'].'.'.strtolower(pathinfo($doc['filename'],PATHINFO_EXTENSION));
    $key=peopleDocumentKey($case['people_tenant_id'],$case['person_id'],$storage->build_key('people',$case['people_tenant_id'],'document',$case['person_id'],$filename));
    $s=$pdo->prepare('INSERT INTO quanta_onboarding_document_transfers (tenant_id,import_id,case_id,people_tenant_id,person_id,task_id,workspace_id,provider_receipt_id,receipt_hash,descriptor_hash,binding_hash,storage_identity,storage_key,created_by_user_id) VALUES (:t,:i,:case_id,:people_tenant_id,:person_id,:task_id,:workspace_id,:provider_receipt_id,:receipt_hash,:descriptor_hash,:binding_hash,:storage_identity,:k,:u)');
    $s->execute(['t'=>$scope['tenant_id'],'i'=>$id,'k'=>$key,'u'=>$scope['actor_user_id']]+$expected);
    return quantaOnboardingTransferGet($pdo,$scope['tenant_id'],$id,true);
}

/** Record verified IO independently; the later business commit may still be rejected. */
function quantaOnboardingTransferStored(PDO $pdo,array $scope,array $reservation,array $prepared): void {
    if($pdo->inTransaction())throw new LogicException('Stored-file checkpoint owns its transaction');
    $pdo->beginTransaction();
    try{
        $row=quantaOnboardingTransferGet($pdo,$scope['tenant_id'],$reservation['import_id'],true);
        foreach(['case_id','people_tenant_id','person_id','storage_key','receipt_hash','descriptor_hash','binding_hash','storage_identity']as$k)
            if(!$row||(string)$row[$k]!== (string)$reservation[$k])throw new OnboardingConflict('Document transfer reservation changed');
        if($row['state']!=='reserved'){
            if(onboardingHash(json_decode($row['stamp_json'],true,32,JSON_THROW_ON_ERROR))!==onboardingHash($prepared['stamp']))throw new OnboardingConflict('The retained document changed. Reconcile it before retrying.');
        }else{
            $pdo->prepare('UPDATE quanta_onboarding_document_transfers SET state="stored",stamp_json=:s,updated_at=UTC_TIMESTAMP() WHERE tenant_id=:t AND import_id=:i')
                ->execute(['s'=>onboardingJson($prepared['stamp']),'t'=>$scope['tenant_id'],'i'=>$row['import_id']]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Final registration joins the case/document/history transaction. */
function quantaOnboardingTransferLock(PDO $pdo,array $scope,array $case,array $receipt,array $prepared): array {
    $row=quantaOnboardingTransferGet($pdo,$scope['tenant_id'],(string)($prepared['transfer_id']??''),true);
    if(!$row||$row['state']!=='stored'||$row['case_id']!==$case['id']||(int)$row['people_tenant_id']!==$case['people_tenant_id']
        ||(int)$row['person_id']!==$case['person_id']||$row['receipt_hash']!==onboardingHash($receipt)
        ||$row['storage_key']!==$prepared['storage_key']||$row['descriptor_hash']!==$prepared['descriptor_hash']||$row['binding_hash']!==$prepared['binding_hash']||$row['storage_identity']!==$prepared['storage_identity']
        ||onboardingHash(json_decode($row['stamp_json'],true,32,JSON_THROW_ON_ERROR))!==onboardingHash($prepared['stamp']))throw new OnboardingConflict('The prepared document no longer matches its retained transfer');
    return $row;
}

/** Metadata-only reconciliation, including copies whose receipt is no longer offered by the provider. */
function quantaOnboardingTransfers(PDO $pdo,array $scope,string $caseId): array {
    onboardingScope($scope);onboardingCase($pdo,$scope,$caseId);
    $s=$pdo->prepare('SELECT import_id,task_id,provider_receipt_id,state,document_id,created_at,updated_at FROM quanta_onboarding_document_transfers WHERE tenant_id=:t AND case_id=:c ORDER BY updated_at DESC,import_id LIMIT 101');
    $s->execute(['t'=>$scope['tenant_id'],'c'=>$caseId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as&$r){$r['document_id']=$r['document_id']===null?null:(int)$r['document_id'];}unset($r);
    return ['items'=>array_slice($rows,0,100),'has_more'=>count($rows)>100];
}
