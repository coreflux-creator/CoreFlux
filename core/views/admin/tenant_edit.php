<?php
/**
 * Legacy master-tenant edit form — RETIRED 2026-02.
 *
 * Replaced by `/admin/tenants` in the React SPA backed by `/api/tenants.php`.
 * Anyone landing here (bookmark, old admin link, etc.) is bounced to the SPA.
 */

declare(strict_types=1);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$path = $id > 0 ? '/spa.php#/admin/tenants?edit=' . $id : '/spa.php#/admin/tenants';
header('Location: ' . $path, true, 302);
exit;
