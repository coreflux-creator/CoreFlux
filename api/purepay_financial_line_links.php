<?php
/** Reviewed historical source-line associations, separate from time matching and postings. */
declare(strict_types=1);
require_once __DIR__.'/../core/api_bootstrap.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/sub_tenants.php';
require_once __DIR__.'/../core/integrations/purepay_financial_line_links.php';
$ctx=api_require_auth();$tenant=(int)$ctx['tenant_id'];$user=$ctx['user'];
if(getenv('COREFLUX_ENV')==='coreaccounting')api_error('Not found',404);
foreach(['accounting.bank.manage','ap.view','billing.view','placements.view','placements.financials.view','people.view']as$permission)
    rbac_legacy_require($user,$permission);
if(api_method()!=='POST')api_error('Method not allowed',405);
header('Cache-Control: no-store, private');
$body=api_json_body();$action=(string)($_GET['action']??'');
if(!in_array($action,['preview','save','history'],true))api_error('Unknown association action',404);
if(array_diff(array_keys($body),['capture_id','line_id','placement_number','review_hash','confirmed','before'])
    ||!is_int($body['capture_id']??null)||!is_string($body['line_id']??null)
    ||($action!=='history'&&!is_string($body['placement_number']??null))
    ||(isset($body['before'])&&!is_int($body['before'])))api_error('Choose a saved capture, source line and Core placement number.',422);
try {
    $pdo=getDB();$instance=integrationInstanceId();if(!$instance)throw new RuntimeException('Core installation identity is required.');
    $owners=[];foreach(['people','placements']as$module)$owners[$module]=effectiveTenantIdForModule($module,$tenant)??$tenant;
    if($action==='history')api_ok(purepayFinancialLineHistory($pdo,$tenant,$instance,$owners,$body['capture_id'],$body['line_id'],$body['before']??null));
    $number=strtoupper(trim($body['placement_number']));
    if($action==='preview')api_ok(purepayFinancialLinePreview($pdo,$tenant,$instance,$owners,$body['capture_id'],$body['line_id'],$number));
    if(($body['confirmed']??null)!==true||!is_string($body['review_hash']??null))api_error('Confirm the reviewed source line and placement before linking.',422);
    $audit=static function(array $meta)use($pdo,$tenant,$user):void {
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(?,?,"purepay.financial_line.linked",?,?,?,NOW())')
            ->execute([$tenant,(int)$user['id'],$meta['link_id'],json_encode($meta,JSON_THROW_ON_ERROR),$_SERVER['REMOTE_ADDR']??null]);
    };
    api_ok(purepayFinancialLineSave($pdo,$tenant,(int)$user['id'],$instance,$owners,$body['capture_id'],$body['line_id'],$number,$body['review_hash'],$audit));
}catch(PurePayFinancialLineReviewException $e){api_error($e->getMessage(),422);}
catch(Throwable $e){api_error('The source-line association could not be reviewed or saved. Check the current capture, placement, permissions and company connection.',422);}
