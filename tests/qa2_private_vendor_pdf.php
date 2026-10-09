<?php
/** Disposable QA check that Composer's private Dompdf fallback still renders. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-qa', $argv, true)) {
    fwrite(STDERR, "Disposable QA CLI only.\n");
    exit(2);
}
$root = '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html';
$private = '/home/master/.coreaccounting-cleanqa/vendor-private-20261009/vendor';
if (realpath($root) !== $root || realpath($private) !== $private
    || !is_file($root . '/vendor/autoload.php')
    || !is_file($private . '/autoload.php')) {
    throw new RuntimeException('Pinned disposable QA runtime is unavailable.');
}
require_once $root . '/core/pdf_renderer.php';
$dir = cf_pdf_private_temp_dir();
$pdf = $dir . '/vendor-check.pdf';
try {
    _cf_pdf_render_dompdf('<!doctype html><html><body><h1>CoreAccounting QA PDF</h1></body></html>',
        $pdf, ['paper' => 'letter']);
    $header = file_get_contents($pdf, false, null, 0, 5);
    $size = filesize($pdf);
    $loaded = (new ReflectionClass(\Dompdf\Dompdf::class))->getFileName();
    if ($header !== '%PDF-' || $size === false || $size < 1000
        || !str_starts_with((string) realpath((string) $loaded), $private . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private Dompdf did not render a valid PDF.');
    }
    echo json_encode(['pdf_bytes' => $size, 'composer_outside_webroot' => true],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (is_file($pdf)) unlink($pdf);
    rmdir($dir);
}
