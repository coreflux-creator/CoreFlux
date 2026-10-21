<?php
/** Inert template. A guarded CLI renders an expiring, credential-free verification endpoint. */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
header('Cache-Control: no-store');
header('Content-Type: application/json');
$spec = json_decode((string) base64_decode('__COREFLUX_SETTINGS_PROBE_SPEC__', true), true);
$host = strtolower(preg_replace('/:[0-9]+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
if (!is_array($spec) || PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || $host !== ($spec['host'] ?? null) || time() >= ($spec['expires'] ?? 0)
    || !hash_equals((string) ($spec['token_sha256'] ?? ''), hash('sha256', (string) ($_SERVER['HTTP_X_COREFLUX_SETTINGS_PROBE'] ?? '')))) {
    http_response_code(404); exit;
}
try {
    $directory = $spec['directory'];
    if (is_link($directory) || str_replace('\\', '/', (string) realpath($directory)) !== $directory
        || !is_readable($directory . '/settings.php') || !is_readable($directory . '/prepared.json')) {
        throw new RuntimeException('Private preparation unavailable');
    }
    $receipt = json_decode((string) file_get_contents($directory . '/prepared.json'), true, 32, JSON_THROW_ON_ERROR);
    if (($receipt['candidate_commit'] ?? null) !== $spec['commit'] || ($receipt['activation_performed'] ?? null) !== false
        || !hash_equals($spec['config_sha256'], hash_file('sha256', $directory . '/candidate/core/config.php'))
        || !hash_equals($spec['db_sha256'], hash_file('sha256', $directory . '/candidate/core/db.php'))
        || !hash_equals(hash_file('sha256', $directory . '/db.local.php.bridge'), hash_file('sha256', $directory . '/candidate/core/db.local.php'))
        || !in_array(getenv('COREFLUX_ENV'), [false, '', 'production'], true)
        || getenv('COREFLUX_DISABLE_DATABASE') === '1'
        || !in_array(getenv('COREFLUX_DB_CONFIG_PATH'), [false, '', $directory . '/settings.php'], true)) {
        throw new RuntimeException('Candidate or web environment changed');
    }
    require $directory . '/candidate/core/db.php';
    $db = getDB();
    if (!$db || DB_NAME !== $spec['database'] || $db->query('SELECT DATABASE()')->fetchColumn() !== $spec['database']
        || COREFLUX_STAGING || APP_URL !== 'https://www.corefluxapp.com') {
        throw new RuntimeException('Web bootstrap mismatch');
    }
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $readOnly = (int) $db->query('SELECT 1')->fetchColumn() === 1;
    $db->rollBack();
    if (!$readOnly) throw new RuntimeException('Read-only verification failed');
    $flags = [];
    foreach (['COREFLUX_QUANTA_METADATA_DELIVERY', 'COREFLUX_PUREPAY_CORE_LINK_DELIVERY',
        'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY', 'COREFLUX_PUREPAY_PAYROLL_AUTHORITY'] as $flag) {
        $flags[$flag] = in_array($flag, ['COREFLUX_QUANTA_METADATA_DELIVERY', 'COREFLUX_PUREPAY_CORE_LINK_DELIVERY'], true)
            && defined($flag) ? constant($flag) === true : getenv($flag) === '1';
    }
    echo json_encode(['web_verification' => true, 'private_settings_readable' => true,
        'database_connected' => true, 'read_only_probe' => true, 'production_mode' => true,
        'sapi' => PHP_SAPI, 'outbound_flags_from_web_configuration' => $flags, 'activation_performed' => false]);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['error' => 'Candidate web connection verification failed.']);
}
