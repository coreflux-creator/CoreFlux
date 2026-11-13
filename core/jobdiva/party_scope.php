<?php
/** Read company ownership afresh, including inside long-running source imports. */
declare(strict_types=1);
require_once __DIR__.'/../sub_tenants.php';

function jobdivaPartyDirectoryScope(PDO $pdo,int $workspace,bool $lock=false): array
{
    if($workspace<=0||($lock&&!$pdo->inTransaction()))throw new InvalidArgumentException('An active company workspace is required.');
    $suffix=$lock?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT id,parent_id,tenant_type,is_active FROM tenants WHERE id=?'.$suffix);$q->execute([$workspace]);$tenant=$q->fetch(PDO::FETCH_ASSOC);
    if(!$tenant||!(int)$tenant['is_active'])throw new RuntimeException('The workspace is no longer active.',409);
    $q=$pdo->prepare('SELECT scope_mode FROM tenant_module_scope WHERE tenant_id=? AND module_key="companies"'.$suffix);$q->execute([$workspace]);
    $mode=$q->fetchColumn();$mode=$mode===false?SUBTENANT_MODULE_SCOPE_DEFAULTS['companies']:$mode;
    if(!in_array($mode,['shared','isolated'],true))throw new RuntimeException('Review the company directory configuration.',409);
    $owner=$tenant['tenant_type']==='sub'&&!empty($tenant['parent_id'])&&$mode==='shared'?(int)$tenant['parent_id']:$workspace;
    $q=$pdo->prepare('SELECT id,is_active FROM tenants WHERE id=?'.$suffix);$q->execute([$owner]);$directory=$q->fetch(PDO::FETCH_ASSOC);
    if(!$directory||!(int)$directory['is_active'])throw new RuntimeException('The company directory is no longer active.',409);
    return ['workspace'=>$workspace,'owner'=>$owner,'tenant'=>$tenant,'mode'=>$mode,'directory'=>$directory];
}
