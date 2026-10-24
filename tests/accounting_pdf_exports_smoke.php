<?php
/** PDF exports must not share a fixed, cross-user output directory. */
declare(strict_types=1);

require_once __DIR__ . '/../core/pdf_renderer.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$dir = cf_pdf_private_temp_dir();
try {
    $resolved = realpath($dir);
    $temp = realpath(sys_get_temp_dir());
    $check('output directory is private and inside system temp',
        $resolved !== false && $temp !== false
        && str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR . 'cf-pdf-')
        && is_writable($resolved));
    if (PHP_OS_FAMILY !== 'Windows') {
        $check('output directory is owner-only', (fileperms($dir) & 0777) === 0700);
    }
} finally {
    @rmdir($dir);
}
$check('temporary output directory is removed', !is_dir($dir));

foreach (['../private.pdf', "bad\r\nname.pdf"] as $filename) {
    try {
        cf_stream_html_pdf('test', $filename);
        $check('unsafe filename rejected', false);
    } catch (InvalidArgumentException $e) {
        $check('unsafe filename rejected', true);
    }
}
try {
    cf_stream_html_pdf('test', 'safe.pdf', [], 'redirect');
    $check('unsafe disposition rejected', false);
} catch (InvalidArgumentException $e) {
    $check('unsafe disposition rejected', true);
}

foreach ([
    'accounting close packet' => __DIR__ . '/../modules/accounting/api/close_packet.php',
    'billing money movement' => __DIR__ . '/../modules/billing/api/money_movement_pdf.php',
    'billing statement' => __DIR__ . '/../modules/billing/api/statement_pdf.php',
] as $label => $path) {
    $source = (string) file_get_contents($path);
    $check("{$label} uses the private PDF streamer",
        str_contains($source, 'cf_stream_html_pdf(')
        && !str_contains($source, "sys_get_temp_dir() . '/cf-pdf-"));
    $check("{$label} does not expose renderer paths to the user",
        str_contains($source, "api_error('PDF renderer unavailable', 503)")
        && str_contains($source, 'error_log('));
}

$renderer = (string) file_get_contents(__DIR__ . '/../core/pdf_renderer.php');
$check('system renderer stages HTML in a private directory',
    str_contains($renderer, "\$tmpHtml = \$htmlDir . '/source.html'")
    && str_contains($renderer, '@rmdir($htmlDir)')
    && !str_contains($renderer, "tempnam(sys_get_temp_dir(), 'cf-pdf-') . '.html'"));
$close = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/close.php');
$check('packet uses PDF-safe date wording and labels earlier reopen history',
    str_contains($close, "' to ' . \$h(\$period['end_date'])")
    && str_contains($close, 'Most recent reopen'));

echo "Accounting PDF exports: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
