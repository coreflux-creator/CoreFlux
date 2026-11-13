<?php
/** Fresh directory policies and locked current company references for business writes. */
declare(strict_types=1);
require_once __DIR__.'/company_identity.php';
require_once __DIR__.'/../../../core/jobdiva/party_scope.php';

function companyRelationshipAtomic(PDO $pdo,callable $write): mixed
{
    $outer=$pdo->inTransaction();$save='company_ref_'.bin2hex(random_bytes(8));
    if($outer)$pdo->exec('SAVEPOINT '.$save);else$pdo->beginTransaction();
    try{$result=$write();if($outer)$pdo->exec('RELEASE SAVEPOINT '.$save);else$pdo->commit();return$result;}
    catch(Throwable $e){if($pdo->inTransaction()){if($outer){$pdo->exec('ROLLBACK TO SAVEPOINT '.$save);$pdo->exec('RELEASE SAVEPOINT '.$save);}else$pdo->rollBack();}throw$e;}
}

function companyRelationshipScope(PDO $pdo,int $workspace,string $module): array
{
    if(!$pdo->inTransaction()||!in_array($module,['staffing','placements','ap'],true))throw new LogicException('A current business-reference transaction is required.');
    $companies=jobdivaPartyDirectoryScope($pdo,$workspace,true);$tenant=$companies['tenant'];
    $q=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=? AND module_key=? FOR UPDATE');$q->execute([$workspace,$module]);$mode=$q->fetchColumn();
    $mode=$mode===false?(SUBTENANT_MODULE_SCOPE_DEFAULTS[$module]??'isolated'):$mode;
    if(!in_array($mode,['shared','isolated'],true))throw new RuntimeException('Review this workspace sharing configuration.',409);
    $owner=$tenant['tenant_type']==='sub'&&!empty($tenant['parent_id'])&&$mode==='shared'?(int)$tenant['parent_id']:$workspace;
    $q=$pdo->prepare('SELECT is_active FROM tenants WHERE id=? FOR UPDATE');$q->execute([$owner]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('The owning workspace is no longer active.',409);
    return ['workspace'=>$workspace,'owner'=>$owner,'company_owner'=>$companies['owner'],'module'=>$module,'mode'=>$mode];
}

function companyRelationshipId(mixed $value): ?int
{
    if($value===null||$value===''||$value===0||$value==='0')return null;
    if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]*$/D',(string)$value)
        ||filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)throw new InvalidArgumentException('Choose a valid existing record.');
    return(int)$value;
}

/** A shared consumer cannot acquire a company private to one viewer. */
function companyRelationshipCurrent(PDO $pdo,array $scope,int $company): array
{
    if(!$pdo->inTransaction())throw new LogicException('A company reference must stay locked through its write.');
    $consumerOwner=jobdivaPartyDirectoryScope($pdo,$scope['owner'],true)['owner'];
    if($consumerOwner!==$scope['company_owner'])throw new RuntimeException('This shared record cannot use a company private to this workspace. Review its sharing setup.',409);
    $identity=companyIdentityResolve($pdo,$scope['company_owner'],$company,true);
    if(!$identity)throw new InvalidArgumentException('Choose a current company in this workspace directory.');
    if($identity['merged'])throw new RuntimeException('This company was merged. Refresh and choose its current company number.',409);
    return$identity['company'];
}
