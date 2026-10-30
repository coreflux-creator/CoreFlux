<?php
/**
 * Core\PdfRenderer — pure-PHP HTML→PDF using a system renderer.
 *
 * Prefer headless Chromium for modern CSS, then wkhtmltopdf. Shared hosts
 * without a system binary use the Composer-installed Dompdf fallback.
 *
 * Usage:
 *   require_once 'core/pdf_renderer.php';
 *   cf_render_html_to_pdf($html, '/path/to/out.pdf');
 */
declare(strict_types=1);

/**
 * Render an HTML string to a PDF file.
 *
 * @param string $html       Complete HTML document (use a <!doctype html> wrapper).
 * @param string $outPath    Absolute path the PDF will be written to.
 * @param array  $opts       Options:
 *                           - 'paper' => 'letter'|'a4' (default 'letter')
 *                           - 'landscape' => bool
 *                           - 'margins' => '0.5in' or [top, right, bottom, left]
 *                           - 'timeout_sec' => 30
 * @return bool              true on success.
 * @throws RuntimeException  on render failure or no renderer installed.
 */
function cf_render_html_to_pdf(string $html, string $outPath, array $opts = []): bool {
    if ($html === '') {
        throw new InvalidArgumentException('cf_render_html_to_pdf: empty HTML');
    }
    if ($outPath === '') {
        throw new InvalidArgumentException('cf_render_html_to_pdf: outPath required');
    }
    $dir = dirname($outPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) {
        throw new RuntimeException("cf_render_html_to_pdf: directory not writable: {$dir}");
    }

    $bin = _cf_pdf_find_renderer();
    if ($bin === null) return _cf_pdf_render_dompdf($html, $outPath, $opts);

    $timeout = (int) ($opts['timeout_sec'] ?? 30);
    $htmlDir = cf_pdf_private_temp_dir();
    $tmpHtml = $htmlDir . '/source.html';

    try {
        if (file_put_contents($tmpHtml, $html) !== strlen($html)) {
            throw new RuntimeException('Could not stage PDF source HTML');
        }
        if (str_contains($bin, 'wkhtmltopdf')) {
            $cmd = _cf_pdf_wkhtmltopdf_cmd($bin, $tmpHtml, $outPath, $opts);
        } else {
            $cmd = _cf_pdf_chromium_cmd($bin, $tmpHtml, $outPath, $opts);
        }

        $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc  = proc_open($cmd, $descr, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not spawn PDF renderer');
        }

        // Set a hard timeout. The chromium command sometimes hangs on bad
        // CSS; we kill it rather than waiting forever.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = microtime(true);
        $stdout = '';
        $stderr = '';
        $exitCode = null;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exitCode = $status['exitcode']; // grab exit code while it's still valid
                break;
            }
            if (microtime(true) - $start > $timeout) {
                proc_terminate($proc, 9);
                throw new RuntimeException("PDF renderer timed out after {$timeout}s");
            }
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            usleep(50_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeExit = proc_close($proc);
        $exit = $exitCode !== null ? $exitCode : $closeExit; // proc_close returns -1 if already reaped

        if ($exit !== 0 || !is_file($outPath) || filesize($outPath) === 0) {
            throw new RuntimeException("PDF renderer failed (exit={$exit}): " . substr($stderr, 0, 400));
        }
        return true;
    } finally {
        @unlink($tmpHtml);
        @rmdir($htmlDir);
    }
}

function cf_pdf_private_temp_dir(): string {
    $tmpDir = sys_get_temp_dir() . '/cf-pdf-' . bin2hex(random_bytes(16));
    if (!@mkdir($tmpDir, 0700) || !is_writable($tmpDir)) {
        if (is_dir($tmpDir)) @rmdir($tmpDir);
        throw new RuntimeException('Could not create a private PDF output directory');
    }
    return $tmpDir;
}

/** Render and serve a PDF from a private, per-request temporary directory. */
function cf_stream_html_pdf(string $html, string $filename, array $opts = [], string $disposition = 'inline'): void {
    if (!preg_match('/^[A-Za-z0-9_.-]+\.pdf$/', $filename)
        || !in_array($disposition, ['inline', 'attachment'], true)) {
        throw new InvalidArgumentException('Invalid PDF response filename or disposition');
    }

    $tmpDir = cf_pdf_private_temp_dir();
    $outPath = $tmpDir . '/document.pdf';
    try {
        cf_render_html_to_pdf($html, $outPath, $opts);
        $size = filesize($outPath);
        if ($size === false || $size < 5) throw new RuntimeException('Rendered PDF is empty');

        header_remove('Content-Type');
        header('Cache-Control: private, no-store');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        if (readfile($outPath) === false) error_log('[pdf_renderer] Could not stream rendered PDF');
    } finally {
        if (is_file($outPath)) @unlink($outPath);
        @rmdir($tmpDir);
    }
}

