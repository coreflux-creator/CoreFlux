<?php
declare(strict_types=1);
require_once __DIR__ . '/../deploy/full_core_upgrade_executor.php';
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL ' . $name);
    $checks++; echo "PASS $name\n";
};
$sql = <<<'SQL'
-- Before the statement; ignored
INSERT INTO `a``b`(note) VALUES ('Keep -- this; /*and this*/ # too', 'it''s fine'); # trailing;
/* separator */ SELECT "a;\"b", 1--2;
SQL;
$parts = fullCoreUpgradeStatements($sql);
$assert(count($parts) === 2 && str_contains($parts[0], 'Keep -- this; /*and this*/ # too')
    && str_contains($parts[1], '1--2'), 'quoted comments, doubled quotes and arithmetic remain intact');
$parts = fullCoreUpgradeStatements("SET @q='CREATE TRIGGER t BEFORE UPDATE ON a FOR EACH ROW BEGIN SET NEW.id=OLD.id; END'; PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;");
$assert(count($parts) === 4 && str_contains($parts[0], 'BEGIN SET NEW.id=OLD.id; END'), 'prepared compound trigger remains one quoted value');
foreach (["SELECT 'unfinished", 'SELECT `unfinished', 'SELECT 1 /* unfinished',
    '/*!40101 SET @x=1 */;', '/*M!100101 SET @x=1 */;',
    'DELIMITER $$', 'CREATE TRIGGER t BEFORE UPDATE ON a FOR EACH ROW BEGIN SET NEW.id=1; END;',
    'USE another_database;', 'DROP DATABASE another_database;', 'CREATE SCHEMA another_database;',
    'ALTER DATABASE another_database CHARACTER SET utf8mb4;', 'START TRANSACTION;', 'COMMIT;',
    'ROLLBACK;', 'LOCK TABLES a WRITE;', 'UNLOCK TABLES;', '-- nothing to execute'] as $bad) {
    try { fullCoreUpgradeStatements($bad); } catch (RuntimeException $e) { $assert(true, 'incomplete or unsafe migration refused'); continue; }
    $assert(false, 'incomplete or unsafe migration refused');
}
$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/full-core-upgrade-pending.json'), true, 512, JSON_THROW_ON_ERROR);
$assert(count($fixture['pending_paths']) === 60 && count(array_unique($fixture['pending_paths'])) === 60, 'fixture is the exact 60-file reviewed pending set');
$count = 0;
foreach ($fixture['pending_paths'] as $path) {
    $parts = fullCoreUpgradeStatements(fullCoreMigrationRead(dirname(__DIR__), $path));
    $count += count($parts);
}
$assert($count > 60, 'all pending migration files compile before any database work');
echo "Upgrade parser: $checks checks passed; $count pending statements parsed.\n";
