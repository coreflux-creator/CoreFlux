<?php
/**
 * Core\PdfRenderer — pure-PHP HTML→PDF using a system renderer.
 *
 * We prefer headless Chromium for fidelity (modern CSS, web fonts, flexbox,
 * grid all work). We fall back to wkhtmltopdf if Chromium isn't present
 * and finally throw a clear "no renderer installed" error so the operator
 * knows what to install on the host.
 *
 * Why not a composer-installed PHP library (Dompdf, mPDF)?
 *   - CoreFlux has no vendor/ directory deployed today
 *   - Chromium is already present in the container/host
 *   - Output fidelity is markedly better for invoice-style layouts
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

    $timeout = (int) ($opts['timeout_sec'] ?? 30);
    $tmpHtml = tempnam(sys_get_temp_dir(), 'cf-pdf-') . '.html';
    file_put_contents($tmpHtml, $html);

    try {
        $bin = _cf_pdf_find_renderer();
        if ($bin === null) {
            throw new RuntimeException(
                'No PDF renderer found. Install chromium-browser (apt: chromium) or wkhtmltopdf on the host.'
            );
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
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) break;
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
        $exit = proc_close($proc);

        if ($exit !== 0 || !is_file($outPath) || filesize($outPath) === 0) {
            throw new RuntimeException("PDF renderer failed (exit={$exit}): " . substr($stderr, 0, 400));
        }
        return true;
    } finally {
        @unlink($tmpHtml);
    }
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
    $args = [
        $bin,
        '--headless=new',
        '--disable-gpu',
        '--no-sandbox',                 // required for unprivileged container exec
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
