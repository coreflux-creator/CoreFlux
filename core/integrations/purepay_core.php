<?php
/** Reviewed, tenant-owned Core metadata access. This connection never executes payroll or AP. */
declare(strict_types=1);
require_once __DIR__.'/../purepay_adapter.php';
require_once __DIR__.'/../encryption.php';
require_once __DIR__.'/identity.php';
require_once __DIR__.'/delivery.php';

const PUREPAY_CORE_VERSION = '2026-10-20.1';
const PUREPAY_CORE_LINK_VERSION = '2026-10-21.1';
const PUREPAY_CORE_PATH = '/integrations/coreflux';

function purepayCoreApiBase(): string
{
    // Private PHP configuration also works when the web host disables putenv().
    // Keep this preview destination separate from the AP payment rail.
    $preview=defined('COREFLUX_PUREPAY_PREVIEW_API_BASE')
        ? constant('COREFLUX_PUREPAY_PREVIEW_API_BASE')
        : getenv('COREFLUX_PUREPAY_PREVIEW_API_BASE');
    if(defined('COREFLUX_PUREPAY_PREVIEW_API_BASE') || ($preview!==false && $preview!=='')) {
        if(!is_string($preview) || !defined('COREFLUX_STAGING') || COREFLUX_STAGING!==true
            || $preview!=='https://beautiful-kalam-6.preview.emergentagent.com/api/v1') {
            throw new RuntimeException('PurePay preview access requires the reviewed isolated staging destination.');
        }
        return $preview;
    }
    $base=rtrim(trim((string)(getenv('PUREPAY_CORE_API_BASE') ?: purepayApiBase())),'/');
    $url=parse_url($base);
    if(!$url || ($url['scheme']??'')!=='https' || empty($url['host'])
        || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
        || ($url['path']??'')!=='/api/v1' || strlen($base)>255)throw new RuntimeException('Configure a valid HTTPS PurePay Core API address.');
    return $base;
}

