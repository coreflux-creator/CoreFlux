<?php
/**
 * Installer helpers — shared between install.php and update.php.
 * Pure functions; no HTML, no headers, no top-level execution.
 */

declare(strict_types=1);

/**
 * Apply pending migrations. Returns a per-file log:
 *   [ ['file' => 'core/migrations/...', 'status' => 'applied'|'already_applied'|'unreadable'], ... ]
 */
function runMigrationsInProcess(): array {
    require_once __DIR__ . '/config.php';
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path     VARCHAR(255) NOT NULL UNIQUE,
    applied_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum_sha  CHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

    $applied = [];
    foreach ($pdo->query('SELECT file_path FROM schema_migrations') as $r) $applied[$r['file_path']] = true;

    $root = dirname(__DIR__);
    $paths = array_merge(
        glob($root . '/core/migrations/*.sql')      ?: [],
        glob($root . '/modules/*/migrations/*.sql') ?: []
    );
    $paths = array_values(array_filter($paths, fn(string $p) => !preg_match('#/modules/_[^/]+/#', $p)));
    sort($paths);

    $log = [];
    $insert = $pdo->prepare('INSERT INTO schema_migrations (file_path, checksum_sha) VALUES (:p, :c)');
    foreach ($paths as $abs) {
        $rel = ltrim(str_replace($root, '', $abs), '/');
        if (isset($applied[$rel])) { $log[] = ['file' => $rel, 'status' => 'already_applied']; continue; }
        $sql = file_get_contents($abs);
        if ($sql === false) { $log[] = ['file' => $rel, 'status' => 'unreadable']; continue; }
        $pdo->exec($sql);
        $insert->execute(['p' => $rel, 'c' => hash('sha256', $sql)]);
        $log[] = ['file' => $rel, 'status' => 'applied'];
    }
    return $log;
}

/**
 * 4-check smoke test: data key, encryption round-trip, OpenAI direct, SMTP connect.
 * Returns [ ['check' => ..., 'ok' => bool, 'detail' => '...'], ... ]
 */
function runSmokeInProcess(string $localCfg): array {
    require_once $localCfg;
    $log = [];

    $raw = base64_decode(defined('COREFLUX_DATA_KEY') ? COREFLUX_DATA_KEY : '', true);
    $log[] = ['check' => 'data_key', 'ok' => ($raw !== false && strlen($raw) === 32)];

    require_once __DIR__ . '/encryption.php';
    $plain = 'install-test-' . bin2hex(random_bytes(4));
    $back  = decryptField(encryptField($plain));
    $log[] = ['check' => 'encryption', 'ok' => ($back === $plain)];

    require_once __DIR__ . '/ai_service.php';
    [$content, $latency, $model, $http, $err] = aiCallOpenAI([
        'model' => defined('AI_MODEL_SUMMARY') ? AI_MODEL_SUMMARY : 'gpt-5.4-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'Answer in one short word.'],
            ['role' => 'user',   'content' => 'Reply with the single word: ready.'],
        ],
        'max_completion_tokens' => 30,
    ]);
    $log[] = [
        'check'  => 'openai',
        'ok'     => $content !== null,
        'detail' => $content !== null
            ? "model=$model, ${latency}ms"
            : "http=$http err=" . substr((string)$err, 0, 200),
    ];

    $smtpOk = false; $smtpDetail = '';
    try {
        $libBase = dirname(__DIR__) . '/lib/PHPMailer/src';
        require_once $libBase . '/Exception.php';
        require_once $libBase . '/PHPMailer.php';
        require_once $libBase . '/SMTP.php';
        $smtp = new PHPMailer\PHPMailer\SMTP();
        if ($smtp->connect(SMTP_HOST, SMTP_PORT, 5)) {
            $smtp->quit(); $smtp->close();
            $smtpOk = true;
            $smtpDetail = SMTP_HOST . ':' . SMTP_PORT;
        } else {
            $smtpDetail = 'connect failed';
        }
    } catch (Throwable $e) {
        $smtpDetail = $e->getMessage();
    }
    $log[] = ['check' => 'smtp', 'ok' => $smtpOk, 'detail' => $smtpDetail];

    return $log;
}
