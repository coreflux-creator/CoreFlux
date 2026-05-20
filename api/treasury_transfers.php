<?php
/**
 * Treasury Transfers API (Sprint 7c, spec §15).
 *
 *   GET    /api/treasury_transfers.php?status=&kind=&from=&to=
 *   POST   /api/treasury_transfers.php                          create draft
 *   POST   /api/treasury_transfers.php?action=approve&id=N
 *   POST   /api/treasury_transfers.php?action=execute&id=N
 *
 * Transfer kind is detected from the source/destination bank-account
 * entities at create time:
 *   internal     — same entity      → emits `treasury.transfer.completed`
 *                                      (1 JE, 2 lines, both Cash)
 *   intercompany — different entities → emits `treasury.intercompany.transfer.completed`
 *                                       (engine renders 2 mirror JEs via 2 templates)
 *
 * For the MVP, intercompany is wired but the second JE is the responsibility
 * of a tenant-defined posting rule with a `journal_template` that posts
 * to the destination entity's books. Sprint 7e fleshes out the multi-JE
 * orchestration when modules fully migrate to the event layer.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) (api_query('action') ?? '');

if ($method === 'GET') {
    rbac_legacy_require($user, 'treasury.view_bank_balances');
    $where = ['tenant_id = :t']; $p = ['t' => $tid];
    foreach (['status' => 'status', 'kind' => 'transfer_kind'] as $q => $col) {
        $v = api_query($q);
        if ($v !== null && $v !== '') { $where[] = "{$col} = :{$q}"; $p[$q] = (string) $v; }
    }
    if ($f = api_query('from')) { $where[] = 'transfer_date >= :f'; $p['f'] = (string) $f; }
    if ($t = api_query('to'))   { $where[] = 'transfer_date <= :tt'; $p['tt'] = (string) $t; }
    $limit  = max(1, min(500, (int) (api_query('limit') ?? 100)));
    $offset = max(0, (int) (api_query('offset') ?? 0));

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, transfer_number, transfer_kind, source_bank_account_id, destination_bank_account_id,
                source_entity_id, destination_entity_id, amount, currency, transfer_date, memo,
                status, source_journal_entry_id, destination_journal_entry_id, executed_at
           FROM treasury_transfers
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC
          LIMIT ' . $limit . ' OFFSET ' . $offset
    );
    $stmt->execute($p);
    api_ok(['rows' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'limit' => $limit, 'offset' => $offset]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'treasury.create_transfer');
    $body = api_json_body();
    api_require_fields($body, ['source_bank_account_id', 'destination_bank_account_id', 'amount', 'transfer_date']);

    $pdo = getDB();
    // Resolve src + dest bank accounts for entity_id detection.
    $srcId = (int) $body['source_bank_account_id'];
    $dstId = (int) $body['destination_bank_account_id'];
    if ($srcId === $dstId) api_error('source and destination cannot be the same', 422);

    $banks = $pdo->prepare(
        'SELECT id, entity_id FROM accounting_bank_accounts WHERE tenant_id = :t AND id IN (:src, :dst)'
    );
    // PDO doesn't expand IN — use two separate queries for clarity.
    $oneStmt = $pdo->prepare('SELECT id, entity_id FROM accounting_bank_accounts WHERE tenant_id = :t AND id = :id');
    $oneStmt->execute(['t' => $tid, 'id' => $srcId]); $src = $oneStmt->fetch(\PDO::FETCH_ASSOC);
    $oneStmt->execute(['t' => $tid, 'id' => $dstId]); $dst = $oneStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$src || !$dst) api_error('Bank account not found', 404);
    $srcEntity = (int) ($src['entity_id'] ?? 0);
    $dstEntity = (int) ($dst['entity_id'] ?? 0);
    $kind = ($srcEntity && $dstEntity && $srcEntity !== $dstEntity) ? 'intercompany' : 'internal';

    $num = $body['transfer_number'] ?? sprintf('TXF-%d-%s', $tid, substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = $pdo->prepare(
        'INSERT INTO treasury_transfers
            (tenant_id, transfer_number, transfer_kind,
             source_bank_account_id, destination_bank_account_id,
             source_entity_id, destination_entity_id,
             amount, currency, transfer_date, memo, status, created_by_user_id)
         VALUES (:t, :n, :k, :sba, :dba, :se, :de, :amt, :cur, :td, :memo, "draft", :u)'
    );
    $stmt->execute([
        't' => $tid, 'n' => $num, 'k' => $kind,
        'sba' => $srcId, 'dba' => $dstId,
        'se' => $srcEntity ?: 0, 'de' => $dstEntity ?: $srcEntity ?: 0,
        'amt' => round((float) $body['amount'], 2),
        'cur' => (string) ($body['currency'] ?? 'USD'),
        'td' => (string) $body['transfer_date'],
        'memo' => $body['memo'] ?? null,
        'u' => $user['id'] ?? null,
    ]);
    api_ok([
        'id' => (int) $pdo->lastInsertId(),
        'transfer_number' => $num,
        'transfer_kind' => $kind,
        'status' => 'draft',
    ], 201);
}

$id = (int) (api_query('id') ?? 0);
if ($id <= 0) api_error('id required', 400);

$pdo = getDB();
$rowStmt = $pdo->prepare('SELECT * FROM treasury_transfers WHERE tenant_id = :t AND id = :id');
$rowStmt->execute(['t' => $tid, 'id' => $id]);
$xfer = $rowStmt->fetch(\PDO::FETCH_ASSOC);
if (!$xfer) api_error('Transfer not found', 404);

if ($method === 'POST' && $action === 'approve') {
    rbac_legacy_require($user, 'treasury.approve_transfer');
    if (!in_array($xfer['status'], ['draft', 'pending_approval'], true)) {
        api_error("Cannot approve from status {$xfer['status']}", 409);
    }
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare('UPDATE treasury_transfers SET status="approved", approved_by_user_id=:u, approved_at=NOW() WHERE id=:id')
        ->execute(['u' => $user['id'] ?? null, 'id' => $id]);
    api_ok(['id' => $id, 'status' => 'approved']);
}

if ($method === 'POST' && $action === 'execute') {
    rbac_legacy_require($user, 'treasury.execute_payment'); // execute_payment perm covers transfers too
    if (!in_array($xfer['status'], ['approved', 'scheduled'], true)) {
        api_error("Transfer must be approved before execute (current: {$xfer['status']})", 409);
    }

    $eventType = $xfer['transfer_kind'] === 'intercompany'
        ? 'treasury.intercompany.transfer.completed'
        : 'treasury.transfer.completed';

    // Hydrate src + dst bank GL accounts so templates can target them
    // without dereferencing through accounting_bank_accounts.
    $bankStmt = $pdo->prepare(
        'SELECT ba.id, ba.gl_account_code, aa.id AS gl_account_id
           FROM accounting_bank_accounts ba
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :t AND ba.id = :id LIMIT 1'
    );
    $bankStmt->execute(['t' => $tid, 'id' => (int) $xfer['source_bank_account_id']]);
    $srcBank = $bankStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    $bankStmt->execute(['t' => $tid, 'id' => (int) $xfer['destination_bank_account_id']]);
    $dstBank = $bankStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $event = [
        'entity_id'        => (int) $xfer['source_entity_id'],
        'event_type'       => $eventType,
        'source_module'    => 'treasury_transfers',
        'source_record_id' => 'txf:' . $xfer['id'],
        'event_date'       => (string) $xfer['transfer_date'],
        'payload' => [
            'transfer_id'                    => (int) $xfer['id'],
            'transfer_number'                => (string) $xfer['transfer_number'],
            'transfer_kind'                  => (string) $xfer['transfer_kind'],
            'amount'                         => (float) $xfer['amount'],
            'currency'                       => (string) $xfer['currency'],
            'source_bank_account_id'         => (int) $xfer['source_bank_account_id'],
            'destination_bank_account_id'    => (int) $xfer['destination_bank_account_id'],
            'source_bank_gl_account_id'      => isset($srcBank['gl_account_id']) ? (int) $srcBank['gl_account_id'] : null,
            'source_bank_gl_account_code'    => $srcBank['gl_account_code'] ?? null,
            'destination_bank_gl_account_id' => isset($dstBank['gl_account_id']) ? (int) $dstBank['gl_account_id'] : null,
            'destination_bank_gl_account_code' => $dstBank['gl_account_code'] ?? null,
            'source_entity_id'               => (int) $xfer['source_entity_id'],
            'destination_entity_id'          => (int) $xfer['destination_entity_id'],
            'memo'                           => $xfer['memo'],
        ],
    ];

    try {
        $r = accountingProcessEvent($tid, $event, $user['id'] ?? null);
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE treasury_transfers SET status="failed", failure_reason=:f WHERE id=:id')
            ->execute(['f' => $e->getMessage(), 'id' => $id]);
        api_error('Execute failed: ' . $e->getMessage(), 422);
    }

    if ($r['status'] !== 'posted') {
        $msg = $r['error'] ?? 'event not posted';
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE treasury_transfers SET status="failed", failure_reason=:f, accounting_event_id=:ev WHERE id=:id')
            ->execute(['f' => $msg, 'ev' => $r['event_id'] ?? null, 'id' => $id]);
        api_error('Execute failed: ' . $msg, 422);
    }

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE treasury_transfers
            SET status="executed", executed_by_user_id=:u, executed_at=NOW(),
                source_journal_entry_id=:je, accounting_event_id=:ev, failure_reason=NULL
          WHERE id=:id'
    )->execute([
        'u' => $user['id'] ?? null, 'je' => (int) $r['journal_entry_id'],
        'ev' => $r['event_id'] ?? null, 'id' => $id,
    ]);
    api_ok([
        'id' => $id, 'status' => 'executed',
        'transfer_kind' => (string) $xfer['transfer_kind'],
        'source_journal_entry_id' => (int) $r['journal_entry_id'],
        'event_id' => $r['event_id'] ?? null,
    ]);
}

api_error('Method not allowed', 405);
