<?php
/**
 * Time Settlement API
 *
 *   GET  /api/time/settlement?target=billing|ap|payroll
 *        Returns approved + un-extracted day-level entries grouped by
 *        placement × work_date. Optional filters: placement_id, person_id,
 *        from, to. If `cycle_window=1`, applies the placement's cycle
 *        suggestion to bound the result to the current cycle window.
 *
 *   POST /api/time/settlement?action=extract
 *        Body: { entry_ids: [...], target: '...', target_ref: <int> }
 *        Atomically marks every entry as extracted to the target+ref.
 *        Permission: time.settlement.extract.<target>
 *
 *   POST /api/time/settlement?action=unextract
 *        Body: { entry_ids: [...], target: '...', reason: '...' }
 *        Reverses an extract for corrections.
 *        Permission: time.settlement.unextract.<target>
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/settlement.php';

$ctx  = api_require_auth();
$user = $ctx['user'];

$method = api_method();
$action = $_GET['action'] ?? '';

// ----------------------------------------------------------- GET (list)
if ($method === 'GET') {
    $target = (string) ($_GET['target'] ?? '');
    if (!in_array($target, TIME_SETTLEMENT_TARGETS, true)) {
        api_error('target must be one of: billing|ap|payroll', 422);
    }
    RBAC::requirePermission($user, "time.settlement.view.$target");

    $filters = array_filter([
        'placement_id' => isset($_GET['placement_id']) ? (int) $_GET['placement_id'] : null,
        'person_id'    => isset($_GET['person_id'])    ? (int) $_GET['person_id']    : null,
        'from'         => $_GET['from'] ?? null,
        'to'           => $_GET['to']   ?? null,
    ], fn ($v) => $v !== null && $v !== '' && $v !== 0);

    $rows = timeSettlementReady($target, $filters);

    // Group by (placement_id, work_date) — each day is its own block.
    $blocks = [];
    foreach ($rows as $r) {
        $key = $r['placement_id'] . '|' . $r['work_date'];
        if (!isset($blocks[$key])) {
            $cycle = $target === 'billing'
                ? ($r['client_bill_cycle'] ?? 'monthly')
                : ($target === 'ap'
                    ? ($r['vendor_pay_cycle'] ?? 'biweekly')
                    : 'biweekly');
            $anchor = $target === 'billing'
                ? ($r['client_bill_cycle_anchor'] ?? null)
                : ($target === 'ap'
                    ? ($r['vendor_pay_cycle_anchor'] ?? null)
                    : null);
            $blocks[$key] = [
                'placement_id'   => (int) $r['placement_id'],
                'work_date'      => $r['work_date'],
                'cycle_default'  => $cycle,
                'cycle_window'   => timeSettlementCycleSuggestion($cycle, $anchor, $r['work_date']),
                'entries'        => [],
                'total_hours'    => 0.0,
            ];
        }
        $blocks[$key]['entries'][] = [
            'id'          => (int) $r['id'],
            'category'    => $r['category'],
            'hours'       => (float) $r['hours'],
            'description' => $r['description'],
            'person_id'   => (int) $r['person_id'],
        ];
        $blocks[$key]['total_hours'] += (float) $r['hours'];
    }

    api_ok([
        'target' => $target,
        'count'  => count($rows),
        'blocks' => array_values($blocks),
    ]);
}

// ----------------------------------------------------------- POST (mutations)
if ($method !== 'POST') api_error('Method not allowed', 405);

$body   = api_json_body();
$target = (string) ($body['target'] ?? '');
if (!in_array($target, TIME_SETTLEMENT_TARGETS, true)) {
    api_error('target must be one of: billing|ap|payroll', 422);
}

if ($action === 'extract') {
    RBAC::requirePermission($user, "time.settlement.extract.$target");
    $ids       = $body['entry_ids'] ?? [];
    $targetRef = (int) ($body['target_ref'] ?? 0);
    if (!is_array($ids) || !$ids) api_error('entry_ids[] required', 422);
    if ($targetRef <= 0)          api_error('target_ref required (positive int)', 422);

    try {
        $res = timeSettlementExtract($ids, $target, $targetRef, (int) ($user['id'] ?? 0));
    } catch (TimeSettlementException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

if ($action === 'unextract') {
    RBAC::requirePermission($user, "time.settlement.unextract.$target");
    $ids    = $body['entry_ids'] ?? [];
    $reason = trim((string) ($body['reason'] ?? ''));
    if (!is_array($ids) || !$ids) api_error('entry_ids[] required', 422);
    if ($reason === '')           api_error('reason required for un-extract', 422);

    try {
        $res = timeSettlementUnExtract($ids, $target, $reason, (int) ($user['id'] ?? 0));
    } catch (TimeSettlementException $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

api_error('Unknown action — use ?action=extract|unextract', 400);