function _cf_pdf_render_dompdf(string $html, string $outPath, array $opts): bool {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('No PDF renderer available. Run composer install for the application or configure CF_PDF_RENDERER_BIN.');
    }

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('tempDir', sys_get_temp_dir());
    $renderer = new \Dompdf\Dompdf($options);
    $renderer->setPaper((string) ($opts['paper'] ?? 'letter'), !empty($opts['landscape']) ? 'landscape' : 'portrait');
    try {
        $renderer->loadHtml($html, 'UTF-8');
        $renderer->render();
        $pdf = $renderer->output();
    } catch (Throwable $e) {
        throw new RuntimeException('PDF rendering failed: ' . $e->getMessage(), 0, $e);
    }
    if (!str_starts_with($pdf, '%PDF-')) throw new RuntimeException('PDF rendering did not produce a valid file');
    $tempPath = tempnam(dirname($outPath), '.pdf-');
    if ($tempPath === false) throw new RuntimeException('Could not reserve PDF output path');
    try {
        $written = file_put_contents($tempPath, $pdf);
        if ($written !== strlen($pdf) || !rename($tempPath, $outPath)) {
            throw new RuntimeException('Could not save the rendered PDF');
        }
    } finally {
        if (is_file($tempPath)) @unlink($tempPath);
    }
    return true;
}

function _cf_pdf_find_renderer(): ?string {
    foreach (['CF_PDF_RENDERER_BIN'] as $env) {
        $v = getenv($env);
        if ($v && is_executable($v)) return $v;
    }
    foreach ([
        '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable', '/usr/local/bin/chromium', '/usr/bin/wkhtmltopdf',
    ] as $cand) {
        if (is_executable($cand)) return $cand;
    }
    return null;
}

function _cf_pdf_chromium_cmd(string $bin, string $htmlPath, string $pdfPath, array $opts): string {
    // Each invocation gets its own user-data-dir so concurrent renders can
    // coexist without stepping on each other's profile lock.
    $udd = sys_get_temp_dir() . '/cf-chrome-' . bin2hex(random_bytes(4));
    @mkdir($udd, 0700, true);
    $args = [
        $bin,
        '--headless',                   // legacy headless is the most portable across distro chromium builds
        '--disable-gpu',
        '--no-sandbox',                 // required for unprivileged container exec
        '--disable-dev-shm-usage',      // /dev/shm is tiny inside most containers
        '--disable-software-rasterizer',
        '--disable-extensions',
        '--disable-features=VizDisplayCompositor',
        '--no-first-run',
        '--no-default-browser-check',
        '--user-data-dir=' . $udd,
        '--hide-scrollbars',
        '--no-pdf-header-footer',
        '--print-to-pdf=' . $pdfPath,
        '--virtual-time-budget=2000',   // give web fonts a beat to load
    ];
    if (!empty($opts['landscape'])) {
        $args[] = '--landscape';
    }
    $args[] = 'file://' . $htmlPath;
    return implode(' ', array_map('escapeshellarg', $args));
}

function _cf_pdf_wkhtmltopdf_cmd(string $bin, string $htmlPath, string $pdfPath, array $opts): string {
    $args = [$bin, '--quiet'];
    $paper = strtoupper((string) ($opts['paper'] ?? 'letter'));
    $args[] = '-s';
    $args[] = $paper;
    if (!empty($opts['landscape'])) {
        $args[] = '-O';
        $args[] = 'Landscape';
    }
    $m = $opts['margins'] ?? '0.5in';
    if (is_array($m)) {
        $args[] = '-T'; $args[] = (string) ($m[0] ?? '0.5in');
        $args[] = '-R'; $args[] = (string) ($m[1] ?? '0.5in');
        $args[] = '-B'; $args[] = (string) ($m[2] ?? '0.5in');
        $args[] = '-L'; $args[] = (string) ($m[3] ?? '0.5in');
    } else {
        $args[] = '-T'; $args[] = (string) $m;
        $args[] = '-R'; $args[] = (string) $m;
        $args[] = '-B'; $args[] = (string) $m;
        $args[] = '-L'; $args[] = (string) $m;
    }
    $args[] = $htmlPath;
    $args[] = $pdfPath;
    return implode(' ', array_map('escapeshellarg', $args));
}
