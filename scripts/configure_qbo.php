<?php
/**
 * Install the host-local QuickBooks configuration from environment variables.
 *
 * This script intentionally prints presence checks only; credential values must
 * never be written to deployment logs. It is safe to run repeatedly.
 *
 * Usage:
 *   QBO_CLIENT_ID=... QBO_CLIENT_SECRET=... php scripts/configure_qbo.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$values = [
    'QBO_CLIENT_ID'     => trim((string) getenv('QBO_CLIENT_ID')),
    'QBO_CLIENT_SECRET' => trim((string) getenv('QBO_CLIENT_SECRET')),
    'QBO_REDIRECT_URI'  => trim((string) (getenv('QBO_REDIRECT_URI') ?: 'https://www.corefluxapp.com/api/qbo/oauth_callback.php')),
    'QBO_ENV'           => trim((string) (getenv('QBO_ENV') ?: 'sandbox')),
    'QBO_SCOPES'        => trim((string) (getenv('QBO_SCOPES') ?: 'com.intuit.quickbooks.accounting com.intuit.quickbooks.payment')),
];

foreach (['QBO_CLIENT_ID', 'QBO_CLIENT_SECRET', 'QBO_REDIRECT_URI'] as $required) {
    if ($values[$required] === '') {
        fwrite(STDERR, $required . " is required.\n");
        exit(1);
    }
}

if (!in_array($values['QBO_ENV'], ['sandbox', 'production'], true)) {
    fwrite(STDERR, "QBO_ENV must be sandbox or production.\n");
    exit(1);
}

$configDir = realpath(__DIR__ . '/../core');
if ($configDir === false) {
    fwrite(STDERR, "Unable to resolve the CoreFlux core directory.\n");
    exit(1);
}

$target = $configDir . DIRECTORY_SEPARATOR . 'config.local.php';
$content = is_file($target) ? file_get_contents($target) : "<?php\n";
if ($content === false || !str_starts_with(ltrim($content), '<?php')) {
    fwrite(STDERR, "The host-local configuration is unreadable or is not PHP.\n");
    exit(1);
}

$beginMarker = '// BEGIN COREFLUX MANAGED QBO CONFIG';
$endMarker = '// END COREFLUX MANAGED QBO CONFIG';
$managedPattern = '/\R?' . preg_quote($beginMarker, '/') . '.*?' . preg_quote($endMarker, '/') . '\R?/s';
$content = (string) preg_replace($managedPattern, "\n", $content);
$content = (string) preg_replace('/\?>\s*$/', '', $content);

$managed = [$beginMarker];
foreach ($values as $key => $value) {
    $managed[] = "if (!defined('{$key}')) define('{$key}', " . var_export($value, true) . ');';
}
$managed[] = $endMarker;

$next = rtrim($content) . "\n\n" . implode("\n", $managed) . "\n";
$temp = tempnam($configDir, 'qbo-config-');
if ($temp === false || file_put_contents($temp, $next, LOCK_EX) === false) {
    if (is_string($temp) && is_file($temp)) {
        @unlink($temp);
    }
    fwrite(STDERR, "Unable to write the host-local QuickBooks configuration.\n");
    exit(1);
}

// Cloudways runs PHP-FPM as an application user in the file's www-data
// group, while deployments arrive as the SSH owner. Group-read is therefore
// required; all write access remains owner-only and "other" has no access.
@chmod($temp, 0640);
if (!rename($temp, $target)) {
    @unlink($temp);
    fwrite(STDERR, "Unable to install the host-local QuickBooks configuration.\n");
    exit(1);
}
@chmod($target, 0640);

fwrite(STDOUT, "QuickBooks host configuration installed (credentials withheld).\n");
