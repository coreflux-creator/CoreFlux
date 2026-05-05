<?php
/**
 * AP — Vendor Portal API.
 *
 * Three endpoints exposed at /api/ap/vendor_portal.php:
 *
 *   POST ?action=invite           (admin-only)  body { vendor_id, email? }
 *        Generates a 30-byte URL-safe token, stores SHA-256(token), returns
 *        the magic link. (Email send is hooked via core/mailer if available.)
 *
 *   GET  ?action=redeem&token=X
 *        Single-use redemption. Validates token, opens a vendor session,
 *        sets cf_vp_sid HttpOnly cookie, redirects (or returns JSON in fetch
 *        contexts) to /vendor/portal.
 *
 *   GET  ?action=me
 *        Returns the current vendor's bills + payments + invoice metadata.
 *        Auth via cf_vp_sid cookie (no platform-user session needed).
 *
 * The portal has its own session model — vendors are NOT in the `users`
 * table. Sessions live 14 days.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/ap.php';

$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$pdo    = getDB();

// ───── invite (admin) ─────
if ($method === 'POST' && $action === 'invite') {
    $ctx      = api_require_auth();
    $tenantId = (int) $ctx['tenant_id'];
    RBAC::requirePermission($ctx['user'], 'ap.vendor.create');
    $body     = api_json_body();
    $vendorId = (int) ($body['vendor_id'] ?? 0);
    if ($vendorId <= 0) api_error('vendor_id required', 422);

    $vendor = $pdo->prepare('SELECT id, vendor_name, contact_email FROM ap_vendors WHERE tenant_id = :t AND id = :id');
    $vendor->execute(['t' => $tenantId, 'id' => $vendorId]);
    $vendor = $vendor->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) api_error('Vendor not found', 404);
    $email = trim((string) ($body['email'] ?? $vendor['contact_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_error('valid email required', 422);

    $token     = vendorPortalGenerateToken();
    $tokenHash = hash('sha256', $token);
    $expires   = date('Y-m-d H:i:s', time() + 7 * 86400);

    $pdo->prepare(
        'INSERT INTO ap_vendor_portal_tokens
            (tenant_id, vendor_id, token_hash, issued_to_email, issued_by_user, expires_at)
         VALUES (:t, :v, :th, :em, :ub, :ex)'
    )->execute([
        't' => $tenantId, 'v' => $vendorId, 'th' => $tokenHash,
        'em' => $email, 'ub' => (int) ($ctx['user']['id'] ?? 0), 'ex' => $expires,
    ]);

    $base = (function () {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$proto}://{$host}";
    })();
    $magicLink = "{$base}/api/ap/vendor_portal.php?action=redeem&token={$token}";

    if (function_exists('mailerSend')) {
        try {
            mailerSend([
                'to'      => $email,
                'subject' => 'Your vendor portal access — ' . ($vendor['vendor_name'] ?? 'CoreFlux'),
                'body_html' => "<p>Hi,</p><p>Click below to view your bills and payments:</p>"
                    . "<p><a href=\"{$magicLink}\">Open vendor portal →</a></p>"
                    . "<p>This link expires in 7 days.</p>",
            ]);
        } catch (\Throwable $_) { /* swallow — admin still gets the link returned */ }
    }

    apAudit('ap.vendor.portal_invited', [
        'vendor_id' => $vendorId, 'email' => $email, 'expires_at' => $expires,
    ], $vendorId);

    api_ok([
        'magic_link' => $magicLink,
        'expires_at' => $expires,
        'email'      => $email,
        'note'       => 'Email send is best-effort; share the link directly if needed.',
    ]);
}

// ───── redeem (vendor; no session yet) ─────
if ($method === 'GET' && $action === 'redeem') {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '') api_error('token required', 422);
    $tokenHash = hash('sha256', $token);

    $row = $pdo->prepare(
        "SELECT * FROM ap_vendor_portal_tokens
          WHERE token_hash = :h AND revoked_at IS NULL
            AND expires_at > NOW() LIMIT 1"
    );
    $row->execute(['h' => $tokenHash]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Link expired</h1><p>This vendor-portal link has expired or been revoked. Ask the AP team for a fresh invite.</p>';
        exit;
    }

    // Open a 14-day session.
    $sessionId = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', time() + 14 * 86400);
    $pdo->prepare(
        'INSERT INTO ap_vendor_portal_sessions
            (tenant_id, vendor_id, session_id, expires_at)
         VALUES (:t, :v, :s, :e)'
    )->execute(['t' => $row['tenant_id'], 'v' => $row['vendor_id'], 's' => $sessionId, 'e' => $expires]);

    if (!$row['consumed_at']) {
        $pdo->prepare(
            'UPDATE ap_vendor_portal_tokens SET consumed_at = NOW(), last_used_at = NOW()
              WHERE id = :id'
        )->execute(['id' => $row['id']]);
    } else {
        $pdo->prepare('UPDATE ap_vendor_portal_tokens SET last_used_at = NOW() WHERE id = :id')
            ->execute(['id' => $row['id']]);
    }

    setcookie('cf_vp_sid', $sessionId, [
        'expires'  => time() + 14 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    apAudit('ap.vendor.portal_session_opened', ['vendor_id' => $row['vendor_id']], (int) $row['vendor_id']);
    header('Location: /#/vendor/portal', true, 302);
    exit;
}

// ───── me (vendor session required) ─────
if ($method === 'GET' && $action === 'me') {
    $sid = (string) ($_COOKIE['cf_vp_sid'] ?? '');
    if ($sid === '') api_error('Not authenticated as vendor', 401);
    $sess = $pdo->prepare(
        'SELECT * FROM ap_vendor_portal_sessions
          WHERE session_id = :s AND expires_at > NOW() LIMIT 1'
    );
    $sess->execute(['s' => $sid]);
    $sess = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$sess) api_error('Session expired', 401);

    $tid       = (int) $sess['tenant_id'];
    $vendorId  = (int) $sess['vendor_id'];

    $vendor = $pdo->prepare('SELECT id, vendor_name, contact_email, vendor_type FROM ap_vendors WHERE tenant_id = :t AND id = :id');
    $vendor->execute(['t' => $tid, 'id' => $vendorId]);
    $vendor = $vendor->fetch(PDO::FETCH_ASSOC);

    $bills = $pdo->prepare(
        'SELECT id, invoice_number, invoice_date, due_date, amount_total, status, posted_at
           FROM ap_bills
          WHERE tenant_id = :t AND vendor_id = :v
          ORDER BY invoice_date DESC LIMIT 100'
    );
    $bills->execute(['t' => $tid, 'v' => $vendorId]);
    $bills = $bills->fetchAll(PDO::FETCH_ASSOC);

    $payments = [];
    try {
        $pmt = $pdo->prepare(
            'SELECT p.id, p.payment_date, p.amount, p.method, p.status, p.bill_id,
                    b.invoice_number
               FROM ap_payments p
               LEFT JOIN ap_bills b ON b.id = p.bill_id AND b.tenant_id = p.tenant_id
              WHERE p.tenant_id = :t AND p.vendor_id = :v
              ORDER BY p.payment_date DESC LIMIT 100'
        );
        $pmt->execute(['t' => $tid, 'v' => $vendorId]);
        $payments = $pmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { /* schema variation tolerated */ }

    api_ok([
        'vendor'   => $vendor,
        'bills'    => $bills,
        'payments' => $payments,
        'session_expires_at' => $sess['expires_at'],
    ]);
}

api_error('Unknown action', 422);

function vendorPortalGenerateToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
}