function purepayCoreContextValidate(array $data): array
{
    $org=$data['organization']??null;
    $caps=$data['capabilities']??null;
    if (!is_array($org) || !is_string($org['id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$org['id'])
        || !is_string($org['name']??null) || trim($org['name'])==='' || preg_match('/[\x00-\x1f\x7f]/',$org['name'])
        || preg_match_all('/./us',$org['name'])===false || preg_match_all('/./us',$org['name'])>200
        || ($data['contract_version']??null)!==PUREPAY_CORE_VERSION || ($data['execution_side_effects']??null)!==false
        || !is_array($caps) || !array_is_list($caps) || array_filter($caps,static fn($v)=>!is_string($v))
        || !in_array('read:coreflux',$caps,true)) {
        throw new RuntimeException('PurePay has not provided the required Core organization contract.');
    }
    $caps=array_values(array_intersect(['read:coreflux','write:coreflux','read:payroll','read:people_setup','write:payroll_inputs','write:payroll_authority','write:people_inputs','write:assignment_inputs'],$caps)); sort($caps);
    if(in_array('read:people_setup',$caps,true) && !in_array('read:payroll',$caps,true))throw new RuntimeException('Employee setup access requires employee read access.');
    if(in_array('write:payroll_inputs',$caps,true) && !in_array('read:payroll',$caps,true))throw new RuntimeException('Payroll input access requires payroll read access.');
    if(in_array('write:people_inputs',$caps,true) && !in_array('read:payroll',$caps,true))throw new RuntimeException('People input access requires employee read access.');
    if(in_array('write:payroll_authority',$caps,true) && (!in_array('read:payroll',$caps,true) || !in_array('write:payroll_inputs',$caps,true)))throw new RuntimeException('Payroll reservation access requires payroll read and input access.');
    $link=$data['link_contract_version']??null;
    if($link!==null && (!is_string($link) || !preg_match('/^[0-9.-]{1,32}$/D',$link)))throw new RuntimeException('Invalid provider link contract.');
    $input=$data['payroll_input_contract_version']??null;
    if($input!==null && (!is_string($input) || !preg_match('/^[0-9.-]{1,32}$/D',$input)))throw new RuntimeException('Invalid provider payroll input contract.');
    $authority=$data['payroll_authority_contract_version']??null;
    if($authority!==null && (!is_string($authority) || !preg_match('/^[0-9.-]{1,32}$/D',$authority)))throw new RuntimeException('Invalid provider payroll reservation contract.');
    $people=$data['people_input_contract_version']??null;
    if($people!==null && (!is_string($people) || !preg_match('/^[0-9.-]{1,32}$/D',$people)))throw new RuntimeException('Invalid provider people input contract.');
    $assignment=$data['assignment_input_contract_version']??null;
    if($assignment!==null && (!is_string($assignment) || !preg_match('/^[0-9.-]{1,32}$/D',$assignment)))throw new RuntimeException('Invalid provider commercial input contract.');
    $setup=$data['people_setup_contract_version']??null;
    if($setup!==null && (!is_string($setup) || !preg_match('/^[0-9.-]{1,32}$/D',$setup)))throw new RuntimeException('Invalid provider employee setup contract.');
    return ['organization'=>['id'=>$org['id'],'name'=>$org['name']], 'capabilities'=>$caps,'link_contract_version'=>$link,
        'contract_version'=>PUREPAY_CORE_VERSION,'execution_side_effects'=>false]
        + ($input===null?[]:['payroll_input_contract_version'=>$input])
        + ($authority===null?[]:['payroll_authority_contract_version'=>$authority])
        + ($people===null?[]:['people_input_contract_version'=>$people])
        + ($assignment===null?[]:['assignment_input_contract_version'=>$assignment])
        + ($setup===null?[]:['people_setup_contract_version'=>$setup]);
}

/** Never pass provider response text or credentials to the browser or audit trail. */
function purepayCoreGet(string $key,string $path): array
{
    try { return purepayCall($key,'GET',PUREPAY_CORE_PATH.$path,[],25,null,purepayCoreApiBase()); }
    catch(PurePayApiException $e) {
        throw new RuntimeException(match($e->httpStatus) {
            401=>'PurePay did not accept this key. Restore the connection and check again.',
            403=>'This operation needs the dedicated Core key and its required capability.',
            404=>'The provider environment does not expose this Core resource yet.',
            409=>'The provider identity is ambiguous. Reconcile its duplicate records first.',
            default=>'PurePay could not complete this read. Check the provider connection and try again.',
        });
    }
}

/** Internal only. Never serialize this row in an API response. */
function purepayCoreConnection(PDO $pdo,int $tenantId,bool $lock=false): ?array
{
    if($tenantId<=0)throw new InvalidArgumentException('A company is required.');
    $st=$pdo->prepare('SELECT * FROM purepay_core_connections WHERE tenant_id=:t'.($lock?' FOR UPDATE':''));
    $st->execute(['t'=>$tenantId]); return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function purepayCoreSummary(?array $row): array
{
    return ['connected'=>($row['status']??'')==='active','api_key_last4'=>$row['api_key_last4']??null,
        'organization'=>$row && $row['remote_organization_id']!==null ? ['id'=>$row['remote_organization_id'],'name'=>$row['remote_organization_name']]:null,
        'api_base'=>purepayCoreApiBase(),'reviewed_at'=>$row['verified_at']??null];
}

function purepayCoreHash(array $row,string $key,string $instance,array $context): string
{
    integrationRecordReference($instance,(int)$row['tenant_id'],'person','P-1');
    return integrationDeliveryHash(['tenant_id'=>(int)$row['tenant_id'],'connection_id'=>(int)$row['id'],
        'revision'=>(int)$row['revision'],'instance'=>$instance,'base'=>purepayCoreApiBase(),
        'credential_digest'=>hash('sha256',$key),'context'=>purepayCoreContextValidate($context)]);
}

function purepayCoreAssertPin(?array $row,string $instance,array $context): void
{
    if($row && $row['remote_organization_id']!==null && ($row['remote_organization_id']!==$context['organization']['id']
        || $row['core_instance_id']!==$instance || $row['remote_api_base']!==purepayCoreApiBase())) {
        throw new RuntimeException('This company already has a different permanent provider identity. Its existing links need migration review.');
    }
}

function purepayCoreConnect(PDO $pdo,int $tenantId,string $key,string $instance,?callable $audit=null): void
{
    if($tenantId<=0 || strlen($key)<12 || strlen($key)>1000 || preg_match('/[\x00-\x20\x7f]/',$key))throw new InvalidArgumentException('Enter a valid dedicated Core API key.');
    integrationRecordReference($instance,$tenantId,'person','P-1');
    if($pdo->inTransaction())throw new LogicException('Connection changes require their own transaction.');
    $context=purepayCoreContextValidate(purepayCoreGet($key,'/context'));
    $cipher=encryptField($key);
    $pdo->beginTransaction();
    try {
        // An upsert acquires the same connection lock for first connect and rotation.
        $pdo->prepare('INSERT INTO purepay_core_connections(tenant_id) VALUES(:t) ON DUPLICATE KEY UPDATE id=id')->execute(['t'=>$tenantId]);
        $row=purepayCoreConnection($pdo,$tenantId,true);
        purepayCoreAssertPin($row,$instance,$context);
        $pdo->prepare('UPDATE purepay_core_connections SET api_key_ct=:k,api_key_last4=:l,status="active",revision=revision+1,binding_hash=NULL,verified_by=NULL,verified_at=NULL WHERE tenant_id=:t')
            ->execute(['k'=>$cipher,'l'=>substr($key,-4),'t'=>$tenantId]);
        if($audit!==null)$audit();
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function purepayCoreReview(PDO $pdo,int $tenantId,string $instance): array
{
    $row=purepayCoreConnection($pdo,$tenantId);
    if(!$row || $row['status']!=='active')throw new RuntimeException('Connect the dedicated PurePay Core key first.');
    $key=(string)decryptField($row['api_key_ct']);
    $context=purepayCoreContextValidate(purepayCoreGet($key,'/context'));
    purepayCoreAssertPin($row,$instance,$context);
    $hash=purepayCoreHash($row,$key,$instance,$context);
    // A credential rotation during the remote request invalidates its result.
    $live=purepayCoreConnection($pdo,$tenantId);
    if(!$live || $live['status']!=='active' || $live['revision']!==$row['revision'])throw new RuntimeException('The connection changed. Check it again.');
    return ['organization'=>$context['organization'],'capabilities'=>$context['capabilities'],
        'review_hash'=>$hash,'verified'=>is_string($row['binding_hash']) && hash_equals($row['binding_hash'],$hash),
        'contract_version'=>$context['contract_version'],'link_contract_version'=>$context['link_contract_version']]
        + (isset($context['payroll_input_contract_version'])?['payroll_input_contract_version'=>$context['payroll_input_contract_version']]:[])
        + (isset($context['payroll_authority_contract_version'])?['payroll_authority_contract_version'=>$context['payroll_authority_contract_version']]:[])
        + (isset($context['people_input_contract_version'])?['people_input_contract_version'=>$context['people_input_contract_version']]:[])
        + (isset($context['assignment_input_contract_version'])?['assignment_input_contract_version'=>$context['assignment_input_contract_version']]:[])
        + (isset($context['people_setup_contract_version'])?['people_setup_contract_version'=>$context['people_setup_contract_version']]:[]);
}

function purepayCoreConfirm(PDO $pdo,int $tenantId,int $actor,string $instance,string $reviewHash,string $organizationId,?callable $audit=null): array
{
    if($actor<=0)throw new InvalidArgumentException('A reviewing administrator is required.');
    if($pdo->inTransaction())throw new LogicException('Company review requires its own transaction.');
    $review=purepayCoreReview($pdo,$tenantId,$instance); // fresh provider context, no record access
    if(!hash_equals($review['review_hash'],$reviewHash) || $review['organization']['id']!==$organizationId)throw new RuntimeException('The organization or access changed. Check it again before confirming.');
    $pdo->beginTransaction();
    try {
        $row=purepayCoreConnection($pdo,$tenantId,true);
        $context=$review+['execution_side_effects'=>false];
        if(!$row || $row['status']!=='active' || !hash_equals($reviewHash,purepayCoreHash($row,(string)decryptField($row['api_key_ct']),$instance,$context)))throw new RuntimeException('The connection changed during review.');
        purepayCoreAssertPin($row,$instance,$context);
        $pdo->prepare('UPDATE purepay_core_connections SET core_instance_id=:i,remote_organization_id=:o,remote_organization_name=:n,remote_api_base=:b,binding_hash=:h,verified_by=:u,verified_at=NOW() WHERE tenant_id=:t')
            ->execute(['i'=>$instance,'o'=>$organizationId,'n'=>$review['organization']['name'],'b'=>purepayCoreApiBase(),'h'=>$reviewHash,'u'=>$actor,'t'=>$tenantId]);
        if($audit!==null)$audit();
        $pdo->commit();return array_replace($review,['confirmed'=>true,'verified'=>true]);
    } catch(PDOException $e) { if($pdo->inTransaction())$pdo->rollBack(); if($e->getCode()==='23000')throw new RuntimeException('This provider organization is already bound to another Core company.');throw $e; }
    catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function purepayCoreDisconnect(PDO $pdo,int $tenantId,?callable $audit=null): void
{
    if($pdo->inTransaction())throw new LogicException('Connection changes require their own transaction.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE purepay_core_connections SET status="revoked",api_key_ct=NULL,api_key_last4=NULL,revision=revision+1,binding_hash=NULL,verified_by=NULL,verified_at=NULL WHERE tenant_id=:t')->execute(['t'=>$tenantId]);
        if($audit!==null)$audit();
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function purepayCoreRecordView(string $kind,array $row): array
{
    $fields=match($kind) {
        'person'=>['id','name','email','title','department','employment_type','status','start_date'],
        'placement'=>['id','item_id','item_name','customer_id','vendor_id','employee_id','start_date','end_date','status','auto_link','auto_bills','bills_drive_invoicing',
            'placement_number','person_number','client_number','vendor_number','source_revision'],
        'client'=>['id','name','email','status'], 'vendor'=>['id','display_name','legal_name','email','relationship','status'],
        default=>throw new InvalidArgumentException('Unknown provider record type.'),
    };
    if(!is_string($row['id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$row['id']))throw new RuntimeException('The provider returned a record without a stable identity.');
    $out=[];
    foreach($fields as$field)if(array_key_exists($field,$row)) {
        if(!is_scalar($row[$field]) && $row[$field]!==null)throw new RuntimeException('The provider returned an invalid record field.');
        $out[$field]=$row[$field];
    }
    if(isset($row['record_version'])) {
        if(!is_string($row['record_version']) || !preg_match('/^[a-f0-9]{64}$/D',$row['record_version']))throw new RuntimeException('Invalid provider record version.');
        $out['record_version']=$row['record_version'];
    }
    return $out;
}

/** One explicit page, never a silent truncated list or an automatic write. */
function purepayCoreRecords(PDO $pdo,int $tenantId,string $instance,string $kind,int $limit=50,?string $cursor=null): array
{
    if(!in_array($kind,['person','placement','client','vendor'],true) || $limit<1 || $limit>200
        || ($cursor!==null && (strlen($cursor)>512 || !preg_match('/^[A-Za-z0-9_+=\/-]+$/D',$cursor))))throw new InvalidArgumentException('Invalid record page.');
    $review=purepayCoreReview($pdo,$tenantId,$instance);
    if(!$review['verified'])throw new RuntimeException('Confirm the provider organization before reading its records.');
    if($kind==='person' && !in_array('read:payroll',$review['capabilities'],true))throw new RuntimeException('Employee records require the read payroll capability.');
    $row=purepayCoreConnection($pdo,$tenantId);
    if(!$row || $row['status']!=='active')throw new RuntimeException('The connection changed. Check it again.');
    $key=(string)decryptField($row['api_key_ct']);
    $context=$review+['execution_side_effects'=>false];
    if($row['status']!=='active' || !hash_equals($review['review_hash'],purepayCoreHash($row,$key,$instance,$context)))throw new RuntimeException('The connection changed. Check it again.');
    $page=purepayCoreGet($key,'/records/'.$kind.'?'.http_build_query(array_filter(['limit'=>$limit,'cursor'=>$cursor],static fn($v)=>$v!==null)));
    $live=purepayCoreConnection($pdo,$tenantId);
    if(!$live || $live['status']!=='active' || $live['revision']!==$row['revision'] || $live['binding_hash']!==$row['binding_hash'])throw new RuntimeException('The connection changed during the read. No records were returned.');
    if(!is_array($page['items']??null) || !array_is_list($page['items']) || count($page['items'])>$limit || !array_key_exists('next_cursor',$page)
        || ($page['next_cursor']!==null && (!is_string($page['next_cursor']) || $page['next_cursor']==='' || strlen($page['next_cursor'])>512 || $page['next_cursor']===$cursor)))throw new RuntimeException('The provider returned an incomplete record page.');
    $items=[];$ids=[];
    foreach($page['items']as$item) {
        if(!is_array($item))throw new RuntimeException('The provider returned an invalid record.');
        $item=purepayCoreRecordView($kind,$item);
        if(isset($ids[$item['id']]))throw new RuntimeException('The provider returned duplicate identities. Reconcile them before linking.');
        $ids[$item['id']]=true;$items[]=$item;
    }
    return ['items'=>$items,'next_cursor'=>$page['next_cursor'],'organization'=>$review['organization'],'kind'=>$kind];
}
