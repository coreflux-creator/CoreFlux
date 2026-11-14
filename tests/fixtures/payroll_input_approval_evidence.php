<?php
/** Seed retained approval evidence only in the explicitly disposable payroll fixtures. */
declare(strict_types=1);

function payrollInputFixtureApprovalEvidence(PDO $pdo, array $ids, array $dimensionOverrides=[]): void
{
    foreach ($ids as $id) {
        $q=$pdo->prepare('SELECT * FROM time_entries WHERE id=?');$q->execute([$id]);$entry=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM placement_rates WHERE id=? AND placement_id=?');
        $q->execute([$entry['rate_snapshot_id'],$entry['placement_id']]);$rate=$q->fetch(PDO::FETCH_ASSOC);
        $rateJson=staffingDimensionSnapshotCanonicalJson($rate);
        $rateHash=hash('sha256',$rateJson);
        $dimensions=staffingDimensionSnapshotCanonicalJson(array_replace(['engagement_type'=>'w2'],$dimensionOverrides,['source_rate_hash'=>$rateHash,'approved_rate_version'=>1]));
        $pdo->prepare('UPDATE time_entries SET dimension_snapshot_json=?,dimension_snapshot_hash=? WHERE id=?')->execute([$dimensions,hash('sha256',$dimensions),$id]);
        $pdo->prepare('INSERT INTO time_approval_rates(tenant_id,time_entry_id,placement_id,rate_owner_tenant_id,rate_id,source_rate_hash,entry_facts_hash,rate_json) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$entry['tenant_id'],$id,$entry['placement_id'],$rate['tenant_id'],$rate['id'],$rateHash,timeApprovedEntryFactsHash($entry),$rateJson]);
    }
}
