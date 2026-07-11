<?php
/**
 * Legacy login route compatibility shim.
 *
 * Older links point at /auth/login.php, but the maintained handler is the
 * root /login.php endpoint used by the SPA login screen. Keep this file as a
 * forwarding wrapper so old URLs do not hit removed db_connection/functions
 * includes and fail before authentication starts.
 */
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $_POST['username'] = $_POST['username'] ?? $_POST['email'] ?? '';
    require __DIR__ . '/../login.php';
    exit;
}

header('Location: /login.html');
exit;
