<?php
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_maintenance.php';
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks++; echo "PASS $name\n";
};
$refuses = static function (callable $fn, string $name) use ($assert): void {
    try { $fn(); } catch (RuntimeException $e) { $assert(true, $name); return; }
    $assert(false, $name);
};
$hash = static fn(string $s): string => hash('sha256', $s);
$root = '/home/123.cloudwaysapps.com/example/public_html';
$operation = str_repeat('a', 32);
$assert(fullCoreMaintenanceCrontabAbsent(1, '', "no crontab for fixture\n", 'fixture'),
    'exact native missing-table result is recognized');
foreach ([
    [0, '', "no crontab for fixture\n", 'fixture'],
    [2, '', "no crontab for fixture\n", 'fixture'],
    [1, '* * * * * true', "no crontab for fixture\n", 'fixture'],
    [1, '', "permission denied\n", 'fixture'],
    [1, '', "no crontab for another-user\n", 'fixture'],
    [1, '', "no crontab for fixture\npermission denied\n", 'fixture'],
    [1, '', '', 'fixture'],
    [1, '', "no crontab for \n", ''],
] as $unexpected) {
    $assert(!fullCoreMaintenanceCrontabAbsent(...$unexpected), 'scheduler failure cannot masquerade as absent table');
}
$lines = ['# installed crontab', 'MAILTO=""', 'SHELL=/bin/bash',
    '* * * * * cd ' . $root . ' && php cron/a.php; php cron/b.php > /tmp/test',
    '0 3 * * * php /another/app/job.php', '@reboot php ' . $root . '/cron/start.php',
    '0 0 * * * printf "day\\%s" $(date +\\%F) | php /relative-core-wrapper'];
$source = implode("\r\n", $lines); // Deliberately no trailing newline.
$review = [];
foreach ([4 => 'pause', 5 => 'keep', 6 => 'pause', 7 => 'pause'] as $n => $action) {
    $review[$n] = ['sha256' => $hash($lines[$n - 1]), 'action' => $action];
}
$make = static fn(string $s, array $r) => fullCoreMaintenanceCron($s, $hash($source), $root, $r, $operation);
$plan = $make($source, $review);
$assert($plan['paused_jobs'] === 3 && $plan['retained_jobs'] === 1, 'exact reviewed job scope');
$expected = $lines;
foreach ([3, 5, 6] as $i) $expected[$i] = '# coreflux-maintenance-' . $operation . ' ' . $expected[$i];
$assert($plan['held_bytes'] === implode("\r\n", $expected), 'comments env unrelated jobs shell syntax and line endings preserved');
$assert(fullCoreMaintenanceRestore($source, $hash($source), $plan['held_sha256'], $plan['held_bytes']) === $source,
    'restore original bytes without reconstructing commands');
$refuses(fn() => $make($source . "\n", $review), 'stale whole-scheduler review refused');
$missing = $review; unset($missing[7]);
$refuses(fn() => $make($source, $missing), 'unreviewed indirect job refused');
$changed = $review; $changed[4]['sha256'] = str_repeat('b', 64);
$refuses(fn() => $make($source, $changed), 'stale individual job refused');
$keep = $review; $keep[4]['action'] = 'keep';
$refuses(fn() => $make($source, $keep), 'explicit target-root job cannot be retained');
$extra = $review; $extra[2] = ['sha256' => $hash($lines[1]), 'action' => 'pause'];
$refuses(fn() => $make($source, $extra), 'environment line cannot be treated as a job');
$refuses(fn() => fullCoreMaintenanceRestore($source, $hash($source), $plan['held_sha256'], $plan['held_bytes'] . '# concurrent change'),
    'concurrent scheduler edits prevent automatic restoration');
$refuses(fn() => fullCoreMaintenanceRestore($source . 'changed', $hash($source), $plan['held_sha256'], $plan['held_bytes']),
    'damaged recovery copy refused');
$refuses(fn() => fullCoreMaintenanceWebRule("bad\nRewriteRule ^ - [L]"), 'operation cannot inject web directives');
$rule = fullCoreMaintenanceWebRule($operation);
$assert(str_contains($rule, 'InheritDownBefore') && str_contains($rule, '[R=503,L]'), 'parent guard applies before descendant routes');
$assert(!str_contains($rule, $root) && !str_contains($rule, $source), 'web guard contains no private recovery material');
$empty = fullCoreMaintenanceCron('', $hash(''), $root, [], $operation);
$assert($empty['held_bytes'] === '' && $empty['paused_jobs'] === 0, 'empty application scheduler stays empty');
foreach (["not a cron", "* * * * * php task\0", "# x\rbroken"] as $bad) {
    $refuses(fn() => fullCoreMaintenanceCron($bad, $hash($bad), $root, [], $operation), 'malformed scheduler input refused');
}
$absent = ['error' => 'Bad request: expected /api/<module>/<endpoint> or /api/v1/<module>/<resource>',
    'status' => 400, 'request_id' => str_repeat('a',16)];
$first = fullCoreMaintenanceAbsentSignature(400,json_encode($absent));
$absent['request_id'] = str_repeat('b',16);
$assert(fullCoreMaintenanceAbsentSignature(400,json_encode($absent)) === $first, 'known missing API route compares independently of trace ID');
$absent['error'] = 'Unexpected backend error';
$refuses(fn() => fullCoreMaintenanceAbsentSignature(400,json_encode($absent)), 'arbitrary bad request cannot pass cleanup');
$refuses(fn() => fullCoreMaintenanceAbsentSignature(200,'probe ran'), 'executed probe cannot pass missing-file check');
$refuses(fn() => fullCoreMaintenanceAbsentSignature(302,''), 'unexpected redirect cannot pass cleanup');
$assert(fullCoreMaintenanceAbsentSignature(404,'missing') !== fullCoreMaintenanceAbsentSignature(404,'different'), 'ordinary missing response body remains exact');
echo "PASS $checks maintenance preparation checks; no scheduler or webroot changed\n";
