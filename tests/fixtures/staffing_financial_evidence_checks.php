<?php
/** Only included in the disposable native financial fixture; transport remains synthetic GET-only. */
require_once __DIR__.'/../../core/integrations/staffing_financial_evidence.php';
require_once __DIR__.'/staffing_workbook_fixture.php';
foreach([1,2]as$pass)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../../core/migrations/189_staffing_financial_evidence.sql'))as$sql)$pdo->exec($sql);
[$workbookBytes,$workbookSelection]=staffingWorkbookFixture();$workbookSelection['currency_cell']='A9';
$wire['invoice']['document']['line_items'][0]['amount']='1568.00';
$wire['invoice']['document']['line_items'][0]['hours']='16.00000000';
$wire['invoice']['document']['line_items'][0]['service_start']=null;$wire['invoice']['document']['line_items'][0]['service_end']=null;
$wire['invoice']['unavailable_fields']=purepayFinancialObservationMissing(array_diff_key($wire['invoice'],array_flip(['unavailable_fields','observed_at','observation_hash'])));
$wire['invoice']['observation_hash']=purepayFinancialObservationHash($wire['invoice']);
$invoiceCapture=$save($read('invoice'));$invoiceLineId=$wire['invoice']['document']['line_items'][0]['line_id'];
$invoiceReview=purepayFinancialLinePreview($pdo,1,$instance,$owners,$invoiceCapture['id'],$invoiceLineId,'PL-1');
$invoiceLink=purepayFinancialLineSave($pdo,1,7,$instance,$owners,$invoiceCapture['id'],$invoiceLineId,'PL-1',$invoiceReview['review_hash'],$audit);
$associationId=$invoiceLink['link']['id'];
$previewWorkbook=static fn(string$decision='record',string$reason='Synthetic workbook review',?string$bytes=null)=>staffingEvidencePreview($pdo,1,$instance,$owners,$associationId,$decision,
    $decision==='record'?($bytes??$workbookBytes):null,$decision==='record'?'synthetic.xlsx':null,$decision==='record'?$workbookSelection:null,$reason);
