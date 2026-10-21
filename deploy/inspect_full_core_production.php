<?php
/** Read-only production inventory. No install, migration, bootstrap or activation. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--inspect-production') {
    throw new RuntimeException('Explicit production inspection CLI required.');
}
$requestedRoot = rtrim((string) getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT'), '/');
$root = realpath($requestedRoot);
$package = realpath(dirname(__DIR__));
if (!$root || $root !== $requestedRoot || $root === $package
    || !preg_match('~^/home/[^/]+\.cloudwaysapps\.com/[a-z0-9]+/public_html$~D', $root)
    || basename(dirname($root)) === 'muzqvdvqbx') {
    throw new RuntimeException('Exact production app root required; staging is excluded.');
}
$manifest = json_decode((string) file_get_contents($package . '/integration-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['product'] ?? '') !== 'full-core-staging'
    || !preg_match('/^[a-f0-9]{40}$/D', $manifest['commit'] ?? '')
    || !is_array($manifest['files'] ?? null) || !$manifest['files']) {
    throw new RuntimeException('Verified full-Core candidate manifest required.');
}
$changed = [];
$added = [];
foreach ($manifest['files'] as $relative => $hash) {
    if (!is_string($relative) || preg_match('/[\x00-\x1f\x7f]/', $relative)
        || str_contains($relative, '\\') || str_contains($relative, ':')
        || str_starts_with($relative, '/') || in_array('..', explode('/', $relative), true)
        || str_contains($relative, '.local.') || basename($relative) === '.env'
        || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
        throw new RuntimeException('Unsafe candidate path or digest.');
    }
    $candidate = $package . '/' . $relative;
    if (is_link($candidate) || !is_file($candidate)
        || !str_starts_with((string) realpath($candidate), $package . '/')
        || !hash_equals($hash, hash_file('sha256', $candidate))) {
        throw new RuntimeException('Candidate integrity failure: ' . $relative);
    }
    $live = $root . '/' . $relative;
    $parent = dirname($live);
    while (!file_exists($parent) && !is_link($parent)) $parent = dirname($parent);
    $resolvedParent = realpath($parent);
    if (is_link($live) || !$resolvedParent
        || ($resolvedParent !== $root && !str_starts_with($resolvedParent, $root . '/'))) {
        throw new RuntimeException('Live path escapes the app: ' . $relative);
    }
    if (!is_file($live)) $added[] = $relative;
    elseif (!hash_equals($hash, hash_file('sha256', $live))) $changed[] = $relative;
}

// Use deployed configuration, never candidate configuration or an API bootstrap.
require_once $root . '/core/db.php';
require_once $package . '/core/migration_hash.php';
$db = getDB();
if (!$db || $db->query('SELECT DATABASE()')->fetchColumn() !== basename(dirname($root))) {
    throw new RuntimeException('Production database does not match the app identity.');
}
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $ledger = $db->query('SELECT filename, sha256 FROM _migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = [];
    $drift = [];
    foreach (array_keys($manifest['files']) as $relative) {
        if (!preg_match('~^(core/migrations|modules/[^_/][^/]*/migrations)/[^/]+\.sql$~D', $relative)) continue;
        $key = str_starts_with($relative, 'core/') ? basename($relative) : $relative;
        if (!isset($ledger[$key])) $pending[] = $relative;
        elseif (!corefluxMigrationHashMatches($ledger[$key], (string) file_get_contents($package . '/' . $relative))) {
            $drift[] = $relative;
        }
    }
    $clients = $db->query("SELECT t.id, t.name, COUNT(DISTINCT c.id) AS client_count
        FROM tenants t LEFT JOIN companies c ON c.tenant_id=t.id AND c.deleted_at IS NULL
        AND EXISTS (SELECT 1 FROM company_roles r WHERE r.company_id=c.id AND r.role='client')
        WHERE t.name IN ('Thunderhawk', 'charge d\'Affaires') GROUP BY t.id,t.name")->fetchAll(PDO::FETCH_ASSOC);
    $flags = [];
    foreach (['COREFLUX_QUANTA_METADATA_DELIVERY', 'COREFLUX_PUREPAY_CORE_LINK_DELIVERY',
        'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY', 'COREFLUX_PUREPAY_PAYROLL_AUTHORITY'] as $flag) {
        $flags[$flag] = in_array($flag, ['COREFLUX_QUANTA_METADATA_DELIVERY', 'COREFLUX_PUREPAY_CORE_LINK_DELIVERY'], true)
            && defined($flag) ? constant($flag) === true : getenv($flag) === '1';
    }
    $liveIndex = (string) file_get_contents($root . '/index.html');
    $candidateIndex = (string) file_get_contents($package . '/index.html');
    preg_match('~spa-assets/index-[A-Za-z0-9_-]+\.js~', $liveIndex, $liveBundle);
    preg_match('~spa-assets/index-[A-Za-z0-9_-]+\.js~', $candidateIndex, $candidateBundle);
    echo json_encode([
        'mode' => 'read-only-production-inspection', 'candidate_commit' => $manifest['commit'],
        'database_identity_verified' => true, 'live_bundle' => $liveBundle[0] ?? null,
        'candidate_bundle' => $candidateBundle[0] ?? null, 'client_counts' => $clients,
        'changed_file_count' => count($changed), 'added_file_count' => count($added),
        'changed_files' => $changed, 'added_files' => $added,
        'pending_migrations' => $pending, 'applied_migration_drift' => $drift,
        'outbound_flags_from_cli_configuration' => $flags,
        'activation_performed' => false,
        'remaining_release_gates' => ['Review migration/data impact and production-only source changes',
            'Confirm web/worker flags, backup and recovery procedure', 'Explicit production cutover authorization'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
