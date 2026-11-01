<?php
declare(strict_types=1);

$portal = dirname(__DIR__) . '/billing/invoice.php';
$source = (string) file_get_contents($portal);
foreach ([
    "header('Cache-Control: private, no-store, max-age=0')",
    "header('Referrer-Policy: no-referrer')",
    "header('X-Robots-Tag: noindex, nofollow')",
] as $header) {
    if (strpos($source, $header) === false
        || strpos($source, $header) > strpos($source, "require_once __DIR__ . '/../core/db.php'")) {
        throw new RuntimeException("Invoice portal does not set an early privacy header: $header");
    }
}

$fixture = tempnam(sys_get_temp_dir(), 'coreacc-portal-');
if ($fixture === false || !rename($fixture, $fixture . '.php')) {
    throw new RuntimeException('Could not create private staging configuration fixture.');
}
$fixture .= '.php';
register_shutdown_function(static fn() => @unlink($fixture));
file_put_contents($fixture, <<<'PHP'
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'portal_fixture');
define('DB_USER', 'portal_fixture');
define('DB_PASS', 'not-a-real-password');
PHP);

$environment = [
    'COREFLUX_ENV' => 'staging',
    'COREFLUX_ACCOUNTING_DB_CONFIG_PATH' => $fixture,
];
$original = [];
foreach ($environment as $name => $value) {
    $original[$name] = getenv($name);
    putenv($name . '=' . $value);
}
try {
    $code = '$_GET["t"] = "invalid"; ob_start(); require '
        . var_export($portal, true)
        . '; $html = ob_get_clean(); echo "STATUS=" . http_response_code() . "\n";'
        . ' echo str_contains($html, "This link is invalid or has expired.") ? "INVALID_LINK\n" : "MISSING_MESSAGE\n";';
    $process = proc_open([PHP_BINARY, '-r', $code], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not launch invoice portal fixture.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $diagnostics = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($output) !== "STATUS=404\nINVALID_LINK") {
        throw new RuntimeException('Invoice portal invalid-link response failed: ' . $output . $diagnostics);
    }
} finally {
    foreach ($original as $name => $value) {
        $value === false ? putenv($name) : putenv($name . '=' . $value);
    }
}

echo "Passed: invoice portal privacy headers and invalid-token 404\n";
