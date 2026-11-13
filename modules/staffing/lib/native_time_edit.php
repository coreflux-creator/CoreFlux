<?php
/** A native time edit keeps directory, placement, time and audit effects atomic. */
declare(strict_types=1);
require_once __DIR__.'/time_workspace.php';
require_once __DIR__.'/../../placements/lib/identity.php';
require_once __DIR__.'/../../people/lib/company_relationships.php';

final class StaffingNativeTimeEdit
{
    private static ?PDO $connection=null;
    private static array $scope=[];

    public static function active(): bool
    {
        return self::$connection!==null && self::$connection===getDB() && self::$connection->inTransaction();
    }

    public static function owner(string $module): ?int
    {
        return self::active()?(self::$scope[$module]??null):null;
    }

    public static function run(callable $work): mixed
    {
        $pdo=getDB();$workspace=staffingTimeWorkspace();
        if (!$pdo) throw new RuntimeException('Time storage is unavailable.',503);
        if (self::$connection!==null && (!self::active() || (self::$scope['workspace']??0)!==$workspace)) throw new RuntimeException('The time edit context changed. Reload this workspace.',409);
        $nested=$pdo->inTransaction();$previousConnection=self::$connection;$previousScope=self::$scope;
        // Prevent a deadlock from silently turning subsequent writes into autocommits.
        $auto=$nested?null:(int)$pdo->query('SELECT @@autocommit')->fetchColumn();
        if (!$nested) {$pdo->exec('SET autocommit=0');$pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');}
        try {
            return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$work):mixed {
                $placements=companyRelationshipScope($pdo,$workspace,'placements');
                $people=companyRelationshipScope($pdo,$workspace,'people');
                self::$connection=$pdo;self::$scope=['workspace'=>$workspace,'placements'=>$placements['owner'],'people'=>$people['owner']];
                return $work();
            });
        } finally {
            self::$connection=$previousConnection;self::$scope=$previousScope;
            if (!$nested) {if ($pdo->inTransaction()) $pdo->rollBack();$pdo->exec('SET autocommit='.$auto);}
        }
    }
}

function staffingTimeCurrentPlacement(int $placementId,int $personId): array
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('A protected native time edit is required.');
    $identity=placementIdentityResolve(getDB(),(int)StaffingNativeTimeEdit::owner('placements'),$placementId,true);
    if (!$identity) throw new RuntimeException('The original placement is unavailable in this directory.',404);
    if ($identity['merged']) throw new RuntimeException('This time belongs to a retained placement. Review its original time history and use the current placement for new entries.',409);
    if ((int)$identity['placement']['person_id']!==$personId) throw new RuntimeException('The placement belongs to another worker.',409);
    return $identity['placement'];
}

function staffingTimeAssertEditableEntry(array $entry): void
{
    if (!in_array($entry['status']??'', ['draft','pending_review','rejected'],true)) throw new RuntimeException('Approved, superseded or downstream time must retain its original evidence. Use the correction workflow.',409);
    staffingTimesheetAssertEntryNotSettled($entry);
    staffingTimeAssertOpenEntryPeriod($entry);
    staffingTimeCurrentPlacement((int)$entry['placement_id'],(int)$entry['person_id']);
    $q=getDB()->prepare('SELECT id FROM quanta_time_imports WHERE tenant_id=? AND time_entry_id=? FOR UPDATE');
    $q->execute([staffingTimeWorkspace(),$entry['id']]);
    if ($q->fetchColumn()) throw new RuntimeException('Use source-time correction review to change imported Quanta time.',409);
}

function staffingTimeAssertOpenEntryPeriod(array $entry): void
{
    $period=staffingTimeFind('SELECT id,status FROM time_periods WHERE tenant_id=:tenant_id AND id=:id',['id'=>$entry['period_id']]);
    if (!$period || $period['status']!=='open') throw new RuntimeException('The original time period is locked, closed or unavailable. Review its correction workflow.',409);
}

function staffingTimeResolveOpenPeriod(string $workDate,string $periodStart,string $periodEnd): int
{
    if (!StaffingNativeTimeEdit::active()) throw new LogicException('A protected native time edit is required.');
    $period=staffingTimeFind('SELECT id,status FROM time_periods WHERE tenant_id=:tenant_id AND start_date<=:day_lo AND end_date>=:day_hi ORDER BY start_date DESC,id DESC LIMIT 1',['day_lo'=>$workDate,'day_hi'=>$workDate]);
    if ($period) {
        if ($period['status']!=='open') throw new RuntimeException('The time period for this date is locked or closed. Review its correction workflow.',409);
        return (int)$period['id'];
    }
    return staffingTimeInsert('time_periods',['period_type'=>'weekly','start_date'=>$periodStart,'end_date'=>$periodEnd,'label'=>'Week of '.$periodStart,'status'=>'open']);
}

function staffingTimeAssertEditableHeader(array $header,bool $reopening=false): void
{
    if (!in_array($header['status']??'', ['draft','submitted','rejected'],true)) throw new RuntimeException('This timesheet is approved or already feeds downstream work. Use the correction workflow.',409);
    staffingTimesheetValidateWeek((int)$header['person_id'],(string)$header['period_start'],(string)$header['period_end']);
    $entries=staffingTimeQuery('SELECT * FROM time_entries WHERE tenant_id=:tenant_id AND timesheet_id=:id ORDER BY id',['id'=>$header['id']]);
    foreach ($entries as $entry) {
        if (!in_array($entry['status'],['draft','pending_review','rejected'],true)) continue;
        if ((int)$entry['person_id']!==(int)$header['person_id']) throw new RuntimeException('Review this timesheet\'s original worker identity.',409);
        // Reopening changes workflow state only; it never permits changing a
        // source value or replacing an already approved sibling.
        staffingTimeCurrentPlacement((int)$entry['placement_id'],(int)$header['person_id']);
        if ($reopening) {staffingTimesheetAssertEntryNotSettled($entry);staffingTimeAssertOpenEntryPeriod($entry);}
    }
}
