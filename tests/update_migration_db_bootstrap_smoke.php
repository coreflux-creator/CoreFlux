<?php
/**
 * Smoke: update.php migration bootstrap surfaces real database failures.
 *
 * The hosted updater can deploy files without shell exec, but it still needs
 * the PHP database bootstrap to run canonical migrations. This pins the
 * production-safe fallback from localhost to 127.0.0.1 and ensures the update
 * page reports the real database bootstrap reason instead of a generic
 * "no PDO available" dead end.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  ok    $name\n";
    } else {
        $fail++;
        echo "  FAIL  $name\n";
    }
};

$db = (string) file_get_contents(__DIR__ . '/../core/db.php');
$migrate = (string) file_get_contents(__DIR__ . '/../core/migrate.php');

echo "core/db.php - updater-safe DB bootstrap\n";
$a('records last DB bootstrap error', str_contains($db, '$coreflux_db_last_error'));
$a('exposes getDBLastError for admin diagnostics', str_contains($db, 'function getDBLastError(): ?string'));
$a('detects missing PDO extension explicitly', str_contains($db, "PDO extension is not loaded"));
$a('tries 127.0.0.1 when configured host is localhost', str_contains($db, "DB_HOST === 'localhost'") && str_contains($db, "\$hosts[] = '127.0.0.1'"));
$a('preserves localhost fallback when configured host is 127.0.0.1', str_contains($db, "DB_HOST === '127.0.0.1'") && str_contains($db, "\$hosts[] = 'localhost'"));
$a('catches bootstrap failures without requiring PDOException class', str_contains($db, 'catch (\\Throwable $e)'));
$a('tracks disabled database mode distinctly', str_contains($db, 'COREFLUX_DISABLE_DATABASE=1') && str_contains($db, 'USE_DATABASE is disabled'));

echo "\ncore/migrate.php - update page diagnostic contract\n";
$a('migration runner includes DB bootstrap detail', str_contains($migrate, 'getDBLastError()'));
$a('migration error keeps no-PDO prefix for existing UI handling', str_contains($migrate, "'no PDO available: ' . \$reason"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
