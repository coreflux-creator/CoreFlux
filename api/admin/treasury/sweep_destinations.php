<?php
/**
 * /api/admin/treasury/sweep_destinations.php — CRUD for sweep destination
 * recipients (the high-yield / external account a Treasury Sweep Rule
 * deposits excess cash into).
 *
 * Wraps the existing mercury_recipients primitives and exposes the
 * exact flow the CLI helper (scripts/sweep_destination_setup.php)
 * collapses, so tenants can configure destinations from the browser
 * instead of shell access.
 *
 *   GET    /api/admin/treasury/sweep_destinations.php
 *            → { rows: [ {id, name, status, last4, account_type,
 *                         mercury_counterparty_id, mercury_push_status,
 *                         wired_rule_ids: [ ... ], created_at} ],
 *                rules: [ {id, name, source_account_id,
 *                          destination_recipient_id} ],
 *                live_mode: bool }
 *
 *   POST   /api/admin/treasury/sweep_destinations.php
 *            body: { name, routing_number, account_number,
 *                    account_type?, account_id?,
 *                    push_to_mercury?: bool (default true),
 *                    wire_rule_id?: int }
 *            → { row: {...}, push: {...}, wired_rule_id: int|null }
 *
 *   DELETE /api/admin/treasury/sweep_destinations.php?id=42
 *            → { ok: true }
 *            Unwires any rule that references this destination first,
 *            then soft-revokes the recipient row.
 *
 * RBAC: accounting.bank.manage (same gate as Mercury settings + sweep
 * rules — only users who can wire Mercury accounts up can author the
 * destinations sweeps land in).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mercury_recipients.php';
require_once __DIR__ . '/../../../core/treasury_sweep_engine.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

rbac_legacy_require($user, 'accounting.bank.manage');

$pdo = getDB();

// ----------------------------------------------------------------- GET
if ($method === 'GET') {
    try {
        $recipients = mercuryRecipientList($tid, 'sweep_destination');
    } catch (\Throwable $e) {
        $recipients = [];
    }

    // Eager-load rule wiring so the UI can show "used by N rules" badges
    // and the dropdown of available rules to wire on create.
    try {
        $stmt = $pdo->prepare(
            'SELECT id, name, source_account_id, destination_recipient_id, enabled
               FROM tenant_sweep_rules WHERE tenant_id = :t ORDER BY sort_order, id'
        );
        $stmt->execute(['t' => $tid]);
        $rules = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $rules = [];
    }

    // Attach `wired_rule_ids` per destination — denormalised for the UI.
    $byDest = [];
    foreach ($rules as $r) {
        $drId = (int) ($r['destination_recipient_id'] ?? 0);
        if ($drId > 0) $byDest[$drId][] = (int) $r['id'];
    }
    foreach ($recipients as &$rec) {
        $rec['wired_rule_ids'] = $byDest[(int) $rec['id']] ?? [];
    }
    unset($rec);

    api_ok([
        'rows'      => $recipients,
        'rules'     => $rules,
        'live_mode' => function_exists('treasurySweepLiveModeEnabled')
                       ? treasurySweepLiveModeEnabled() : false,
    ]);
}

// ----------------------------------------------------------------- POST
if ($method === 'POST') {
    $body = api_json_body();
    $name           = trim((string) ($body['name'] ?? ''));
    $routing        = (string) ($body['routing_number'] ?? '');
    $accountNumber  = (string) ($body['account_number'] ?? '');
    $accountType    = (string) ($body['account_type'] ?? 'checking');
    $accountIdRef   = trim((string) ($body['account_id'] ?? ''));
    $push           = array_key_exists('push_to_mercury', $body)
                        ? (bool) $body['push_to_mercury']
                        : true;
    $wireRuleId     = isset($body['wire_rule_id']) ? (int) $body['wire_rule_id'] : 0;

    if ($name === '')           api_error('name required', 422);
    // Defer routing/account validation to mercuryRecipientCreate which
    // already enforces 9-digit routing + 4–17 char account.

    // Optional pre-flight: if wiring to a rule, refuse to deposit back
    // into the rule's source account (sweep can't loop on itself).
    $ruleRow = null;
    if ($wireRuleId > 0) {
        $r = $pdo->prepare(
            'SELECT id, name, source_account_id FROM tenant_sweep_rules
              WHERE tenant_id = :t AND id = :id'
        );
        $r->execute(['t' => $tid, 'id' => $wireRuleId]);
        $ruleRow = $r->fetch(\PDO::FETCH_ASSOC);
        if (!$ruleRow) api_error("wire_rule_id {$wireRuleId} not found", 422);
        if ($accountIdRef !== '' && (string) $ruleRow['source_account_id'] === $accountIdRef) {
            api_error('destination account matches the rule source — a sweep cannot loop on itself', 422);
        }
    }

    try {
        $rec = mercuryRecipientCreate($tid, [
            'kind' => 'sweep_destination',
            'name' => $name,
            'notes' => $accountIdRef !== ''
                ? "Sweep destination for Mercury account {$accountIdRef}"
                : 'Sweep destination',
            'bank' => [
                'routing_number' => $routing,
                'account_number' => $accountNumber,
                'account_type'   => $accountType,
            ],
        ], $user['id'] ?? null);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        error_log('[sweep_destinations.php] create failed: ' . $e->getMessage());
        api_error('create failed: ' . $e->getMessage(), 500);
    }

    $recipientId = (int) $rec['id'];

    // Step 2 — best-effort Mercury counterparty push. Local row stays
    // valid even if push fails; operator can retry via PUT (todo) or
    // continue manually.
    $pushResult = null;
    if ($push) {
        try {
            $pushResult = mercuryRecipientPushToMercury($tid, $recipientId, $user['id'] ?? null);
        } catch (\Throwable $e) {
            $pushResult = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // Step 3 — optionally wire as destination on a rule.
    $wiredRuleId = null;
    if ($wireRuleId > 0) {
        try {
            $upd = $pdo->prepare(
                'UPDATE tenant_sweep_rules
                    SET destination_recipient_id = :r,
                        destination_account_id   = :acct
                  WHERE id = :id AND tenant_id = :t'
            );
            $upd->execute([
                'r'    => $recipientId,
                'acct' => $accountIdRef !== '' ? $accountIdRef : null,
                'id'   => $wireRuleId,
                't'    => $tid,
            ]);
            $wiredRuleId = $wireRuleId;
        } catch (\Throwable $e) {
            error_log('[sweep_destinations.php] rule wiring failed: ' . $e->getMessage());
        }
    }

    api_ok([
        'row'           => mercuryRecipientGet($tid, $recipientId),
        'push'          => $pushResult,
        'wired_rule_id' => $wiredRuleId,
    ]);
}

// --------------------------------------------------------------- DELETE
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);

    // Confirm ownership before any writes (defense-in-depth — list path
    // also filters but we never trust client side for tenant scope).
    $check = mercuryRecipientGet($tid, $id);
    if (!$check) api_error('Not found', 404);
    if (($check['kind'] ?? '') !== 'sweep_destination') {
        api_error('not a sweep destination', 422);
    }

    // Unwire from any rules first, otherwise rules would point at a
    // revoked recipient and the worker would error.
    try {
        $unwire = $pdo->prepare(
            'UPDATE tenant_sweep_rules
                SET destination_recipient_id = NULL,
                    destination_account_id   = NULL
              WHERE tenant_id = :t AND destination_recipient_id = :r'
        );
        $unwire->execute(['t' => $tid, 'r' => $id]);
    } catch (\Throwable $e) {
        error_log('[sweep_destinations.php] unwire failed: ' . $e->getMessage());
    }

    try {
        mercuryRecipientRevoke($tid, $id);
    } catch (\Throwable $e) {
        error_log('[sweep_destinations.php] revoke failed: ' . $e->getMessage());
        api_error('revoke failed: ' . $e->getMessage(), 500);
    }

    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
