<?php
/** Local-only session setup for the MySQL-backed business API test. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server'
    || getenv('SIM_MODE') !== '1'
    || (int) getenv('SIM_TENANT_ID') <= 0
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    return true;
}

if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/__ci__/session') {
    return false;
}

$actor = (string) ($_GET['actor'] ?? '');
if (!in_array($actor, ['uploader', 'approver'], true)) {
    http_response_code(400);
    return true;
}

session_start();
$userId = $actor === 'uploader' ? 1 : 2;
$_SESSION['user'] = [
    'id' => $userId,
    'name' => 'CI ' . ucfirst($actor),
    'email' => 'ci-' . $actor . '@coreflux.test',
    'role' => 'master_admin',
    'global_role' => 'master_admin',
];
$_SESSION['tenant_id'] = (int) getenv('SIM_TENANT_ID');
header('Content-Type: application/json');
echo json_encode(['actor' => $actor, 'user_id' => $userId]);
return true;
