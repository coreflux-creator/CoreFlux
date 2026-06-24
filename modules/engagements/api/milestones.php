<?php
/**
 * Engagements API — milestone create/patch.
 *
 *   POST  /modules/engagements/api/milestones.php?engagement_id=N
 *         Body: { name, amount, target_date?, description? }
 *
 *   PATCH /modules/engagements/api/milestones.php?id=N
 *         Body: { name?, amount?, target_date?, description?, notes?,
 *                 sort_order?, status? }
 *         When status='invoiced', body MUST include invoice_id.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../lib/engagements.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
$uid = (int) ($ctx['user']['id'] ?? $ctx['user_id'] ?? 0);

$method = api_method();

if ($method === 'POST') {
    rbac_legacy_require_any($ctx['user'], ['master_admin', 'tenant_admin', 'admin', 'billing.manage', '*']);
    $egId = (int) ($_GET['engagement_id'] ?? 0);
    if ($egId <= 0) api_error('engagement_id required', 400);
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    try {
        $msId = engagementsMilestoneCreate($tid, $egId, $body, $uid);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 400);
    }
    api_ok(['id' => $msId, 'engagement' => engagementsGet($tid, $egId)]);
}

if ($method === 'PATCH') {
    rbac_legacy_require_any($ctx['user'], ['master_admin', 'tenant_admin', 'admin', 'billing.manage', '*']);
    $msId = (int) ($_GET['id'] ?? 0);
    if ($msId <= 0) api_error('id required', 400);
    $patch = json_decode((string) file_get_contents('php://input'), true) ?: [];

    // Special handling for the invoiced transition — attach the
    // billing_invoices.id rather than just flipping the status.
    if (($patch['status'] ?? null) === 'invoiced' && !empty($patch['invoice_id'])) {
        try {
            engagementsMilestoneAttachInvoice($tid, $msId, (int) $patch['invoice_id'], $uid);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 400);
        }
    } else {
        try {
            engagementsMilestoneUpdate($tid, $msId, $patch, $uid);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 400);
        }
    }

    // Re-fetch the parent engagement for fresh rollups.
    $st = getDB()->prepare('SELECT engagement_id FROM engagement_milestones WHERE tenant_id = :t AND id = :id LIMIT 1');
    $st->execute(['t' => $tid, 'id' => $msId]);
    $egId = (int) ($st->fetchColumn() ?: 0);

    api_ok(['engagement' => $egId ? engagementsGet($tid, $egId) : null]);
}

api_error('Method not allowed', 405);
