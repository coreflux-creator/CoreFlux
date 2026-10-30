<?php
/** CLI-only synthetic receipt fixture; never originates a payment or posts cash. */
declare(strict_types=1);
const CALLBACK_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CALLBACK_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const CALLBACK_BASE='https://pure-pay-support.preview.emergentagent.com/api/v1';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||realpath(getcwd())!==CALLBACK_ROOT||getenv('COREFLUX_ENV')!=='staging'
    ||!in_array($mode,['inspect','seed','verify'],true)||!preg_match('#^/tmp/core-demo-callback-[a-f0-9]{12}$#',__DIR__)){
    fwrite(STDERR,"Exact isolated fixture location and mode required\n");exit(2);
}
require_once __DIR__.'/purepay_demo_callback_contract.php';
umask(0077);
$pdo=null;$locked=false;$phase='source';
try{
    $manifestPath=__DIR__.'/native-source.json';
    if(!is_string($argv[2]??null)||!hash_equals($argv[2],hash_file('sha256',$manifestPath)))throw new RuntimeException('Source manifest changed');
    $manifest=json_decode(file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
    foreach(['core/db.php','core/purepay_service.php','core/purepay_webhooks.php','core/purepay_adapter.php',
        'core/purepay_financial_binding.php','core/purepay_payment_evidence.php','modules/ap/lib/ap.php','api/webhooks/purepay.php']as$required){
        if(!isset($manifest[$required]))throw new RuntimeException('Missing native source pin');
    }
    foreach($manifest as$name=>$digest){
        if(!is_string($name)||!preg_match('#^(core|modules/ap/lib|api/webhooks)/[a-zA-Z0-9_./-]+\.php$#D',$name)
            ||str_contains($name,'..')||!hash_equals($digest,hash_file('sha256',CALLBACK_ROOT.'/'.$name)))throw new RuntimeException('Native source changed');
    }
    foreach(['core/db.php','core/purepay_service.php','core/purepay_webhooks.php','core/integrations/identity.php',
             'core/sub_tenants.php','core/audit.php','core/RBAC.php','core/rbac/permissions.php','core/rbac/legacy_map.php']as$file)require_once CALLBACK_ROOT.'/'.$file;
    $pdo=getDB();$phase='environment';
    if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true
        ||integrationInstanceId()!==CALLBACK_INSTANCE||purepayApiBase()!==CALLBACK_BASE
        ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn()!==1
        ||effectiveTenantIdForModule('ap',999)!==999)throw new RuntimeException('Wrong fixture environment');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');$pdo->exec('SET SESSION max_statement_time=10');
    $operator=$pdo->query("SELECT id,role FROM users WHERE id=4 AND is_active=1 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    if(!$operator||(RBACResolver::activeMembership(4,999)['status']??null)!=='active')throw new RuntimeException('Operator unavailable');
    $operator['global_role']=$operator['role'];$operator['role']=RBACResolver::personaTypeOf(4,999);
    [$module,$action]=RbacLegacyMap::resolve('accounting.bank.manage');
    if(!RBAC::hasPermission($operator,'accounting.bank.manage')||!RBACResolver::can($operator,999,$module,$action))throw new RuntimeException('Operator unavailable');
    if($mode==='inspect'){
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $summary=purepayFinancialSummary($pdo,999);
        $q=$pdo->query('SELECT status,key_scope,webhook_secret_last4 FROM purepay_connections WHERE tenant_id=999');$row=$q->fetch(PDO::FETCH_ASSOC);
        $counts=[];foreach(['purepay_payment_links','purepay_webhook_events']as$table)$counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE tenant_id=999')->fetchColumn();
        echo json_encode(['checked_at'=>gmdate('c'),'tenant'=>999,'read_only'=>true,'financial_company'=>$summary,
            'connection_present'=>(bool)$row,'key_scope'=>$row['key_scope']??null,'webhook_configured'=>!empty($row['webhook_secret_last4']),
            'counts'=>$counts,'provider_contacted'=>false],JSON_THROW_ON_ERROR),"\n";return;
    }
    $raw=stream_get_contents(STDIN,65537);if(strlen($raw)>65536||!hash_equals((string)($argv[3]??''),hash('sha256',$raw)))throw new RuntimeException('Reviewed plan changed');
    $plan=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$review=coreDemoCallbackPlan($plan,time(),$mode==='seed');
    $hash=hash('sha256',$raw);$paymentRef=$review['payment_id'];$eventId=$review['event_id'];
    $baselinePath=__DIR__.'/fixture-'.$hash.'.before.json';$receiptPath=__DIR__.'/fixture-'.$hash.'.json';
    $locked=(int)$pdo->query("SELECT GET_LOCK('core_demo_callback_999',5)")->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Another fixture operation is active');
    $phase='connection';$conn=purepayGetConnection(999);$binding=purepayFinancialRequire($pdo,999);
    if(!$conn||$conn['status']!=='active'||$conn['key_scope']!=='read_only'||$binding['provider_key_scope']!=='read'
        ||$binding['remote_organization_id']!=='org_purepay_demo'||$binding['remote_api_base']!==CALLBACK_BASE
        ||!hash_equals($review['endpoint_sha256'],coreDemoCallbackEndpointHash($review['endpoint_id'],(string)$conn['webhook_secret'])))throw new RuntimeException('Dedicated read-only company or secret differs');
    $connectionHash=purepayConnectionFingerprint(999,(string)$conn['api_key']);unset($conn);
    $protected=static function()use($pdo,$paymentRef,$eventId):array{
        $tables=$pdo->query("SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name='tenant_id' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        $result=[];
        foreach($tables as$table){
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table name');
            $rows=$pdo->query('SELECT * FROM `'.$table.'` WHERE tenant_id=999')->fetchAll(PDO::FETCH_ASSOC);$kept=[];
            foreach($rows as$row){
                if($table==='ap_payments'&&($row['reference']??null)===$paymentRef)continue;
                if($table==='purepay_payment_links'&&($row['purepay_payment_id']??null)===$paymentRef)continue;
                if($table==='purepay_webhook_events'&&($row['event_id']??null)===$eventId)continue;
                if($table==='audit_log'&&in_array($row['event']??null,['purepay.demo.callback_fixture','purepay.webhook.payment.settled'],true)
                    &&(json_decode((string)($row['meta_json']??''),true)['payment_id']??null)===$paymentRef)continue;
                $kept[]=serialize($row);
            }
            sort($kept,SORT_STRING);$result[$table]=['count'=>count($kept),'sha256'=>hash('sha256',serialize($kept))];
        }
        // These child tables lack tenant_id; follow their existing parent ownership.
        foreach(['ap_payment_allocations'=>['payment_id','ap_payments'],
                 'accounting_journal_entry_lines'=>['je_id','accounting_journal_entries']]as$table=>[$key,$parent]){
            $rows=$pdo->query('SELECT c.* FROM '.$table.' c JOIN '.$parent.' p ON p.id=c.'.$key.' WHERE p.tenant_id=999 ORDER BY c.id')->fetchAll(PDO::FETCH_ASSOC);
            $result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
        }
        ksort($result,SORT_STRING);return$result;
    };
    $readFixture=static function()use($pdo,$paymentRef):array{
        $q=$pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id=999 AND reference=? ORDER BY id');$q->execute([$paymentRef]);$payments=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM purepay_payment_links WHERE tenant_id=999 AND purepay_payment_id=? ORDER BY id');$q->execute([$paymentRef]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
        return[$payments,$links];
    };
    $auditCounts=static function()use($pdo,$paymentRef):array{
        $counts=['purepay.demo.callback_fixture'=>0,'purepay.webhook.payment.settled'=>0];
        $rows=$pdo->query("SELECT event,meta_json FROM audit_log WHERE tenant_id=999 AND event IN('purepay.demo.callback_fixture','purepay.webhook.payment.settled')")->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as$row)if((json_decode((string)$row['meta_json'],true)['payment_id']??null)===$paymentRef)$counts[$row['event']]++;
        return$counts;
    };
    if($mode==='seed'){
        $phase='seed';$pdo->beginTransaction();
        [$payments,$links]=$readFixture();
        if(!$payments&&!$links){
            if(is_file($baselinePath)||is_file($receiptPath))throw new RuntimeException('Inspect prior fixture evidence before retry');
            $baseline=['plan_sha256'=>$hash,'connection_fingerprint'=>$connectionHash,'protected'=>$protected()];
            $file=fopen($baselinePath,'x');if(!$file)throw new RuntimeException('Cannot retain fixture baseline');
            chmod($baselinePath,0600);fwrite($file,json_encode($baseline,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));fflush($file);fclose($file);
            $pdo->prepare('INSERT INTO ap_payments(tenant_id,vendor_name,pay_date,method,reference,amount,currency,unallocated_amount,status,disbursement_rail,rail_external_ref,rail_status,notes,created_by_user_id)
                VALUES(999,"DEMO CALLBACK - NO FUNDS MOVED",CURRENT_DATE(),"other",?,1.00,"USD",1.00,"sent","purepay",?,"submitted",?,4)')
                ->execute([$paymentRef,$paymentRef,'Synthetic callback acceptance only; plan '.$hash]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO purepay_payment_links(tenant_id,source_ref,core_payment_id,purepay_bill_id,purepay_payment_id,amount_cents,status,request_fingerprint,connection_fingerprint)
                VALUES(999,?,?,?,?,100,"submitted",?,?)')->execute(['ap_payment:'.$id,$id,$review['bill_id'],$paymentRef,$hash,$connectionHash]);
            platformAuditLogWrite(999,4,'purepay.demo.callback_fixture',$id,['payment_id'=>$paymentRef,'plan_sha256'=>$hash,'synthetic'=>true,'funds_moved'=>false]);
        }else{
            $baseline=json_decode(file_get_contents($baselinePath),true,512,JSON_THROW_ON_ERROR);
        }
        [$payments,$links]=$readFixture();
        if(count($payments)!==1||count($links)!==1||$links[0]['request_fingerprint']!==$hash
            ||$links[0]['connection_fingerprint']!==$connectionHash||$links[0]['purepay_bill_id']!==$review['bill_id']
            ||(int)$links[0]['core_payment_id']!==(int)$payments[0]['id']||$payments[0]['status']!=='sent'
            ||$links[0]['status']!=='submitted'||(float)$payments[0]['amount']!==1.0||$payments[0]['currency']!=='USD'
            ||$baseline['plan_sha256']!==$hash||$baseline['connection_fingerprint']!==$connectionHash
            ||$auditCounts()!==['purepay.demo.callback_fixture'=>1,'purepay.webhook.payment.settled'=>0]
            ||$baseline['protected']!==$protected())throw new RuntimeException('Fixture identity or protected state differs');
        $pdo->commit();
        $receipt=['prepared'=>true,'tenant'=>999,'plan_sha256'=>$hash,'core_payment_id'=>(int)$payments[0]['id'],
            'provider_payment_id'=>$paymentRef,'provider_bill_id'=>$review['bill_id'],'event_id'=>$eventId,
            'amount'=>'1.00','currency'=>'USD','cash_posting'=>false,'provider_execution'=>false,'protected_sets'=>count($baseline['protected']),
            'fixture_before'=>['payment'=>$payments[0],'link'=>$links[0]]];
    }else{
        $phase='verify';$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
        $baseline=json_decode(file_get_contents($baselinePath),true,512,JSON_THROW_ON_ERROR);
        $prepared=json_decode(file_get_contents($receiptPath),true,512,JSON_THROW_ON_ERROR);
        [$payments,$links]=$readFixture();
        if(count($payments)!==1||count($links)!==1||$links[0]['request_fingerprint']!==$hash
            ||$prepared['plan_sha256']!==$hash||(int)$prepared['core_payment_id']!==(int)$payments[0]['id']
            ||(int)$links[0]['core_payment_id']!==(int)$payments[0]['id']||$links[0]['source_ref']!=='ap_payment:'.$payments[0]['id']
            ||$links[0]['purepay_bill_id']!==$review['bill_id']||(int)$links[0]['amount_cents']!==100
            ||$links[0]['connection_fingerprint']!==$connectionHash
            ||$baseline['connection_fingerprint']!==$connectionHash||$baseline['plan_sha256']!==$hash
            ||$baseline['protected']!==$protected()||!purepayStoredPaymentEvidence($links[0])
            ||$links[0]['status']!=='settled'||$payments[0]['rail_status']!=='settled'||$payments[0]['status']!=='sent'
            ||$payments[0]['journal_entry_id']!==null||$payments[0]['cleared_at']!==null
            ||$payments[0]['bank_account_id']!==null||$payments[0]['vendor_name']!=='DEMO CALLBACK - NO FUNDS MOVED'
            ||$auditCounts()!==['purepay.demo.callback_fixture'=>1,'purepay.webhook.payment.settled'=>1]
            ||(float)$payments[0]['amount']!==1.0||$payments[0]['currency']!=='USD')throw new RuntimeException('Core receipt or financial isolation is unverified');
        foreach(['payment'=>['rail_status','updated_at'],'link'=>['status','response_json','last_error','last_error_json','last_synced_at','updated_at']]as$kind=>$allowed){
            $current=$kind==='payment'?$payments[0]:$links[0];$original=$prepared['fixture_before'][$kind];
            foreach($allowed as$field){unset($current[$field],$original[$field]);}
            if($current!==$original)throw new RuntimeException('Unexpected fixture field changed');
        }
        $q=$pdo->prepare('SELECT * FROM purepay_webhook_events WHERE tenant_id=999 AND event_id=?');$q->execute([$eventId]);$events=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($events)!==1||(int)$events[0]['verified']!==1||empty($events[0]['processed_at'])||!empty($events[0]['processing_error'])
            ||!hash_equals($review['body_sha256'],hash('sha256',$events[0]['raw_body'])))throw new RuntimeException('Callback receipt differs');
        $pdo->rollBack();
        $receipt=['verified'=>true,'tenant'=>999,'plan_sha256'=>$hash,'core_payment_id'=>(int)$payments[0]['id'],
            'event_id'=>$eventId,'event_count'=>1,'provider_status'=>'settled','ap_status'=>'sent','cash_posted'=>false,
            'ledger_and_other_protected_sets_unchanged'=>true,'protected_sets'=>count($baseline['protected']),
            'synthetic_fixture_retained'=>true,'provider_contacted'=>false];
        $receiptPath=__DIR__.'/fixture-'.$hash.'.verified.json';
    }
    if(file_put_contents($receiptPath,json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Cannot retain result');
    chmod($receiptPath,0600);unset($receipt['fixture_before']);echo json_encode($receipt,JSON_THROW_ON_ERROR),"\n";
}catch(Throwable $error){
    if($pdo&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,json_encode(['failed'=>true,'phase'=>$phase,'type'=>get_class($error),'line'=>$error->getLine()],JSON_THROW_ON_ERROR)."\n");exit(2);
}finally{
    if($locked&&$pdo)$pdo->query("SELECT RELEASE_LOCK('core_demo_callback_999')");
}
