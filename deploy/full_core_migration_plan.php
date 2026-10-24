<?php
/** Read-only, revision-bound migration plan for the populated full Core release. */
declare(strict_types=1);

require_once __DIR__ . '/../core/migration_hash.php';

// Reviewed deployed Git blobs. This is deliberately not a caller-provided skip list.
const FULL_CORE_RETAINED_MIGRATION_BLOBS = [
    'core/migrations/007_subtenant_provisioning.sql' => '9d2477212bf669aec2d5be613ede9e22a4517dbb',
    'core/migrations/041_csv_mapping_presets.sql' => '8f8a853bc225dd2a96391c0fb6d0e7c16a2e4bdc',
    'core/migrations/043_simulation_harness.sql' => '09515e5b299f867e6b120009a5e3982690e082ca',
];

function fullCoreMigrationPath(string $path): bool
{
    return preg_match('~^(core/migrations|modules/[a-zA-Z0-9][a-zA-Z0-9_-]*/migrations)/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.sql$~D', $path) === 1;
}

/** Exact historical fingerprints reconciled against deployed ledgers and Git objects. */
function fullCoreMigrationHistory(): array
{
    $history = json_decode((string) file_get_contents(__DIR__ . '/full_core_migration_history.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($history['version'] ?? null) !== 1) throw new RuntimeException('Historical migration review version is invalid.');
    foreach (['prior_legacy', 'retired'] as $kind) {
        if (!is_array($history[$kind] ?? null)) throw new RuntimeException('Historical migration review is incomplete.');
        foreach ($history[$kind] as $path => $record) {
            if (!is_string($path) || !fullCoreMigrationPath($path)
                || !preg_match('/^[a-f0-9]{64}$/D', $record['recorded_sha256'] ?? '')
                || !preg_match('/^[a-f0-9]{64}$/D', $record['normalized_sha256'] ?? '')
                || !preg_match('/^[a-f0-9]{40}$/D', $record['source_git_blob'] ?? '')) {
                throw new RuntimeException('Historical migration fingerprint is invalid.');
            }
        }
    }
    return $history;
}

function fullCoreMigrationRead(string $root, string $path, bool $optional = false): ?string
{
    if (!fullCoreMigrationPath($path)) throw new RuntimeException('Invalid canonical migration path.');
    $base = realpath($root);
    if ($base === false || !is_dir($base) || is_link($root)) throw new RuntimeException('Migration root is invalid.');
    $file = $base . '/' . $path;
    $part = $base;
    foreach (explode('/', $path) as $segment) {
        $part .= '/' . $segment;
        if (is_link($part)) throw new RuntimeException('Linked migration path refused: ' . $path);
    }
    if (!file_exists($file) && $optional) return null;
    $resolved = realpath($file);
    if ($resolved === false || !is_file($resolved)
        || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $base) . '/')) {
        throw new RuntimeException('Migration file is missing or outside its root: ' . $path);
    }
    $sql = file_get_contents($resolved);
    if ($sql === false || trim($sql) === '') throw new RuntimeException('Migration file is unreadable or empty: ' . $path);
    return $sql;
}

