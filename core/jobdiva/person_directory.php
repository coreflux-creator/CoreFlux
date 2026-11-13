<?php
declare(strict_types=1);
require_once __DIR__.'/../sub_tenants.php';

/** Return the common People/Placements owner without cached sharing decisions. */
function jobdivaPersonDirectoryCheck(PDO $pdo, int $tenant, bool $lock = false): int
{
    if ($lock && !$pdo->inTransaction()) throw new LogicException('Directory locking requires a transaction');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $s = $pdo->prepare('SELECT parent_id,tenant_type,is_active FROM tenants WHERE id=:t'.$suffix);
    $s->execute(['t'=>$tenant]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row || !(int)$row['is_active']) throw new RuntimeException('The Core workspace is no longer active');
    $s=$pdo->prepare('SELECT module_key,scope_mode FROM tenant_module_scope WHERE tenant_id=:t AND module_key IN ("people","placements")'.$suffix);
    $s->execute(['t'=>$tenant]); $modes=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    $owners=[];
    foreach (['people','placements'] as $key) {
        $mode=$modes[$key]??SUBTENANT_MODULE_SCOPE_DEFAULTS[$key];
        if(!in_array($mode,['shared','isolated'],true))throw new RuntimeException('Review this workspace sharing configuration.',409);
        $owners[$key]=$row['tenant_type']==='sub'&&$row['parent_id']&&$mode==='shared'?(int)$row['parent_id']:$tenant;
    }
    if($owners['people']!==$owners['placements'])throw new RuntimeException('Review People and Placements sharing before importing this assignment.',409);
    $s=$pdo->prepare('SELECT is_active FROM tenants WHERE id=?'.$suffix);$s->execute([$owners['people']]);
    if((int)$s->fetchColumn()!==1)throw new RuntimeException('The People directory owner is no longer active.',409);
    return $owners['people'];
}
