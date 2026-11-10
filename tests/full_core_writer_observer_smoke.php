<?php
declare(strict_types=1);
define('CORE_WRITER_LIBRARY_ONLY', true);
require_once __DIR__ . '/../deploy/inspect_full_core_writers.php';
$checks = 0;
$check = static function(bool $ok, string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$refuse = static function(callable $fn, string $why) use ($check): void { try { $fn(); } catch (RuntimeException) { $check(true, $why); return; } $check(false, $why); };
$stat = static fn(int $pid, string $name, int $parent, string $ticks): string => "$pid ($name) S $parent " . implode(' ', array_fill(0, 17, '0')) . " $ticks 0\n";
$record = $stat(42, 'worker ) tricky', 17, '12345');
$check(coreWriterStat($record) === ['pid'=>42,'ppid'=>17,'state'=>'S','start_ticks'=>'12345'], 'Kernel comm closing parentheses preserve identity.');
$refuse(static fn()=>coreWriterStat('42 broken'), 'Malformed stat rejected.');
$status = "Name:\tphp\nPPid:\t17\nUid:\t1004\t1004\t1004\t1004\n";
$command = "php\0worker.php\0--password=must-not-leak\0";
$read = static fn(string $path): string => str_ends_with($path,'/stat') ? $record : (str_ends_with($path,'/status') ? $status : $command);
$p = coreWriterProcess(42, $read);
$check($p['core_account'] && $p['command_sha256'] === hash('sha256',$command), 'Native command fingerprint is bound without plaintext.');
$check(!str_contains(json_encode($p), 'must-not-leak'), 'Process arguments never appear in evidence.');
$check($p['command_title_sha256'] === hash('sha256',rtrim($command,"\0")), 'Normalized title preserves argument boundaries.');
$check(!$p['core_pool_title'], 'Ordinary CLI cannot claim pool title.');
$calls=0;
$refuse(static function() use(&$calls,$stat,$status): void {
    coreWriterProcess(42, static function(string $path) use(&$calls,$stat,$status): string {
        if(str_ends_with($path,'/stat')) return $stat(42,'php',17,(++$calls === 1)?'12345':'99999');
        return str_ends_with($path,'/status') ? $status : "php\0";
    });
}, 'PID reuse during observation is rejected.');
$refuse(static fn()=>coreWriterProcess(43,$read), 'PID mismatch is rejected.');
$statusReads=0;
$refuse(static function() use(&$statusReads,$record,$status,$command): void {
    coreWriterProcess(42,static function(string $path) use(&$statusReads,$record,$status,$command): string {
        if(str_ends_with($path,'/stat')) return $record;
        if(str_ends_with($path,'/status')) return ++$statusReads===1 ? $status : str_replace('1004','0',$status);
        return $command;
    });
}, 'Credential change during observation is rejected.');
$commandReads=0;
$refuse(static function() use(&$commandReads,$record,$status): void {
    coreWriterProcess(42,static function(string $path) use(&$commandReads,$record,$status): string {
        if(str_ends_with($path,'/stat')) return $record;
        if(str_ends_with($path,'/status')) return $status;
        return ++$commandReads===1 ? "php\0" : "other\0";
    });
}, 'Command change during observation is rejected.');
$refuse(static fn()=>coreWriterProcess(42,static fn(string $path):string=>str_ends_with($path,'/stat')?$record:(str_ends_with($path,'/status')?str_replace("PPid:\t17","PPid:\t18",$status):$command)), 'Ancestry mismatch is rejected.');
$empty=coreWriterProcess(42,static fn(string $path):string=>str_ends_with($path,'/stat')?$record:(str_ends_with($path,'/status')?$status:''));
$check($empty['empty_command'] && $empty['core_account'], 'Empty command owned by application stays in inventory.');
$external=coreWriterProcess(42,static fn(string $path):string=>str_ends_with($path,'/stat')?$record:(str_ends_with($path,'/status')?str_replace('1004','0',$status):"php\0/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html/cron/task.php\0"));
$check(!$external['core_account'] && $external['core_reference'], 'Other-owner Core references remain visible.');
$directory=sys_get_temp_dir().'/core-writer-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
try {
    file_put_contents($directory.'/job','first'); $errors=[];
    $one=coreWriterRegistry([$directory],$errors);
    file_put_contents($directory.'/job','other'); clearstatcache();
    $two=coreWriterRegistry([$directory],$errors);
    $check($one[$directory.'/job']['sha256']!==$two[$directory.'/job']['sha256'],'Same-size registry edit changes fingerprint.');
    file_put_contents($directory.'/added','x');
    $three=coreWriterRegistry([$directory],$errors);
    $check($two[$directory]['members_sha256']!==$three[$directory]['members_sha256'],'Added scheduler file changes membership fingerprint.');
    $missing=coreWriterRegistry([$directory.'/absent'],$errors);
    $check($missing[$directory.'/absent']['state']==='absent','Readable missing file is explicitly absent.');
    if (PHP_OS_FAMILY === 'Linux') {
        symlink($directory.'/job',$directory.'/link'); $linked=coreWriterRegistry([$directory.'/link'],$errors);
        file_put_contents($directory.'/job','third'); $changed=coreWriterRegistry([$directory.'/link'],$errors);
        $check($linked[$directory.'/link']['resolved']['sha256']!==$changed[$directory.'/link']['resolved']['sha256'],'Changed linked unit content changes registry fingerprint.');
        symlink('/dev/null',$directory.'/mask'); $masked=coreWriterRegistry([$directory.'/mask'],$errors);
        $check($masked[$directory.'/mask']['resolved']['masked']===true,'Native masked unit is explicit.');
        symlink($directory.'/missing',$directory.'/dangling'); $dangling=coreWriterRegistry([$directory.'/dangling'],$errors);
        $check($dangling[$directory.'/dangling']['absent_target']===$directory.'/missing','Readable dangling unit target is pinned as absent.');
        file_put_contents($directory.'/missing','new job'); $appeared=coreWriterRegistry([$directory.'/dangling'],$errors);
        $check(isset($appeared[$directory.'/dangling']['resolved']['sha256'])&&!isset($appeared[$directory.'/dangling']['absent_target']),'Newly installed dangling target changes observed registry.');
        unlink($directory.'/link');unlink($directory.'/mask');unlink($directory.'/dangling');unlink($directory.'/missing');
    }
} finally { foreach(['job','added'] as $name) if(is_file($directory.'/'.$name)) unlink($directory.'/'.$name); rmdir($directory); }
$refuse(static fn()=>coreWriterObserve(['version'=>1,'action'=>'inspect-writers','owner_uid'=>0,'challenge'=>str_repeat('a',64)]),'Wrong native owner cannot observe.');
echo "full_core_writer_observer_smoke: $checks checks passed\n";
