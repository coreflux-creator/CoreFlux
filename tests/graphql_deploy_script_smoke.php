<?php
/**
 * Smoke for /app/graphql/deploy/scripts/deploy.sh
 *
 * Validates the deploy script in --dry-run mode plus a real deploy into
 * /tmp. We don't need root, systemd, or nginx for these assertions —
 * the script is pure file-shuffling + yarn build + supergraph compose.
 *
 * Skips cleanly when:
 *   - node / yarn / router are not on PATH
 *   - /app/graphql/* is missing
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$script = '/app/graphql/deploy/scripts/deploy.sh';
$assert('deploy.sh exists',            is_file($script));
$assert('deploy.sh is executable',     is_executable($script));

foreach (['node', 'yarn', 'router'] as $bin) {
    if (trim((string) shell_exec("command -v $bin 2>/dev/null")) === '') {
        echo "SKIP: $bin not in PATH\n";
        exit(0);
    }
}

$tmpDst = '/tmp/deploy-smoke-' . bin2hex(random_bytes(4));
register_shutdown_function(function () use ($tmpDst) { shell_exec("rm -rf " . escapeshellarg($tmpDst)); });

// -------- DRY-RUN: no side effects, listing must include all expected steps.
echo "\nDry-run output\n";
$dry = (string) shell_exec("{$script} --dry-run --src=/app/graphql --dst={$tmpDst} 2>&1");
$assert('dry-run prints rsync line',                str_contains($dry, '[dry-run] rsync'));
$assert('dry-run mentions subgraph-coreflux build', str_contains($dry, 'subgraph-coreflux'));
$assert('dry-run mentions subgraph-jobdiva build',  str_contains($dry, 'subgraph-jobdiva'));
$assert('dry-run mentions mcp-server build',        str_contains($dry, 'mcp-server'));
$assert('dry-run mentions compose.mjs',             str_contains($dry, 'compose.mjs'));
$assert('dry-run ended with DONE',                  str_contains($dry, 'DONE'));
$assert('dry-run did NOT create destination dir',   !is_dir($tmpDst));

// -------- --skip-build: still composes, but skips the yarn install lines.
echo "\nSkip-build mode\n";
$out = (string) shell_exec("{$script} --skip-build --src=/app/graphql --dst={$tmpDst} 2>&1");
$assert('skip-build composed supergraph',           str_contains($out, '[compose] wrote') || str_contains($out, 'compose.mjs'));
$assert('skip-build mentions --skip-build hint',    str_contains($out, '(--skip-build)'));
$assert('skip-build wrote files to destination',    is_dir($tmpDst) && is_dir("{$tmpDst}/subgraph-coreflux"));

// Clean for a real run.
shell_exec("rm -rf " . escapeshellarg($tmpDst));

// -------- Real deploy: full path including yarn install.
echo "\nFull deploy (real rsync + build + compose)\n";
$out = (string) shell_exec("{$script} --src=/app/graphql --dst={$tmpDst} 2>&1");
$assert('full deploy succeeded (DONE)',             str_contains($out, "\nDONE."));
$assert('supergraph.graphql produced',              is_file("{$tmpDst}/router/supergraph.graphql"));
$assert('dist/index.js for coreflux',               is_file("{$tmpDst}/subgraph-coreflux/dist/index.js"));
$assert('dist/index.js for jobdiva',                is_file("{$tmpDst}/subgraph-jobdiva/dist/index.js"));
$assert('dist/index.js for mcp-server',             is_file("{$tmpDst}/mcp-server/dist/index.js"));
$sg = (string) @file_get_contents("{$tmpDst}/router/supergraph.graphql");
$assert('supergraph contains Placement type',       str_contains($sg, 'type Placement'));
$assert('supergraph contains JobDivaAssignment',    str_contains($sg, 'type JobDivaAssignment'));

// -------- Re-run should be idempotent.
echo "\nIdempotency (re-run on same dst)\n";
$out2 = (string) shell_exec("{$script} --skip-build --src=/app/graphql --dst={$tmpDst} 2>&1");
$assert('second run still ends with DONE',          str_contains($out2, "\nDONE."));

echo "\n=== Summary ===\n";
echo "Passed: {$pass}\n";
echo "Failed: {$fail}\n";
exit($fail === 0 ? 0 : 1);
