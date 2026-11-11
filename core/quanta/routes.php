<?php
/** Explicit routing; captured assignments never become a fallback for legacy time. */
declare(strict_types=1);
require_once __DIR__.'/sync.php';
require_once __DIR__.'/time_control_delivery.php';
require_once __DIR__.'/../tx_helpers.php';

function quantaRouteInput(array $route): array
{
    foreach(['worker_id','worksite_id','effective_from'] as $field)if(!is_string($route[$field]??null))throw new InvalidArgumentException('Complete worker, worksite and start date required');
    $worker=quantaTimeControlIdentifier($route['worker_id']);$site=$route['worksite_id'];if($site!=='')quantaTimeControlIdentifier($site);
    if(!is_int($route['placement_id']??null)||$route['placement_id']<=0)throw new InvalidArgumentException('Choose a Core placement');
    $id=$route['id']??0;if(!is_int($id)||$id<0)throw new InvalidArgumentException('Invalid route identity');
    $assignment=array_key_exists('quanta_assignment_id',$route)?$route['quanta_assignment_id']:'';
    if(!is_string($assignment)||($assignment!==''&&!preg_match('/^cfa_[a-f0-9]{64}$/D',$assignment)))throw new InvalidArgumentException('Invalid captured assignment identity');
    if($assignment!==''&&$site==='')throw new InvalidArgumentException('Captured assignments require a worksite');
    $from=quantaContractDate($route['effective_from']);$to=$route['effective_to']??null;
    if($to==='')$to=null;if($to!==null&&quantaContractDate($to)<$from)throw new InvalidArgumentException('Route end precedes start');
    $dimensions=quantaDimensions($route['dimension_values']??null);
    return ['id'=>$id,'worker_id'=>$worker,'worksite_id'=>$site,'dimension_values'=>$dimensions,'dimension_key'=>quantaDimensionKey($dimensions),
        'placement_id'=>$route['placement_id'],'effective_from'=>$from,'effective_to'=>$to,'quanta_assignment_id'=>$assignment];
}

/** Dates and work context already overlap. Either legacy ambiguity or a duplicate captured target is a conflict. */
function quantaRouteOverlapConflict(array $a,array $b): bool
{
    $left=$a['quanta_assignment_id']??'';$right=$b['quanta_assignment_id']??'';
    return ($left===''&&$right==='')||(int)$a['placement_id']===(int)$b['placement_id']||($left!==''&&$left===$right);
}

function quantaRouteSource(int $tenant,array $access,array $route,bool $lock=false): array
{
    $owners=quantaTimeControlOwners($tenant,$lock);
    $source=quantaMetadataSource(getDB(),$access['instance_id'],$owners,'placement',$route['placement_id'],$lock);
    if(!in_array($source['assignment']['status'],['active','ended'],true))throw new InvalidArgumentException('Activate the placement before routing time');
    $map=quantaWorkerPersonMap(quantaWorkerLinks($tenant,$lock));quantaAssertWorkerPerson($route['worker_id'],(int)$source['person_record']['id'],$map);
    if($route['quanta_assignment_id']!==''&&quantaCoreRemoteId($access['context']['workspace']['id'],$source['reference'])!==$route['quanta_assignment_id'])throw new InvalidArgumentException('The captured assignment belongs to a different placement or company');
    return ['owners'=>$owners,'placement'=>$source,'linked_person'=>$map[$route['worker_id']]];
}

