<?php
/**
 * /api/financial_state.php — read & manage the Unified Financial State Cache.
 *
 * Phase 2 — Unified Financial State Cache. Read path for the CFO Dashboard
 * widgets, Phase 2 AI rule evaluation, period-close materialized views,
 * and the External Auditor view (P3).
 *
 *   GET /api/financial_state.php?scope_key=period_id&scope_value=42
 *     → 200 { scope: {...}, dirty: bool, metrics: { metric_key: row, ... } }
 *
 *   GET /api/financial_state.php?scope_key=period_id&scope_value=42&metric_key=revenue.posted
 *     → 200 { scope: {...}, metric_key, numeric_value, json_value, computed_at, ... }
 *     → 404 if metric not in cache and rebuild produced no value
 *
 *   POST { action: "rebuild", scope_key, scope_value }
 *     → 200 { ok: true, metrics_written, ms }
 *
 *   POST { action: "mark_dirty", scope_key, scope_value, reason? }
 *     → 200 { ok: true }
 *
 * Auth: standard session/JWT. No fine-grained RBAC at this layer — the
 * data is the same numbers the CFO Dashboard already exposes. Mutations
 * are limited to mark_dirty / rebuild (idempotent, no business-state
 * change).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/financial_state_cache.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$userId   = (int) ($ctx['user']['id'] ?? 0) ?: null;
$method   = api_method();

if ($method === 'GET') {
    $scopeKey   = (string) api_query('scope_key', '');
    $scopeValue = (string) api_query('scope_value', '');
    $metricKey  = api_query('metric_key', null);

    if ($scopeKey === '' || $scopeValue === '') {
        api_error('scope_key and scope_value are required', 422);
    }

    // Single-metric read.
    if ($metricKey !== null && $metricKey !== '') {
        $row = fscRead($tenantId, $scopeKey, $scopeValue, (string) $metricKey);
        if ($row === null) {
            api_error('metric not found in cache', 404, [
                'scope_key'   => $scopeKey,
                'scope_value' => $scopeValue,
                'metric_key'  => $metricKey,
            ]);
        }
        api_ok([
            'scope'       => ['scope_key' => $scopeKey, 'scope_value' => $scopeValue],
            'metric_key'  => $row['metric_key'],
            'numeric_value' => $row['numeric_value'],
            'json_value'    => $row['json_value'],
            'source_hash'   => $row['source_hash'],
            'computed_at'   => $row['computed_at'],
            'computed_ms'   => $row['computed_ms'] !== null ? (int) $row['computed_ms'] : null,
        ]);
    }

    // All-for-scope read. fscRead auto-rebuilds if dirty; we report the
    // pre-read dirty state so the caller can show "as of HH:MM" UX.
    $wasDirty = fscIsDirty($tenantId, $scopeKey, $scopeValue);
    $metrics  = fscRead($tenantId, $scopeKey, $scopeValue);

    api_ok([
        'scope'      => ['scope_key' => $scopeKey, 'scope_value' => $scopeValue],
        'was_dirty'  => $wasDirty,
        'dirty_now'  => fscIsDirty($tenantId, $scopeKey, $scopeValue),
        'count'      => is_array($metrics) ? count($metrics) : 0,
        'metrics'    => $metrics ?: new stdClass(),
    ]);
}

if ($method === 'POST') {
    $body   = api_json_body();
    $action = (string) ($body['action'] ?? '');
    $scopeKey   = (string) ($body['scope_key']   ?? '');
    $scopeValue = (string) ($body['scope_value'] ?? '');

    if ($scopeKey === '' || $scopeValue === '') {
        api_error('scope_key and scope_value are required', 422);
    }

    if ($action === 'mark_dirty') {
        $reason = $body['reason'] ?? 'manual';
        fscMarkDirty($tenantId, $scopeKey, $scopeValue, (string) $reason, $userId);
        api_ok(['ok' => true, 'marked' => true]);
    }

    if ($action === 'rebuild') {
        try {
            $result = fscRebuild($tenantId, $scopeKey, $scopeValue);
            api_ok(['ok' => true] + $result);
        } catch (\Throwable $e) {
            api_error('rebuild failed: ' . $e->getMessage(), 500);
        }
    }

    api_error('Unknown action. Use "mark_dirty" or "rebuild".', 422);
}

api_error('Method not allowed', 405);
