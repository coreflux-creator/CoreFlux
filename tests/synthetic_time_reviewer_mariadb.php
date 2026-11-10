<?php
/** Real membership dual-write, permission, idempotency and rollback checks in a disposable database. */
declare(strict_types=1);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)
    &&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if (!$ci&&!$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/memberships.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/rbac/permissions.php';
require_once __DIR__.'/../core/rbac/legacy_map.php';
require_once __DIR__.'/../deploy/synthetic_time_reviewer.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$db='coreflux_reviewer_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);
$passed=0;
$check=static function(bool $ok,string $message)use(&$passed):void {if(!$ok)throw new RuntimeException($message);$passed++;echo "PASS $message\n";};
$fails=static function(callable $fn):bool {try{$fn();return false;}catch(RuntimeException $e){return true;}};
try {
    $pdo->exec('CREATE TABLE tenants(id INT PRIMARY KEY,is_active INT,is_simulation INT) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO tenants VALUES(999,1,1),(2,1,0)');
    $pdo->exec('CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY,tenant_id INT,name VARCHAR(255),email VARCHAR(255) UNIQUE,
        password VARCHAR(255),password_hash VARCHAR(255),role VARCHAR(40),is_active INT,is_global_admin INT,created_at DATETIME) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO users(id,tenant_id,name,email,role,is_active,is_global_admin) VALUES(4,999,'Existing operator','stage-admin@coreflux.test','admin',1,0)");
    $pdo->exec('CREATE TABLE tenant_memberships(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,tenant_id INT,persona_type VARCHAR(40),
        persona_label VARCHAR(80),is_primary INT,status VARCHAR(40),linked_entity_type VARCHAR(40),linked_entity_id INT,
        created_at DATETIME,updated_at DATETIME,last_active_at DATETIME,UNIQUE KEY(user_id,tenant_id,persona_label)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE user_tenants(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,tenant_id INT,role VARCHAR(40),is_default INT,
        status VARCHAR(40),created_at DATETIME,updated_at DATETIME,last_active_at DATETIME,UNIQUE KEY(user_id,tenant_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE membership_module_access(id INT AUTO_INCREMENT PRIMARY KEY,membership_id INT,module_key VARCHAR(60),
        access_level VARCHAR(20),sub_tenant_scope JSON,granted_by_user_id INT,granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY(membership_id,module_key)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE membership_audit(id INT AUTO_INCREMENT PRIMARY KEY,tenant_id INT,membership_id INT,action VARCHAR(60),
        actor_user_id INT,target_user_id INT,detail JSON,occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $_SESSION['user']=['id'=>4];
    $all=static function()use($pdo):array {
        $data=[];foreach(['users','tenant_memberships','user_tenants','membership_module_access','membership_audit'] as $table)
            $data[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return $data;
    };
    $before=$all();
    $pdo->exec("CREATE TRIGGER reject_reviewer_receipt BEFORE INSERT ON membership_audit FOR EACH ROW
        BEGIN IF NEW.action='synthetic_time_reviewer_20261025' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced receipt failure'; END IF; END");
    $check($fails(fn()=>syntheticTimeReviewerProvision($pdo))&&$all()===$before,'mandatory audit failure rolls back user, both memberships and grant');
    $pdo->exec('DROP TRIGGER reject_reviewer_receipt');
    $first=syntheticTimeReviewerProvision($pdo);$id=$first['user_id'];$mid=$first['membership_id'];
    $check($first['created']&&$id!==4&&$first['time_review_allowed_in_both_models'],'distinct reviewer receives native time-review permissions');
    $check($first['other_modules_denied']&&!$first['global_admin']&&!$first['credentials_issued']&&!$first['invitation_sent'],'no broad access or invitation issued');
    $check((int)$pdo->query('SELECT COUNT(*) FROM user_tenants WHERE user_id='.$id.' AND tenant_id=999 AND role="manager"')->fetchColumn()===1,'native helper provisions the legacy membership');
    $state=$all();$repeat=syntheticTimeReviewerProvision($pdo);
    $check(!$repeat['created']&&$repeat['user_id']===$id&&$all()===$state,'repeat returns the original reviewer without new grants or audit rows');
    $check($state['users'][0]===$before['users'][0],'existing operator remains unchanged');
    foreach ([
        ['UPDATE tenants SET is_simulation=0 WHERE id=999','UPDATE tenants SET is_simulation=1 WHERE id=999','ordinary workspace'],
        ['UPDATE users SET is_active=0 WHERE id='.$id,'UPDATE users SET is_active=1 WHERE id='.$id,'inactive reviewer'],
        ['UPDATE users SET is_global_admin=1 WHERE id='.$id,'UPDATE users SET is_global_admin=0 WHERE id='.$id,'global-admin drift'],
        ['UPDATE users SET role="admin" WHERE id='.$id,'UPDATE users SET role="manager" WHERE id='.$id,'role escalation'],
        ['UPDATE tenant_memberships SET tenant_id=2 WHERE id='.$mid,'UPDATE tenant_memberships SET tenant_id=999 WHERE id='.$mid,'foreign membership'],
        ['UPDATE membership_module_access SET access_level="read" WHERE membership_id='.$mid,'UPDATE membership_module_access SET access_level="admin" WHERE membership_id='.$mid,'insufficient time grant'],
        ['UPDATE membership_module_access SET sub_tenant_scope=NULL WHERE membership_id='.$mid,'UPDATE membership_module_access SET sub_tenant_scope="[999]" WHERE membership_id='.$mid,'widened branch scope'],
        ["UPDATE membership_audit SET actor_user_id=NULL WHERE action='synthetic_time_reviewer_20261025'","UPDATE membership_audit SET actor_user_id=4 WHERE action='synthetic_time_reviewer_20261025'",'missing authorized audit actor'],
    ] as [$mutate,$restore,$name]) {
        $pdo->exec($mutate);$state=$all();
        $check($fails(fn()=>syntheticTimeReviewerProvision($pdo))&&$all()===$state,$name.' is held without repairing or changing records');
        $pdo->exec($restore);
    }
    RBACResolver::grantModule($mid,'payroll','read',null,4);$state=$all();
    $check($fails(fn()=>syntheticTimeReviewerProvision($pdo))&&$all()===$state,'unexpected second module is held without silently rewriting grants');
    $pdo->exec('DELETE FROM membership_module_access WHERE membership_id='.$mid.' AND module_key="payroll"');
    $pdo->exec('INSERT INTO tenant_memberships(user_id,tenant_id,persona_type,persona_label,is_primary,status) VALUES('.$id.',2,"manager","Other",0,"active")');
    $state=$all();$check($fails(fn()=>syntheticTimeReviewerProvision($pdo))&&$all()===$state,'second company membership cannot be adopted');
    $pdo->exec('DELETE FROM tenant_memberships WHERE user_id='.$id.' AND tenant_id=2');
    $state=$all();
    $pdo->exec("CREATE TRIGGER reject_retirement_receipt BEFORE INSERT ON membership_audit FOR EACH ROW
        BEGIN IF NEW.action='synthetic_time_reviewer_retired' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced retirement receipt failure'; END IF; END");
    $check($fails(fn()=>syntheticTimeReviewerRetire($pdo,$id))&&$all()===$state,'retirement audit failure restores the original active account, membership and time grant');
    $pdo->exec('DROP TRIGGER reject_retirement_receipt');
    $retired=syntheticTimeReviewerRetire($pdo,$id);
    $check($retired['account_inactive']&&$retired['active_memberships']===0&&$retired['module_grants']===0,'retirement disables the test account and removes its active access');
    $check((int)$pdo->query('SELECT COUNT(*) FROM users WHERE id='.$id)->fetchColumn()===1
        &&(int)$pdo->query("SELECT COUNT(*) FROM membership_audit WHERE target_user_id=$id AND action='synthetic_time_reviewer_retired'")->fetchColumn()===1,
        'retirement preserves the original user identity and mandatory audit receipt');
    $state=$all();$check($fails(fn()=>syntheticTimeReviewerProvision($pdo))&&$all()===$state,'provisioning replay cannot reactivate a retired reviewer');
    $verified=syntheticTimeReviewerVerifyRetired($pdo,$id);
    $check($verified['account_inactive']&&$all()===$state,'retired-state verification is read-only');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id='.$id);$changed=$all();
    $check($fails(fn()=>syntheticTimeReviewerVerifyRetired($pdo,$id))&&$all()===$changed,'reactivated account cannot be reported as retired');
    $pdo->exec('UPDATE users SET is_active=0 WHERE id='.$id);
    $pdo->exec('INSERT INTO membership_module_access(membership_id,module_key,access_level) VALUES('.$mid.',"time","admin")');$changed=$all();
    $check($fails(fn()=>syntheticTimeReviewerVerifyRetired($pdo,$id))&&$all()===$changed,'leftover time grant cannot be reported as retired');
    echo "$passed synthetic reviewer integration checks passed\n";
} finally {
    if ($pdo->inTransaction())$pdo->rollBack();
    $pdo->exec('DROP DATABASE '.$db);unset($_SESSION['user']);
}
