<?php
/**
 * Installer helpers — shared between install.php and update.php.
 * Pure functions; no HTML, no headers, no top-level execution.
 */

declare(strict_types=1);

/**
 * Detect whether shell exec is available on this PHP host. Cloudways and most
 * managed/shared PHP hosts disable exec()/shell_exec() via php.ini's
 * `disable_functions` for security, which raises "Call to undefined function
 * exec()" errors. Any installer/updater code that wants to shell out MUST
 * gate the call behind this helper and fall back gracefully when it returns
 * false.
 */
function installerCanExec(): bool {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

/**
 * Inspect the SPA bundle currently shipped in /spa-assets/ vs the freshest
 * source file under /dashboard/src/ + /modules/. Surfaces a clear "stale UI"
 * warning when source changed after the last `yarn build` was committed.
 * Returns rows in the same shape as runSmokeInProcess() so update.php can
 * render them without special-casing.
 */
function spaBundleStatus(string $root): array {
    $rows = [];
    $assetsDir = $root . '/spa-assets';
    if (!is_dir($assetsDir)) {
        $rows[] = ['check' => 'spa-assets dir', 'ok' => false, 'detail' => "missing $assetsDir — run yarn build in /dashboard"];
        return $rows;
    }

    $jsFile = $cssFile = '';
    $jsMTime = 0;
    foreach (scandir($assetsDir) as $f) {
        if (preg_match('/^index-.*\.js$/',  $f)) { $jsFile  = $f; $jsMTime = filemtime("$assetsDir/$f"); }
        if (preg_match('/^index-.*\.css$/', $f)) { $cssFile = $f; }
    }
    if ($jsFile === '' || $cssFile === '') {
        $rows[] = ['check' => 'spa bundle', 'ok' => false, 'detail' => 'no index-*.js or index-*.css in /spa-assets/'];
        return $rows;
    }

    $rows[] = [
        'check'  => 'spa bundle',
        'ok'    => true,
        'detail' => "$jsFile (built " . date('Y-m-d H:i', $jsMTime) . ")",
    ];

    $newest = 0; $newestFile = '';
    $scan = function (string $dir) use (&$scan, &$newest, &$newestFile): void {
        if (!is_dir($dir)) return;
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            if (!$f->isFile()) continue;
            $path = $f->getPathname();
            if (!preg_match('/\.(jsx?|tsx?|css)$/', $path)) continue;
            $m = $f->getMTime();
            if ($m > $newest) { $newest = $m; $newestFile = $path; }
        }
    };
    $scan($root . '/dashboard/src');
    foreach (glob($root . '/modules/*/ui') ?: [] as $uiDir) $scan($uiDir);

    // Tolerance: 600 seconds (10 min). Git pull does NOT preserve commit
    // mtime — it stamps every pulled file with the current wall-clock time in
    // whatever order git happens to write them. With ~100+ modified files,
    // write-ordering drift of 10-30s between a bundle file and a source
    // file from the SAME commit is routine on Cloudways. The previous 5s
    // tolerance produced constant false-positive "STALE" warnings after
    // every `git pull`. 10 minutes is still tight enough to catch a real
    // case of "someone edited source but forgot to re-run yarn build"
    // (which shows up as MANY minutes or hours of drift), while immune to
    // same-commit pull ordering.
    if ($newest > $jsMTime + 600) {
        $rel = ltrim(str_replace($root, '', $newestFile), '/');
        $driftMin = (int) round(($newest - $jsMTime) / 60);
        $rows[] = [
            'check'  => 'spa bundle freshness',
            'ok'    => false,
            'detail' => "STALE — UI source $rel is {$driftMin}min newer than the bundle. " .
                        "Run `yarn build` in /dashboard/, commit the new /spa-assets/ files, " .
                        "redeploy. Until then the browser will keep showing the old UI.",
        ];
    } else {
        $rows[] = [
            'check'  => 'spa bundle freshness',
            'ok'    => true,
            'detail' => 'bundle is newer than all UI source files',
        ];
    }
    return $rows;
}

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
CREATE TABLE IF NOT EXISTS coreflux_migrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path     VARCHAR(255) NOT NULL UNIQUE,
    applied_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checksum_sha  CHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

    $applied = [];
    foreach ($pdo->query('SELECT file_path FROM coreflux_migrations') as $r) $applied[$r['file_path']] = true;

    $root = dirname(__DIR__);
    $paths = array_merge(
        glob($root . '/core/migrations/*.sql')      ?: [],
        glob($root . '/modules/*/migrations/*.sql') ?: []
    );
    $paths = array_values(array_filter($paths, fn(string $p) => !preg_match('#/modules/_[^/]+/#', $p)));
    sort($paths);

    $log = [];
    $insert = $pdo->prepare('INSERT INTO coreflux_migrations (file_path, checksum_sha) VALUES (:p, :c)');
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
