<?php
/** Reversible web-ingress rehearsal on the existing synthetic staging app only; no scheduler/DB writes. */
declare(strict_types=1);
require_once __DIR__ . '/full_core_maintenance.php';

const CORE_MAINTENANCE_STAGE_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CORE_MAINTENANCE_STAGE_DATABASE = 'muzqvdvqbx';
const CORE_MAINTENANCE_STAGE_ORIGIN = 'https://phpstack-1516771-6707602.cloudwaysapps.com';

function coreMaintenancePrivateWrite(string $path, string $bytes): void
{
    $stream = @fopen($path, 'x+b');
    if (!$stream) throw new RuntimeException('Private recovery file already exists or cannot be created.');
    try {
        if (!chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)) {
            throw new RuntimeException('Private recovery file could not be persisted.');
        }
    } finally { fclose($stream); }
}

function coreMaintenanceStageFile(string $relative): string
{
    if (!preg_match('~^(?:\.htaccess|(?:api/|modules/accounting/api/)?maintenance_[a-f0-9]{32}\.php)$~D', $relative)) {
        throw new RuntimeException('Unexpected staging maintenance path.');
    }
    $path = CORE_MAINTENANCE_STAGE_ROOT . '/' . $relative;
    if (realpath(dirname($path)) !== dirname($path) || is_link($path)) throw new RuntimeException('Staging path is linked or missing.');
    return $path;
}

/** Recheck the exact original bytes at the final replacement; do not overwrite a concurrent deploy. */
function coreMaintenanceStageReplace(string $path, string $expectedHash, string $bytes, int $mode): void
{
    if ($path !== coreMaintenanceStageFile('.htaccess')) throw new RuntimeException('Only the staging root guard may be replaced.');
    $current = file_get_contents($path);
    if ($current === false) throw new RuntimeException('Cannot read staging web configuration.');
    fullCoreMaintenanceDigest($current, $expectedHash);
    $temporary = tempnam(dirname($path), '.core-maintenance-');
    if ($temporary === false) throw new RuntimeException('Cannot prepare staging web configuration.');
    try {
        $stream = fopen($temporary, 'wb');
        try {
            if (!$stream || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream) || !fsync($stream)
                || !chmod($temporary, $mode)) throw new RuntimeException('Cannot persist staging web configuration.');
        } finally { if (is_resource($stream)) fclose($stream); }
        fullCoreMaintenanceDigest((string)file_get_contents(coreMaintenanceStageFile('.htaccess')), $expectedHash);
        if (!rename($temporary, $path)) throw new RuntimeException('Cannot replace staging web configuration.');
    } finally { if (is_file($temporary)) unlink($temporary); }
}

function coreMaintenanceStageHttp(string $path, string $method, string $token): array
{
    coreMaintenanceStageFile($path);
    $headers = [];
    $curl = curl_init(CORE_MAINTENANCE_STAGE_ORIGIN . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['X-Core-Maintenance-Probe: ' . $token, 'Cache-Control: no-cache'],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $headers[strtolower(trim($key))][] = trim($value); }
            return strlen($line);
        }]);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_errno($curl); curl_close($curl);
    if ($body === false || $error !== 0) throw new RuntimeException('Staging probe transport failed; configuration must be restored.');
    return [$status, $body, $headers];
}

