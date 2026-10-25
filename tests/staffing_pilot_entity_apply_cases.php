<?php
/** Called only by the disposable MariaDB directory fixture. */
declare(strict_types=1);
require_once __DIR__.'/../deploy/staffing_pilot_entity_apply.php';

function testPilotEntityApply(PDO $pdo,array $pilot,callable $check): void
{
    $pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT NULL,
        actor_type VARCHAR(40),event VARCHAR(100),target_id BIGINT,object_type VARCHAR(80),meta_json JSON,
        before_json JSON,after_json JSON,request_id VARCHAR(100),source VARCHAR(100),created_at DATETIME) ENGINE=InnoDB');
    $expected=['id'=>77,'code'=>'SYN','legal_name'=>'Synthetic employer'];
    $audit=static fn($before,$after)=>fullCorePilotEntityAudit($pdo,$pilot,$before===null?null:(int)$before,$after,
        'thunderhawk-pilot-entity-123456',str_repeat('a',40));
    $snapshot=static function()use($pdo):array{
        $state=[];
        foreach(['placements','people','placement_rates','external_entity_mappings','accounting_entities','audit_log'] as $table) {
            $state[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        }
        return $state;
    };
    $apply=static fn($p,$e,$owner,$writer)=>fullCorePilotApplyEntityInheritance($pdo,$p,$e,static fn()=>$owner,$writer);
    $reject=static function(callable $action,string $label)use($pdo,$check,$snapshot):void{
        $before=$snapshot();$held=false;
        try{$action();}catch(Throwable $e){$held=true;}
        $check($held&&!$pdo->inTransaction()&&$snapshot()===$before,$label);
    };
    $before=$snapshot();
    $result=$apply($pilot,$expected,11,$audit);
    $after=$snapshot();$expectedState=$before;
    $expectedState['placements'][0]['accounting_entity_id']=$after['placements'][0]['accounting_entity_id'];
    $expectedState['audit_log']=$after['audit_log'];
    $check($result['status']==='aligned'&&$result['audit_written']&&(int)$after['placements'][0]['accounting_entity_id']===77
        &&$after===$expectedState,'only the selected placement entity and its audit receipt change');
    $row=$after['audit_log'][0];$meta=json_decode($row['meta_json'],true);
    $check(count($after['audit_log'])===1&&$row['actor_user_id']===null&&$row['actor_type']==='system'
        &&$row['event']==='placements.entity_inherited'&&(int)$row['tenant_id']===11&&(int)$row['target_id']===1
        &&$meta['before']['accounting_entity_id']===0&&$meta['after']['accounting_entity_id']===77,
        'canonical audit records the real system actor and exact before and after values');
    $repeat=$apply($pilot,$expected,11,static function(){throw new RuntimeException('must not audit a replay');});
    $check($repeat['status']==='already_aligned'&&!$repeat['record_writes']&&$snapshot()===$after,
        'replaying the completed change writes neither a second receipt nor another record');
    $pdo->exec('UPDATE placements SET accounting_entity_id=0 WHERE id=1');
    $reject(static fn()=>$apply($pilot,$expected,11,$audit),'reusing a receipt for a newly missing reference cannot silently write again');
    $pdo->exec('DELETE FROM audit_log');
    $reject(static fn()=>$apply($pilot,$expected,12,$audit),'different effective accounting owner stops the change');
    $reject(static fn()=>$apply($pilot,array_replace($expected,['legal_name'=>'Renamed employer']),11,$audit),
        'reviewed employer identity must still match');
    $wrong=$pilot;$wrong['jobdiva_ids']['person']='candidatealpha';
    $reject(static fn()=>$apply($wrong,$expected,11,$audit),'source identity mismatch preserves every row');
    $reject(static fn()=>$apply(array_replace($pilot,['person_id'=>4]),$expected,11,$audit),
        'a different linked person cannot receive the correction');
    $reject(static fn()=>$apply(array_replace($pilot,['placement_id'=>5]),$expected,11,$audit),
        'another company placement cannot receive the correction');
    foreach(['["accounting_entity_id"]','{}'] as $overrides){
        $s=$pdo->prepare('UPDATE placements SET coreflux_overridden_fields=:v WHERE id=1');$s->execute(['v'=>$overrides]);
        $reject(static fn()=>$apply($pilot,$expected,11,$audit),'explicit or unreadable overrides hold the correction');
    }
    $pdo->exec('UPDATE placements SET coreflux_overridden_fields=NULL,accounting_entity_id=88 WHERE id=1');
    $reject(static fn()=>$apply($pilot,$expected,11,$audit),'an existing entity reference is never replaced');
    $pdo->exec('UPDATE placements SET accounting_entity_id=0 WHERE id=1');
    $pdo->exec('UPDATE accounting_entities SET active=0 WHERE id=77');
    $reject(static fn()=>$apply($pilot,$expected,11,$audit),'inactive employer cannot be inherited');
    $pdo->exec('UPDATE accounting_entities SET active=1 WHERE id=77');
    $pdo->exec("INSERT INTO accounting_entities VALUES(99,11,1,'SECOND','Second synthetic employer')");
    $reject(static fn()=>$apply($pilot,$expected,11,$audit),'ambiguous active employers require review');
    $pdo->exec('DELETE FROM accounting_entities WHERE id=99');
    $reject(static fn()=>$apply($pilot,$expected,11,static fn()=>false),'failed audit confirmation rolls back the placement update');
    $reject(static fn()=>$apply($pilot,$expected,11,static function($before,$after)use($audit){
        $audit($before,$after);throw new RuntimeException('simulated receipt failure');
    }),'failure after audit insertion rolls back both records');
    foreach(['UPDATE people SET entity_id=77 WHERE id=1','UPDATE placement_rates SET approved_at=NOW() WHERE id=1'] as $mutation){
        $reject(static fn()=>$apply($pilot,$expected,11,static function($before,$after)use($pdo,$audit,$mutation){
            $audit($before,$after);$pdo->exec($mutation);return true;
        }),'unexpected person or rate changes roll back with the entity and receipt');
    }
    $pdo->exec('ALTER TABLE audit_log ENGINE=MyISAM');
    $reject(static fn()=>$apply($pilot,$expected,11,$audit),'nontransactional audit storage is rejected before writes');
    $pdo->exec('DROP TABLE audit_log');
    $pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,user_id BIGINT NULL,
        action VARCHAR(100),entity_id BIGINT,entity VARCHAR(80),meta_json JSON,created_at DATETIME) ENGINE=InnoDB');
    $pdo->exec('UPDATE placements SET accounting_entity_id=NULL WHERE id=1');
    $result=$apply($pilot,$expected,11,$audit);$row=$pdo->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
    $meta=json_decode($row['meta_json'],true);
    $check($result['status']==='aligned'&&$row['action']==='placements.entity_inherited'&&$row['user_id']===null
        &&(int)$row['entity_id']===1&&$row['entity']==='placement'&&$meta['actor_type']==='system'
        &&$meta['before']['accounting_entity_id']===null,'legacy audit aliases preserve the same scoped system receipt');
    $pdo->exec('UPDATE placements SET accounting_entity_id=0 WHERE id=1');
    $pdo->exec('DROP TABLE audit_log');
    $pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT NOT NULL,
        actor_user_id BIGINT NULL,actor_type VARCHAR(40),event VARCHAR(100),target_id BIGINT,object_type VARCHAR(80),
        user_id BIGINT NULL,action VARCHAR(100) NOT NULL,entity_id BIGINT NOT NULL,entity VARCHAR(80) NOT NULL,
        meta_json JSON,created_at DATETIME) ENGINE=InnoDB');
    $result=$apply($pilot,$expected,11,$audit);$row=$pdo->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
    $check($result['status']==='aligned'&&$row['event']===$row['action']&&$row['target_id']===$row['entity_id']
        &&$row['object_type']===$row['entity']&&$row['actor_user_id']===null&&$row['user_id']===null,
        'hybrid live audit schema fills mandatory legacy aliases as well as canonical fields');
    $pdo->exec('UPDATE placements SET accounting_entity_id=0 WHERE id=1');
    $pdo->exec('DROP TABLE audit_log');
}
