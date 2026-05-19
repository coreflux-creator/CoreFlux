<?php
/**
 * /api/mercury_recipients.php — Recipient Vault REST surface (Slice 2).
 *
 *   GET     /api/mercury_recipients.php[?kind=vendor|funding_source]
 *           → list local recipients with bank_last4 + mercury_id
 *
 *   GET     /api/mercury_recipients.php?id=N
 *           → single row + bank_method + mercury_mappings
 *
 *   POST    /api/mercury_recipients.php
 *           body: { kind, name, email?, payment_method?, notes?,
 *                   bank: { routing_number, account_number, account_type?, nickname? } }
 *           → creates local recipient + primary bank method
 *
 *   PATCH   /api/mercury_recipients.php?id=N
 *           body: { name?, email?, payment_method?, status?, notes? }
 *
 *   DELETE  /api/mercury_recipients.php?id=N             → soft-revoke
 *
 *   POST    /api/mercury_recipients.php?action=push&id=N → push vendor to Mercury
 *
 *   POST    /api/mercury_recipients.php?action=set_funding_default
 *           body: { recipient_id, mercury_account_id }
 *
 *   GET     /api/mercury_recipients.php?action=funding_default
 *
 * RBAC: writes gated by `accounting.bank.manage`. Reads accept either
 * `accounting.bank.view` or `accounting.bank.manage`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/mercury_recipients.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$id     = (int) ($_GET['id'] ?? 0);

function mrAudit(string $event, array $meta, int $tenantId, ?int $userId): void
{
    try {
        $pdo = getDB();
        $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES (:t, :u, :e, :tid, :m, :ip, NOW())'
        )->execute([
            't'   => $tenantId,
            'u'   => $userId,
            'e'   => $event,
            'tid' => $meta['recipient_id'] ?? null,
            'm'   => json_encode($meta),
            'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) { /* best-effort */ }
}

$canView   = rbac_legacy_can($user, 'accounting.bank.view')
          || rbac_legacy_can($user, 'accounting.bank.manage');
$canManage = rbac_legacy_can($user, 'accounting.bank.manage');

// ----------------------------------------------------------------- GET funding_default
if ($method === 'GET' && $action === 'funding_default') {
    if (!$canView) api_error('Permission denied', 403);
    api_ok(['funding_default' => mercuryRecipientGetFundingDefault($tenantId)]);
}

// ----------------------------------------------------------------- GET single / list
if ($method === 'GET') {
    if (!$canView) api_error('Permission denied', 403);
    if ($id > 0) {
        $rec = mercuryRecipientGet($tenantId, $id);
        if (!$rec) api_error('Not found', 404);
        api_ok(['recipient' => $rec]);
    }
    $kind = (string) ($_GET['kind'] ?? '');
    $rows = mercuryRecipientList($tenantId, $kind !== '' ? $kind : null);
    api_ok(['rows' => $rows, 'count' => count($rows)]);
}

// All POST/PATCH/DELETE require manage perm.
if (!$canManage) api_error('Permission denied', 403);

// ----------------------------------------------------------------- POST create / actions
if ($method === 'POST' && $action === 'push') {
    if ($id <= 0) api_error('id required', 422);
    try {
        $res = mercuryRecipientPushToMercury($tenantId, $id, $user['id'] ?? null);
    } catch (MercuryApiException $e) {
        api_error($e->getMessage(), 502, ['http_status' => $e->httpStatus]);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    mrAudit('mercury.recipient.pushed', ['recipient_id' => $id, 'mercury_id' => $res['mercury_id']], $tenantId, $user['id'] ?? null);
    api_ok(['ok' => true] + $res);
}

if ($method === 'POST' && $action === 'set_funding_default') {
    $body = api_json_body();
    $recipientId = (int) ($body['recipient_id'] ?? 0);
    $mercuryAcctId = trim((string) ($body['mercury_account_id'] ?? ''));
    if ($recipientId <= 0)   api_error('recipient_id required', 422);
    if ($mercuryAcctId === '') api_error('mercury_account_id required', 422);
    try {
        mercuryRecipientSetFundingDefault($tenantId, $recipientId, $mercuryAcctId, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    mrAudit('mercury.funding_default.set', [
        'recipient_id' => $recipientId, 'mercury_account_id' => $mercuryAcctId,
    ], $tenantId, $user['id'] ?? null);
    api_ok(['ok' => true]);
}

if ($method === 'POST') {
    $body = api_json_body();
    try {
        $rec = mercuryRecipientCreate($tenantId, $body, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    mrAudit('mercury.recipient.created', [
        'recipient_id' => $rec['id'] ?? null, 'kind' => $body['kind'] ?? null,
    ], $tenantId, $user['id'] ?? null);
    api_ok(['ok' => true, 'recipient' => $rec], 201);
}

if ($method === 'PATCH') {
    if ($id <= 0) api_error('id required', 422);
    $body = api_json_body();
    try {
        mercuryRecipientUpdate($tenantId, $id, $body);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    mrAudit('mercury.recipient.updated', ['recipient_id' => $id, 'patch' => array_keys($body)], $tenantId, $user['id'] ?? null);
    api_ok(['ok' => true]);
}

if ($method === 'DELETE') {
    if ($id <= 0) api_error('id required', 422);
    mercuryRecipientRevoke($tenantId, $id);
    mrAudit('mercury.recipient.revoked', ['recipient_id' => $id], $tenantId, $user['id'] ?? null);
    api_ok(['ok' => true]);
}

api_error('Method/action not allowed', 405);
