<?php
/** Apply the user's explicit five-line PL-864 confirmation in the isolated pilot only. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--link-confirmed-historical-lines'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root)exit(2);
// Exact application source hashes are filled from the committed release before use.
$runtimeHashes=[
    'core/integrations/purepay_financial_line_links.php'=>'a9ad5987cfc2eb79958ba350cdc89cade157522df901f7323dfff40eca84f22e',
    'core/integrations/purepay_financial_observations.php'=>'cb63dbc88210c7887cec75bad9dd83fb08ccd2fe3dbd31097db8c04f6c943ba9',
    'core/integrations/purepay_financial_observation_contract.php'=>'4e03825870d755d5626fcb5fccaff8f1878f2e207ec0d3d200da7cf8e8e5f4d4',
    'core/migrations/184_purepay_financial_line_links.sql'=>'0619a0c94c42d80e0bcf434e839416c86ccac2e34578693e70df19ee4f9391a5',
];
if(count($runtimeHashes)!==4)exit(2);
foreach($runtimeHashes as$file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents($root.'/'.$file)))!==$hash)exit(2);
require_once $root.'/core/integrations/purepay_financial_line_links.php';
require_once $root.'/core/rbac/permissions.php';
$pdo=getDB();$instance=integrationInstanceId();$owners=['people'=>2002,'placements'=>2002];
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
    ||$instance!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||purepayFinancialPreviewTenant()!==2002
    ||purepayApiBase()!=='https://purepay.online:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'accounting','admin'))exit(2);
foreach(['people','placements','ap','billing']as$module)if(!RBACResolver::can(4,2002,$module,'read'))exit(2);
$expected=[
    [4,'inv_82de265509','il_qbo_6019_21','446fa0484259ee3223482e57484c02fd66b23415ac25815db429a651b9f9ac86'],
    [5,'inv_ea597af5df','il_qbo_4155_21','e0d14abfb26cfc9d46d32d21e2346c16e29eec3aec0f7e23a05861baa5a13feb'],
    [6,'inv_dcb25061c4','il_qbo_3474_24','f6f5b0bc5a25a1e4ec196c8fbec4cbe8907927c0841707fd01921a65f17c7ebe'],
    [7,'inv_191eb3675c','il_qbo_6472_18','8107b861f558d6064248174737a46f3857b20ad73cee052142da56f04418ef2c'],
    [8,'inv_0f1382bcb5','il_qbo_7101_16','554f045e13a35d7001d8ec92c3b6e256ac57f4552124f90f6759d3de0f610a4f'],
];
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'authorization'=>'User confirmed these five historical source lines for PL-864 on 2026-10-30',
    'production_changed'=>false,'financial_postings'=>false,'payments_or_payroll_executed'=>false];$phase='review';
try {
    $previews=[];$subjects=[];
    foreach($expected as[$captureId,$document,$line,$hash]){
        $review=purepayFinancialLinePreview($pdo,2002,$instance,$owners,$captureId,$line,'PL-864');
        if($review['source']['document_id']!==$document||$review['source']['observation_hash']!==$hash
            ||$review['source']['line']['item_id']!=='itm_eb535696e3'||$review['source']['customer_id']!=='cus_e25f880f86'
            ||$review['target']['placement_id']!==864||$review['target']['person_id']!==358
            ||$review['target']['placement']['number']!=='PL-864'||$review['target']['person']['number']!=='P-358')
            throw new RuntimeException('The reviewed source line or canonical target differs from the user confirmation.');
        $previews[]=$review;$subjects[]=$review['subject_hash'];
    }
    $protected=static function()use($pdo,$subjects):string {
        $out=[];
        foreach(['people','placements','placement_rates','staffing_clients','core_record_numbers','time_entries','staffing_timesheets',
            'payroll_runs','payroll_line_items','payroll_provider_results','payroll_native_observations','ap_bills','ap_payments',
            'accounting_journal_entries','accounting_journal_entry_lines','billing_invoices','billing_payments','purepay_connections',
            'purepay_financial_bindings','purepay_financial_organizations','purepay_payment_links','purepay_webhook_events',
            'purepay_financial_observations']as$table){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));sort($rows,SORT_STRING);
            $out[$table]=hash('sha256',serialize($rows));
        }
        $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE NOT(tenant_id=2002 AND subject_hash IN (?,?,?,?,?)) ORDER BY id');
        $q->execute($subjects);$out['other_line_associations']=hash('sha256',serialize($q->fetchAll(PDO::FETCH_ASSOC)));
        return hash('sha256',serialize($out));
    };
    $before=$protected();$countBefore=(int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_line_links')->fetchColumn();$created=0;$results=[];
    $audit=static function(array $meta)use($pdo):void {
        $meta['authorization']='user_confirmed_five_historical_invoice_lines_PL-864_2026-10-30';
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(2002,4,"purepay.financial_line.linked",?,?,NOW())')
            ->execute([$meta['link_id'],json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    $phase='save_confirmed_associations';
    foreach($previews as$review){
        $saved=purepayFinancialLineSave($pdo,2002,4,$instance,$owners,$review['capture_id'],$review['source']['line_id'],'PL-864',$review['review_hash'],$audit);
        if(!$saved['replayed'])$created++;
        $fresh=purepayFinancialLinePreview($pdo,2002,$instance,$owners,$review['capture_id'],$review['source']['line_id'],'PL-864');
        $replay=purepayFinancialLineSave($pdo,2002,4,$instance,$owners,$review['capture_id'],$review['source']['line_id'],'PL-864',$fresh['review_hash'],$audit);
        $history=purepayFinancialLineHistory($pdo,2002,$instance,$owners,$review['capture_id'],$review['source']['line_id']);
        if(!$replay['replayed']||$replay['link']['id']!==$saved['link']['id']||$history['items'][0]['id']!==$saved['link']['id'])
            throw new RuntimeException('Association replay or history differs.');
        $q=$pdo->prepare('SELECT payload_ct FROM purepay_financial_line_links WHERE tenant_id=2002 AND id=?');$q->execute([$saved['link']['id']]);
        if(str_contains($q->fetchColumn(),'PL-864'))throw new RuntimeException('Association evidence is not encrypted.');
        $results[]=['link_id'=>$saved['link']['id'],'capture_id'=>$review['capture_id'],'document_id'=>$review['source']['document_id'],
            'source_line_id'=>$review['source']['line_id'],'link_hash'=>$saved['link']['saved']['link_hash'],
            'placement'=>$review['target']['placement'],'person'=>$review['target']['person'],'replayed_without_duplicate'=>true,
            'history_verified'=>true,'evidence_encrypted'=>true,'future_item_rule'=>false,'time_entries_matched'=>false,
            'currency_verified'=>false,'ledger_posted'=>false];
    }
    if($protected()!==$before||(int)$pdo->query('SELECT COUNT(*) FROM purepay_financial_line_links')->fetchColumn()!==$countBefore+$created)
        throw new RuntimeException('Protected business records or unrelated associations changed.');
    $report+=['passed'=>true,'created'=>$created,'associations'=>$results,'all_protected_business_records_unchanged'=>true];
}catch(Throwable $e){$report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
