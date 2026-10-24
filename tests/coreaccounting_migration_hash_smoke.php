<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/migration_hash.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$lf = "CREATE TABLE example (id INT);\nINSERT INTO example VALUES (1);\n";
$crlf = str_replace("\n", "\r\n", $lf);
$mixed = "CREATE TABLE example (id INT);\r\nINSERT INTO example VALUES (1);\n";
$changed = "CREATE TABLE example (id INT);\nINSERT INTO example VALUES (2);\n";

$check('canonical migration hash ignores line endings',
    corefluxMigrationHash($lf) === corefluxMigrationHash($crlf));
$check('new canonical hash accepts either packaged line ending',
    corefluxMigrationHashMatches(corefluxMigrationHash($lf), $crlf));
$check('old LF ledger hash accepts CRLF package',
    corefluxMigrationHashMatches(hash('sha256', $lf), $crlf));
$check('old CRLF ledger hash accepts LF package',
    corefluxMigrationHashMatches(hash('sha256', $crlf), $lf));
$check('old mixed-ending ledger hash still matches its exact package',
    corefluxMigrationHashMatches(hash('sha256', $mixed), $mixed));
$check('changed SQL statements still require migration',
    !corefluxMigrationHashMatches(hash('sha256', $lf), $changed));
$check('failed sentinel is never accepted as an applied migration',
    !corefluxMigrationHashMatches('FAIL:' . str_repeat('a', 58), $lf));
$check('missing ledger row is not accepted', !corefluxMigrationHashMatches(null, $lf));

$provisioner = (string) file_get_contents(__DIR__ . '/../deploy/provision_coreaccounting_tenant.php');
$check('first-tenant provisioner uses the same migration identity check',
    str_contains($provisioner, 'corefluxMigrationHashMatches((string) $recorded[\'sha256\'], $sql)'));

echo "Migration hash checks: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
