<?php
/** Native scoped cycle snapshots and CRUD audit evidence in disposable MariaDB. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/payroll/lib/cycles.php';
$password=$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$connect=static fn()=>new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$control=$connect();$db='coreflux_cycle_audit_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());
$control->exec('CREATE DATABASE '.$db);$control->exec('USE '.$db);$count=0;$connections=[];
$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
try{
    $control->exec('CREATE TABLE payroll_pay_cycles(id BIGINT PRIMARY KEY,tenant_id BIGINT,name VARCHAR(80),schedule_id BIGINT,active TINYINT,notes TEXT) ENGINE=InnoDB');
    $control->exec("INSERT INTO payroll_pay_cycles VALUES(1,11,'Synthetic cohort',10,1,NULL),(2,22,'Other company',20,1,NULL)");
    foreach(['canonical','legacy'] as $schema){
        $fields=$schema==='canonical'?'actor_user_id BIGINT,event VARCHAR(100),target_id BIGINT,object_type VARCHAR(80),before_json JSON,after_json JSON':'user_id BIGINT,action VARCHAR(100) NOT NULL,entity_id BIGINT,entity VARCHAR(80) NOT NULL';
        $control->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT NOT NULL,'.$fields.',meta_json JSON,created_at DATETIME) ENGINE=InnoDB');
        $pdo=$connect();$connections[]=$pdo;$pdo->exec('USE '.$db);$pdo->beginTransaction();
        $first=payrollCycleAuditRow(11,1);
        $check($first!==null&&(int)$first['tenant_id']===11&&$first['name']==='Synthetic cohort',"$schema actual cycle snapshot");
        $check(payrollCycleAuditRow(11,2)===null&&payrollCycleAuditRow(22,1)===null&&payrollCycleAuditRow(0,1)===null,"$schema foreign and invalid scope returns no snapshot");
        payrollAuditLight('payroll.cycle.created',['cycle_id'=>1],1,['tenant_id'=>11,'actor_user_id'=>7,'after'=>$first]);
        $pdo->exec("UPDATE payroll_pay_cycles SET name='Reviewed cohort' WHERE tenant_id=11 AND id=1");
        $updated=payrollCycleAuditRow(11,1);
        payrollAuditLight('payroll.cycle.updated',['cycle_id'=>1],1,['tenant_id'=>11,'actor_user_id'=>8,'before'=>$first,'after'=>$updated]);
        $pdo->exec('UPDATE payroll_pay_cycles SET active=0 WHERE tenant_id=11 AND id=1');
        $disabled=payrollCycleAuditRow(11,1);
        payrollAuditLight('payroll.cycle.deactivated',['cycle_id'=>1],1,['tenant_id'=>11,'actor_user_id'=>8,'before'=>$updated,'after'=>$disabled]);
        $rows=$pdo->query('SELECT * FROM audit_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $check(count($rows)===3,"$schema all three native audit calls write receipts");
        foreach($rows as $i=>$row){
            $meta=json_decode($row['meta_json'],true,512,JSON_THROW_ON_ERROR);
            $expectedAfter=[$first,$updated,$disabled][$i];
            $check((int)$row['tenant_id']===11&&(int)($row['actor_user_id']??$row['user_id'])===($i===0?7:8)
                &&(int)($row['target_id']??$row['entity_id'])===1&&$meta['after']===$expectedAfter,"$schema receipt $i retains actor, owner, target and after snapshot");
            if($i>0)$check($meta['before']===[$first,$updated][$i-1],"$schema receipt $i retains its exact predecessor");
            if($schema==='canonical')$check(json_decode($row['after_json'],true)===$expectedAfter,"$schema receipt $i stores canonical snapshot");
        }
        $check(payrollCycleAuditRow(22,2)['name']==='Other company',"$schema other company is unchanged");
        $pdo->rollBack();
        $check((int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===0&&payrollCycleAuditRow(11,1)===$first,"$schema changes and receipts roll back together");
        $control->exec('DROP TABLE audit_log');
    }
    echo "$count native payroll cycle audit checks passed\n";
}finally{if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$control->exec('DROP DATABASE '.$db);}
