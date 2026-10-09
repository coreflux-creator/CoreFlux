<?php
/** Seed and verify a synthetic reset against the disposable CI installation. */
declare(strict_types=1);

$action = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($action, ['--setup', '--verify'], true)
    || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || getenv('MAIL_DRIVER') !== 'log') {
    fwrite(STDERR, "Disposable log-only CoreAccounting CI database only.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$email = 'ci-admin@coreaccounting.invalid';
$rawToken = 'ci-synthetic-reset-token-2026';
$newPassword = 'ci-synthetic-reset-password-2026';
$user = $pdo->prepare('SELECT id, password, password_hash FROM users WHERE email = :email LIMIT 1');
$user->execute([':email' => $email]);
$row = $user->fetch(PDO::FETCH_ASSOC);
if (!$row || (int) $row['id'] <= 0) throw new RuntimeException('Synthetic CI administrator is missing.');

if ($action === '--setup') {
    $count = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE email = :email');
    $count->execute([':email' => $email]);
    if ((int) $count->fetchColumn() !== 0) {
        throw new RuntimeException('Synthetic reset rows already exist.');
    }
    $insert = $pdo->prepare('INSERT INTO password_resets
        (user_id, email, token_hash, expires_at, created_at)
        VALUES (:user_id, :email, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())');
    $insert->execute([':user_id' => (int) $row['id'], ':email' => $email,
        ':token_hash' => hash('sha256', $rawToken)]);
    echo "Synthetic reset token staged in disposable CI.\n";
    exit(0);
}

foreach (['password', 'password_hash'] as $column) {
    if (!password_verify($newPassword, (string) ($row[$column] ?? ''))) {
        throw new RuntimeException("Synthetic password was not updated in $column.");
    }
}
$count = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE email = :email');
$count->execute([':email' => $email]);
if ((int) $count->fetchColumn() !== 0) {
    throw new RuntimeException('Consumed synthetic reset token remained active.');
}
echo "Synthetic password changed once and reset token was consumed.\n";
