<?php
/** Database regressions for retained, entry-specific approval prices in financial review. */
$approvalBefore=$fingerprint();
$originalEntry=$pdo->query('SELECT * FROM time_entries WHERE id=71')->fetch(PDO::FETCH_ASSOC);
$originalArchive=$pdo->query('SELECT * FROM time_approval_rates WHERE time_entry_id=71 AND tenant_id=1')->fetch(PDO::FETCH_ASSOC);
$restoreArchive=static function()use($pdo,$originalArchive):void{
    $columns=array_keys($originalArchive);
    $pdo->prepare('INSERT INTO time_approval_rates (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($originalArchive));
};
$restoreEntry=static function()use($pdo,$originalEntry):void{
    $columns=array_keys($originalEntry);
    $pdo->prepare('UPDATE time_entries SET '.implode(',',array_map(static fn($c)=>'`'.$c.'`=?',$columns)).' WHERE id=71')->execute(array_values($originalEntry));
};
$secondEntry=array_replace($originalEntry,['id'=>75,'work_date'=>'2026-10-07','hours'=>'2.00','external_id'=>'synthetic-75']);
$columns=array_keys($secondEntry);
$pdo->prepare('INSERT INTO time_entries (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($secondEntry));
$secondRate=json_decode($originalArchive['rate_json'],true,512,JSON_THROW_ON_ERROR);$secondRate['pay_rate']='75.0000';$secondRate['bill_rate']='108.0000';
$archiveComparisonEntry(75,$secondRate);
$versions=$comparisonRead();
$check($versions['time']['approved_hours']==='10.00'&&$versions['time']['pay_amount']==='670.00'&&$versions['time']['bill_amount']==='1000.00','two archived approvals sharing a rate ID retain distinct original prices in the complete report');
$pdo->exec('DELETE FROM time_approval_rates WHERE time_entry_id=75');$pdo->exec('DELETE FROM time_entries WHERE id=75');

$pdo->exec('DELETE FROM time_approval_rates WHERE time_entry_id=71');
$missing=$comparisonRead();
$check($missing['time']['approved_hours']==='8.00'&&$missing['time']['pay_amount']===null&&$missing['financial_lines'][0]['core_amount']===null
    &&str_contains($missing['time']['entries'][0]['rate_evidence_detail'],'archived approval rate is missing'),'missing modern archive retains approved hours but refuses current-rate substitution');
$restoreArchive();
foreach(['rate_json'=>str_replace('65.0000','66.0000',$originalArchive['rate_json']),'entry_facts_hash'=>str_repeat('0',64),'rate_owner_tenant_id'=>2,'placement_id'=>3,'rate_id'=>88]as$field=>$value){
    $pdo->prepare('UPDATE time_approval_rates SET `'.$field.'`=? WHERE time_entry_id=71')->execute([$value]);
    $changed=$comparisonRead();
    $check($changed['time']['pay_amount']===null&&$changed['time']['entries'][0]['rate_evidence']==='unavailable','corrupt retained approval evidence is exposed: '.$field);
    $pdo->prepare('UPDATE time_approval_rates SET `'.$field.'`=? WHERE time_entry_id=71')->execute([$originalArchive[$field]]);
}
$pdo->exec('UPDATE time_approval_rates SET tenant_id=2 WHERE time_entry_id=71');
$check($comparisonRead()['time']['pay_amount']===null,'another time workspace cannot lend its retained approval rate');
$pdo->exec('UPDATE time_approval_rates SET tenant_id=1 WHERE time_entry_id=71');
$pdo->exec("UPDATE time_entries SET dimension_snapshot_hash=REPEAT('0',64) WHERE id=71");
$check($comparisonRead()['time']['pay_amount']===null,'changed approval dimension bytes cannot establish historical economics');$restoreEntry();
$pdo->exec("UPDATE time_entries SET approved_via='unverified' WHERE id=71");
$check($comparisonRead()['time']['pay_amount']===null,'unverified original review method cannot price historical time');$restoreEntry();
$pdo->exec("UPDATE time_entries SET status='billing_ready' WHERE id=71");
$check($comparisonRead()['time']['pay_amount']==='520.00','downstream approved lifecycle status retains the original approval price');$restoreEntry();

// Supported legacy evidence is an exact retained hash, never an assumed historical price.
$pdo->exec('DELETE FROM time_approval_rates WHERE time_entry_id=71');
$legacySnapshot=staffingDimensionSnapshotCanonicalJson(['source_rate_hash'=>$originalArchive['source_rate_hash']]);
$pdo->prepare('UPDATE time_entries SET dimension_snapshot_json=?,dimension_snapshot_hash=? WHERE id=71')->execute([$legacySnapshot,hash('sha256',$legacySnapshot)]);
$check($comparisonRead()['time']['pay_amount']==='520.00','legacy approval reads only an exactly matching original rate hash');
$pdo->exec('UPDATE placement_rates SET pay_rate=99 WHERE id=7');
$check($comparisonRead()['time']['pay_amount']===null,'legacy changed rate is a visible evidence gap rather than a replacement price');
$pdo->exec('UPDATE placement_rates SET pay_rate=65 WHERE id=7');
$pdo->exec('UPDATE placement_rates SET tenant_id=2 WHERE id=7');
$check($comparisonRead()['time']['pay_amount']===null,'legacy lookup cannot borrow another placement directory');
$pdo->exec('UPDATE placement_rates SET tenant_id=1 WHERE id=7');
$restoreEntry();$restoreArchive();
$pdo->exec('RENAME TABLE time_approval_rates TO fixture_hidden_approval_rates');
$check($fails($comparisonRead),'missing approval schema fails the complete read rather than claiming per-entry missing history');
$pdo->exec('RENAME TABLE fixture_hidden_approval_rates TO time_approval_rates');
$check($fingerprint()===$approvalBefore,'regression fixtures restore every original record and approval byte');
$beforeRead=$fingerprint();$beforeRequests=count($calls);$finalRead=$comparisonRead();
$check($finalRead['time']['pay_amount']==='520.00'&&$fingerprint()===$beforeRead&&count($calls)===$beforeRequests,'archived-rate financial comparison is repeatable, read-only and makes no provider request');