/** Safe after ordinary exceptions and usable by an explicit later recovery after process loss. */
function coreMaintenanceStageRecover(array $receipt, string $private): void
{
    $operation = $receipt['operation'] ?? '';
    if (!is_string($operation) || !preg_match('/^[a-f0-9]{32}$/D', $operation)
        || ($receipt['root'] ?? '') !== CORE_MAINTENANCE_STAGE_ROOT
        || ($receipt['database'] ?? '') !== CORE_MAINTENANCE_STAGE_DATABASE
        || ($receipt['original_mode'] ?? null) !== 0644) throw new RuntimeException('Recovery receipt target does not match staging.');
    $expectedPaths = ['maintenance_' . $operation . '.php', 'api/maintenance_' . $operation . '.php',
        'modules/accounting/api/maintenance_' . $operation . '.php'];
    if (array_keys($receipt['probes'] ?? []) !== $expectedPaths) throw new RuntimeException('Recovery probe inventory does not match.');
    if (is_link($private . '/original.htaccess')) throw new RuntimeException('Recovery copy is linked.');
    $original = file_get_contents($private . '/original.htaccess');
    if ($original === false) throw new RuntimeException('Recovery copy is missing.');
    fullCoreMaintenanceDigest($original, $receipt['original_sha256']);
    $guardHash = hash('sha256', fullCoreMaintenanceWebRule($operation));
    if (!hash_equals($guardHash, $receipt['held_sha256'] ?? '')) throw new RuntimeException('Recovery guard does not match.');
    $path = coreMaintenanceStageFile('.htaccess');
    $current = file_get_contents($path);
    if ($current === false) throw new RuntimeException('Cannot read current staging guard.');
    if (!hash_equals($receipt['original_sha256'], hash('sha256', $current))) {
        $restored = fullCoreMaintenanceRestore($original, $receipt['original_sha256'], $guardHash, $current);
        coreMaintenanceStageReplace($path, $guardHash, $restored, $receipt['original_mode']);
    }
    foreach ($receipt['probes'] as $relative => $hash) {
        $probe = coreMaintenanceStageFile($relative);
        if (!file_exists($probe)) continue;
        if (!is_file($probe) || !hash_equals($hash, hash_file('sha256', $probe)) || !unlink($probe)) {
            throw new RuntimeException('Probe changed; manual recovery review required.');
        }
    }
}

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array($argv[1] ?? '', ['--rehearse-staging-web', '--recover-staging-web', '--inspect-staging-web'], true)
    || realpath(CORE_MAINTENANCE_STAGE_ROOT) !== CORE_MAINTENANCE_STAGE_ROOT
    || str_starts_with((string)realpath(__DIR__), CORE_MAINTENANCE_STAGE_ROOT . '/')) {
    throw new RuntimeException('Exact isolated staging CLI required; no production target is accepted.');
}
require_once CORE_MAINTENANCE_STAGE_ROOT . '/core/db.php';
require_once CORE_MAINTENANCE_STAGE_ROOT . '/core/mail_bootstrap.php';
$db = getDB(); $mail = cf_mail_bootstrap();
if (!$db || $db->query('SELECT DATABASE()')->fetchColumn() !== CORE_MAINTENANCE_STAGE_DATABASE
    || (int)$db->query('SELECT is_simulation FROM tenants WHERE id=999')->fetchColumn() !== 1
    || !defined('COREFLUX_STAGING') || !COREFLUX_STAGING
    || $mail->default_driver_name() !== 'log' || $mail->driver('resend') !== null) {
    throw new RuntimeException('Isolated staging database, simulation tenant and log-only mail are required.');
}
$lock = 'core-web-maintenance:' . CORE_MAINTENANCE_STAGE_DATABASE;
$claim = $db->prepare('SELECT GET_LOCK(?,0)'); $claim->execute([$lock]);
if ((int)$claim->fetchColumn() !== 1) throw new RuntimeException('Another staging maintenance rehearsal owns this app.');
$home = realpath((string)getenv('HOME'));
if (!$home || $home === '/' || str_starts_with($home, CORE_MAINTENANCE_STAGE_ROOT) || !is_writable($home)) {
    throw new RuntimeException('Private SSH home is required.');
}
$parent = $home . '/.coreflux-integration-backups/' . CORE_MAINTENANCE_STAGE_DATABASE;
if (realpath($parent) !== $parent || is_link($parent) || !is_writable($parent)) throw new RuntimeException('Existing private staging backup directory required.');
if ($argv[1] === '--inspect-staging-web') {
    $directories = glob($parent . '/web-maintenance-*', GLOB_ONLYDIR);
    usort($directories, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    $inspections = [];
    foreach (array_slice($directories, 0, 5) as $private) {
        if (realpath($private) !== $private || is_link($private) || is_link($private . '/receipt.json') || !is_file($private . '/receipt.json')) continue;
        $receipt = json_decode((string)file_get_contents($private . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($receipt['root'] ?? '') !== CORE_MAINTENANCE_STAGE_ROOT || ($receipt['database'] ?? '') !== CORE_MAINTENANCE_STAGE_DATABASE) continue;
        $currentHash = hash_file('sha256',coreMaintenanceStageFile('.htaccess')); $probes = [];
        foreach ($receipt['probes'] ?? [] as $relative => $hash) {
            $path = coreMaintenanceStageFile($relative);
            [$status, $body, $headers] = coreMaintenanceStageHttp($relative, 'GET', '');
            $probes[] = ['path' => $relative, 'file_exists' => file_exists($path), 'http_status' => $status,
                'body_bytes' => strlen($body), 'body_sha256' => hash('sha256',$body),
                'returns_probe_operation' => (json_decode($body,true)['operation'] ?? null) === ($receipt['operation'] ?? ''),
                'redirect_path' => isset($headers['location'][0]) ? parse_url($headers['location'][0],PHP_URL_PATH) : null];
        }
        $inspections[] = ['operation' => $receipt['operation'], 'original_configuration_restored' => $currentHash === $receipt['original_sha256'],
            'guard_still_installed' => $currentHash === $receipt['held_sha256'], 'probes' => $probes];
    }
    echo json_encode(['status' => 'read-only-staging-maintenance-inspection', 'operations' => $inspections], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
$operation = ($argv[1] === '--recover-staging-web') ? ($argv[2] ?? '') : bin2hex(random_bytes(16));
if (!preg_match('/^[a-f0-9]{32}$/D', $operation)) throw new RuntimeException('Exact recovery operation identifier required.');
$private = $parent . '/web-maintenance-' . $operation;
if ($argv[1] === '--recover-staging-web') {
    if (realpath($private) !== $private || is_link($private) || is_link($private . '/receipt.json')) throw new RuntimeException('Private recovery receipt required.');
    $receipt = json_decode((string)file_get_contents($private . '/receipt.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($receipt['operation'] ?? '') !== $operation) throw new RuntimeException('Recovery operation does not match.');
    coreMaintenanceStageRecover($receipt, $private);
    echo json_encode(['status' => 'staging_web_restored', 'operation' => $operation, 'production_changed' => false]), "\n";
    exit(0);
}
if (!mkdir($private, 0700)) throw new RuntimeException('Cannot create new recovery receipt directory.');
$path = coreMaintenanceStageFile('.htaccess'); $original = file_get_contents($path);
if ($original === false || (fileperms($path) & 0777) !== 0644 || str_contains($original, '# CoreFlux maintenance ')) {
    throw new RuntimeException('Unexpected staging web configuration or unresolved maintenance.');
}
$token = bin2hex(random_bytes(32));
$probeSource = '<?php if (!hash_equals(' . var_export(hash('sha256', $token), true)
    . ',hash("sha256",$_SERVER["HTTP_X_CORE_MAINTENANCE_PROBE"]??""))) { http_response_code(404); exit; } '
    . 'header("Content-Type: application/json"); header("Cache-Control: no-store"); echo json_encode(["operation"=>'
    . var_export($operation, true) . ',"method"=>$_SERVER["REQUEST_METHOD"]]);';
$probePaths = ['maintenance_' . $operation . '.php', 'api/maintenance_' . $operation . '.php',
    'modules/accounting/api/maintenance_' . $operation . '.php'];
foreach ($probePaths as $relative) {
    if (file_exists(coreMaintenanceStageFile($relative))) throw new RuntimeException('Synthetic probe path is already occupied.');
}
$guard = fullCoreMaintenanceWebRule($operation);
$receipt = ['operation' => $operation, 'root' => CORE_MAINTENANCE_STAGE_ROOT, 'database' => CORE_MAINTENANCE_STAGE_DATABASE,
    'original_sha256' => hash('sha256', $original), 'original_mode' => 0644, 'held_sha256' => hash('sha256', $guard),
    'probes' => array_fill_keys($probePaths, hash('sha256', $probeSource))];
coreMaintenancePrivateWrite($private . '/original.htaccess', $original);
coreMaintenancePrivateWrite($private . '/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$cleanupNeeded = true;
register_shutdown_function(static function () use (&$cleanupNeeded, $receipt, $private): void {
    if (!$cleanupNeeded) return;
    try { coreMaintenanceStageRecover($receipt, $private); }
    catch (Throwable $error) { error_log('Staging web recovery required for operation ' . $receipt['operation']); }
});
$normal = static function (string $relative, string $method) use ($token, $operation): void {
    [$status, $body] = coreMaintenanceStageHttp($relative, $method, $token);
    $value = json_decode($body, true);
    if ($status !== 200 || ($value['operation'] ?? '') !== $operation || ($value['method'] ?? '') !== $method) {
        throw new RuntimeException('Synthetic staging handler did not return its expected response.');
    }
};
try {
    foreach ($probePaths as $relative) {
        $probe = coreMaintenanceStageFile($relative); coreMaintenancePrivateWrite($probe, $probeSource);
        if (!chmod($probe, 0644)) throw new RuntimeException('Cannot expose synthetic staging probe.');
        [$denied] = coreMaintenanceStageHttp($relative, 'GET', '');
        if ($denied !== 404) throw new RuntimeException('Probe access guard failed.');
        foreach (['GET', 'POST'] as $method) $normal($relative, $method);
    }
    coreMaintenanceStageReplace($path, $receipt['original_sha256'], $guard, 0644);
    $blocked = 0;
    foreach ($probePaths as $relative) foreach (['GET', 'POST'] as $method) {
        fullCoreMaintenanceDigest((string)file_get_contents(coreMaintenanceStageFile('.htaccess')), $receipt['held_sha256']);
        [$status, $body, $headers] = coreMaintenanceStageHttp($relative, $method, $token);
        if ($status !== 503 || !str_contains($body, 'CoreFlux is temporarily unavailable for maintenance.')
            || !in_array('300', $headers['retry-after'] ?? [], true)
            || !in_array('no-store', $headers['cache-control'] ?? [], true)) {
            throw new RuntimeException('Hosted ingress guard did not block the synthetic request.');
        }
        $blocked++;
    }
    coreMaintenanceStageReplace($path, $receipt['held_sha256'], $original, 0644);
    foreach ($probePaths as $relative) foreach (['GET', 'POST'] as $method) $normal($relative, $method);
    coreMaintenanceStageRecover($receipt, $private); $cleanupNeeded = false;
    foreach ($probePaths as $relative) {
        [$status] = coreMaintenanceStageHttp($relative, 'GET', $token);
        if ($status !== 404) throw new RuntimeException('Removed probe status mismatch at ' . $relative . ': HTTP ' . $status);
    }
    $result = ['status' => 'staging_web_maintenance_passed', 'operation' => $operation, 'blocked_requests' => $blocked,
        'restored_sha256' => hash_file('sha256', $path), 'probes_removed' => true, 'production_changed' => false,
        'scheduler_changed' => false, 'business_writers_drained' => false];
    coreMaintenancePrivateWrite($private . '/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} finally {
    if ($cleanupNeeded) { coreMaintenanceStageRecover($receipt, $private); $cleanupNeeded = false; }
    $release = $db->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock]);
}
