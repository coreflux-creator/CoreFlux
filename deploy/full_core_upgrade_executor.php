<?php
/** Exact-plan upgrade engine. Only disposable rehearsals are enabled until host recovery/maintenance is verified. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_migration_plan.php';

/** Preserve quoted comments/semicolons and reject incomplete or unsupported input before any SQL runs. */
function fullCoreUpgradeStatements(string $sql): array
{
    $statements = []; $buffer = ''; $outside = ''; $quote = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $c = $sql[$i]; $next = $sql[$i + 1] ?? '';
        if ($quote !== null) {
            $buffer .= $c; $outside .= ' ';
            if ($c === '\\' && $quote !== '`' && $i + 1 < $length) {
                $buffer .= $sql[++$i]; $outside .= ' ';
            } elseif ($c === $quote) {
                if ($next === $quote) { $buffer .= $sql[++$i]; $outside .= ' '; }
                else $quote = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c; $buffer .= $c; $outside .= ' '; continue;
        }
        if ($c === '#' || ($c === '-' && $next === '-' && (ord($sql[$i + 2] ?? "\n") <= 32))) {
            while ($i < $length && $sql[$i] !== "\n") $i++;
            $buffer .= "\n"; $outside .= "\n"; continue;
        }
        if ($c === '/' && $next === '*') {
            if (($sql[$i + 2] ?? '') === '!' || substr($sql, $i + 2, 2) === 'M!') {
                throw new RuntimeException('Executable SQL comments require separate review.');
            }
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) throw new RuntimeException('Unterminated SQL comment.');
            $i = $end + 1; $buffer .= ' '; $outside .= ' '; continue;
        }
        if ($c === ';') {
            if (trim($buffer) !== '') $statements[] = trim($buffer);
            $buffer = ''; $outside .= ';'; continue;
        }
        $buffer .= $c; $outside .= $c;
    }
    if ($quote !== null) throw new RuntimeException('Unterminated SQL literal or identifier.');
    if (preg_match('/\b(?:BEGIN|DELIMITER)\b/i', $outside)) {
        throw new RuntimeException('Unquoted compound SQL requires separate review.');
    }
    if (trim($buffer) !== '') $statements[] = trim($buffer);
    if (!$statements) throw new RuntimeException('Migration has no executable statements.');
    foreach ($statements as $statement) {
        if (preg_match('/^(?:USE\b|(?:CREATE|DROP|ALTER)\s+(?:DATABASE|SCHEMA)\b|START\s+TRANSACTION\b|COMMIT\b|ROLLBACK\b|LOCK\s+TABLES\b|UNLOCK\s+TABLES\b)/i', $statement)) {
            throw new RuntimeException('Migration may not change the target database or transaction/locking scope.');
        }
    }
    return $statements;
}

function fullCoreUpgradeLedger(PDO $db): array
{
    $rows = $db->query('SELECT filename, sha256, last_error FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, static fn(array $a, array $b): int => strcmp($a['filename'], $b['filename']));
    return $rows;
}

function fullCoreUpgradeJournalWrite($stream, array $event): void
{
    $bytes = json_encode(['at' => gmdate('c')] + $event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)
        || !function_exists('fsync') || !fsync($stream)) {
        throw new RuntimeException('Upgrade journal could not be persisted; recovery review required.');
    }
}

/**
 * Run the actual executor on an explicitly named disposable database. No production CLI/API entry exists.
 * The callback models loss of a future host maintenance guard; it is not production approval evidence.
 * Every newly started migration acquires a durable FAIL marker before its first statement. DDL can commit;
 * no automatic retry or rollback is claimed. Recovery requires inspecting/restoring the matching snapshot.
 */
