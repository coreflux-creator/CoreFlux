<?php
/** Private, read-only commercial extension for the existing source-copy pilot. */
declare(strict_types=1);
const TH_COMMERCIAL_SOURCE_ROOT='/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--export-private-commercial-source')exit(2);
require_once TH_COMMERCIAL_SOURCE_ROOT.'/core/db.php';$p=getDB();
if(!$p||$p->query('SELECT DATABASE()')->fetchColumn()!=='grcudkpvcd')exit(2);
$p->exec('SET SESSION TRANSACTION READ ONLY');$p->beginTransaction();
try{
    $placement=$p->query('SELECT id,tenant_id,person_id,external_id,client_bill_cycle,client_bill_cycle_anchor,
        vendor_pay_cycle,vendor_pay_cycle_anchor,client_payment_terms_override,vendor_payment_terms_override,vendor_pwp_enabled,
        billing_operating_cycle_id,ap_operating_cycle_id,payroll_operating_cycle_id
        FROM placements WHERE tenant_id=2 AND id=864 AND deleted_at IS NULL')->fetch(PDO::FETCH_ASSOC);
    if(!$placement||$placement['external_id']!=='jd:57862243'||(int)$placement['person_id']!==358)throw new RuntimeException('Source identity changed');
    $rates=$p->query('SELECT id,tenant_id,placement_id,effective_from,effective_to,bill_rate,pay_rate,currency,bill_rate_unit,pay_rate_unit,
        ot_multiplier,dt_multiplier,adder_pct,bill_adder_pct,bill_adder_flat,bill_discount_pct,bill_discount_flat,
        workers_comp_pct,benefits_load_pct,c2c_overhead_pct,other_cost_per_hour,other_cost_flat,background_fee_total
        FROM placement_rates WHERE tenant_id=2 AND placement_id=864 AND superseded_by IS NULL')->fetchAll(PDO::FETCH_ASSOC);
    if(count($rates)!==1)throw new RuntimeException('Review changed source rate periods');
    $defaults=$p->query('SELECT w2_payroll_load_pct,w2_workers_comp_pct,w2_benefits_load_pct,c2c_overhead_pct
        FROM tenant_staffing_economics_defaults WHERE tenant_id=2')->fetch(PDO::FETCH_ASSOC);
    if(!$defaults)throw new RuntimeException('Source economics defaults missing');
    $parties=$p->query('SELECT id,source_ref,source_type,source_id,role,display_name,company_id,person_id,user_id,ap_vendor_id,
        money_flow,settlement_channel,fee_basis,fee_pct,fee_flat,payment_terms,payment_terms_overridden,pwp_enabled,pwp_overridden,
        operating_cycle_id,cycle_overridden,effective_from,effective_to,source_system,source_external_id,source_managed,active
        FROM placement_economic_parties WHERE tenant_id=2 AND placement_id=864 ORDER BY id LIMIT 101')->fetchAll(PDO::FETCH_ASSOC);
    if(count($parties)>100||(int)$p->query('SELECT COUNT(*) FROM placement_economic_items WHERE tenant_id=2 AND placement_id=864')->fetchColumn()!==0)
        throw new RuntimeException('Commercial source requires expanded review');
    $ids=array_values(array_unique(array_filter([...(array_column($parties,'operating_cycle_id')),
        $placement['billing_operating_cycle_id'],$placement['ap_operating_cycle_id'],$placement['payroll_operating_cycle_id']])));
    $cycles=[];if($ids){$q=$p->prepare('SELECT id,purpose,name,cadence,anchor_date,source_system,external_id,default_payment_terms,active
        FROM staffing_operating_cycles WHERE tenant_id=2 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);$cycles=$q->fetchAll(PDO::FETCH_ASSOC);}
    if(count($ids)!==count($cycles))throw new RuntimeException('Unresolved source cycle');
    $out=['kind'=>'thunderhawk_commercial_source_20261030','captured_at'=>gmdate('c'),'read_only'=>true,
        'placement'=>$placement,'rates'=>$rates,'tenant_defaults'=>$defaults,'parties'=>$parties,'cycles'=>$cycles,
        'one_time_items'=>[],'limitation'=>'Stored Core source, not a fresh JobDiva response or approval.'];
    $p->rollBack();echo json_encode($out,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),"\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
