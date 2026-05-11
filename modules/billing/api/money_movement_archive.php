<?php
/**
 * Money Movement archive — list snapshots + read one historical snapshot.
 *
 *   GET /api/billing/money_movement_archive.php
 *     → last 12 weeks of snapshots for this tenant (newest first).
 *
 *   GET /api/billing/money_movement_archive.php?as_of=YYYY-MM-DD
 *     → full snapshot + rendered HTML email for that week (read-only).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/money_movement.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
RBAC::requirePermission($user, 'billing.view');

$asOf = isset($_GET['as_of']) ? (string) $_GET['as_of'] : null;

if ($asOf === null) {
    api_ok(['rows' => moneyMovementListSnapshots($tid, 12)]);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);
$snapshot = moneyMovementReadSnapshot($tid, $asOf);
if (!$snapshot) api_error("No snapshot for {$asOf}.", 404);

$prior = moneyMovementGetPriorSnapshot($tid, $asOf);
$wow   = moneyMovementWowDelta($snapshot, $prior);
$tenantName = 'CoreFlux';
try {
    $tn = getDB()->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
    $tn->execute(['id' => $tid]);
    $r = $tn->fetch(\PDO::FETCH_ASSOC);
    if ($r && !empty($r['name'])) $tenantName = (string) $r['name'];
} catch (\Throwable $_) { /* shrug */ }

api_ok([
    'snapshot' => $snapshot,
    'wow'      => $wow,
    'email'    => moneyMovementRenderEmail($snapshot, $tenantName, '', null, $wow),
]);
