<?php
/** Native MariaDB streaming. Credentials and SQL never enter controller logs or command arguments. */
declare(strict_types=1);

final class FullCoreDatabaseTransfer
{
    private array $arguments;
    private array $environment;
    private const DB = 'grcudkpvcd';
    public const CHUNK = 524288;

    public function __construct(array $connection)
    {
        if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 1004
            || ($connection['username'] ?? null) !== self::DB || !is_string($connection['password'] ?? null)
            || !str_starts_with($connection['dsn'] ?? '', 'mysql:')) throw new RuntimeException('Native application database identity required.');
        $parts = [];
        foreach (explode(';', substr($connection['dsn'], 6)) as $part) {
            if ($part === '') continue;
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || isset($parts[$pair[0]])) throw new RuntimeException('Canonical database connection required.');
            $parts[$pair[0]] = $pair[1];
        }
        if (($parts['dbname'] ?? null) !== self::DB || ($parts['charset'] ?? null) !== 'utf8mb4'
            || array_diff(array_keys($parts), ['host','port','unix_socket','dbname','charset'])) throw new RuntimeException('Exact database transport required.');
        $this->arguments = ['--no-defaults', '--user=' . self::DB, '--default-character-set=utf8mb4'];
        if (isset($parts['unix_socket'])) {
            if (isset($parts['host']) || isset($parts['port']) || !preg_match('~^/[A-Za-z0-9_./-]+$~D', $parts['unix_socket'])) throw new RuntimeException('Exact local socket required.');
            $this->arguments[] = '--socket=' . $parts['unix_socket'];
        } else {
            if (!in_array($parts['host'] ?? null, ['localhost','127.0.0.1'], true)
                || (isset($parts['port']) && !preg_match('/^[0-9]{1,5}$/D', $parts['port']))) throw new RuntimeException('Local Core database only.');
            $this->arguments[] = '--host=' . $parts['host'];
            if (isset($parts['port'])) $this->arguments[] = '--port=' . $parts['port'];
        }
        $this->environment = getenv(); $this->environment['MYSQL_PWD'] = $connection['password'];
    }

    private function command(string $mode): array
    {
        $suffix = match ($mode) {
            'capture' => ['--single-transaction','--quick','--skip-lock-tables','--routines','--events','--triggers',
                '--skip-add-locks','--skip-disable-keys','--hex-blob','--skip-comments','--max-allowed-packet=256M',
                '--max-statement-time=180',self::DB],
            'restore' => ['--binary-mode','--skip-reconnect','--local-infile=0','--max-allowed-packet=256M',self::DB],
            default => throw new RuntimeException('Exact native transfer mode required.'),
        };
        return array_merge(['/usr/bin/timeout','--signal=TERM','--kill-after=5s','1800s',
            $mode === 'capture' ? '/usr/bin/mariadb-dump' : '/usr/bin/mariadb'], $this->arguments, $suffix);
    }

    /** Only this reviewed native command and its direct timeout child may coexist with the hold. */
    public function writerAllowances(string $mode): array
    {
        $command = $this->command($mode);
        return ['timeout'=>['parent'=>'owner','command_title_sha256'=>hash('sha256',implode("\0",$command))],
            'client'=>['parent'=>'timeout','command_title_sha256'=>hash('sha256',implode("\0",array_slice($command,4)))]];
    }

    private static function identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    public static function defaults(PDO $db): array
    {
        $row = $db->query('SELECT DEFAULT_CHARACTER_SET_NAME charset,DEFAULT_COLLATION_NAME collation_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !preg_match('/^[a-z0-9_]+$/D', $row['charset'] ?? '')
            || !preg_match('/^[a-z0-9_]+$/D', $row['collation_name'] ?? '')) throw new RuntimeException('Exact database defaults required.');
        return $row;
    }

    /** No unknown visible connection is accepted before or after a native transfer. */
    public static function idle(PDO $db): void
    {
        if ($db->query('SELECT DATABASE()')->fetchColumn() !== self::DB
            || !str_starts_with((string)$db->query('SELECT CURRENT_USER()')->fetchColumn(), self::DB . '@')
            || !in_array(strtoupper((string)$db->query('SELECT @@GLOBAL.event_scheduler')->fetchColumn()), ['OFF','DISABLED'], true)
            || (int)$db->query('SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('Database identity or event-writer state differs.');
        }
        $id = (int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();
        foreach ($db->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['Id'] !== $id) throw new RuntimeException('Another database connection remains.');
        }
    }

    private static function childConnection(PDO $db, ?int &$connection): void
    {
        $id = (int)$db->query('SELECT CONNECTION_ID()')->fetchColumn(); $others = [];
        foreach ($db->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['Id'] !== $id) $others[] = $row;
        }
        if (count($others) > 1) throw new RuntimeException('Unexpected connection during native database transfer.');
        if ($others) {
            $other = $others[0];
            if ($other['User'] !== self::DB || ($other['db'] !== self::DB && $other['db'] !== null)
                || !preg_match('/^(?:localhost|127\.0\.0\.1)(?::[0-9]+)?$/D', $other['Host'])
                || ($connection !== null && $connection !== (int)$other['Id'])) throw new RuntimeException('Native transfer connection changed.');
            $connection = (int)$other['Id'];
        }
    }

    public function capture(PDO $db, callable $guard, callable $emit): array
    {
        self::idle($db); $guard(); self::idle($db);
        $defaults = self::defaults($db);
        $tables = $db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        if (!$tables) throw new RuntimeException('Populated source schema required.');
        $locks = [];
        foreach ($tables as $table) {
            if ($table['ENGINE'] !== 'InnoDB') throw new RuntimeException('Native consistent backup requires InnoDB tables.');
            $locks[] = self::identifier(self::DB) . '.' . self::identifier($table['TABLE_NAME']) . ' READ';
        }
        // Existing rows cannot change while the native dump runs; final native fingerprints
        // also detect new objects or other metadata changes outside these table locks.
        $db->exec('LOCK TABLES ' . implode(',', $locks));
        $process = null; $pipes = []; $hash = hash_init('sha256'); $bytes = 0; $childId = null;
        try {
            $guard(); self::idle($db);
            $command = $this->command('capture');
            $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null, $this->environment);
            if (!is_resource($process)) throw new RuntimeException('Native dump unavailable.');
            fclose($pipes[0]); unset($pipes[0]);
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $diagnostic = hash_init('sha256'); $lastGuard = 0.0; $started = microtime(true);
            while (!feof($pipes[1]) || !feof($pipes[2])) {
                if (microtime(true) - $started > 1810) throw new RuntimeException('Native dump timed out.');
                if (microtime(true) - $lastGuard >= 1) { self::childConnection($db,$childId); $guard(); self::childConnection($db,$childId); $lastGuard = microtime(true); }
                $read = array_values(array_filter($pipes, static fn($f): bool => !feof($f))); $write = $except = [];
                if (!$read || stream_select($read,$write,$except,1) === 0) continue;
                foreach ($read as $stream) {
                    $chunk = fread($stream,self::CHUNK);
                    if ($chunk === false) throw new RuntimeException('Native dump pipe failed.');
                    if ($stream === $pipes[2]) { hash_update($diagnostic,$chunk); continue; }
                    if ($chunk !== '') { hash_update($hash,$chunk); $emit($bytes,$chunk); $bytes += strlen($chunk); }
                }
            }
            foreach ($pipes as $pipe) fclose($pipe); $pipes = [];
            $status = proc_close($process); $process = null;
            if ($status !== 0 || $bytes < 1) throw new RuntimeException('Native full dump did not complete.');
            self::idle($db); $guard(); self::idle($db);
            return ['sql_bytes'=>$bytes,'sql_sha256'=>hash_final($hash),'native_full_schema_and_data'=>true,
                'native_exit_code'=>0,'diagnostic_sha256'=>hash_final($diagnostic),'database_defaults'=>$defaults];
        } finally {
            if (is_resource($process)) proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) proc_close($process);
            $db->exec('UNLOCK TABLES');
        }
    }

    /** Explicit recovery only. Keep the database itself present so a lost process can reconnect. */
    public static function emptyRecoveryTarget(PDO $db, array $defaults, callable $guard): array
    {
        if (!preg_match('/^[a-z0-9_]+$/D', $defaults['charset'] ?? '')
            || !preg_match('/^[a-z0-9_]+$/D', $defaults['collation_name'] ?? '')) throw new RuntimeException('Original database defaults required.');
        self::idle($db); $guard(); self::idle($db); $count = 0;
        $objects = $db->query("SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_TYPE DESC,TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
        // Original and reviewed upgrade paths do not contain routines/events; reject unexpected ones.
        if ((int)$db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('Unexpected routine requires recovery review.');
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($objects as $object) {
                if ($count % 20 === 0) { $guard(); self::idle($db); }
                $kind = match ($object['TABLE_TYPE']) { 'VIEW'=>'VIEW', 'BASE TABLE'=>'TABLE', default=>throw new RuntimeException('Unexpected database object.') };
                $db->exec('DROP ' . $kind . ' ' . self::identifier(self::DB) . '.' . self::identifier($object['TABLE_NAME'])); $count++;
            }
            $db->exec('ALTER DATABASE ' . self::identifier(self::DB) . ' CHARACTER SET ' . $defaults['charset'] . ' COLLATE ' . $defaults['collation_name']);
        } finally { $db->exec('SET FOREIGN_KEY_CHECKS=1'); }
        $guard(); self::idle($db); self::assertEmpty($db);
        return ['database'=>self::DB,'removed_objects'=>$count,'empty'=>true,'database_defaults'=>self::defaults($db)];
    }

    private static function assertEmpty(PDO $db): void
    {
        if ((int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0
            || (int)$db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('Exact empty recovery target required.');
    }

    public function restore(PDO $db, array $expected, callable $guard, callable $read): array
    {
        self::idle($db); self::assertEmpty($db); $guard(); self::idle($db);
        if (!is_int($expected['sql_bytes'] ?? null) || $expected['sql_bytes'] < 1
            || !preg_match('/^[a-f0-9]{64}$/D',$expected['sql_sha256'] ?? '')) throw new RuntimeException('Verified original native SQL required.');
        $command = $this->command('restore');
        $process = proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes,null,$this->environment);
        if (!is_resource($process)) throw new RuntimeException('Native restore unavailable.');
        stream_set_blocking($pipes[0],false); stream_set_blocking($pipes[2],false);
        $hash = hash_init('sha256'); $bytes = 0; $childId = null; $started = microtime(true); $lastGuard = 0.0;
        try {
            while (true) {
                $chunk = $read($bytes, self::CHUNK);
                if (!is_string($chunk) || strlen($chunk) > self::CHUNK) throw new RuntimeException('Bounded exact restore stream required.');
                if ($chunk === '') break;
                if ($bytes + strlen($chunk) > $expected['sql_bytes']) throw new RuntimeException('Restore exceeds recorded SQL size.');
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    if (microtime(true) - $started > 1810) throw new RuntimeException('Native restore timed out.');
                    if (microtime(true) - $lastGuard >= 1) { self::childConnection($db,$childId); $guard(); self::childConnection($db,$childId); $lastGuard = microtime(true); }
                    $readable = feof($pipes[2]) ? [] : [$pipes[2]]; $write = [$pipes[0]]; $except = [];
                    if (stream_select($readable,$write,$except,1) === false) throw new RuntimeException('Native restore pipe failed.');
                    if ($readable) stream_get_contents($pipes[2]); // Never forward SQL diagnostics to the controller.
                    if ($write) {
                        $n = fwrite($pipes[0],substr($chunk,$offset,65536));
                        if ($n === false || $n === 0) throw new RuntimeException('Native restore stopped accepting data.');
                        $offset += $n;
                    }
                }
                hash_update($hash,$chunk); $bytes += strlen($chunk);
            }
            fclose($pipes[0]); unset($pipes[0]);
            while (!feof($pipes[2])) {
                if (microtime(true) - $started > 1810) throw new RuntimeException('Native restore timed out.');
                if (microtime(true) - $lastGuard >= 1) { self::childConnection($db,$childId); $guard(); $lastGuard = microtime(true); }
                $r = [$pipes[2]]; $w = $e = []; if (stream_select($r,$w,$e,1)) stream_get_contents($pipes[2]);
            }
            fclose($pipes[2]); $pipes = []; $code = proc_close($process); $process = null;
            if ($code !== 0 || $bytes !== $expected['sql_bytes'] || !hash_equals($expected['sql_sha256'],hash_final($hash))) throw new RuntimeException('Native restore outcome differs from verified SQL.');
            self::idle($db); $guard(); self::idle($db);
            return ['database'=>self::DB,'sql_bytes'=>$bytes,'sql_sha256'=>$expected['sql_sha256'],'native_exit_code'=>0];
        } finally {
            if (is_resource($process)) proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($process)) proc_close($process);
        }
    }
}