function fullCoreUpgradeRehearse(PDO $db, string $expectedDatabase, string $reviewedPlanHash,
    array $manifest, string $package, string $live, string $journalPath, callable $maintenanceGuard): array
{
    if (PHP_SAPI !== 'cli' || !preg_match('/^coreflux_upgrade_fixture_[a-z0-9_]+$/D', $expectedDatabase)
        || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $db->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase || $db->inTransaction()) {
        throw new RuntimeException('Explicit disposable upgrade database required; production execution is unavailable.');
    }
    if (!str_contains((string) $db->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) {
        throw new RuntimeException('The upgrade rehearsal requires MariaDB.');
    }
    $mode = strtoupper((string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn());
    if (preg_match('/(?:^|,)(?:NO_BACKSLASH_ESCAPES|ANSI_QUOTES)(?:,|$)/', $mode)) {
        throw new RuntimeException('Session SQL quoting mode differs from the reviewed parser.');
    }
    $parent = realpath(dirname($journalPath));
    $normal = static fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    if (!$parent || $normal($parent) !== $normal(dirname($journalPath)) || is_link(dirname($journalPath))
        || !preg_match('/^[A-Za-z0-9_.-]+\.jsonl$/D', basename($journalPath))) {
        throw new RuntimeException('New absolute private journal path required.');
    }
    foreach ([$package, $live] as $root) {
        $resolved = realpath($root);
        if (!$resolved || $normal($parent) === $normal($resolved)
            || str_starts_with($normal($parent), $normal($resolved) . '/')) {
            throw new RuntimeException('Upgrade journal must be outside source and webroot.');
        }
    }
    if (file_exists($journalPath) || is_link($journalPath)) throw new RuntimeException('Existing upgrade journal requires recovery review; automatic replay is refused.');
    $lock = 'core-upgrade:' . substr(hash('sha256', $expectedDatabase), 0, 50);
    $lockStatement = $db->prepare('SELECT GET_LOCK(?, 0)'); $lockStatement->execute([$lock]);
    if ((int) $lockStatement->fetchColumn() !== 1) throw new RuntimeException('Another upgrade owns this database.');
    $journal = null; $current = null; $marker = null; $stage = 'preflight'; $applied = []; $executed = 0;
    $oldTimeout = (int) $db->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
    $oldInnoTimeout = (int) $db->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
    try {
        $assertGuard = static function () use ($maintenanceGuard): void {
            if ($maintenanceGuard() !== true) throw new RuntimeException('Maintenance guard is not held.');
        };
        $assertGuard();
        $ledger = fullCoreUpgradeLedger($db);
        $legacy = $db->query('SELECT file_path, checksum_sha FROM coreflux_migrations ORDER BY file_path')->fetchAll(PDO::FETCH_KEY_PAIR);
        $plan = fullCoreMigrationVerifyPlan($reviewedPlanHash, $manifest, $package, $live, $ledger, $legacy);
        $work = [];
        foreach ($plan['ordered_migrations'] as $entry) {
            if ($entry['action'] !== 'apply_pending') continue;
            $sql = fullCoreMigrationRead($package, $entry['path']);
            if (!hash_equals($entry['candidate_sha256'], hash('sha256', $sql))) throw new RuntimeException('Reviewed migration changed.');
            $work[] = ['entry' => $entry, 'hash' => corefluxMigrationHash($sql), 'statements' => fullCoreUpgradeStatements($sql)];
        }
        $journal = @fopen($journalPath, 'x+b');
        if (!$journal || !chmod($journalPath, 0600) || !flock($journal, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Cannot create an exclusive private upgrade journal.');
        }
        fullCoreUpgradeJournalWrite($journal, ['event' => 'start', 'database' => $expectedDatabase,
            'candidate_commit' => $manifest['commit'], 'plan_sha256' => $reviewedPlanHash,
            'pending_files' => count($work), 'production_execution' => false]);
        $db->exec('SET SESSION lock_wait_timeout=5');
        $db->exec('SET SESSION innodb_lock_wait_timeout=5');
        $expectedLedger = $ledger;
        $checkLedger = static function () use ($db, &$expectedLedger, $legacy): void {
            if (fullCoreUpgradeLedger($db) !== $expectedLedger
                || $db->query('SELECT file_path, checksum_sha FROM coreflux_migrations ORDER BY file_path')->fetchAll(PDO::FETCH_KEY_PAIR) !== $legacy) {
                throw new RuntimeException('Migration history changed during the upgrade.');
            }
        };
        foreach ($work as $file) {
            $current = $file['entry']['ledger_key']; $marker = null; $stage = 'migration_intent';
            $assertGuard(); $checkLedger();
            fullCoreUpgradeJournalWrite($journal, ['event' => 'migration_start', 'path' => $file['entry']['path'], 'sha256' => $file['hash']]);
            $intent = 'FAIL:' . substr($file['hash'], 0, 59);
            $insert = $db->prepare('INSERT INTO _migrations(filename,sha256,last_error,duration_ms) VALUES(?,?,?,NULL)');
            $intentMessage = 'Upgrade in progress; recovery required. Plan ' . $reviewedPlanHash;
            $insert->execute([$current, $intent, $intentMessage]);
            $marker = $intent;
            $expectedLedger[] = ['filename' => $current, 'sha256' => $intent, 'last_error' => $intentMessage];
            usort($expectedLedger, static fn(array $a, array $b): int => strcmp($a['filename'], $b['filename']));
            fullCoreUpgradeJournalWrite($journal, ['event' => 'intent_recorded', 'path' => $file['entry']['path']]);
            $start = microtime(true);
            foreach ($file['statements'] as $index => $statement) {
                $stage = 'statement'; $assertGuard(); $checkLedger();
                $identity = ['path' => $file['entry']['path'], 'statement' => $index + 1, 'sha256' => hash('sha256', $statement)];
                fullCoreUpgradeJournalWrite($journal, ['event' => 'statement_start'] + $identity);
                $result = $db->query($statement);
                if ($result) $result->closeCursor();
                $executed++;
                fullCoreUpgradeJournalWrite($journal, ['event' => 'statement_complete'] + $identity);
            }
            $stage = 'ledger_commit'; $assertGuard(); $checkLedger();
            fullCoreUpgradeJournalWrite($journal, ['event' => 'ledger_commit_start', 'path' => $file['entry']['path']]);
            $save = $db->prepare('UPDATE _migrations SET sha256=?,last_error=NULL,duration_ms=?,applied_at=CURRENT_TIMESTAMP WHERE filename=? AND sha256=?');
            $save->execute([$file['hash'], (int) round((microtime(true) - $start) * 1000), $current, $marker]);
            if ($save->rowCount() !== 1) throw new RuntimeException('Migration intent changed before success could be recorded.');
            $marker = null;
            foreach ($expectedLedger as &$record) {
                if ($record['filename'] === $current) { $record['sha256'] = $file['hash']; $record['last_error'] = null; }
            }
            unset($record);
            $applied[] = $file['entry']['path'];
            fullCoreUpgradeJournalWrite($journal, ['event' => 'migration_complete', 'path' => $file['entry']['path']]);
        }
        $stage = 'completion'; $assertGuard(); $checkLedger();
        $receipt = ['event' => 'complete', 'database' => $expectedDatabase, 'plan_sha256' => $reviewedPlanHash,
            'applied_files' => $applied, 'executed_statements' => $executed, 'production_execution' => false];
        fullCoreUpgradeJournalWrite($journal, $receipt);
        return $receipt;
    } catch (Throwable $error) {
        // The marker intentionally remains. Never pretend DDL can be rolled back, silently skip errors, or auto-resume.
        if (is_resource($journal)) {
            try { fullCoreUpgradeJournalWrite($journal, ['event' => 'failed', 'stage' => $stage, 'ledger_key' => $current,
                'sqlstate' => $error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null,
                'driver_code' => $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null,
                'recovery_required' => true]); } catch (Throwable $journalError) { /* Earlier durable intent remains authoritative. */ }
        }
        throw new RuntimeException('Upgrade stopped during ' . $stage . '; inspect the private journal and recover before retrying.', 0, $error);
    } finally {
        try {
            $db->exec('SET SESSION lock_wait_timeout=' . $oldTimeout);
            $db->exec('SET SESSION innodb_lock_wait_timeout=' . $oldInnoTimeout);
            $release = $db->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]);
        } catch (Throwable $ignored) { /* A lost connection already released its advisory lock. */ }
        if (is_resource($journal)) { flock($journal, LOCK_UN); fclose($journal); }
    }
}