function fullCoreMigrationPlan(array $manifest, string $package, string $live,
    array $ledgerRows, array $legacyLedger): array
{
    if (($manifest['product'] ?? null) !== 'full-core-staging'
        || !preg_match('/^[a-f0-9]{40}$/D', $manifest['commit'] ?? '')
        || !is_array($manifest['files'] ?? null) || !$manifest['files']) {
        throw new RuntimeException('Verified full-Core candidate manifest required.');
    }
    if (realpath($package) === realpath($live)) throw new RuntimeException('Candidate and deployed roots must differ.');
    $history = fullCoreMigrationHistory();
    $ledger = [];
    foreach ($ledgerRows as $row) {
        if (!is_array($row) || !is_string($row['filename'] ?? null)
            || !is_string($row['sha256'] ?? null) || !array_key_exists('last_error', $row)
            || isset($ledger[$row['filename']])) {
            throw new RuntimeException('Incomplete or duplicate canonical migration ledger row.');
        }
        // Do not include database error text in a release artifact.
        $ledger[$row['filename']] = ['sha256' => $row['sha256'], 'failed' => $row['last_error'] !== null];
    }
    ksort($ledger, SORT_STRING);
    foreach ($legacyLedger as $path => $hash) {
        if (!is_string($path) || !is_string($hash)) throw new RuntimeException('Invalid legacy migration ledger.');
    }
    ksort($legacyLedger, SORT_STRING);
    $manifestFiles = $manifest['files'];
    ksort($manifestFiles, SORT_STRING);
    $core = []; $modules = [];
    foreach ($manifestFiles as $path => $hash) {
        if (!is_string($path) || preg_match('/[\x00-\x1f\x7f]/', $path)
            || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/')
            || in_array('..', explode('/', $path), true)
            || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new RuntimeException('Invalid manifest entry.');
        }
        if (preg_match('~^(core/migrations|modules/[^_/][^/]*/migrations)/~D', $path)
            && !fullCoreMigrationPath($path)) throw new RuntimeException('Invalid packaged migration path.');
        if (!fullCoreMigrationPath($path)) continue;
        if (str_starts_with($path, 'core/')) $core[] = $path;
        else $modules[] = $path;
    }
    sort($core, SORT_NATURAL); sort($modules, SORT_NATURAL);
    if (!$core) throw new RuntimeException('Candidate has no canonical core migrations.');
    $entries = []; $seen = []; $blockers = [];
    foreach (array_merge($core, $modules) as $path) {
        $key = str_starts_with($path, 'core/') ? basename($path) : $path;
        if (isset($seen[$key])) throw new RuntimeException('Duplicate canonical migration identity.');
        $seen[$key] = true;
        $sql = fullCoreMigrationRead($package, $path);
        $hash = hash('sha256', $sql);
        if (!hash_equals($manifestFiles[$path], $hash)) throw new RuntimeException('Candidate migration changed: ' . $path);
        $deployed = fullCoreMigrationRead($live, $path, true);
        $row = $ledger[$key] ?? null;
        $entry = ['path' => $path, 'ledger_key' => $key, 'candidate_sha256' => $hash,
            'deployed_sha256' => $deployed === null ? null : hash('sha256', $deployed),
            'recorded_sha256' => $row['sha256'] ?? null, 'action' => 'blocked',
            'effective_sha256' => null];
        $reason = null;
        if ($row === null) {
            if (isset($legacyLedger[$path])) {
                // The automatic runner consults _migrations. Never silently adopt or re-run legacy-only history.
                $reason = corefluxMigrationHashMatches($legacyLedger[$path], $sql)
                    ? 'legacy_only_requires_review' : 'legacy_history_conflict';
            } else {
                $entry['action'] = 'apply_pending';
                $entry['effective_sha256'] = $hash;
            }
        } elseif ($row['failed'] || !preg_match('/^[a-f0-9]{64}$/D', $row['sha256'])) {
            $reason = 'failed_migration_requires_recovery';
        } elseif ($deployed === null || !corefluxMigrationHashMatches($row['sha256'], $deployed)) {
            $reason = 'deployed_source_does_not_match_successful_history';
        } elseif (isset($legacyLedger[$path]) && !corefluxMigrationHashMatches($legacyLedger[$path], $deployed)
            && !hash_equals($history['prior_legacy'][$path]['recorded_sha256'] ?? '', $legacyLedger[$path])) {
            $reason = 'migration_ledgers_disagree';
        } elseif (corefluxMigrationHashMatches($row['sha256'], $sql)) {
            $entry['action'] = 'keep_applied';
            $entry['effective_sha256'] = $hash;
        } else {
            $normalized = str_replace("\r\n", "\n", $deployed);
            $blob = hash('sha1', 'blob ' . strlen($normalized) . "\0" . $normalized);
            if (isset(FULL_CORE_RETAINED_MIGRATION_BLOBS[$path])
                && hash_equals(FULL_CORE_RETAINED_MIGRATION_BLOBS[$path], $blob)) {
                $entry['action'] = 'retain_deployed';
                $entry['effective_sha256'] = $entry['deployed_sha256'];
                $entry['reviewed_git_blob'] = $blob;
            } else $reason = 'unreviewed_applied_source_change';
        }
        if ($reason === null && $row !== null && isset($legacyLedger[$path])
            && !corefluxMigrationHashMatches($legacyLedger[$path], $deployed)) {
            $entry['legacy_history'] = ['action' => 'preserve_reviewed_prior_version',
                'recorded_sha256' => $legacyLedger[$path],
                'source_git_blob' => $history['prior_legacy'][$path]['source_git_blob']];
        }
        if ($reason !== null) $blockers[] = ['path' => $path, 'reason' => $reason];
        $entries[] = $entry;
    }
    $retired = [];
    foreach ($ledger as $key => $row) {
        if (isset($seen[$key])) continue;
        $path = str_contains($key, '/') ? $key : 'core/migrations/' . $key;
        $reviewed = $history['retired'][$path] ?? null;
        if ($reviewed && !$row['failed'] && hash_equals($reviewed['recorded_sha256'], $row['sha256'])
            && fullCoreMigrationRead($live, $path, true) === null
            && (!isset($legacyLedger[$path]) || hash_equals($row['sha256'], $legacyLedger[$path]))) {
            $retired[] = ['path' => $path, 'ledger_key' => $key, 'recorded_sha256' => $row['sha256'],
                'source_git_blob' => $reviewed['source_git_blob'], 'action' => 'preserve_retired_history'];
        } else $blockers[] = ['ledger_key' => $key, 'reason' => 'recorded_migration_not_packaged'];
    }
    $plan = ['version' => 1, 'candidate_commit' => $manifest['commit'],
        'manifest_files_sha256' => hash('sha256', json_encode($manifestFiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'ledger_sha256' => hash('sha256', json_encode([$ledger, $legacyLedger], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'history_review_sha256' => hash('sha256', json_encode($history, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'ordered_migrations' => $entries, 'retired_history' => $retired, 'blockers' => $blockers,
        'source_review_ready' => !$blockers, 'activation_authorized' => false,
        'requirements_before_apply' => [
            'Bind the final candidate and fresh database/source inventory to this plan hash',
            'Retain reviewed deployed migration bytes and their existing ledger records',
            'Verify matching file/database backup and tested recovery',
            'Pause and verify all web, API and worker writers; drain existing work',
            'Obtain explicit production cutover authorization',
        ]];
    $plan['plan_sha256'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $plan;
}

/** Refuse a saved review if source, candidate identity or either ledger changed. No SQL is executed. */
function fullCoreMigrationVerifyPlan(string $reviewedHash, array $manifest, string $package,
    string $live, array $ledgerRows, array $legacyLedger): array
{
    $fresh = fullCoreMigrationPlan($manifest, $package, $live, $ledgerRows, $legacyLedger);
    if (!$fresh['source_review_ready'] || !preg_match('/^[a-f0-9]{64}$/D', $reviewedHash)
        || !hash_equals($reviewedHash, $fresh['plan_sha256'])) {
        throw new RuntimeException('Migration review is blocked or stale; obtain a fresh exact plan.');
    }
    return $fresh;
}
