<?php
/** Actual installed employment compensation on invented records, with complete transaction rollback. */
declare(strict_types=1);
const JR_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const JR_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==JR_ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(__DIR__)!==__DIR__||dirname(__DIR__)!==JR_PRIVATE||!str_starts_with(basename(__DIR__),'compensation-acceptance-')
    ||($argv[1]??'')!=='--synthetic-rollback'||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??''))exit(2);
$bytes=file_get_contents(__DIR__.'/manifest.json');if(hash('sha256',$bytes)!==$argv[2])exit(2);
$input=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
foreach($input['tools']as$name=>$hash){if(!in_array($name,['verify_thunderhawk_compensation.php','people_compensation_checks.php','thunderhawk_time_payroll_schema.php'],true)||is_link(__DIR__.'/'.$name)||hash_file('sha256',__DIR__.'/'.$name)!==$hash)exit(2);}
if(count($input['tools'])!==3||!str_starts_with($input['installation_directory'],JR_PRIVATE.'/compensation-')||realpath($input['installation_directory'])!==$input['installation_directory'])exit(2);
$directory=$input['installation_directory'];$packageBytes=file_get_contents($directory.'/compensation-application-package.json');
$installed=json_decode(file_get_contents($directory.'/installation-state.json'),true,512,JSON_THROW_ON_ERROR);
if($installed['phase']!=='completed'||$installed['candidate']!==$input['candidate']||$installed['package_sha256']!==$input['package_sha256']||hash('sha256',$packageBytes)!==$input['package_sha256'])exit(2);
$package=json_decode($packageBytes,true,512,JSON_THROW_ON_ERROR);
foreach($package['files']as$f=>$entry)if(is_link(JR_ROOT.'/'.$f)||hash_file('sha256',JR_ROOT.'/'.$f)!==$entry['sha256'])exit(2);
foreach($package['dependencies']as$f=>$hash)if(is_link(JR_ROOT.'/'.$f)||hash('sha256',str_replace("\r\n","\n",file_get_contents(JR_ROOT.'/'.$f)))!==$hash)exit(2);
if(hash_file('sha256',dirname(JR_ROOT).'/private_html/coreflux.thunderhawk-pilot.php')!==$package['feature_sha256'])exit(2);
require_once JR_ROOT.'/modules/people/lib/compensation.php';
require_once JR_ROOT.'/modules/people/lib/employees.php';
require_once JR_ROOT.'/core/integrations/identity.php';
require_once JR_ROOT.'/core/integrations/staffing_field_contract.php';
require_once JR_ROOT.'/core/migrate.php';
require_once __DIR__.'/thunderhawk_time_payroll_schema.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
if(is_file(__DIR__.'/result.json'))throw new RuntimeException('Acceptance already completed; read the retained result.');
$parent=1002011;$child=1002012;
if($pdo->query('SELECT id FROM tenants WHERE id IN(1002011,1002012)')->fetchColumn())throw new RuntimeException('Synthetic reference fixture IDs already exist.');
$baseline=thunderhawkTimePayrollTableState($pdo);$checks=[];$passed=false;$failure=null;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
$fails=static function(callable $fn,?int $code=null):bool{try{$fn();return false;}catch(Throwable $e){return$code===null||(int)$e->getCode()===$code;}};
$pdo->beginTransaction();
try{
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(1002011,"Synthetic compensation parent",1,1,"master")');
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type,parent_id) VALUES(1002012,"Synthetic compensation child",1,1,"sub",1002011)');
    $pdo->exec('INSERT INTO users(tenant_id,name,email,role,is_active,password,password_hash) VALUES(1002011,"Synthetic compensation actor","compensation-acceptance@example.invalid","admin",1,NULL,NULL)');$actor=(int)$pdo->lastInsertId();
    require __DIR__.'/people_compensation_checks.php';
    foreach(staffingCompensationFieldDefinitions()as$field=>$definition){
        $rule=staffingFieldContractRules('people_compensation',$field);
        $check($rule['owner']==='core_employment_compensation'&&$rule['definition_status']==='reviewed_core_definition','Installed compensation ownership: '.$field);
        foreach(['jobdiva','quanta','purepay','purepayroll']as$provider)$check($rule['remote_contracts'][$provider]['status']==='unsupported_with_gap','Dated compensation receiver gap remains explicit: '.$provider.'.'.$field);
    }
    $passed=true;
}catch(Throwable $e){$failure=$e->getMessage();}
finally{if($pdo->inTransaction())$pdo->rollBack();}
$preserved=thunderhawkTimePayrollTableState($pdo)===$baseline;
$result=['checked_at'=>gmdate('c'),'passed'=>$passed&&$preserved,'failure'=>$failure,'candidate'=>$input['candidate'],'checks'=>count($checks),'labels'=>$checks,
    'all_original_rows_preserved'=>$preserved,'synthetic_transaction_rolled_back'=>!$pdo->inTransaction(),'fixture_tenants_remaining'=>(int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE id IN(1002011,1002012)')->fetchColumn(),
    'provider_requests'=>0,'real_payroll_payments_or_postings'=>false,'production_changed'=>false,'arabella_changed'=>false];
$mask=umask(0077);$file=fopen(__DIR__.'/result.json','x+b');umask($mask);
if(!$file)throw new RuntimeException('Acceptance receipt already exists');$body=json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
if(fwrite($file,$body)!==strlen($body)||!fflush($file)||!fsync($file))throw new RuntimeException('Acceptance receipt could not be saved');fclose($file);
echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;exit($result['passed']?0:1);
