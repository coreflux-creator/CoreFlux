<?php
/** Explicit user-authorized simulation reviewer; never a production account or human sign-off. */
declare(strict_types=1);
const REVIEWER_PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if (PHP_SAPI!=='cli' || ($argv[1]??'')!=='--create-demo-time-reviewer' || getenv('COREFLUX_ENV')!=='staging'
    || realpath(getcwd())!==REVIEWER_PILOT_ROOT) {
    fwrite(STDERR,"The exact isolated pilot and explicit reviewer mode are required.\n");exit(2);
}
foreach (['core/memberships.php','core/RBAC.php','core/rbac_config.php','core/rbac/permissions.php','core/rbac/legacy_map.php'] as $file) {
    $reviewed=dirname(__DIR__).'/'.$file;$installed=REVIEWER_PILOT_ROOT.'/'.$file;
    if (!is_file($reviewed)||!is_file($installed)||realpath($reviewed)===realpath($installed)
        || !hash_equals(hash_file('sha256',$reviewed),hash_file('sha256',$installed))) {
        fwrite(STDERR,"Installed permission services differ from reviewed source.\n");exit(2);
    }
}
require_once REVIEWER_PILOT_ROOT.'/core/db.php';
$pdo=getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true) {
    fwrite(STDERR,"Isolated staging database required.\n");exit(2);
}
require_once REVIEWER_PILOT_ROOT.'/core/memberships.php';
require_once REVIEWER_PILOT_ROOT.'/core/RBAC.php';
require_once REVIEWER_PILOT_ROOT.'/core/rbac/permissions.php';
require_once REVIEWER_PILOT_ROOT.'/core/rbac/legacy_map.php';
require_once REVIEWER_PILOT_ROOT.'/core/integrations/identity.php';
require_once __DIR__.'/synthetic_time_reviewer.php';
$report=['checked_at'=>gmdate('c'),'mode'=>'create_synthetic_time_reviewer','production_changed'=>false,
    'time_approved'=>false,'payroll_or_financial_writes'=>false,'independent_human_review'=>false];
try {
    if (integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf') throw new RuntimeException('Pilot instance differs');
    $snapshot=static function()use($pdo):string {
        $data=[];
        foreach (['time_entries','staffing_timesheets','people','placements','quanta_time_imports'] as $table)
            $data[$table]=$pdo->query('SELECT * FROM '.$table.' WHERE tenant_id=999 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $data['operator']=$pdo->query('SELECT * FROM users WHERE id=4')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    };
    $before=$snapshot();$_SESSION['user']=['id'=>4];
    $report+=syntheticTimeReviewerProvision($pdo);
    $report['existing_time_identity_and_operator_unchanged']=hash_equals($before,$snapshot());
    if (!$report['existing_time_identity_and_operator_unchanged']) throw new RuntimeException('Existing pilot records changed');
    $st=$pdo->prepare('SELECT COUNT(*) FROM time_entries WHERE tenant_id=999 AND person_id=15 AND placement_id=13 AND created_by_user_id=?');
    $st->execute([$report['user_id']]);
    if ((int)$st->fetchColumn()!==0) throw new RuntimeException('Reviewer is an entry creator');
    $report['distinct_from_pilot_entry_creators']=true;$report['passed']=true;
} catch(Throwable $e) {
    $report['passed']=false;$report['failure_class']=get_class($e);
} finally { unset($_SESSION['user']); }
echo 'CORE_PILOT_TEST_APPROVER='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
