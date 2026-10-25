<?php
/** Exercise the native writer against canonical, legacy, and observed hybrid schemas. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)&&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$ci&&!$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/audit.php';
$password=$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$connect=static fn()=>new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$control=$connect();$db='coreflux_audit_shapes_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());
$control->exec('CREATE DATABASE '.$db);$control->exec('USE '.$db);
$passed=0;$connections=[];
try {
    $schemas=[
        'canonical'=>'actor_user_id BIGINT NULL,actor_type VARCHAR(40),event VARCHAR(100) NOT NULL,target_id BIGINT NOT NULL,object_type VARCHAR(80)',
        'legacy'=>'user_id BIGINT NULL,action VARCHAR(100) NOT NULL,entity_id BIGINT NOT NULL,entity VARCHAR(80) NOT NULL',
        'hybrid'=>'actor_user_id BIGINT NULL,actor_type VARCHAR(40),event VARCHAR(100) NOT NULL,target_id BIGINT NOT NULL,object_type VARCHAR(80),
            user_id BIGINT NULL,action VARCHAR(100) NOT NULL,entity_id BIGINT NOT NULL,entity VARCHAR(80) NOT NULL',
    ];
    foreach($schemas as $name=>$columns){
        $control->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT NOT NULL,'.$columns.',
            meta_json JSON,created_at DATETIME) ENGINE=InnoDB');
        // Preserve each connection identity so the native schema cache cannot collide.
        $pdo=$connect();$connections[]=$pdo;$pdo->exec('USE '.$db);
        $pdo->beginTransaction();
        platformAuditLogWrite(11,null,'placements.entity_inherited',1,['before'=>['accounting_entity_id'=>null],
            'after'=>['accounting_entity_id'=>77]],['object_type'=>'placement','actor_type'=>'system','source'=>'test']);
        $rows=$pdo->query('SELECT * FROM audit_log')->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1) throw new RuntimeException($name.' audit silently failed');
        $row=$rows[0];$meta=json_decode($row['meta_json'],true,512,JSON_THROW_ON_ERROR);
        foreach(['event','action'] as $key) if(array_key_exists($key,$row)&&$row[$key]!=='placements.entity_inherited') throw new RuntimeException('Event alias mismatch');
        foreach(['target_id','entity_id'] as $key) if(array_key_exists($key,$row)&&(int)$row[$key]!==1) throw new RuntimeException('Target alias mismatch');
        if((int)$row['tenant_id']!==11||$meta['before']['accounting_entity_id']!==null||$meta['after']['accounting_entity_id']!==77) throw new RuntimeException('Audit scope or evidence mismatch');
        $pdo->rollBack();
        if((int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()!==0) throw new RuntimeException('Audit transaction did not roll back');
        $passed++;echo "PASS native $name audit receipt and rollback\n";
        $control->exec('DROP TABLE audit_log');
    }
    echo "$passed native audit schema checks passed\n";
} finally {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    $control->exec('DROP DATABASE '.$db);
}
