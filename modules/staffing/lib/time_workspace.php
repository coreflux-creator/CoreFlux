<?php
/** Time facts belong to the originating workspace, independent of catalog sharing. */
declare(strict_types=1);
require_once __DIR__.'/../../../core/tenant_scope.php';

function staffingTimeWorkspace(): int
{
    if (class_exists('StaffingNativeTimeEdit',false) && StaffingNativeTimeEdit::active()) return (int)StaffingNativeTimeEdit::owner('workspace');
    $workspace=(int)currentTenantId();
    if ($workspace<=0) throw new RuntimeException('Select the original time workspace.',403);
    return $workspace;
}

function staffingTimeQuery(string $sql,array $params=[]): array
{
    $params['tenant_id']=staffingTimeWorkspace();
    $pdo=getDB();
    if (!$pdo) throw new RuntimeException('Time storage is unavailable.',503);
    if (class_exists('StaffingNativeTimeEdit',false) && StaffingNativeTimeEdit::active() && !preg_match('/\bFOR UPDATE\s*$/i',$sql)) $sql.=' FOR UPDATE';
    $q=$pdo->prepare($sql);$q->execute($params);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function staffingTimeFind(string $sql,array $params=[]): ?array
{
    return staffingTimeQuery($sql,$params)[0]??null;
}

function staffingTimeWriteTable(string $table): string
{
    if (!in_array($table,['staffing_timesheets','time_entries','time_periods','tenant_staffing_settings'],true)) throw new LogicException('Use an explicit time workspace table.');
    return $table;
}

function staffingTimeInsert(string $table,array $data): int
{
    staffingTimeWriteTable($table);coreRecordNumbersRejectWrite($data);
    if (isset($data['tenant_id']) && (int)$data['tenant_id']!==staffingTimeWorkspace()) throw new RuntimeException('Time cannot move to another workspace.',409);
    $data['tenant_id']=staffingTimeWorkspace();$data['created_at']=$data['created_at']??date('Y-m-d H:i:s');
    $cols=array_map('_safeIdent',array_keys($data));$pdo=getDB();
    $pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}

function staffingTimeUpdate(string $table,int $id,array $data): int
{
    staffingTimeWriteTable($table);coreRecordNumbersRejectWrite($data);
    if (isset($data['tenant_id']) && (int)$data['tenant_id']!==staffingTimeWorkspace()) throw new RuntimeException('Time cannot move to another workspace.',409);
    unset($data['id'],$data['tenant_id'],$data['created_at']);
    if (!$data) return 0;
    $data['updated_at']=$data['updated_at']??date('Y-m-d H:i:s');
    $cols=array_map('_safeIdent',array_keys($data));
    $q=getDB()->prepare('UPDATE `'.$table.'` SET '.implode(',',array_map(static fn($col)=>'`'.$col.'`=?',$cols)).' WHERE tenant_id=? AND id=?');
    $q->execute([...array_values($data),staffingTimeWorkspace(),$id]);return $q->rowCount();
}

function staffingTimeDelete(string $table,int $id): int
{
    staffingTimeWriteTable($table);$q=getDB()->prepare('DELETE FROM `'.$table.'` WHERE tenant_id=? AND id=?');
    $q->execute([staffingTimeWorkspace(),$id]);return $q->rowCount();
}