function quantaSaveRoutes(int $tenant,int $actor,array $input): array
{
    if(!array_is_list($input)||!$input||count($input)>100)throw new InvalidArgumentException('Provide 1 to 100 placement routes');
    $routes=array_map(static fn($r)=>is_array($r)?quantaRouteInput($r):throw new InvalidArgumentException('Invalid route'),$input);
    $ids=array_values(array_filter(array_column($routes,'id')));if(count($ids)!==count(array_unique($ids)))throw new InvalidArgumentException('Review each route only once');
    $access=quantaWorkspaceForDelivery($tenant);$catalog=quantaCatalog($access['api_key']);
    $workers=array_column($catalog['workers'],null,'id');$sites=array_column($catalog['worksites'],null,'id');$prepared=[];
    foreach($routes as $route){
        if(!isset($workers[$route['worker_id']])||($route['worksite_id']!==''&&!isset($sites[$route['worksite_id']])))throw new InvalidArgumentException('Choose a worker and worksite from the connected Quanta company');
        $source=quantaRouteSource($tenant,$access,$route);
        if($route['quanta_assignment_id']!==''){
            $link=quantaContractRecord($access['api_key'],$access['context']['workspace']['id'],$source['placement']['person']);
            if(!$link||($link['worker_id']??null)!==$route['worker_id'])throw new RuntimeException('Reconcile the shared person identity before routing captured time');
            $remote=quantaContractRecord($access['api_key'],$access['context']['workspace']['id'],$source['placement']['reference']);
            if(!$remote||($remote['worker_id']??null)!==$route['worker_id']||($remote['worksite_id']??null)!==$route['worksite_id']
                ||($remote['person_link_id']??null)!==quantaCoreRemoteId($access['context']['workspace']['id'],$source['placement']['person']))throw new RuntimeException('Reconcile the assignment worker and worksite before routing');
            $plan=quantaAssignmentPlan($access['context']['workspace']['id'],$source['placement']['assignment']+['worksite_id'=>$route['worksite_id']],$remote);
            if($plan['status']!=='unchanged')throw new RuntimeException('Publish current shared assignment details before routing captured time');
        }
        $prepared[]=[$route,$source];
    }
    $pdo=getDB();$owns=cf_tx_begin($pdo);
    try{
        quantaWorkspaceAssertCurrent($pdo,$tenant,$access,true);
        // Every writer locks these same worker identities before inspecting ranges.
        // This also serializes edits to different assignments at one worksite.
        quantaWorkerLinks($tenant,true);
        foreach($prepared as [$route,$source]){
            if(integrationDeliveryHash(quantaRouteSource($tenant,$access,$route,true))!==integrationDeliveryHash($source))throw new RuntimeException('Core placement or directory changed during route review');
            if($route['id']){
                $q=$pdo->prepare('SELECT * FROM quanta_time_routes WHERE tenant_id=:t AND id=:id FOR UPDATE');$q->execute(['t'=>$tenant,'id'=>$route['id']]);$old=$q->fetch(PDO::FETCH_ASSOC);
                if(!$old)throw new InvalidArgumentException('Route is not in this workspace');
                if(($old['quanta_assignment_id']??'')!==$route['quanta_assignment_id'])throw new InvalidArgumentException('A route cannot change between legacy and captured assignment time; review a separate route');
                if(($old['quanta_assignment_id']??'')!==''){
                    foreach(['worker_id','worksite_id','placement_id','quanta_assignment_id'] as $field)if((string)$old[$field]!== (string)$route[$field])throw new InvalidArgumentException('A captured-assignment route cannot change its identity');
                }
            }
            $q=$pdo->prepare('SELECT id,placement_id,quanta_assignment_id FROM quanta_time_routes WHERE tenant_id=:t AND worker_id=:w AND worksite_id=:s
                AND dimension_key=:dk AND id<>:id AND effective_from<=:e AND COALESCE(effective_to,"9999-12-31")>=:f FOR UPDATE');
            $q->execute(['t'=>$tenant,'w'=>$route['worker_id'],'s'=>$route['worksite_id'],'dk'=>$route['dimension_key'],'id'=>$route['id'],'e'=>$route['effective_to']??'9999-12-31','f'=>$route['effective_from']]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $existing)if(quantaRouteOverlapConflict($route,$existing))throw new InvalidArgumentException('Overlapping routes would make legacy time or this captured placement ambiguous');
            $params=['t'=>$tenant,'w'=>$route['worker_id'],'s'=>$route['worksite_id'],'dk'=>$route['dimension_key'],'dv'=>json_encode($route['dimension_values'],JSON_THROW_ON_ERROR),
                'p'=>$route['placement_id'],'f'=>$route['effective_from'],'e'=>$route['effective_to'],'a'=>$route['quanta_assignment_id']];
            if($route['id'])$pdo->prepare('UPDATE quanta_time_routes SET worker_id=:w,worksite_id=:s,dimension_key=:dk,dimension_values_json=:dv,
                placement_id=:p,effective_from=:f,effective_to=:e,quanta_assignment_id=:a WHERE tenant_id=:t AND id=:id')->execute($params+['id'=>$route['id']]);
            else $pdo->prepare('INSERT INTO quanta_time_routes(tenant_id,worker_id,worksite_id,dimension_key,dimension_values_json,placement_id,effective_from,effective_to,quanta_assignment_id,created_by_user_id)
                VALUES(:t,:w,:s,:dk,:dv,:p,:f,:e,:a,:u)')->execute($params+['u'=>$actor]);
        }
        cf_tx_commit($pdo,$owns);
    }catch(Throwable $e){cf_tx_rollback($pdo,$owns);throw $e;}
    platformAuditLogWrite($tenant,$actor,'quanta.routes.saved',null,['count'=>count($routes)],['source'=>'quanta']);
    return quantaRoutes($tenant);
}

function quantaDeleteRoute(int $tenant,int $actor,int $id): array
{
    if($id<=0)throw new InvalidArgumentException('Route ID required');$pdo=getDB();$owns=cf_tx_begin($pdo);
    try{quantaWorkerLinks($tenant,true);$pdo->prepare('DELETE FROM quanta_time_routes WHERE tenant_id=:t AND id=:id')->execute(['t'=>$tenant,'id'=>$id]);cf_tx_commit($pdo,$owns);}
    catch(Throwable $e){cf_tx_rollback($pdo,$owns);throw $e;}
    platformAuditLogWrite($tenant,$actor,'quanta.route.deleted',$id,[],['source'=>'quanta']);return quantaRoutes($tenant);
}
