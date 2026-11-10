<?php
/** Native read-only recovery fingerprint. Business values are streamed into hashes, never returned. */
declare(strict_types=1);
function fullCoreNativeDatabaseState(PDO $db, string $database): array
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== $database
        || !str_contains((string)$db->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) throw new RuntimeException('Exact idle MariaDB connection required.');
    $identifier = static function (string $name): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) throw new RuntimeException('Unsupported native identifier.');
        return '`' . $name . '`';
    };
    // This release has no routines/events. Refuse future additions instead of silently excluding them.
    if ((int)$db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0
        || (int)$db->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() !== 0
        || (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchColumn() !== 0) {
        throw new RuntimeException('Recovery fingerprint requires reviewed InnoDB tables without routines or events.');
    }
    $stringify = $db->getAttribute(PDO::ATTR_STRINGIFY_FETCHES);
    $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    $inventory = static function () use ($db, $identifier): array {
        $objects = []; $triggers = [];
        foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $kind]) {
            if (!in_array($kind, ['BASE TABLE','VIEW'], true)) throw new RuntimeException('Unreviewed database object.');
            $create = $db->query('SHOW CREATE ' . ($kind === 'VIEW' ? 'VIEW ' : 'TABLE ') . $identifier($name))->fetch(PDO::FETCH_ASSOC);
            $objects[$name] = ['kind' => $kind, 'definition_sha256' => hash('sha256', json_encode($create, JSON_THROW_ON_ERROR))];
        }
        foreach ($db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            $create = $db->query('SHOW CREATE TRIGGER ' . $identifier($trigger['Trigger']))->fetch(PDO::FETCH_ASSOC);
            unset($create['Created']); // Native restore creates a new object timestamp; retain its actual definition/compile context.
            $triggers[$trigger['Trigger']] = hash('sha256', json_encode($create, JSON_THROW_ON_ERROR));
        }
        ksort($objects); ksort($triggers);
        return ['objects' => $objects, 'triggers' => $triggers,
            'counters' => $db->query("SELECT TABLE_NAME,CAST(AUTO_INCREMENT AS CHAR) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_KEY_PAIR),
            'database_defaults' => $db->query('SELECT DEFAULT_CHARACTER_SET_NAME,DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()')->fetch(PDO::FETCH_ASSOC)];
    };
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    try {
        $before = $inventory(); $tables = []; $rowCount = 0;
        foreach ($before['objects'] as $name => $object) {
            if ($object['kind'] !== 'BASE TABLE') continue;
            $columns = array_column($db->query('SHOW COLUMNS FROM ' . $identifier($name))->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $indexes = $db->query('SHOW INDEX FROM ' . $identifier($name) . " WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            usort($indexes, static fn(array $a, array $b): int => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
            $primary = array_column($indexes, 'Column_name');
            if (!$primary) throw new RuntimeException('Deterministic native primary-key order required.');
            $hash = hash_init('sha256'); $count = 0;
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $q = $db->query('SELECT ' . implode(',', array_map($identifier, $columns)) . ' FROM ' . $identifier($name)
                . ' ORDER BY ' . implode(',', array_map($identifier, $primary)));
            try {
                while ($row = $q->fetch(PDO::FETCH_NUM)) {
                    hash_update($hash, 'R');
                    foreach ($row as $value) hash_update($hash, $value === null ? 'N;' : 'S' . strlen((string)$value) . ':' . $value . ';');
                    $count++;
                }
            } finally { $q->closeCursor(); $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
            $rowCount += $count;
            $tables[$name] = ['rows' => $count, 'sha256' => hash_final($hash), 'columns' => $columns, 'primary_key' => $primary];
        }
        if ($inventory() !== $before
            || (int)$db->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() !== 0
            || (int)$db->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('Schema or native counters changed during recovery inspection.');
        }
        $state = ['version' => 1, 'database' => $database, 'schema' => $before, 'tables' => $tables];
        return ['snapshot_sha256' => hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'tables' => count($tables), 'rows' => $rowCount, 'schema_objects' => count($before['objects']),
            'triggers' => count($before['triggers']), 'business_values_returned' => false];
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered); $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, $stringify);
    }
}
