<?php
/**
 * AP API — Pay-When-Paid (PWP) link management.
 *
 *   GET   /api/ap/pwp?action=preview&ar_invoice_id=N
 *         → list AP bills that would be auto-linked to this AR invoice
 *
 *   POST  /api/ap/pwp?action=auto_link
 *         body: {ar_invoice_id}
 *         → run apPwpAutoLinkForArInvoice() and return the linked bills
 *
 *   POST  /api/ap/pwp?action=link
 *         body: {bill_id, ar_invoice_id, payment_terms?}
 *         → manual override (sets terms to 'PWP' or 'PWP_NET<N>')
 *
 *   POST  /api/ap/pwp?action=unlink
 *         body: {bill_id}
 *         → clear linked_ar_invoice_id and reset pwp_status to 'not_pwp'
 *
 *   POST  /api/ap/pwp?action=release_for_invoice
 *         body: {ar_invoice_id}
 *         → manual release fallback (e.g. operator marks AR paid out-of-band)
 *
 * Permissions: link/unlink requires ap.bill.create. release_for_invoice
 * requires ap.bill.approve since it transitions bills to 'approved'.
 *
 * SPEC: /app/modules/ap/lib/pwp.php
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/pwp.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'preview') {
    RBAC::requirePermission($user, 'ap.bill.view');
    $arId = (int) ($_GET['ar_invoice_id'] ?? 0);
    if ($arId <= 0) api_error('ar_invoice_id required', 400);

    // Same query as auto-link but read-only (no UPDATE).
    $pdo = getDB();
    $inv = scopedFind('SELECT id, period_start, period_end FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $arId]);
    if (!$inv) api_error('AR invoice not found', 404);

    $pq = $pdo->prepare(
        'SELECT DISTINCT placement_id FROM billing_invoice_lines
          WHERE invoice_id = :i AND placement_id IS NOT NULL'
    );
    $pq->execute(['i' => $arId]);
    $placementIds = array_map('intval', array_column($pq->fetchAll(\PDO::FETCH_ASSOC), 'placement_id'));

    if (empty($placementIds) || empty($inv['period_start']) || empty($inv['period_end'])) {
        api_ok(['candidates' => [], 'reason' => 'AR invoice has no placement+period data to match on']);
    }

    $placeholders = [];
    $params = ['t' => $tid, 'ps' => $inv['period_start'], 'pe' => $inv['period_end']];
    foreach ($placementIds as $i => $pid) {
        $k = 'p' . $i;
        $placeholders[] = ':' . $k;
        $params[$k] = $pid;
    }
    $sql = 'SELECT DISTINCT b.id, b.vendor_name, b.amount_due, b.status, b.payment_terms,
                            b.linked_ar_invoice_id, b.pwp_status,
                            v.default_pwp
              FROM ap_bills b
              JOIN ap_bill_lines bl ON bl.bill_id = b.id
              LEFT JOIN ap_vendors_index v ON v.tenant_id = b.tenant_id AND v.vendor_name = b.vendor_name
             WHERE b.tenant_id = :t
               AND b.status NOT IN ("paid","void")
               AND b.period_start = :ps AND b.period_end = :pe
               AND bl.placement_id IN (' . implode(',', $placeholders) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    $cands = [];
    foreach ($rows as $r) {
        $parsed = apPwpParseTerms($r['payment_terms']);
        $isPwp = $parsed['is_pwp'] || (int) ($r['default_pwp'] ?? 0) === 1;
        $cands[] = [
            'bill_id'     => (int) $r['id'],
            'vendor_name' => $r['vendor_name'],
            'amount_due'  => (float) $r['amount_due'],
            'status'      => $r['status'],
            'payment_terms' => $r['payment_terms'],
            'pwp_status'  => $r['pwp_status'],
            'is_pwp'      => $isPwp,
            'already_linked_to_this' => ((int) ($r['linked_ar_invoice_id'] ?? 0)) === $arId,
        ];
    }
    api_ok(['candidates' => $cands]);
}

if ($method === 'POST' && $action === 'auto_link') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['ar_invoice_id']);
    $arId = (int) $body['ar_invoice_id'];
    try {
        $res = apPwpAutoLinkForArInvoice($tid, $arId, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

if ($method === 'POST' && $action === 'link') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['bill_id', 'ar_invoice_id']);
    try {
        $res = apPwpSetLink(
            $tid,
            (int) $body['bill_id'],
            (int) $body['ar_invoice_id'],
            isset($body['payment_terms']) ? (string) $body['payment_terms'] : null,
            $user['id'] ?? null
        );
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

if ($method === 'POST' && $action === 'unlink') {
    RBAC::requirePermission($user, 'ap.bill.create');
    $body = api_json_body();
    api_require_fields($body, ['bill_id']);
    try {
        $res = apPwpClearLink($tid, (int) $body['bill_id'], $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

if ($method === 'POST' && $action === 'release_for_invoice') {
    RBAC::requirePermission($user, 'ap.bill.approve');
    $body = api_json_body();
    api_require_fields($body, ['ar_invoice_id']);
    try {
        $res = apPwpReleaseForArInvoice($tid, (int) $body['ar_invoice_id'], $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok($res);
}

api_error('Method or action not allowed', 405);
