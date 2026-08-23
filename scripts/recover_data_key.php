<?php
/**
 * Capture or restore the production CoreFlux encryption key during a
 * Cloudways point-in-time web-files recovery.
 *
 * The key is never printed. Capture writes a mode-0600 escrow file outside
 * public_html/private_html so Cloudways web-file restore/rollback leaves it
 * untouched. Restore writes the key back into config.local.php and a backed-up
 * private_html secret file.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = strtolower(trim((string) ($argv[1] ?? 'none')));
$baseArg = (string) ($argv[2] ?? dirname(__DIR__));
$appBase = realpath($baseArg);
if ($appBase === false || !is_dir($appBase . '/public_html/core')) {
    fwrite(STDERR, "Unable to resolve the Cloudways application root.\n");
    exit(1);
}
if (!in_array($mode, ['none', 'capture', 'restore'], true)) {
    fwrite(STDERR, "Recovery mode must be none, capture, or restore.\n");
    exit(1);
}
if ($mode === 'none') {
    echo "Data-key recovery mode: none.\n";
    exit(0);
}

$configPath = $appBase . '/public_html/core/config.local.php';
$privateDir = $appBase . '/private_html';
$privatePath = $privateDir . '/coreflux.secrets.php';
$escrowPath = $appBase . '/.coreflux-data-key-recovery';

/** @return string|null */
function recoveryKeyFromPhpFile(string $path): ?string
{
    if (!is_file($path)) return null;
    $content = file_get_contents($path);
    if ($content === false) return null;
    if (!preg_match('/define\s*\(\s*([\'\"])COREFLUX_DATA_KEY\1\s*,\s*([\'\"])([A-Za-z0-9+\/=]+)\2\s*\)/', $content, $m)) {
        return null;
    }
    return (string) $m[3];
}

function recoveryValidKey(string $encoded): bool
{
    $raw = base64_decode(trim($encoded), true);
    return $raw !== false && strlen($raw) === 32;
}

function recoveryAtomicWrite(string $path, string $content, int $mode): void
{
    $dir = dirname($path);
    $temp = tempnam($dir, '.coreflux-key-');
    if ($temp === false || file_put_contents($temp, $content, LOCK_EX) === false) {
        if (is_string($temp) && is_file($temp)) @unlink($temp);
        throw new RuntimeException('Unable to write the recovery file.');
    }
    @chmod($temp, $mode);
    if (!rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Unable to install the recovery file.');
    }
    @chmod($path, $mode);
}

try {
    if ($mode === 'capture') {
        $key = recoveryKeyFromPhpFile($configPath) ?? recoveryKeyFromPhpFile($privatePath);
        if ($key === null || !recoveryValidKey($key)) {
            throw new RuntimeException('No valid 32-byte CoreFlux data key exists in the restored web files.');
        }
        if (is_file($escrowPath)) {
            $existing = trim((string) file_get_contents($escrowPath));
            if (!recoveryValidKey($existing) || !hash_equals($existing, $key)) {
                throw new RuntimeException('A different or invalid recovery escrow already exists.');
            }
        } else {
            recoveryAtomicWrite($escrowPath, $key . "\n", 0600);
        }
        echo "Original CoreFlux data key captured securely (value withheld).\n";
        exit(0);
    }

    $key = is_file($escrowPath) ? trim((string) file_get_contents($escrowPath)) : '';
    if (!recoveryValidKey($key)) {
        throw new RuntimeException('The recovery escrow is missing or invalid.');
    }

    $content = is_file($configPath) ? file_get_contents($configPath) : "<?php\n";
    if ($content === false || !str_starts_with(ltrim($content), '<?php')) {
        throw new RuntimeException('The current host configuration is unreadable or is not PHP.');
    }
    $existing = recoveryKeyFromPhpFile($configPath);
    if ($existing !== null && recoveryValidKey($existing) && !hash_equals($existing, $key)) {
        throw new RuntimeException('The current host has a different valid data key; refusing to overwrite it.');
    }

    $begin = '// BEGIN COREFLUX RECOVERED DATA KEY';
    $end = '// END COREFLUX RECOVERED DATA KEY';
    $content = (string) preg_replace('/\R?' . preg_quote($begin, '/') . '.*?' . preg_quote($end, '/') . '\R?/s', "\n", $content);
    if ($existing === null || !recoveryValidKey($existing)) {
        $content = (string) preg_replace(
            '/\R?\s*(?:if\s*\([^\r\n]*\)\s*)?define\s*\(\s*([\'\"])COREFLUX_DATA_KEY\1\s*,.*?\)\s*;\s*/s',
            "\n",
            $content
        );
    }
    $content = (string) preg_replace('/\?>\s*$/', '', $content);
    if ($existing === null || !recoveryValidKey($existing)) {
        $content = rtrim($content) . "\n\n{$begin}\n"
            . "if (!defined('COREFLUX_DATA_KEY')) define('COREFLUX_DATA_KEY', " . var_export($key, true) . ");\n"
            . "{$end}\n";
    }
    recoveryAtomicWrite($configPath, $content, 0640);

    if (!is_dir($privateDir) && !mkdir($privateDir, 0750, true) && !is_dir($privateDir)) {
        throw new RuntimeException('Unable to create private_html for the durable key copy.');
    }
    $privateExisting = recoveryKeyFromPhpFile($privatePath);
    if ($privateExisting !== null && recoveryValidKey($privateExisting) && !hash_equals($privateExisting, $key)) {
        throw new RuntimeException('private_html contains a different valid data key; refusing to overwrite it.');
    }
    $privateContent = "<?php\n// Host-only, included in Cloudways application backups.\n"
        . "if (!defined('COREFLUX_DATA_KEY')) define('COREFLUX_DATA_KEY', " . var_export($key, true) . ");\n";
    recoveryAtomicWrite($privatePath, $privateContent, 0640);

    echo "Original CoreFlux data key restored to host configuration and private backup (value withheld).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Data-key recovery failed: ' . $e->getMessage() . "\n");
    exit(1);
}

