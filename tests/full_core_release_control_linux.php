<?php
/** Real independent processes verify the fixed owner lock and durable response-loss policy. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !is_file('/run/core-production-upgrade-fixture')) throw new RuntimeException('Disposable native container required.');
require __DIR__ . '/../deploy/full_core_release_control.php';

if (($argv[1] ?? '') === '--child') {
    try {
        $spec = json_decode(file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
        $action = $argv[3]; $bytes = FullCoreReleaseControl::encode($spec); $hash = hash('sha256', $bytes);
        $control = new FullCoreReleaseControl($spec, $hash);
        $trace = '/fixture/owner-' . posix_geteuid() . '/trace';
        $native = static function () use ($trace): array {
            file_put_contents($trace, "side effect\n", FILE_APPEND);
            return ['trace_sha256' => hash_file('sha256', $trace)];
        };
        // These are explicit synthetic receipt contracts. The combined native fixture obtains its peer receipts
        // from actual separate-owner maintenance, database and file operations.
        $peerFor = static function (string $step, bool $wrong = false) use ($control,$spec,$hash): void {
            $action = $control->peerRequiredFor($step); if ($action === null) return;
            $uid = posix_geteuid() === 1003 ? 1004 : 1003;
            $receipt = ['operation'=>$spec['operation'],'candidate_commit'=>$spec['candidate_commit'],
                'manifest_sha256'=>$spec['manifest_sha256'],'migration_plan_sha256'=>$spec['migration_plan_sha256'],
                'root'=>$spec['root'],'owner_uid'=>$uid,'owner_plan_sha256'=>$spec['owner_plan_sha256'][$uid],
                'action'=>$action,'evidence_sha256'=>$hash,'native_receipt_sha256'=>hash('sha256','synthetic peer contract '.$action)];
            if ($wrong) $receipt['operation'] = str_repeat('f',32);
            $control->acceptPeerOutcome($receipt);
        };
        if ($action === 'lock') { echo "locked\n"; fflush(STDOUT); fgets(STDIN); }
        elseif ($action === 'inspect') echo json_encode($control->snapshot(), JSON_THROW_ON_ERROR);
        elseif ($action === 'abandon') $control->abandonUnstarted();
        elseif ($action === 'hold') $control->perform('hold', $hash, $native);
        elseif ($action === 'skip') $control->perform('apply-files', $hash, $native);
        elseif ($action === 'no-peer') $control->perform('prepare-files', $hash, $native);
        elseif ($action === 'wrong-peer') $peerFor('prepare-files',true);
        elseif ($action === 'no-prepared-peer') {
            foreach (['hold','prepare-files'] as $step) { $peerFor($step); $control->perform($step,$hash,$native); }
            $control->perform('upgrade-database',$hash,$native);
        }
        elseif ($action === 'crash-hold') { $control->begin('hold', $hash); $native(); exit(73); }
        elseif ($action === 'resolve-hold' || $action === 'wrong-receipt') {
            if (!is_file($trace) || file_get_contents($trace) !== "side effect\n") throw new RuntimeException('Observed native outcome differs.');
            $pending = $control->snapshot()['pending'];
            $receipt = $control->outcome('hold', $hash, hash('sha256', FullCoreReleaseControl::encode(['trace_sha256' => hash_file('sha256', $trace)])));
            if ($action === 'wrong-receipt') $receipt['candidate_commit'] = str_repeat('f', 40);
            $control->finish('hold', $pending['token'], $receipt);
        } elseif ($action === 'recover') $control->requestRecovery();
        elseif ($action === 'finish-recovery') {
            $steps = posix_geteuid() === 1004 ? ['restore-database','recover-files','verify-recovery','refresh-recovery-cache','restore-routing']
                : ['recover-files','verify-recovery','restore-scheduler'];
            foreach ($steps as $step) { $peerFor($step); $control->perform($step, $hash, $native); }
        } elseif ($action === 'crash-activate') {
            foreach (['hold','prepare-files','upgrade-database','apply-files','verify-files','refresh-cache'] as $step) {
                $peerFor($step); $control->perform($step, $hash, $native);
            }
            $control->begin('activate', $hash); exit(73);
        } elseif ($action === 'wrong-upgrade') {
            foreach (['hold','prepare-files'] as $step) { $peerFor($step); $control->perform($step, $hash, $native); }
            $peerFor('upgrade-database');
            $control->begin('upgrade-database', $hash);
            $binding = $spec + ['release_control_spec_sha256' => $hash]; $binding['migration_plan_sha256'] = str_repeat('e',64);
            $control->assertUpgrade($binding, $hash);
        } else throw new RuntimeException('Unknown fixture action.');
        exit(0);
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(2); }
}
if (posix_geteuid() !== 0) throw new RuntimeException('Container initializer required.');
$root = FullCoreReleaseControl::ROOT;
mkdir($root, 0775, true); chown($root, 1004); chgrp($root, 33); chmod($root, 0775);
$private = dirname($root) . '/private_html'; mkdir($private, 0775); chown($private, 1004); chgrp($private, 33); chmod($private, 0775);
foreach ([1003,1004] as $uid) { mkdir('/fixture/owner-' . $uid, 0700); chown('/fixture/owner-' . $uid, $uid); }
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $label);
    $checks++; echo "PASS $label\n";
};
$spec = static function (string $label): string {
    $s = ['version' => 1, 'root' => FullCoreReleaseControl::ROOT, 'operation' => substr(hash('sha256', $label),0,32),
        'candidate_commit' => str_repeat('a',40), 'manifest_sha256' => str_repeat('b',64), 'migration_plan_sha256' => str_repeat('c',64),
        'owner_plan_sha256' => [1003 => hash('sha256','master'), 1004 => hash('sha256','app')]];
    $path = '/fixture/spec-' . $label . '.json'; file_put_contents($path, FullCoreReleaseControl::encode($s)); return $path;
};
$start = static function (int $uid, string $spec, string $action): array {
    $process = proc_open(['/usr/sbin/runuser','-u',$uid === 1003 ? 'coremaster' : 'coreapp','--',PHP_BINARY,__FILE__,'--child',$spec,$action],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['file','/fixture/control.stderr','a']], $pipes);
    return [$process,$pipes];
};
$run = static function (int $uid, string $spec, string $action) use ($start): array {
    [$process,$pipes] = $start($uid,$spec,$action); fclose($pipes[0]); $bytes = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return [proc_close($process),$bytes];
};
$first = $spec('first'); $second = $spec('second');
[$process,$pipes] = $start(1004,$first,'lock');
$check(fgets($pipes[1]) === "locked\n", 'first owner process holds the fixed application release lock');
$check($run(1004,$first,'inspect')[0] === 2 && $run(1004,$second,'inspect')[0] === 2, 'same and different operations cannot overlap the owner lock');
$check($run(1003,$first,'inspect')[0] === 0, 'master account has its own native lock for coordinated two-owner work');
fwrite($pipes[0], "release\n"); fclose($pipes[0]); fclose($pipes[1]);
$check(proc_close($process) === 0, 'first process releases only its live lock');
$check($run(1004,$first,'inspect')[0] === 0, 'same operation can inspect durable state in a new process');
$check($run(1004,$second,'inspect')[0] === 2, 'another operation cannot bypass an unresolved release after process exit');
$check($run(1004,$first,'skip')[0] === 2 && !is_file('/fixture/owner-1004/trace'), 'phase skipping fails before invoking the side effect');
$check($run(1004,$first,'abandon')[0] === 0 && $run(1004,$second,'inspect')[0] === 0, 'explicitly abandoning a never-started operation releases the next operation');
$check($run(1003,$first,'abandon')[0] === 0, 'other owner can independently abandon its never-started session');
$masterCrash = $spec('master-crash');
$check($run(1003,$masterCrash,'crash-hold')[0] === 73 && $run(1003,$masterCrash,'recover')[0] === 0,
    'uncertain first master side effect can select explicit recovery');
$check($run(1003,$masterCrash,'abandon')[0] === 2, 'recovery cannot relabel an uncertain first side effect as never started');
$check($run(1003,$masterCrash,'finish-recovery')[0] === 0, 'master uncertain hold requires verified recovery before another operation');
$check($run(1004,$second,'crash-hold')[0] === 73, 'child terminates after a real side effect and before receipt acknowledgment');
$check($run(1004,$second,'hold')[0] === 2 && file_get_contents('/fixture/owner-1004/trace') === "side effect\n", 'lost response cannot replay the side effect');
$check($run(1004,$second,'wrong-receipt')[0] === 2, 'different-candidate receipt cannot complete an uncertain action');
$check($run(1004,$second,'resolve-hold')[0] === 0 && file_get_contents('/fixture/owner-1004/trace') === "side effect\n",
    'independent native outcome inspection completes the original intent without rerunning it');
$check($run(1004,$second,'hold')[0] === 2 && $run(1004,$second,'abandon')[0] === 2, 'completed action cannot replay or be declared unstarted');
$check($run(1004,$second,'no-peer')[0] === 2 && file_get_contents('/fixture/owner-1004/trace') === "side effect\n",
    'missing other-owner hold prevents file backup phase before its side effect');
$check($run(1004,$second,'wrong-peer')[0] === 2, 'another release cannot supply required other-owner evidence');
$changed = json_decode(file_get_contents($second),true); $changed['manifest_sha256'] = str_repeat('d',64);
file_put_contents('/fixture/spec-changed.json',FullCoreReleaseControl::encode($changed));
$check($run(1004,'/fixture/spec-changed.json','inspect')[0] === 2, 'same operation cannot silently switch manifest');
$check($run(1004,$second,'recover')[0] === 0 && $run(1004,$second,'hold')[0] === 2, 'recovery is durable and forbids forward execution');
$check($run(1004,$second,'finish-recovery')[0] === 0, 'only ordered verified recovery phases can reach recovered state');
$noPrepared=$spec('without-prepared-peer'); $traceBefore=strlen(file_get_contents('/fixture/owner-1004/trace'));
$check($run(1004,$noPrepared,'no-prepared-peer')[0] === 2
    && strlen(file_get_contents('/fixture/owner-1004/trace')) === $traceBefore+2*strlen("side effect\n"),
    'database upgrade refuses before its side effect until the other owner confirms completed file backups');
$check($run(1004,$noPrepared,'recover')[0] === 0 && $run(1004,$noPrepared,'finish-recovery')[0] === 0,
    'missing backup confirmation leaves recoverable control state');
$third = $spec('third');
$check($run(1004,$third,'crash-activate')[0] === 73, 'activation intent persists even if the process dies before its response');
$check($run(1004,$third,'recover')[0] === 2, 'unknown activation outcome forbids restoration of the old database backup');
$fourth = $spec('fourth');
$check($run(1004,$fourth,'inspect')[0] === 2, 'another release cannot bypass uncertain activation');
$check($run(1003,$fourth,'wrong-upgrade')[0] === 2, 'master owner cannot obtain application database upgrade intent');
// Damage is confined to this disposable container and demonstrates that a terminal flag alone is insufficient.
$identity = json_decode(file_get_contents($third),true)['operation'];
$statePath = $private . '/core-release-control-1004/operation-' . $identity . '/state.json';
$original = file_get_contents($statePath); $state = json_decode($original,true); $state['terminal'] = 'complete'; $state['pending'] = null;
file_put_contents($statePath,FullCoreReleaseControl::encode($state));
$check($run(1004,$fourth,'inspect')[0] === 2, 'forged terminal flag without complete receipt sequence does not unlock a new operation');
file_put_contents($statePath,$original);
$receiptPath = $private . '/core-release-control-1004/operation-' . $identity . '/hold.json';
file_put_contents($receiptPath,'damaged');
$check($run(1004,$third,'inspect')[0] === 2, 'changed completed receipt stops state inspection and future progress');
echo "PASS native release control: $checks checks; independent processes and synthetic side effects only\n";
