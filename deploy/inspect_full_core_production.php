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
$productionDivergence = [];
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
    elseif (!hash_equals($hash, hash_file('sha256', $live))) {
        $changed[] = $relative;
        // Candidate-generated vendor dependencies have no Git source baseline.
        // Report source that matches neither the candidate nor its main baseline.
        if (array_key_exists($relative, $manifest['source_base_blobs'] ?? [])) {
            $baseBlob = $manifest['source_base_blobs'][$relative];
            $bytes = file_get_contents($live);
            if ($bytes === false) throw new RuntimeException('Cannot read live source: ' . $relative);
            $liveBlob = hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes);
            if ($baseBlob === null || !hash_equals($baseBlob, $liveBlob)) $productionDivergence[] = $relative;
        }
    }
}

// Use deployed configuration, never candidate configuration or an API bootstrap.
require_once $root . '/core/db.php';
require_once $package . '/core/migration_hash.php';
require_once $package . '/core/accounting/schema_contract.php';
require_once $package . '/deploy/full_core_migration_plan.php';
require_once $package . '/deploy/staffing_directory_inventory.php';
$db = getDB();
if (!$db || $db->query('SELECT DATABASE()')->fetchColumn() !== basename(dirname($root))) {
    throw new RuntimeException('Production database does not match the app identity.');
}
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $ledgerRows = $db->query('SELECT filename, sha256, last_error FROM _migrations')->fetchAll(PDO::FETCH_ASSOC);
    $ledger = array_column($ledgerRows, 'sha256', 'filename');
    $ledgerErrors = array_column($ledgerRows, 'last_error', 'filename');
    $legacyExists = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='coreflux_migrations'")->fetchColumn() === 1;
    $legacyLedger = $legacyExists ? $db->query('SELECT file_path, checksum_sha FROM coreflux_migrations')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $pending = [];
    $drift = [];
    $legacyOnly = [];
    $unrecorded = [];
    $migrationIssues = [];
    foreach (array_keys($manifest['files']) as $relative) {
        if (!preg_match('~^(core/migrations|modules/[^_/][^/]*/migrations)/[^/]+\.sql$~D', $relative)) continue;
        $key = str_starts_with($relative, 'core/') ? basename($relative) : $relative;
        $sql = (string) file_get_contents($package . '/' . $relative);
        if (!isset($ledger[$key])) {
            $pending[] = $relative;
            if (isset($legacyLedger[$relative]) && corefluxMigrationHashMatches($legacyLedger[$relative], $sql)) {
                $legacyOnly[] = $relative;
            } else $unrecorded[] = $relative;
        }
        elseif (!corefluxMigrationHashMatches($ledger[$key], (string) file_get_contents($package . '/' . $relative))) {
            $drift[] = $relative;
            $liveSql = is_file($root . '/' . $relative) ? file_get_contents($root . '/' . $relative) : false;
            $migrationIssues[] = ['path' => $relative,
                'recorded_failure' => str_starts_with($ledger[$key], 'FAIL:'),
                'last_error' => $ledgerErrors[$key] ?? null,
                'deployed_file_matches_record' => $liveSql !== false && corefluxMigrationHashMatches($ledger[$key], $liveSql),
                'legacy_ledger_matches_candidate' => isset($legacyLedger[$relative]) && corefluxMigrationHashMatches($legacyLedger[$relative], $sql)];
        }
    }
    $clients = $db->query("SELECT t.id, t.name, COUNT(DISTINCT c.id) AS client_count
        FROM tenants t LEFT JOIN companies c ON c.tenant_id=t.id AND c.deleted_at IS NULL
        AND EXISTS (SELECT 1 FROM company_roles r WHERE r.company_id=c.id AND r.role='client')
        WHERE t.name IN ('Thunderhawk', 'charge d\'Affaires') GROUP BY t.id,t.name")->fetchAll(PDO::FETCH_ASSOC);
    $flags = [];
    foreach (['COREFLUX_QUANTA_METADATA_DELIVERY', 'COREFLUX_QUANTA_WORKER_DELIVERY', 'COREFLUX_QUANTA_ONBOARDING_DELIVERY', 'COREFLUX_PUREPAY_CORE_LINK_DELIVERY',
        'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY', 'COREFLUX_PUREPAY_PAYROLL_AUTHORITY', 'COREFLUX_PUREPAY_PEOPLE_DELIVERY', 'COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY'] as $flag) {
        $flags[$flag] = in_array($flag, ['COREFLUX_QUANTA_METADATA_DELIVERY', 'COREFLUX_QUANTA_WORKER_DELIVERY', 'COREFLUX_QUANTA_ONBOARDING_DELIVERY', 'COREFLUX_PUREPAY_CORE_LINK_DELIVERY', 'COREFLUX_PUREPAY_PEOPLE_DELIVERY'], true)
            && defined($flag) ? constant($flag) === true : getenv($flag) === '1';
    }
    $liveIndex = (string) file_get_contents($root . '/index.html');
    $candidateIndex = (string) file_get_contents($package . '/index.html');
    preg_match('~spa-assets/index-[A-Za-z0-9_-]+\.js~', $liveIndex, $liveBundle);
    preg_match('~spa-assets/index-[A-Za-z0-9_-]+\.js~', $candidateIndex, $candidateBundle);
    require_once $package . '/core/StorageService.php';
    echo json_encode([
        'mode' => 'read-only-production-inspection', 'candidate_commit' => $manifest['commit'],
        'database_identity_verified' => true, 'live_bundle' => $liveBundle[0] ?? null,
        'candidate_bundle' => $candidateBundle[0] ?? null, 'client_counts' => $clients,
        'staffing_directories' => fullCoreStaffingDirectoryInventory($db, ['Thunderhawk', "charge d'Affaires"]),
        'thunderhawk_pilot' => fullCoreStaffingPilotInventory($db, [
            'tenant_id'=>2, 'workspace_name'=>'Thunderhawk', 'person_directory_id'=>2, 'accounting_directory_id'=>2,
            'person_id'=>358, 'placement_id'=>864,
            'jobdiva_ids'=>['person'=>'18424007240614','placement'=>'57862243','job'=>'27926305'],
        ]),
        'changed_file_count' => count($changed), 'added_file_count' => count($added),
        'changed_files' => $changed, 'added_files' => $added,
        'comparison_base_commit' => $manifest['base_commit'] ?? null,
        'production_source_different_from_both_base_and_candidate' => $productionDivergence,
        'pending_migrations' => $pending, 'applied_migration_drift' => $drift,
        'matching_migrations_in_legacy_ledger_only' => $legacyOnly,
        'migrations_without_matching_record_in_either_ledger' => $unrecorded,
        'migration_record_issues' => $migrationIssues,
        'migration_upgrade_plan' => fullCoreMigrationPlan($manifest, $package, $root, $ledgerRows, $legacyLedger),
        'accounting_schema' => coreAccountingInspectSchema($db),
        'outbound_flags_from_cli_configuration' => $flags,
        // Configuration presence only. Never print storage names, keys or credentials,
        // instantiate a driver, create a directory or contact the object store here.
        'storage_environment' => \Core\StorageService::configuration_status() + [
            'object_access_verified' => false,
        ],
        'activation_performed' => false,
        'remaining_release_gates' => ['Review migration/data impact and production-only source changes',
            'Confirm web/worker flags, backup and recovery procedure', 'Explicit production cutover authorization'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
