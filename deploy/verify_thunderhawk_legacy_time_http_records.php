<?php
/** Read-only follow-up: attribute every appended row to the completed native review. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
$private=dirname($root).'/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--verify-native-legacy-time-records'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==$root||realpath($private)!==$private||posix_geteuid()!==1003)exit(2);
$http=json_decode(file_get_contents($private.'/legacy-time-native-http-result.json'),true,64,JSON_THROW_ON_ERROR);
if(($http['failed_check']??null)!=='Only authorized review or synthetic access tables changed: rbac_bridge_audit'
    ||($http['reviewed_entries']??null)!==12||!$http['synthetic_actor_retired']||!$http['private_session_removed'])exit(2);
$actor=$http['synthetic_actor_id'];if(!is_int($actor)||$actor<=0)exit(2);
$identity=json_decode(file_get_contents($private.'/legacy-http-actor-'.$actor.'.private.json'),true,32,JSON_THROW_ON_ERROR);
if($identity['user_id']!==$actor||$identity['tenant_id']!==2002||!$identity['retired'])exit(2);$mid=$identity['membership_id'];
$before=json_decode(file_get_contents($private.'/legacy-http-before-'.$actor.'.private.json'),true,64,JSON_THROW_ON_ERROR);
require_once $root.'/core/quanta/legacy_time_delivery.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')exit(2);
$package=json_decode(file_get_contents(__DIR__.'/core-legacy-time-package.json'),true,64,JSON_THROW_ON_ERROR);
$expect=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$newRows=[];$changed=[];$same=0;$pdo->exec('START TRANSACTION READ ONLY');
try{
    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);sort($tables,SORT_STRING);
    $expect($tables===array_keys($before),'Table set changed');
    foreach($tables as $table){
        $expect((bool)preg_match('/^[A-Za-z0-9_]+$/D',$table),'Unexpected table');
        $original=array_count_values($before[$table]);$hashes=[];$extra=[];$q=$pdo->query('SELECT * FROM `'.$table.'`');
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            $hash=hash('sha256',serialize($row));$hashes[]=$hash;
            if(($original[$hash]??0)>0)$original[$hash]--;else $extra[]=$row;
        }
        $expect(array_sum($original)===0,'An existing row changed or disappeared: '.$table);
        if(!$extra){$same++;continue;}
        $changed[$table]=['before'=>count($before[$table]),'after'=>count($hashes),'appended'=>count($extra)];
        foreach($extra as $row){
            $valid=match($table){
                'users'=>(int)$row['id']===$actor&&(int)$row['tenant_id']===2002&&(int)$row['is_active']===0
                    &&(int)$row['is_global_admin']===0&&$row['password']===null&&$row['password_hash']===null,
                'tenant_memberships'=>(int)$row['id']===$mid&&(int)$row['user_id']===$actor&&(int)$row['tenant_id']===2002&&$row['status']==='revoked',
                'user_tenants'=>(int)$row['user_id']===$actor&&(int)$row['tenant_id']===2002&&$row['status']==='inactive',
                'membership_audit'=>(int)$row['target_user_id']===$actor&&(int)$row['tenant_id']===2002&&(int)$row['membership_id']===$mid,
                'rbac_bridge_audit'=>(int)$row['user_id']===$actor&&(int)$row['tenant_id']===2002
                    &&(int)$row['legacy_ok']===1&&(int)$row['new_ok']===0
                    &&in_array($row['perm'],['integrations.quanta.manage','people.view','placements.view','time.view','time.review'],true),
                'audit_log'=>(int)($row['actor_user_id']??$row['user_id']??0)===$actor&&(int)$row['tenant_id']===2002,
                'integration_deliveries'=>in_array((int)$row['id'],$http['local_preview_delivery_ids'],true)&&(int)$row['tenant_id']===2002
                    &&(int)$row['created_by']===$actor&&$row['provider']==='quanta'&&$row['operation']==='time.legacy'
                    &&$row['state']==='preview'&&$row['attempted_at']===null&&$row['reviewed_by']===null&&$row['approved_at']===null
                    &&$row['remote_id']===null&&$row['remote_receipt_id']===null,
                default=>false,
            };
            $expect($valid,'Appended row is not attributable to the authorized review: '.$table);
        }
        $newRows[$table]=$extra;
    }
    $expect(count($newRows['integration_deliveries']??[])===12,'Expected twelve local reviews');
    $expect(count($newRows['users']??[])===1&&count($newRows['tenant_memberships']??[])===1&&count($newRows['user_tenants']??[])===1,'Expected one retired synthetic reviewer');
    $q=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$mid]);
    $expect((int)$q->fetchColumn()===0,'Synthetic permission grants remain');
    $entries=[];
    foreach($newRows['integration_deliveries'] as $row){
        $payload=json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        $entries[]=$payload['body']['source_entry_id'];
        $expect($payload['body']['expected_version']===0&&$payload['expected']['proof']['review_publication']['core_tenant_id']===2002,'Original Core proof changed');
    }
    sort($entries);$expected=$pdo->query('SELECT DISTINCT quanta_entry_id FROM quanta_time_imports WHERE tenant_id=2002 ORDER BY quanta_entry_id')->fetchAll(PDO::FETCH_COLUMN);
    $expect($entries===$expected,'Local previews do not cover the exact original imports');
    foreach([2002,2,999,2003] as $tenant)$expect(!quantaTimeControlEnabled($tenant),'Operational activation changed');
    $expect(hash_file('sha256',dirname($root).'/private_html/coreflux.thunderhawk-pilot.php')===$package['feature_sha256'],'Feature configuration changed');
    $report=['passed'=>true,'checked_at'=>gmdate('c'),'original_http_result_preserved'=>true,'original_rows_unchanged'=>true,
        'unchanged_tables'=>$same,'appended_rows'=>$changed,'synthetic_reviewer_retired'=>true,'synthetic_grants_removed'=>true,
        'local_unattempted_previews'=>12,'original_imports_exactly_covered'=>true,'permission_denial_audit_rows'=>count($newRows['rbac_bridge_audit']??[]),
        'provider_writes'=>false,'operational_policy_enabled'=>false,'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false];
}finally{$pdo->rollBack();}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
