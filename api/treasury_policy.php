<?php
/**
 * Treasury policy API.
 *
 *   GET  /api/treasury_policy.php
 *   POST /api/treasury_policy.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../modules/treasury/lib/policy.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tid = (int) $ctx['tenant_id'];
$method = api_method();
$actorUserId = isset($user['id']) ? (int) $user['id'] : null;

if ($method === 'GET') {
    rbac_legacy_require($user, 'treasury.payment.view');
    api_ok(['policy' => treasuryPolicyGet($tid)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'treasury.manage_forecast');
    $policy = treasuryPolicySave($tid, api_json_body(), $actorUserId);
    api_ok(['ok' => true, 'policy' => $policy]);
}

api_error('Method not allowed', 405);
