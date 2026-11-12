<?php
/** Exercise real resolver queries against a disposable SQL schema. */
declare(strict_types=1);
$sqlite=in_array('--sqlite',$argv,true);
$ci=getenv('GITHUB_ACTIONS')==='true'&&getenv('CI')==='true';
$local=getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE')==='1'&&in_array('--confirm-disposable-fixture',$argv,true)
    &&(string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD')!=='';
if(!$sqlite&&!$ci&&!$local)throw new RuntimeException('Explicit disposable fixture required');
$pdo=$sqlite?new PDO('sqlite::memory:'):new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$ci?'root':getenv('COREFLUX_DELIVERY_TEST_PASSWORD'));
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$GLOBALS['pdo']=$pdo;
$db='coreflux_rbac_scope_'.($ci?'ci':gmdate('YmdHis').'_'.getmypid());
if(!$sqlite){$pdo->exec('CREATE DATABASE '.$db);$pdo->exec('USE '.$db);}
function getDB():PDO{return $GLOBALS['pdo'];}
$source=file_get_contents(__DIR__.'/../core/rbac/permissions.php');
$source=str_replace("require_once __DIR__ . '/../db.php';",'',$source);
eval(preg_replace('/^\s*<\?php/','',$source));
$passed=0;
$check=static function(bool$ok,string$name)use(&$passed):void{if(!$ok)throw new RuntimeException($name);$passed++;echo "PASS $name\n";};
try{
 $pdo->exec('CREATE TABLE users(id INT PRIMARY KEY,tenant_id INT,role VARCHAR(40),is_active INT,is_global_admin INT DEFAULT 0)');
 $pdo->exec('CREATE TABLE user_tenants(user_id INT,tenant_id INT,role VARCHAR(40),status VARCHAR(20))');
 $pdo->exec('CREATE TABLE tenant_memberships(id INT PRIMARY KEY,user_id INT,tenant_id INT,persona_label VARCHAR(40),persona_type VARCHAR(40),linked_entity_type VARCHAR(40),linked_entity_id INT,is_primary INT,status VARCHAR(20),last_active_at VARCHAR(40))');
 $pdo->exec('CREATE TABLE membership_module_access(id INT PRIMARY KEY,membership_id INT,module_key VARCHAR(40),access_level VARCHAR(20),sub_tenant_scope VARCHAR(200))');
 $pdo->exec("INSERT INTO users(id,tenant_id,role,is_active) VALUES(1,101,'admin',1),(2,101,'employee',1),(3,101,'admin',0),(4,101,'tenant_admin',1),(5,101,'manager',1),(6,101,'employee',1),(7,101,'admin',1),(8,101,'admin',1)");
 $pdo->exec("INSERT INTO user_tenants VALUES(1,102,'manager','active'),(3,102,'admin','active'),(4,101,'tenant_admin','inactive'),(5,101,'manager','active'),(7,101,'admin','active')");
 $pdo->exec("INSERT INTO tenant_memberships(id,user_id,tenant_id,persona_type,is_primary,status) VALUES(5,5,101,'manager',1,'inactive'),(7,7,101,'admin',1,'pending'),(8,8,101,'admin',1,'active')");
 $can=static fn(int$u,int$t,string$a='read')=>RBACResolver::can($u,$t,'payroll',$a);
 $check($can(1,101,'write'),'unmigrated home-company admin remains usable');
 $check($can(2,101)&&!$can(2,101,'write'),'home-company employee retains read-only legacy access');
 $check($can(1,102,'write'),'explicit active legacy company membership remains usable');
 foreach([1,2,4,5,7,8]as$u)$check(!$can($u,103)&&!$can($u,103,'write'),'foreign company denied for user '.$u);
 $check(!$can(999,101),'unknown user cannot acquire default read access');
 $check(!$can(3,101)&&!$can(3,102),'disabled legacy user denied even with active legacy membership');
 $check(!$can(4,101),'inactive legacy membership overrides home-company role');
 $check(!$can(5,101),'revoked modern membership cannot fall through to older active grant');
 $check(!$can(7,101),'pending modern membership cannot fall through to older active grant');
 $check(!$can(8,101),'active modern membership without module grant does not use legacy role');
 $pdo->exec("INSERT INTO membership_module_access VALUES(8,8,'payroll','read','[101]')");RBACResolver::resetCache();
 $check($can(8,101)&&!$can(8,101,'write'),'explicit modern module grant remains scoped');
 $check(!RBACResolver::can(1,101,'payroll','write',null,999),'missing selected persona cannot use home-company fallback');
 $check(RBACResolver::personaTypeOf(1,101,999)==='','missing selected persona has no legacy role');
 $check(!RBACResolver::can(8,101,'payroll','read',null,7),'foreign selected persona cannot use another active membership');
 $check(RBACResolver::legacyRole(8,101)==='','modern membership never acquires additional legacy grants');
 $pdo->exec('UPDATE users SET is_global_admin=1 WHERE id=6');
 $check($can(6,103,'admin'),'explicit global administrator retains global access');
 $pdo->exec('UPDATE users SET is_global_admin=0 WHERE id=6');
 $pdo->exec('DROP TABLE tenant_memberships');$pdo->exec('DROP TABLE user_tenants');RBACResolver::resetCache();
 if(!$sqlite){$check($can(1,101,'write'),'pre-migration missing tables permit verified home role');$check(!$can(1,103),'pre-migration missing tables never permit foreign role');}
 else $check(!$can(1,103),'unavailable membership schema cannot permit foreign read');
 $pdo->exec('CREATE TABLE tenant_memberships(wrong_column INT)');RBACResolver::resetCache();
 $check(!$can(1,101),'broken membership query fails closed rather than granting home role');
 $pdo->exec('DROP TABLE users');RBACResolver::resetCache();
 $check(!$can(1,101),'unavailable user evidence fails closed');
 echo json_encode(['passed'=>$passed,'database'=>$sqlite?'sqlite':'mariadb'])."\n";
}finally{if(!$sqlite)$pdo->exec('DROP DATABASE '.$db);}
