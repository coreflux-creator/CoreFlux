<?php
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_maintenance.php';
require_once __DIR__ . '/../deploy/full_core_maintenance_channel.php';
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks++;
};
$reject = static function (callable $fn, string $name) use ($assert): void {
    try { $fn(); } catch (Throwable $e) { $assert(true, $name); return; }
    $assert(false, $name);
};
$op = str_repeat('a', 32); $path = 'core-maintenance-' . $op . '-' . str_repeat('b', 32) . '.php';
$raw = ['pool' => 'fixture', 'listen-queue' => 0, 'idle-processes' => 1, 'active-processes' => 1,
    'total-processes' => 2, 'accepted-conn' => 10, 'procs' => [
        ['pid' => 10, 'state' => 'Running', 'script' => '/app/' . $path],
        ['pid' => 11, 'state' => 'Idle', 'request-uri' => '/private-user-information', 'user' => 'private-user'],
    ]];
$filter = static fn(array $value) => fullCoreMaintenanceChannelPool($value, 'fixture', 10, '/app/' . $path);
$quiet = $filter($raw);
$assert($quiet['request_pool_quiet'] && !$quiet['other_busy_processes'], 'complete quiet pool accepted');
$assert(!str_contains(json_encode($quiet), 'private-user'), 'request details never returned');
foreach (['Running', 'Finishing', 'Reading headers', 'unknown future state'] as $state) {
    $v = $raw; $v['procs'][1]['state'] = $state; $v['idle-processes'] = 0; $v['active-processes'] = 2;
    $assert(!$filter($v)['request_pool_quiet'] && $filter($v)['other_busy_processes'] === 1, 'non-idle peers block drain');
}
foreach (['pool' => 'another', 'listen-queue' => 1, 'active-processes' => 0, 'total-processes' => 3] as $key => $value) {
    $v = $raw; $v[$key] = $value; $assert(!$filter($v)['request_pool_quiet'], 'wrong pool/queue/count blocks drain');
}
$v = $raw; $v['procs'][0]['script'] = '/another.php'; $assert(!$filter($v)['request_pool_quiet'], 'own script mismatch blocks drain');
$v = $raw; $v['procs'][0]['pid'] = 12; $assert(!$filter($v)['request_pool_quiet'], 'missing own process blocks drain');
$v = $raw; $v['procs'][1]['pid'] = 10; $reject(fn() => $filter($v), 'duplicate pid rejected');
$v = $raw; $v['listen-queue'] = -1; $reject(fn() => $filter($v), 'invalid count rejected');
$spec = ['operation' => $op, 'candidate_commit' => str_repeat('c', 40), 'path' => $path,
    'root' => '/fixture/public_html', 'private_directory' => '/fixture/private', 'host' => 'fixture.local', 'pool' => 'fixture',
    'token_sha256' => str_repeat('d', 64), 'held_web_sha256' => hash('sha256', fullCoreMaintenanceWebRule($op, $path)),
    'library_sha256' => hash_file('sha256', __DIR__ . '/../deploy/full_core_maintenance_channel.php'),
    'issued_at' => 1000, 'expires' => 2000];
$assert(str_starts_with(fullCoreMaintenanceChannelRender($spec), '<?php'), 'valid bound template rendered');
foreach (['path' => '../app.php', 'private_directory' => '/fixture/public_html/private', 'root' => '/fixture/../public_html',
    'host' => "fixture.local\nInjected", 'expires' => 50000, 'candidate_commit' => 'bad'] as $key => $value) {
    $v = $spec; $v[$key] = $value; $reject(fn() => fullCoreMaintenanceChannelRender($v), 'invalid binding rejected');
}
$reject(fn() => fullCoreMaintenanceWebRule($op, 'api.php'), 'arbitrary maintenance exemption rejected');
$reject(fn() => fullCoreMaintenanceWebRule($op, str_replace($op, str_repeat('e', 32), $path)), 'other operation exemption rejected');
$reject(fn() => fullCoreMaintenanceChannelRun($spec, ['action' => 'status']), 'CLI cannot impersonate FPM');
echo "PASS $checks maintenance-channel checks\n";
