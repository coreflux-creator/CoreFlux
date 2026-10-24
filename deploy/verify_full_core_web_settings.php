<?php
/** Temporary read-only PHP-FPM probe. Does not install runtime, bridge, schema or data. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_settings.php';
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--verify-private-web-settings') {
    throw new RuntimeException('Explicit private web-settings verification CLI required.');
}
$requestedRoot = rtrim((string) getenv('COREFLUX_PRODUCTION_PREFLIGHT_ROOT'), '/');
$root = realpath($requestedRoot);
$package = realpath(dirname(__DIR__));
if (!$root || $root !== $requestedRoot || $root === $package
    || !preg_match('~^/home/[^/]+\.cloudwaysapps\.com/[a-z0-9]+/public_html$~D', $root)
    || basename(dirname($root)) === 'muzqvdvqbx' || !extension_loaded('curl')) {
    throw new RuntimeException('Exact production root and HTTPS client required; staging is excluded.');
}
$manifest = json_decode((string) file_get_contents($package . '/integration-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['product'] ?? '') !== 'full-core-staging'
    || !preg_match('/^[a-f0-9]{40}$/D', $manifest['commit'] ?? '')) throw new RuntimeException('Exact candidate manifest required.');
foreach (['deploy/full_core_settings.php', 'deploy/full_core_settings_web_probe.php', 'deploy/verify_full_core_web_settings.php', 'core/config.php', 'core/db.php'] as $file) {
    if (!isset($manifest['files'][$file]) || is_link($package . '/' . $file)
        || !hash_equals($manifest['files'][$file], hash_file('sha256', $package . '/' . $file))) {
        throw new RuntimeException('Candidate web verifier integrity failed.');
    }
}
$private = dirname($root) . '/private_html/core-full-release-' . $manifest['commit'];
if (is_link($private) || realpath($private) !== $private) throw new RuntimeException('Exact private preparation required.');
$receipt = json_decode((string) file_get_contents($private . '/prepared.json'), true, 32, JSON_THROW_ON_ERROR);
if (($receipt['candidate_commit'] ?? '') !== $manifest['commit'] || ($receipt['private_directory'] ?? '') !== $private
    || ($receipt['candidate_config_sha256'] ?? '') !== $manifest['files']['core/config.php']
    || ($receipt['candidate_db_sha256'] ?? '') !== $manifest['files']['core/db.php']) {
    throw new RuntimeException('Prepared candidate identity changed.');
}
$liveConfigBefore = hash_file('sha256', $root . '/core/config.php');
$token = bin2hex(random_bytes(32));
$name = 'core_settings_check_' . bin2hex(random_bytes(16)) . '.php';
$endpoint = $root . '/api/admin/' . $name;
if (is_link(dirname($endpoint)) || realpath(dirname($endpoint)) !== $root . '/api/admin') throw new RuntimeException('Invalid verification directory.');
$source = fullCoreSettingsProbeSource($receipt, basename(dirname($root)), $token, time() + 180, 'www.corefluxapp.com');
$oldMask = umask(0022);
try { $handle = fopen($endpoint, 'x'); } finally { umask($oldMask); }
if (!$handle) throw new RuntimeException('Cannot reserve temporary verifier.');
$result = null;
try {
    try {
        if (fwrite($handle, $source) !== strlen($source) || !fflush($handle)) throw new RuntimeException('Temporary verifier write failed.');
    } finally { fclose($handle); }
    if (!chmod($endpoint, 0644)) throw new RuntimeException('Temporary verifier permissions failed.');
    $request = static function (?string $token) use ($name): array {
        $curl = curl_init('https://www.corefluxapp.com/api/admin/' . $name);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $token === null ? [] : ['X-CoreFlux-Settings-Probe: ' . $token]]);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        return [$status, $body];
    };
    [$denied, $empty] = $request(null);
    if ($denied !== 404 || $empty !== '') throw new RuntimeException('Temporary verifier access guard did not pass.');
    [$status, $body] = $request($token);
    $result = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($result) || ($result['web_verification'] ?? false) !== true
        || ($result['private_settings_readable'] ?? false) !== true || ($result['database_connected'] ?? false) !== true
        || ($result['read_only_probe'] ?? false) !== true || ($result['production_mode'] ?? false) !== true
        || ($result['sapi'] ?? null) !== 'fpm-fcgi' || ($result['activation_performed'] ?? true) !== false
        || in_array(true, $result['outbound_flags_from_web_configuration'] ?? [true], true)) {
        throw new RuntimeException('Private settings PHP-FPM verification failed; runtime is unchanged.');
    }
} finally {
    if (!unlink($endpoint)) throw new RuntimeException('Temporary verifier cleanup failed; remove the expiring probe before continuing.');
}
if (file_exists($endpoint) || !hash_equals($liveConfigBefore, hash_file('sha256', $root . '/core/config.php'))) {
    throw new RuntimeException('Live configuration or verifier cleanup did not match.');
}
echo json_encode(['candidate_commit' => $manifest['commit'], 'web' => $result,
    'temporary_probe_removed' => true, 'live_config_unchanged' => true, 'activation_performed' => false],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
