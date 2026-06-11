<?php
/**
 * People Access Reviews API.
 *
 * Canonical route: /api/v1/people/access-reviews
 * Legacy-compatible query actions are accepted during the migration window.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/access_reviews.php';

$ctx = api_require_auth();
$user = $ctx['user'] ?? [];
$tenantId = (int) ($ctx['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
$actorUserId = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) (api_query('action') ?? '');

if ($method === 'GET') {
    rbac_legacy_require($user, 'people.access_reviews.view');
    if ($action === 'items') {
        $campaignId = (int) (api_query('campaign_id') ?? api_query('id') ?? 0);
        if ($campaignId <= 0) api_error('campaign_id is required', 422);
        api_ok(['items' => accessReviewListItems($tenantId, $campaignId, $_GET)]);
    }
    if ($action === 'campaign' || (int) (api_query('id') ?? 0) > 0) {
        $campaignId = (int) (api_query('id') ?? 0);
        if ($campaignId <= 0) api_error('id is required', 422);
        $campaign = accessReviewGetCampaign($tenantId, $campaignId);
        if (!$campaign) api_error('Campaign not found', 404);
        api_ok([
            'campaign' => $campaign,
            'items' => accessReviewListItems($tenantId, $campaignId, $_GET),
        ]);
    }
    api_ok(['campaigns' => accessReviewListCampaigns($tenantId, $_GET)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'people.access_reviews.manage');
    $body = api_json_body();
    if ($action === 'create' || $action === '') {
        $campaign = accessReviewCreateCampaign(
            $tenantId,
            (string) ($body['name'] ?? ''),
            [
                'campaign_key' => $body['campaign_key'] ?? null,
                'description' => $body['description'] ?? null,
                'due_at' => $body['due_at'] ?? null,
                'scope' => is_array($body['scope'] ?? null) ? $body['scope'] : [],
            ],
            $actorUserId
        );
        api_ok(['campaign' => $campaign], 201);
    }
    if ($action === 'open') {
        $campaignId = (int) ($body['id'] ?? api_query('id') ?? 0);
        if ($campaignId <= 0) api_error('id is required', 422);
        api_ok(['campaign' => accessReviewOpenCampaign($tenantId, $campaignId, $actorUserId)]);
    }
    if ($action === 'snapshot') {
        $campaignId = (int) ($body['id'] ?? api_query('id') ?? 0);
        if ($campaignId <= 0) api_error('id is required', 422);
        api_ok(['items_snapshot' => accessReviewSnapshotCampaign($tenantId, $campaignId, $actorUserId)]);
    }
    if ($action === 'decision') {
        $itemId = (int) ($body['item_id'] ?? 0);
        if ($itemId <= 0) api_error('item_id is required', 422);
        api_ok(['item' => accessReviewRecordDecision(
            $tenantId,
            $itemId,
            (string) ($body['decision'] ?? ''),
            $actorUserId,
            isset($body['note']) ? (string) $body['note'] : null
        )]);
    }
    if ($action === 'complete') {
        $campaignId = (int) ($body['id'] ?? api_query('id') ?? 0);
        if ($campaignId <= 0) api_error('id is required', 422);
        api_ok(['campaign' => accessReviewCompleteCampaign($tenantId, $campaignId, $actorUserId)]);
    }
}

api_error('Method/action not supported', 405);
