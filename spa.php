<?php
/**
 * CoreFlux React SPA Entry Point
 * Serves the React Dashboard after authentication check
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/data.php';

initSession();

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.html");
    exit;
}

// Serve the React SPA
$indexFile = __DIR__ . '/app/index.html';

if (file_exists($indexFile)) {
    // Read and output the index.html
    echo file_get_contents($indexFile);
} else {
    // Fallback - show error
    http_response_code(500);
    echo "Dashboard not found. Please build the React app.";
}
