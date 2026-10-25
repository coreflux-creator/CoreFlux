<?php
/** Existing service-account reads with an ephemeral session; no connection/cache writes. */
declare(strict_types=1);
require_once __DIR__ . '/jobdiva_pilot_source.php';
require_once __DIR__ . '/staffing_pilot_alignment.php';
require_once __DIR__ . '/staffing_pilot_commercial_alignment.php';

function fullCoreJobdivaFreshPilot(PDO $pdo, array $pilot, string $liveRoot): array
{
    $report = ['status'=>'identity_not_verified','source_refreshed'=>false,'record_writes'=>false,
        'connection_settings_changed'=>false,'session_token_persisted'=>false];
    $identity = fullCoreStaffingPilotInventory($pdo,$pilot);
    if ($identity['status'] !== 'observed') return $report;
    foreach (['person','placement','job'] as $entity) {
        if (($identity['jobdiva_identity'][$entity]['status'] ?? null) !== 'exact_match') return $report;
    }
    require_once $liveRoot . '/core/jobdiva/client.php';
    if (JOBDIVA_BASE_URL !== 'https://api.jobdiva.com' || JOBDIVA_AUTH_PATH !== '/apiv2/v2/authenticate') {
        return array_replace($report,['status'=>'unreviewed_provider_destination']);
    }
    $s=$pdo->prepare('SELECT client_id,username,password_enc,session_token_enc,session_token_exp,status
        FROM jobdiva_connections WHERE tenant_id=:t');
    $s->execute(['t'=>$pilot['tenant_id']]); $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1 || $rows[0]['status']==='disconnected') return array_replace($report,['status'=>'connection_unavailable']);
    $connection=$rows[0]; $token=''; $authCount=0; $authStatus=null;
    try {
        if (!empty($connection['session_token_enc']) && strtotime((string)$connection['session_token_exp']) > time()+60) {
            $token=(string)decryptField((string)$connection['session_token_enc']);
        }
        $authenticate=static function()use(&$token,&$authCount,&$authStatus,$connection):void {
            if ($authCount >= 1) throw new RuntimeException('One authentication attempt per read-only inspection');
            $authCount++;
            $password=decryptField((string)$connection['password_enc']);
            if (!$password || !$connection['client_id'] || !$connection['username']) throw new RuntimeException('Stored credentials unavailable');
            $response=jobdivaRawRequest('GET',JOBDIVA_AUTH_PATH,null,[
                'clientid'=>(string)$connection['client_id'],'username'=>(string)$connection['username'],'password'=>$password],false);
            unset($password);
            $authStatus=(int)($response['status']??0);
            $token=$authStatus===200?jobdivaExtractToken($response):'';
            unset($response);
            if ($token==='') throw new RuntimeException('Provider authentication unavailable');
        };
        if ($token==='') $authenticate();
        $request=static function(string $method,string $path,?array $body,?array $query)use(&$token,$authenticate,&$authCount):array {
            if (!(($method==='POST' && in_array($path,['/apiv2/jobdiva/searchStart','/apiv2/jobdiva/searchCandidate'],true))
                || ($method==='GET' && $path==='/apiv2/bi/EmployeeAssignmentRecordsDetail'))) {
                throw new RuntimeException('Inspection endpoint is not allowlisted');
            }
            $response=jobdivaRawRequest($method,$path,$body,$query,true,$token);
            if (($response['status']??0)===401 && $authCount===0) {
                $authenticate();
                $response=jobdivaRawRequest($method,$path,$body,$query,true,$token);
            }
            return $response;
        };
        $observed=fullCoreJobdivaReadPilot($pilot['jobdiva_ids'],$request);
        $report=array_replace($report,$observed['report']);
        if ($observed['contract'] !== null) {
            $alignment=fullCoreStaffingPilotAlignment($pdo,$pilot,['_jd_contract'=>$observed['contract']]);
            foreach ($alignment['fields'] ?? [] as $field=>$status) {
                $alignment['fields'][$field]=str_replace('_stored_source','_fresh_source',$status);
            }
            $alignment['source_refreshed']=true;
            $alignment['scope']='Nine current Core placement dimensions compared with a fresh exact JobDiva assignment contract.';
            $alignment['limits']=['Tenant mappings and Core overrides can intentionally differ; no overwrite is proposed.',
                'Employer, rates, custom-field values and downstream delivery require separate evidence.'];
            $report['alignment']=$alignment;
            $report['commercial_alignment']=fullCoreStaffingPilotCommercialAlignment($pdo,$pilot,$observed['contract'],gmdate('Y-m-d'));
            $person=fullCoreJobdivaReadPerson($pilot['jobdiva_ids']['person'],$request);
            $report['person_source']=$person['report'];
            if($person['person']!==null)$report['person_alignment']=fullCoreStaffingPilotPersonAlignment($pdo,$pilot,$person['person']);
            unset($person);
            $report['employment_setup_source_presence']=[
                'workers_comp_class'=>fullCorePilotAlignedValue($observed['contract']['workers_comp_class']??null,'workers_comp_class')!==null,
                'branch'=>fullCorePilotAlignedValue($observed['contract']['branch']??null,'branch')!==null,
                'job_division'=>fullCorePilotAlignedValue($observed['contract']['job_division']??null,'branch')!==null,
            ];
        }
    } catch (Throwable $e) {
        // Provider bodies, request URLs and exception messages may contain secrets or worker data.
        $report['status']='authentication_or_transport_unavailable';
    } finally {
        unset($token,$connection,$rows,$observed);
    }
    $report['authentication_attempts']=$authCount;
    $report['authentication_http_status']=$authStatus;
    return $report;
}
