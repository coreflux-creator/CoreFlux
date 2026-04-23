<?php
/**
 * Core AI Suggestions endpoint
 *
 * POST body (from <AISuggestion /> on accept/reject):
 *   {
 *     action: 'approve' | 'reject',
 *     interaction_id: 123,
 *     feature_key: 'payroll.pay_period_summary',
 *     subject_type?: 'paystub',
 *     subject_id?: 42,
 *     draft_content: '...',           // AI-generated text
 *     final_content?: '...'           // human-edited text (when approving)
 *   }
 *
 * Returns: { id: <ai_suggestions.id>, status: 'approved'|'rejected' }
 */

require_once __DIR__ . '/../../core/api_bootstrap.php';

$ctx = api_require_auth();

if (api_method() !== 'POST') {
    api_error('Method not allowed', 405);
}

$body = api_json_body();
api_require_fields($body, ['action', 'feature_key', 'draft_content']);

$action = $body['action'];
if (!in_array($action, ['approve', 'reject'], true)) {
    api_error('Invalid action', 422);
}

$module = explode('.', $body['feature_key'])[0] ?: 'unknown';

$id = scopedInsert('ai_suggestions', [
    'user_id'        => $ctx['user']['id'] ?? null,
    'interaction_id' => $body['interaction_id']  ?? null,
    'module'         => $module,
    'feature_key'    => $body['feature_key'],
    'subject_type'   => $body['subject_type']    ?? null,
    'subject_id'     => $body['subject_id']      ?? null,
    'draft_content'  => $body['draft_content'],
    'final_content'  => $action === 'approve' ? ($body['final_content'] ?? $body['draft_content']) : null,
    'status'         => $action === 'approve' ? 'approved' : 'rejected',
    'reviewed_by'    => $ctx['user']['id'] ?? null,
    'reviewed_at'    => date('Y-m-d H:i:s'),
    'review_notes'   => $body['review_notes'] ?? null,
]);

api_ok(['id' => $id, 'status' => $action === 'approve' ? 'approved' : 'rejected'], 201);