$saveWorkbook=static function(array$review,string$requestId,?callable$auditor=null,?string$bytes=null)use($pdo,$instance,$owners,$associationId,$workbookBytes,$workbookSelection,$audit):array{
    $record=$review['action']==='record';
    return staffingEvidenceSave($pdo,1,7,$instance,$owners,$associationId,$review['action'],$record?($bytes??$workbookBytes):null,$record?'synthetic.xlsx':null,
        $record?$workbookSelection:null,$review['reason'],$requestId,$review['review_hash'],$auditor??$audit);
};
$counts=static fn()=>[(int)$pdo->query('SELECT COUNT(*) FROM staffing_workbook_sources')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM staffing_financial_evidence')->fetchColumn()];
$protected=static function()use($pdo):array{
    $snapshot=[];foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$table){
        if(in_array($table,['staffing_workbook_sources','staffing_financial_evidence','fixture_audit'],true))continue;
        $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows);$snapshot[$table]=hash('sha256',serialize($rows));
    }ksort($snapshot);return$snapshot;
};
$beforeWorkbook=$protected();$reviewWorkbook=$previewWorkbook();
$check($counts()===[0,0]&&$reviewWorkbook['workbook']['file_sha256']===hash('sha256',$workbookBytes),'workbook preview extracts exact bytes without retaining records');
$firstWorkbook=$saveWorkbook($reviewWorkbook,'11111111-1111-4111-8111-111111111111');
$check($counts()===[1,1]&&!$firstWorkbook['replayed']&&$firstWorkbook['is_current'],'confirmed workbook review creates one source and one immutable receipt');
$sourceRow=$pdo->query('SELECT * FROM staffing_workbook_sources')->fetch(PDO::FETCH_ASSOC);
$check(!str_starts_with($sourceRow['content_ct'],"PK\x03\x04")&&decryptField($sourceRow['content_ct'])===$workbookBytes,'original workbook is retained encrypted and byte-for-byte recoverable');
$check($saveWorkbook($reviewWorkbook,'11111111-1111-4111-8111-111111111111')['replayed']&&$counts()===[1,1],'exact workbook save replay has no duplicate source or receipt');
$changedReason=$reviewWorkbook;$changedReason['reason']='Different intent';
$check($fails(fn()=>$saveWorkbook($changedReason,'11111111-1111-4111-8111-111111111111')),'a workbook request identity cannot save different intent');
$check($fails(fn()=>$saveWorkbook($changedReason,'22222222-2222-4222-8222-222222222222')),'a stale preview cannot authorize changed review content');
$changedWorkbookBytes=staffingWorkbookFixture(['<v>1568</v>'=>'<v>1600</v>'])[0];
$changedPreview=$previewWorkbook('record','Synthetic changed source',$changedWorkbookBytes);
$check($fails(fn()=>$saveWorkbook($changedPreview,'33333333-3333-4333-8333-333333333333',static function(){throw new RuntimeException('Synthetic audit failure');},$changedWorkbookBytes))&&$counts()===[1,1],'audit failure rolls back a new encrypted source and its evidence receipt together');
$concurrentPreview=$previewWorkbook('record','Synthetic second review');
$secondWorkbook=$saveWorkbook($concurrentPreview,'44444444-4444-4444-8444-444444444444');
$check($counts()===[1,2]&&$secondWorkbook['receipt']['saved']['previous_id']===$firstWorkbook['receipt']['id'],'review corrections append history and reuse identical retained source bytes');
$check($fails(fn()=>$saveWorkbook($concurrentPreview,'55555555-5555-4555-8555-555555555555'))&&$counts()===[1,2],'competing stale review cannot overwrite the newer evidence');
$wire['invoice']['document']['line_items'][0]['hours']='17.00000000';$wire['invoice']['observation_hash']=purepayFinancialObservationHash($wire['invoice']);
$check($fails($previewWorkbook),'changed provider source requires a new capture before adding workbook support');
$withdrawal=$previewWorkbook('withdraw','Synthetic incorrect row association');
$withdrawn=$saveWorkbook($withdrawal,'66666666-6666-4666-8666-666666666666');
$check($withdrawn['current_action']==='withdraw'&&$counts()===[1,3],'support can be withdrawn after the provider changes without deleting its original file');
$oldRetry=$saveWorkbook($reviewWorkbook,'11111111-1111-4111-8111-111111111111');
$check($oldRetry['replayed']&&!$oldRetry['is_current']&&$oldRetry['current_action']==='withdraw'&&$counts()===[1,3],'old successful request replay never reactivates withdrawn evidence');
$history=staffingEvidenceHistory($pdo,1,$instance,$owners,$associationId);
$check(count($history['items'])===3&&$history['items'][0]['saved']['action']==='withdraw'&&$history['items'][2]['id']===$firstWorkbook['receipt']['id'],'withdrawn and superseded workbook reviews remain visible in history');
$check($fails(fn()=>staffingEvidenceHistory($pdo,2,$instance,$owners,$associationId)),'another financial tenant cannot read the retained workbook history');
$check($fails(fn()=>staffingEvidenceHistory($pdo,1,$instance,['people'=>2,'placements'=>1],$associationId)),'another people directory cannot borrow workbook evidence');
$check($fails(fn()=>staffingEvidenceHistory($pdo,1,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',$owners,$associationId)),'another installation cannot read retained workbook evidence');
$pdo->prepare('UPDATE staffing_workbook_sources SET content_ct=? WHERE id=?')->execute([encryptField('synthetic corruption'),$sourceRow['id']]);
$check($fails(fn()=>staffingEvidenceHistory($pdo,1,$instance,$owners,$associationId)),'corrupted retained source bytes invalidate workbook history reads');
$pdo->prepare('UPDATE staffing_workbook_sources SET content_ct=? WHERE id=?')->execute([$sourceRow['content_ct'],$sourceRow['id']]);
$wire['invoice']['document']['line_items'][0]['hours']='16.00000000';$wire['invoice']['observation_hash']=purepayFinancialObservationHash($wire['invoice']);
$restored=$saveWorkbook($previewWorkbook('record','Synthetic reviewed correction'),'77777777-7777-4777-8777-777777777777');
$reportWithWorkbook=staffingReconciliationRead($pdo,1,$instance,['people'=>1,'placements'=>1,'time'=>1],'PL-1','2026-10-01','2026-10-31');
$invoiceResult=array_values(array_filter($reportWithWorkbook['financial_lines'],static fn($l)=>$l['association_id']===$associationId))[0];
$check($reportWithWorkbook['workbook_evidence_available']&&$invoiceResult['workbook_evidence']['id']===$restored['receipt']['id'],'native placement comparison includes the current reviewed workbook evidence');
$comparison=$invoiceResult['workbook_comparison'];
$check($comparison['provider_vs_worksheet_amount']['numeric_difference']==='0.00'&&$comparison['provider_vs_worksheet_hours_difference']==='0.00','associated invoice and worksheet values compare without rewriting provider facts');
$check($comparison['provider_relation_to_worksheet']==='unknown'&&$comparison['provider_vs_worksheet_amount']['monetary_difference']===null,'known currencies and equal amounts cannot manufacture missing provider service dates');
$check($comparison['worksheet_known_hours_in_common_period']==='16.00'&&$comparison['core_approved_hours_in_common_period']==='8.00'&&$comparison['worksheet_minus_core_hours']==='8.00','worksheet and approved Core hours retain an explicit discrepancy');
$check(!$comparison['time_entry_allocation_proven']&&!$comparison['original_client_approval_proven'],'supplemental comparison does not authorize time allocation or client approval');
$check($protected()===$beforeWorkbook,'workbook review and withdrawal leave financial, time, payroll and provider identity records unchanged');
