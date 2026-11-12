<?php
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/sub_tenants.php';
require_once __DIR__.'/../core/integrations/staffing_financial_evidence.php';
$ctx=api_require_auth();$tenant=(int)$ctx['tenant_id'];$user=$ctx['user'];
if(getenv('COREFLUX_ENV')==='coreaccounting')api_error('Not found',404);
foreach(['accounting.view','ap.view','billing.view','placements.view','placements.financials.view','people.view']as$permission)rbac_legacy_require($user,$permission);
$action=(string)($_GET['action']??'');
if(in_array($action,['preview','save'],true))rbac_legacy_require($user,'accounting.bank.manage');
if(api_method()!=='POST')api_error('Method not allowed',405);
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
if((int)($_SERVER['CONTENT_LENGTH']??0)>3000000)api_error('Choose a workbook no larger than 2 MB.',413);
$body=api_json_body();
$allowed=match($action){
    'preview'=>['association_id','decision','file_name','file_base64','selection','reason'],
    'save'=>['association_id','decision','file_name','file_base64','selection','reason','request_id','review_hash','confirmed'],
    'history'=>['association_id','before'],
    'download'=>['association_id','evidence_id'],
    default=>null,
};
if($allowed===null)api_error('Unknown workbook review action',404);
if(array_diff(array_keys($body),$allowed)||!is_int($body['association_id']??null))api_error('Choose a reviewed source-line association.',422);
try{
    $pdo=getDB();$instance=integrationInstanceId();if(!$instance)throw new RuntimeException('Installation identity unavailable');
    $owners=[];foreach(['people','placements']as$m)$owners[$m]=effectiveTenantIdForModule($m,$tenant)??$tenant;
    if($action==='history'){
        if(isset($body['before'])&&!is_int($body['before']))throw new InvalidArgumentException('Choose a valid history page.');
        api_ok(staffingEvidenceHistory($pdo,$tenant,$instance,$owners,$body['association_id'],$body['before']??null));
    }
    if($action==='download'){
        if(!is_int($body['evidence_id']??null)||$body['evidence_id']<=0)throw new InvalidArgumentException('Choose a saved workbook review.');
        $binding=purepayFinancialRequire($pdo,$tenant);$context=staffingEvidenceContext($tenant,$instance,$binding,$owners);
        staffingEvidenceAssociation($pdo,$context,$body['association_id']);
        $q=$pdo->prepare('SELECT * FROM staffing_financial_evidence WHERE tenant_id=? AND connection_id=? AND association_id=? AND id=?');
        $q->execute([$tenant,$context['connection_id'],$body['association_id'],$body['evidence_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new InvalidArgumentException('The saved workbook review is unavailable in this company.');
        $receipt=staffingEvidenceSaved($pdo,$row,$context);$saved=$receipt['saved'];
        if($saved['action']!=='record')throw new InvalidArgumentException('A withdrawal has no new workbook. Open the earlier review.');
        $source=staffingEvidenceSource($pdo,$tenant,$saved['source_id'],$saved['workbook']['file_sha256']);
        if(purepayFinancialRequire($pdo,$tenant)['binding_hash']!==$binding['binding_hash'])throw new RuntimeException('Company connection changed');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('X-CoreFlux-Tenant-Id: '.$tenant);
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(staffingWorkbookFileName($saved['workbook']['file_name'])));
        header('Content-Length: '.strlen($source['bytes']));echo$source['bytes'];exit;
    }
    if(!is_string($body['decision']??null)||!is_string($body['reason']??null)
        ||(isset($body['file_name'])&&!is_string($body['file_name']))||(isset($body['file_base64'])&&!is_string($body['file_base64']))
        ||(isset($body['selection'])&&!is_array($body['selection'])))throw new InvalidArgumentException('Choose a workbook and its source cells, or explain a withdrawal.');
    $bytes=isset($body['file_base64'])?base64_decode($body['file_base64'],true):null;
    if($bytes===false)throw new InvalidArgumentException('The workbook upload is incomplete.');
    $args=[$instance,$owners,$body['association_id'],$body['decision'],$bytes,$body['file_name']??null,$body['selection']??null,$body['reason']];
    if($action==='preview')api_ok(staffingEvidencePreview($pdo,$tenant,...$args));
    if(($body['confirmed']??null)!==true||!is_string($body['request_id']??null)||!is_string($body['review_hash']??null))throw new InvalidArgumentException('Confirm the current workbook review before saving.');
    $audit=static function(array $meta)use($pdo,$tenant,$user):void{
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(?,?,"staffing.financial_evidence.reviewed",?,?,?,NOW())')
            ->execute([$tenant,(int)$user['id'],$meta['evidence_id'],json_encode($meta,JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null]);
    };
    api_ok(staffingEvidenceSave($pdo,$tenant,(int)$user['id'],...array_merge($args,[$body['request_id'],$body['review_hash'],$audit])));
}catch(InvalidArgumentException|PurePayFinancialLineReviewException $e){api_error($e->getMessage(),422);}
catch(Throwable $e){api_error('The workbook evidence could not be read or saved. Check the current association, source file and company connection.',422);}
