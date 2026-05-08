<?php
/**
 * Accounting Events API (Sprint 7b, spec §38).
 *
 *   GET    /api/accounting/events?status=&event_type=&entity_id=&from=&to=&limit=
 *   POST   /api/accounting/events                     create + auto-process
 *           body: { entity_id, event_type, source_module, source_record_id,
 *                   event_date, payload }
 *           query: ?dry_run=1   → render only; nothing inserted/posted
 *   POST   /api/accounting/events/:id/post            re-process a failed event
 *   POST   /api/accounting/events/sandbox             rule-sandbox preview
 *           body: same as create   query: optional ?rule_id=NN to force a rule
 *
 * RBAC:
 *   - read:           accounting.coa.view
 *   - create + post:  accounting.create_entry  (events that map to JEs do
 *                     real posting through accountingPostJe; existing
 *                     posting perms still apply at the JE layer)
 *   - sandbox:        accounting.manage_posting_rules  (preview only,
 *                     never writes — but still gated since it leaks
 *                     account info)
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

// Parse trailing /:id/action — supports both /api/accounting/events/N/post
// and ?id=N&action=post. The PHP file may be reached at either path.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$pathId = null; $pathAction = null;
if (preg_match('#/events/(\d+)(?:/(\w+))?#', $path, $m)) {
    $pathId = (int) $m[1];
    $pathAction = $m[2] ?? null;
}
$action = (string) (api_query('action') ?? $pathAction ?? '');

// ──────────────────────────────────────────────────────────────────
// GET /api/accounting/events
// ──────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    RBAC::requirePermission($user, 'accounting.coa.view');

    $where  = ['tenant_id = :t'];
    $params = ['t' => $tid];
    if ($s = api_query('status')) {
        $where[] = 'status = :s'; $params['s'] = (string) $s;
    }
    if ($et = api_query('event_type')) {
        $where[] = 'event_type = :et'; $params['et'] = (string) $et;
    }
    if ($e = api_query('entity_id')) {
        $where[] = 'entity_id = :e'; $params['e'] = (int) $e;
    }
    if ($f = api_query('from')) {
        $where[] = 'event_date >= :f'; $params['f'] = (string) $f;
    }
    if ($t = api_query('to')) {
        $where[] = 'event_date <= :tt'; $params['tt'] = (string) $t;
    }
    $limit  = max(1, min(500, (int) (api_query('limit') ?? 100)));
    $offset = max(0, (int) (api_query('offset') ?? 0));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, entity_id, event_type, source_module, source_record_id,
                event_date, status, journal_entry_id, posting_rule_id,
                error_message, received_at, posted_at, payload
           FROM accounting_events
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC
          LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['payload'] = $r['payload'] ? json_decode((string) $r['payload'], true) : null;
    }
    api_ok(['rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ──────────────────────────────────────────────────────────────────
// POST /api/accounting/events/sandbox  (rule sandbox preview)
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && ($action === 'sandbox' || $pathAction === 'sandbox')) {
    RBAC::requirePermission($user, 'accounting.manage_posting_rules');
    $body = api_json_body();
    api_require_fields($body, ['entity_id', 'event_type', 'event_date', 'payload']);

    $event = [
        'entity_id'        => (int) $body['entity_id'],
        'event_type'       => (string) $body['event_type'],
        'source_module'    => (string) ($body['source_module']    ?? 'sandbox'),
        'source_record_id' => (string) ($body['source_record_id'] ?? 'sandbox-preview'),
        'event_date'       => (string) $body['event_date'],
        'payload'          => is_array($body['payload']) ? $body['payload'] : [],
    ];
    try {
        $r = accountingProcessEvent($tid, $event, $user['id'] ?? null, /* dryRun */ true);
        api_ok($r);
    } catch (\Throwable $e) {
        api_ok([
            'status' => 'failed',
            'error'  => $e->getMessage(),
        ]);
    }
}

// ──────────────────────────────────────────────────────────────────
// POST /api/accounting/events/:id/post   (re-process a failed event)
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST' && $pathId && $pathAction === 'post') {
    RBAC::requirePermission($user, 'accounting.create_entry');
    $pdo = getDB();
    $sel = $pdo->prepare('SELECT * FROM accounting_events WHERE tenant_id = :t AND id = :id');
    $sel->execute(['t' => $tid, 'id' => $pathId]);
    $row = $sel->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('Event not found', 404);
    if ($row['status'] === 'posted') {
        api_ok(['status' => 'posted', 'event_id' => $pathId, 'idempotent_replay' => true]);
    }
    if (!in_array($row['status'], ['received', 'failed', 'ignored'], true)) {
        api_error("cannot reprocess event in status {$row['status']}", 409);
    }
    // Delete the placeholder so accountingProcessEvent can re-insert. We
    // hold the same source_record_id keys so subledger_links remain stable.
    $pdo->prepare('DELETE FROM accounting_events WHERE id = :id')->execute(['id' => $pathId]);
    $event = [
        'entity_id'        => (int) $row['entity_id'],
        'event_type'       => (string) $row['event_type'],
        'source_module'    => (string) $row['source_module'],
        'source_record_id' => (string) $row['source_record_id'],
        'event_date'       => (string) $row['event_date'],
        'payload'          => $row['payload'] ? json_decode((string) $row['payload'], true) : [],
    ];
    api_ok(accountingProcessEvent($tid, $event, $user['id'] ?? null));
}

// ──────────────────────────────────────────────────────────────────
// POST /api/accounting/events  (create + auto-process)
// ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    RBAC::requirePermission($user, 'accounting.create_entry');
    $body = api_json_body();
    api_require_fields($body, ['entity_id', 'event_type', 'source_module', 'source_record_id', 'event_date', 'payload']);

    $event = [
        'entity_id'        => (int) $body['entity_id'],
        'event_type'       => (string) $body['event_type'],
        'source_module'    => (string) $body['source_module'],
        'source_record_id' => (string) $body['source_record_id'],
        'event_date'       => (string) $body['event_date'],
        'payload'          => is_array($body['payload']) ? $body['payload'] : [],
    ];
    $dryRun = !empty(api_query('dry_run'));
    api_ok(accountingProcessEvent($tid, $event, $user['id'] ?? null, $dryRun));
}

api_error('Method not allowed', 405);
