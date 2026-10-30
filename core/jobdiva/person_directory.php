<?php
declare(strict_types=1);
require_once __DIR__.'/../sub_tenants.php';

/** JobDiva's placement joins still require tenant-local people. Never create hidden parent/child duplicates. */
function jobdivaPersonDirectoryCheck(PDO $pdo, int $tenant, bool $lock = false): void
{
    if ($lock && !$pdo->inTransaction()) throw new LogicException('Directory locking requires a transaction');
    $suffix = $lock ? ' FOR UPDATE' : '';
    $s = $pdo->prepare('SELECT parent_id,tenant_type,is_active FROM tenants WHERE id=:t'.$suffix);
    $s->execute(['t'=>$tenant]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row || !(int)$row['is_active']) throw new RuntimeException('The Core workspace is no longer active');
    $s=$pdo->prepare('SELECT module_key,scope_mode FROM tenant_module_scope WHERE tenant_id=:t AND module_key IN ("people","placements")'.$suffix);
    $s->execute(['t'=>$tenant]); $modes=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['people','placements'] as $key) {
        $mode=$modes[$key]??SUBTENANT_MODULE_SCOPE_DEFAULTS[$key];
        if ($row['tenant_type']==='sub' && $row['parent_id'] && $mode!=='isolated') {
            throw new RuntimeException('JobDiva identity intake requires people and placements in this workspace. Shared-directory JobDiva projection needs separate reconciliation.');
        }
    }
}
