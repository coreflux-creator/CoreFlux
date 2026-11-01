<?php
/** Verify the actual placement read contract and additive repair of historical partial schemas. */
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true')throw new RuntimeException('Isolated CI only');
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../core/migrate.php';
require_once __DIR__.'/../modules/placements/lib/placements.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')throw new RuntimeException('Exact CI database required');
$pdo->query('SELECT '.placementsSafeFields().' FROM placements p WHERE 1=0')->closeCursor();
echo "PASS actual placement read columns exist after clean installation\n";
$fields=[
    'placement_client_chain'=>['payment_terms_override','pwp_enabled','is_payable'],
    'placement_referrals'=>['payment_terms_override','pwp_enabled'],
    'placement_corp_details'=>['company_id','ap_vendor_id','payment_terms_override','pwp_enabled'],
    'placement_rates'=>['bill_adder_pct','bill_adder_flat','bill_discount_pct','bill_discount_flat','workers_comp_pct','benefits_load_pct','c2c_overhead_pct','other_cost_per_hour','other_cost_flat','economics_snapshot_json'],
];
foreach($fields as$table=>$columns){$pdo->query('SELECT '.implode(',',$columns).' FROM '.$table.' WHERE 1=0')->closeCursor();echo "PASS $table graph source fields exist\n";}
$fixture='coreflux_placement_repair_ci';
$pdo->exec('CREATE DATABASE '.$fixture);$pdo->exec('USE '.$fixture);
$check=static function(bool $ok,string $label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
try{
    $pdo->exec('CREATE TABLE placements(id BIGINT PRIMARY KEY,billing_cycle_id BIGINT,ap_operating_cycle_id BIGINT,client_bill_cycle_anchor DATE,vendor_pay_cycle_anchor DATE,vendor_payment_terms_override VARCHAR(40))');
    $pdo->exec("INSERT INTO placements VALUES(1,44,900,'2026-05-18','2026-05-20','Net 45')");
    foreach(array_keys($fields)as$table)$pdo->exec('CREATE TABLE '.$table.'(id BIGINT PRIMARY KEY)');
    $pdo->exec('ALTER TABLE placement_rates ADD COLUMN bill_adder_pct DECIMAL(8,6) NULL');
    $pdo->exec('INSERT INTO placement_rates VALUES(1,0.150000)');
    $migration=file_get_contents(__DIR__.'/../modules/placements/migrations/009_graph_source_columns_repair.sql');
    for($pass=0;$pass<2;$pass++)foreach(coreflux_split_sql_statements($migration)as$sql)$pdo->exec($sql);
    $row=$pdo->query('SELECT * FROM placements WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $check((int)$row['billing_cycle_id']===44&&(int)$row['ap_operating_cycle_id']===900,'repair preserves both legacy and existing canonical cycle IDs');
    $check($row['billing_operating_cycle_id']===null&&$row['payroll_operating_cycle_id']===null,'repair does not guess links across cycle identity spaces');
    $check($row['client_bill_cycle_anchor']==='2026-05-18'&&$row['vendor_pay_cycle_anchor']==='2026-05-20','repair adds cadence defaults independently of existing anchors');
    $check($row['vendor_payment_terms_override']==='Net 45','repair preserves negotiated terms');
    $check((float)$pdo->query('SELECT bill_adder_pct FROM placement_rates WHERE id=1')->fetchColumn()===0.15,'repair preserves existing economic adjustments');
    $check($row['client_bill_cycle']==='monthly'&&$row['vendor_pay_cycle']==='biweekly','missing cadence fields use the original declared defaults');
    foreach($fields as$table=>$columns)$pdo->query('SELECT '.implode(',',$columns).' FROM '.$table.' WHERE 1=0')->closeCursor();
    echo "PASS graph-source repair replays without data changes\n";
}finally{$pdo->exec('USE coreaccounting_ci');$pdo->exec('DROP DATABASE '.$fixture);}
