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
if (!in_array($actor, ['uploader', 'approver', 'reviewer'], true)) {
    http_response_code(400);
    return true;
}

require_once __DIR__ . '/../core/db.php';
$stmt = getDB()->prepare('SELECT id, name, email, role FROM users
    WHERE tenant_id = :t AND email = :email AND is_active = 1');
$stmt->execute(['t' => (int) getenv('SIM_TENANT_ID'), 'email' => 'ci-' . $actor . '@coreflux.test']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) throw new RuntimeException('Synthetic CI actor is missing');
session_start();
$userId = (int) $user['id'];
$_SESSION['user'] = $user;
$_SESSION['tenant_id'] = (int) getenv('SIM_TENANT_ID');
header('Content-Type: application/json');
echo json_encode(['actor' => $actor, 'user_id' => $userId]);
return true;
