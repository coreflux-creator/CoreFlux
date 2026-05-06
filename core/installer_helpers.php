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

    // Pick the bundle by NEWEST mtime (matches spa.php's selection logic).
    // Old alphabetical-last logic was the root cause of "deploys appear to
    // do nothing" — Vite leaves stale content-hashed siblings behind, and
    // alphabetical order was non-deterministic w.r.t. which was current.
    $jsFile = $cssFile = '';
    $jsMTime = $cssMTime = 0;
    foreach (scandir($assetsDir) as $f) {
        $path = "$assetsDir/$f";
        if (preg_match('/^index-.*\.js$/',  $f) && filemtime($path) > $jsMTime)  { $jsFile  = $f; $jsMTime  = filemtime($path); }
        if (preg_match('/^index-.*\.css$/', $f) && filemtime($path) > $cssMTime) { $cssFile = $f; $cssMTime = filemtime($path); }
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
 *   [ ['file' => '...', 'status' => 'applied'|'applied_with_skips'|'already_applied'|'unreadable'|'failed',
 *      'skipped' => N,   // idempotency skips ("column already exists", etc.)
 *      'errors'  => [],  // any HARD errors (real failures)
 *   ], ... ]
 *
 * Idempotency: catches "Duplicate column / already exists / Duplicate key"
 * and similar safe errors at the per-statement level. A migration that
 * has been partially applied by the runtime self-heal (e.g.
 * `_treasuryEnsurePlaidBalanceColumns`) will now still register as
 * applied without aborting the whole update.
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

    // Idempotency-safe error fragments. Hitting any of these means the
    // statement was a no-op against an already-converged schema.
    $safePatterns = [
        'Duplicate column name',
        'Duplicate key name',
        'already exists',
        'check that column/key exists',
        'Multiple primary key defined',
        "Can't DROP",                  // dropping a column / index that's already gone
        'Unknown column',              // referencing a column we just attempted to drop
    ];

    $log = [];
    $insert = $pdo->prepare('INSERT INTO coreflux_migrations (file_path, checksum_sha) VALUES (:p, :c)');
    foreach ($paths as $abs) {
        $rel = ltrim(str_replace($root, '', $abs), '/');
        if (isset($applied[$rel])) { $log[] = ['file' => $rel, 'status' => 'already_applied']; continue; }
        $sql = file_get_contents($abs);
        if ($sql === false) { $log[] = ['file' => $rel, 'status' => 'unreadable']; continue; }

        // Split on ;\R so we can per-statement-recover from idempotency
        // errors instead of aborting the whole file. Strip comment-only
        // lines so trailing comments after the last `;` don't trigger an
        // empty-query exec error.
        $statements = array_filter(array_map('trim', preg_split('/;\s*\R/m', $sql)));
        $skipped = 0;
        $hardErrors = [];
        foreach ($statements as $stmt) {
            $clean = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
            if ($clean === '' || $clean === ';') continue;
            try {
                $pdo->exec($clean);
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $isSafe = false;
                foreach ($safePatterns as $p) { if (stripos($msg, $p) !== false) { $isSafe = true; break; } }
                if ($isSafe) { $skipped++; continue; }
                $hardErrors[] = substr($msg, 0, 240);
                error_log("[runMigrationsInProcess] {$rel}: {$msg}");
            }
        }

        if ($hardErrors) {
            $log[] = ['file' => $rel, 'status' => 'failed', 'skipped' => $skipped, 'errors' => $hardErrors];
            continue;
        }

        try {
            $insert->execute(['p' => $rel, 'c' => hash('sha256', $sql)]);
        } catch (\Throwable $_) { /* race/dup — non-fatal */ }
        $log[] = [
            'file'    => $rel,
            'status'  => $skipped ? 'applied_with_skips' : 'applied',
            'skipped' => $skipped,
        ];
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
