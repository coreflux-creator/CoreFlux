<?php
/** Native HTTP harness restricted to the existing isolated synthetic lifecycle. */
declare(strict_types=1);
final class ThunderhawkLifecycleNative
{
    public const ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
    public const PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
    public PDO $pdo;
    public array $report;
    private array $before;
    private string $state;
    private ?int $actor=null;
    private ?int $membership=null;
    private ?string $cookie=null;
    private array $modules=[];
    private string $featureHash;
    private string $email;
    private string $phase;
    private array $contactBefore=[];

    public function __construct(string $phase)
    {
        if(!in_array($phase,['activation','activation_resume','publication','publication_resume'],true)||PHP_SAPI!=='cli'
            ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==self::ROOT||posix_geteuid()!==1003
            ||realpath(self::PRIVATE)!==self::PRIVATE||is_link(self::PRIVATE)||(fileperms(self::PRIVATE)&0777)!==0700)exit(2);
        $this->phase=$phase;$this->pdo=getDB();
        if(!COREFLUX_STAGING||$this->pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
            ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
            ||(int)$this->pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
        setRequestTenantId(2002);
        if(quantaTimeControlOwners(2002)!==['people'=>2002,'placements'=>2002,'companies'=>2002,'ap'=>2002])exit(2);
        $q=$this->pdo->query("SELECT COUNT(*) FROM placements p JOIN people pe ON pe.id=p.person_id AND pe.tenant_id=p.tenant_id
            WHERE p.tenant_id=2002 AND p.id=874 AND p.person_id=368 AND p.external_id='SYNTH-LIFECYCLE-20261111'
            AND pe.email_primary IN ('thunderhawk-lifecycle-20261111@example.invalid','thunderhawk-lifecycle-20261111@example.com')");
        if((int)$q->fetchColumn()!==1)exit(2);
        foreach(json_decode(file_get_contents(__DIR__.'/lifecycle-runtime-pins.json'),true,64,JSON_THROW_ON_ERROR) as $file=>$hash){
            $path=is_file(self::ROOT.'/'.$file)?self::ROOT.'/'.$file:self::ROOT.'/'.str_replace('Placements','placements',$file);
            if(hash('sha256',str_replace("\r\n","\n",file_get_contents($path)))!==$hash)throw new RuntimeException('Runtime changed: '.$file);
        }
        $this->state=self::PRIVATE.'/synthetic-lifecycle-'.$phase.'-20261111.json';
        if(file_exists($this->state)||is_link($this->state))throw new RuntimeException('Inspect the existing phase outcome; never repeat an uncertain request.');
        $this->email='core-lifecycle-'.$phase.'-20261111@example.invalid';
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');$q->execute([$this->email]);
        if((int)$q->fetchColumn())throw new RuntimeException('Reserved test actor exists');
        if($phase==='publication_resume')foreach(['people'=>368,'people_employees'=>5] as $table=>$id)
            $this->contactBefore[$table]=$this->pdo->query('SELECT * FROM '.$table.' WHERE tenant_id=2002 AND id='.$id)->fetch(PDO::FETCH_ASSOC);
        $this->before=$this->snapshot();
        $this->featureHash=hash_file('sha256',dirname(self::ROOT).'/private_html/coreflux.thunderhawk-pilot.php');
        $this->report=['checked_at'=>gmdate('c'),'phase'=>$phase,'step'=>'prepared','passed'=>false,
            'tenant_id'=>2002,'person_id'=>368,'person_number'=>'P-368','placement_id'=>874,'placement_number'=>'PL-874',
            'synthetic'=>true,'production_changed'=>false,'arabella_changed'=>false,'real_payroll_payments_postings'=>false,
            'browser_acceptance'=>false,'requests'=>[],'checks'=>[]];
        $old=umask(0077);try{
            $f=fopen($this->state,'x');if(!$f)throw new RuntimeException('Cannot claim phase');fclose($f);
            file_put_contents(self::PRIVATE.'/synthetic-lifecycle-'.$phase.'-before.private.json',json_encode($this->before,JSON_THROW_ON_ERROR),LOCK_EX);
        }finally{umask($old);}
        $this->checkpoint('prepared');
    }
    public function checkpoint(string $step):void
    {
        $this->report['step']=$step;
        if(file_put_contents($this->state,json_encode($this->report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Checkpoint failed');
    }
    public function expect(bool $ok,string $message):void
    {
        if(!$ok){$this->report['failed_check']=$message;throw new RuntimeException($message);}
        $this->report['checks'][]=$message;
    }
    public function actor(array $modules):int
    {
        $this->modules=array_keys($modules);$password=bin2hex(random_bytes(32));$hash=password_hash($password,PASSWORD_DEFAULT);
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO users(tenant_id,name,email,password,password_hash,role,is_active,is_global_admin) VALUES(2002,"Synthetic lifecycle operator",?,?,?,"admin",1,0)')->execute([$this->email,$hash,$hash]);
            $id=(int)$this->pdo->lastInsertId();
            $membership=(int)provisionMembership($id,2002,'admin',['is_primary'=>true,'persona_label'=>'Synthetic lifecycle operator','status'=>'active'])['membership_id'];
            $this->pdo->commit();$this->actor=$id;$this->membership=$membership;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->report['operator_id']=$id;$this->report['operator_membership_id']=$membership;$this->checkpoint('operator_created');
        foreach($modules as $module=>$level)RBACResolver::grantModule($membership,$module,$level,[2002],4);
        $this->cookie=tempnam(self::PRIVATE,'lifecycle-cookie-');if(!$this->cookie||!chmod($this->cookie,0600))throw new RuntimeException('Private session unavailable');
        [$status,,$location]=$this->request('/login.php',['username'=>$this->email,'password'=>$password,'redirect'=>'spa'],'POST',2002,true);unset($password,$hash);
        $this->expect($status===302&&!str_contains($location,'error='),'Native operator login succeeded');
        $this->expect($this->request('/api/placements/placements?id=864',null,'GET',2)[0]===403,'Real Thunderhawk access denied');
        return $id;
    }
    public function request(string $path,?array $body=null,string $method='GET',int $tenant=2002,bool $form=false):array
    {
        $url=parse_url($path);$route=$url['path']??'';parse_str($url['query']??'',$query);
        $routes=['/login.php','/logout.php','/api/people/people','/api/people/employees','/api/placements/placements','/api/placements/rates',
            '/api/payroll/profiles','/api/payroll/pay_schedules','/api/payroll/cycles','/api/quanta.php','/api/purepay_core.php'];
        if(!in_array($route,$routes,true)||!in_array($method,['GET','POST','PATCH'],true)||!in_array($tenant,[2002,2],true))throw new RuntimeException('Request outside fixture scope');
        if($route==='/api/payroll/cycles'&&isset($query['action']))throw new RuntimeException('Cycle advancement is outside this test');
        if($route==='/api/placements/placements'&&isset($query['action'])&&$query['action']!=='activate')throw new RuntimeException('Unreviewed placement action');
        if($route==='/api/quanta.php'&&!in_array($query['action']??'', ['publication_preview','publication_send','publication_status','metadata_preview','metadata_send','metadata_receipt'],true))throw new RuntimeException('Unreviewed Quanta action');
        if($route==='/api/purepay_core.php'&&!in_array($query['action']??'', ['people_input_preview','people_input_send','people_input_status','assignment_input_preview','assignment_input_send','assignment_input_status'],true))throw new RuntimeException('Unreviewed Pure action');
        $ch=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com'.$path);
        $headers=['Accept: application/json','X-CoreFlux-Tenant-Id: '.$tenant];
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CUSTOMREQUEST=>$method];
        if($body!==null){$options[CURLOPT_POSTFIELDS]=$form?http_build_query($body):json_encode((object)$body,JSON_THROW_ON_ERROR);$headers[]='Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json');}
        if($this->cookie!==null){$options[CURLOPT_COOKIEFILE]=$this->cookie;$options[CURLOPT_COOKIEJAR]=$this->cookie;}
        $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$location=(string)curl_getinfo($ch,CURLINFO_REDIRECT_URL);curl_close($ch);
        $this->report['requests'][]=['path'=>$path,'method'=>$method,'tenant'=>$tenant,'status'=>$status];
        if($raw===false)throw new RuntimeException('Native request outcome uncertain; inspect receipt before continuation');
        if($status>=400){$old=umask(0077);try{file_put_contents(self::PRIVATE.'/synthetic-lifecycle-last-response.private.txt',$raw);}finally{umask($old);}}
        return [$status,json_decode($raw,true),$location];
    }
    private function snapshot():array
    {
        $result=[];
        foreach($this->pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
            $rows=[];$q=$this->pdo->query('SELECT * FROM `'.$table.'`');
            while($row=$q->fetch(PDO::FETCH_ASSOC)){
                if($this->phase==='publication_resume'&&(int)($row['tenant_id']??0)===2002
                    &&(($table==='people'&&(int)$row['id']===368)||($table==='people_employees'&&(int)$row['id']===5)))continue;
                // Only these known synthetic rows may be edited during setup.
                if((int)($row['tenant_id']??0)===2002&&(($table==='placements'&&(int)$row['id']===874)
                    ||($table==='placement_rates'&&(int)$row['id']===3)
                    ||($table==='placement_economic_parties'&&(int)$row['placement_id']===874)))continue;
                $rows[]=hash('sha256',serialize($row));
            }
            sort($rows,SORT_STRING);$result[$table]=$rows;
        }
        ksort($result);return $result;
    }
    public function finish(?Throwable $error,array $allowedAdditions):never
    {
        if($error){$this->report['failure_class']=get_class($error);$this->report['failure_line']=$error->getLine();}
        $this->report['passed']=$error===null;
        try{
            foreach($this->contactBefore as $table=>$prior){
                $now=$this->pdo->query('SELECT * FROM '.$table.' WHERE tenant_id=2002 AND id='.(int)$prior['id'])->fetch(PDO::FETCH_ASSOC);
                $field=$table==='people'?'email_primary':'work_email';
                $this->expect(in_array($now[$field],['thunderhawk-lifecycle-20261111@example.invalid','thunderhawk-lifecycle-20261111@example.com'],true),'Only the reviewed synthetic contact is allowed');
                unset($prior[$field],$prior['updated_at'],$now[$field],$now['updated_at']);
                $this->expect($now===$prior,'All other synthetic contact fields preserved in '.$table);
            }
            if($this->actor!==null){
                foreach($this->modules as $module)RBACResolver::revokeModule($this->membership,$module,4);
                deactivateMembership($this->actor,2002);
                $this->pdo->prepare('UPDATE users SET is_active=0,password=NULL,password_hash=NULL WHERE tenant_id=2002 AND id=? AND email=?')->execute([$this->actor,$this->email]);
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$q->execute([$this->membership]);
                $this->expect((int)$q->fetchColumn()===0,'Temporary module grants removed');
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id=2002 AND id=? AND is_active=0 AND password IS NULL AND password_hash IS NULL');$q->execute([$this->actor]);
                $this->expect((int)$q->fetchColumn()===1,'Temporary operator retired');$this->report['operator_retired']=true;
            }
            if($this->cookie!==null&&is_file($this->cookie)){
                $this->request('/logout.php');$this->expect(unlink($this->cookie),'Private session removed');$this->report['private_session_removed']=true;
            }
            $after=$this->snapshot();$this->expect(array_keys($after)===array_keys($this->before),'Schema unchanged');$changed=[];$preserved=0;
            $allowed=array_merge($allowedAdditions,['users','tenant_memberships','user_tenants','membership_audit','audit_log']);
            foreach($this->before as $table=>$hashes){
                $preserved+=count($hashes);if($hashes===$after[$table])continue;
                $this->expect(in_array($table,$allowed,true),'Only reviewed metadata additions in '.$table);
                $counts=array_count_values($after[$table]);foreach(array_count_values($hashes) as $hash=>$count)$this->expect(($counts[$hash]??0)>=$count,'Original record preserved in '.$table);
                $changed[$table]=['before'=>count($hashes),'after'=>count($after[$table])];
            }
            $this->report['original_rows_preserved']=$preserved;$this->report['tables_verified']=count($after);$this->report['added_record_tables']=$changed;
            $this->expect(hash_file('sha256',dirname(self::ROOT).'/private_html/coreflux.thunderhawk-pilot.php')===$this->featureHash,'Operational switches unchanged');
        }catch(Throwable $e){$this->report['passed']=false;$this->report['cleanup_or_preservation_failure']=get_class($e);$this->report['cleanup_or_preservation_line']=$e->getLine();}
        $this->checkpoint($this->report['passed']?'complete':$this->report['step']);
        echo json_encode($this->report,JSON_THROW_ON_ERROR)."\n";exit($this->report['passed']?0:1);
    }
}
// Bootstrap at file scope: Core's configuration globals must not be captured
// inside a constructor's local include scope.
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==ThunderhawkLifecycleNative::ROOT||posix_geteuid()!==1003)exit(2);
require_once ThunderhawkLifecycleNative::ROOT.'/core/quanta/time_control_delivery.php';
require_once ThunderhawkLifecycleNative::ROOT.'/core/memberships.php';
require_once ThunderhawkLifecycleNative::ROOT.'/core/rbac/permissions.php';
