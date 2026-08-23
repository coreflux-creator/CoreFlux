<?php
/**
 * Browser-friendly QBO OAuth callback shim.
 *
 * Some managed browsers block top-level navigation directly into /api even
 * though Intuit requires a browser redirect. Keep the token exchange and
 * state validation in api/qbo.php; this public path only selects that action.
 */
declare(strict_types=1);

$_GET['action'] = 'oauth_callback';
require __DIR__ . '/api/qbo.php';

