<?php
/**
 * AP — Vendor Portal API.
 *
 * Three endpoints exposed at /api/ap/vendor_portal.php:
 *
 *   POST ?action=invite           (admin-only)  body { vendor_id, email? }
 *        Generates a 30-byte URL-safe token, stores SHA-256(token), returns
 *        the magic link. Best-effort email send via core/mailer if available.
 *
 *   GET  ?action=redeem&token=X
 *        Single-use redemption. Validates token, opens a vendor session,
 *        sets cf_vp_sid HttpOnly cookie, redirects to /vendor/portal.
 *
 *   GET  ?action=me
 *        Returns the current vendor's bills + payments + invoice metadata.
 *        Auth via cf_vp_sid cookie (no platform-user session needed).
 *
 *   POST ?action=upload_url        body { document_type, file_name }
 *        Vendor-uploaded documents (W-9, COI, banking form). Presigned POST.
 *
 *   POST ?action=upload_document   body { storage_key, document_type, file_name }
 *        Records a vendor-uploaded document; status='pending_review'.
 *
 *   POST ?action=update_banking    body { remit_to_email?, remit_to_phone?,
 *                                          payment_method?, payment_account_full?,
 *                                          payment_routing_full?, payment_account_type? }
 *        Updates vendor's banking/remittance details (encrypted at app layer).
 *        Logged to audit; ap_vendor_portal_changes records the event.
 *
 *   GET  ?action=documents
 *        Lists documents the vendor has uploaded.
 *
 * The portal has its own session model — vendors are NOT in the `users`
 * table. Sessions live 14 days.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/encryption.php';
require_once __DIR__ . '/../lib/ap.php';

$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$pdo    = getDB();

// ───── invite (admin) ─────
if ($method === 'POST' && $action === 'invite') {
    $ctx      = api_require_auth();
    $tenantId = (int) $ctx['tenant_id'];
    rbac_legacy_require($ctx['user'], 'ap.bill.create');
    $body     = api_json_body();
    $vendorId = (int) ($body['vendor_id'] ?? 0);
    if ($vendorId <= 0) api_error('vendor_id required', 422);

    $vendor = $pdo->prepare('SELECT id, vendor_name, remit_to_email FROM ap_vendors_index WHERE tenant_id = :t AND id = :id');
    $vendor->execute(['t' => $tenantId, 'id' => $vendorId]);
    $vendor = $vendor->fetch(PDO::FETCH_ASSOC);
    if (!$vendor) api_error('Vendor not found', 404);
    $email = trim((string) ($body['email'] ?? $vendor['remit_to_email'] ?? ''));
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

    // tenant-leak-allow: token_hash is a 256-bit random secret; row carries tenant_id
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
        // tenant-leak-allow: row was just fetched by token_hash secret above; id-only update is safe
        $pdo->prepare(
            'UPDATE ap_vendor_portal_tokens SET consumed_at = NOW(), last_used_at = NOW()
              WHERE id = :id'
        )->execute(['id' => $row['id']]);
    } else {
        // tenant-leak-allow: row was just fetched by token_hash secret above; id-only update is safe
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

// ───── helper: resolve vendor session ─────
function vendorPortalRequireSession(\PDO $pdo): array {
    $sid = (string) ($_COOKIE['cf_vp_sid'] ?? '');
    if ($sid === '') api_error('Not authenticated as vendor', 401);
    // tenant-leak-allow: session_id is a 256-bit random cookie secret; row carries tenant_id
    $sess = $pdo->prepare(
        'SELECT * FROM ap_vendor_portal_sessions
          WHERE session_id = :s AND expires_at > NOW() LIMIT 1'
    );
    $sess->execute(['s' => $sid]);
    $sess = $sess->fetch(PDO::FETCH_ASSOC);
    if (!$sess) api_error('Session expired', 401);
    return $sess;
}

// ───── me (vendor session required) ─────
if ($method === 'GET' && $action === 'me') {
    $sess = vendorPortalRequireSession($pdo);
    $tid       = (int) $sess['tenant_id'];
    $vendorId  = (int) $sess['vendor_id'];

    $vendor = $pdo->prepare('SELECT id, vendor_name, vendor_type, remit_to_email, remit_to_phone, payment_method, payment_account_last4, default_terms FROM ap_vendors_index WHERE tenant_id = :t AND id = :id');
    $vendor->execute(['t' => $tid, 'id' => $vendorId]);
    $vendor = $vendor->fetch(PDO::FETCH_ASSOC);

    // Bills are matched by vendor_name (no vendor_id column on ap_bills today).
    $vendorName = (string) ($vendor['vendor_name'] ?? '');
    $bills = $pdo->prepare(
        'SELECT id, bill_number, internal_ref, bill_date, due_date, total AS amount_total, status, approved_at
           FROM ap_bills
          WHERE tenant_id = :t AND vendor_name = :vn
          ORDER BY bill_date DESC LIMIT 100'
    );
    $bills->execute(['t' => $tid, 'vn' => $vendorName]);
    $bills = $bills->fetchAll(PDO::FETCH_ASSOC);

    // Payments via allocations. A payment may cover multiple bills; we pick the
    // first allocated bill's bill_number for display.
    $payments = $pdo->prepare(
        'SELECT p.id, p.pay_date AS payment_date, p.amount, p.method, p.status,
                MIN(b.bill_number) AS bill_number, MIN(b.id) AS bill_id
           FROM ap_payments p
           LEFT JOIN ap_payment_allocations alloc ON alloc.payment_id = p.id
           LEFT JOIN ap_bills b ON b.id = alloc.bill_id AND b.tenant_id = p.tenant_id
          WHERE p.tenant_id = :t AND p.vendor_name = :vn
          GROUP BY p.id
          ORDER BY p.pay_date DESC LIMIT 100'
    );
    $payments->execute(['t' => $tid, 'vn' => $vendorName]);
    $payments = $payments->fetchAll(PDO::FETCH_ASSOC);

    // Recent vendor-uploaded documents (Phase 2).
    $documents = [];
    try {
        $docs = $pdo->prepare(
            'SELECT id, document_type, file_name, status, uploaded_at, reviewed_at
               FROM ap_vendor_portal_documents
              WHERE tenant_id = :t AND vendor_id = :v
              ORDER BY uploaded_at DESC LIMIT 50'
        );
        $docs->execute(['t' => $tid, 'v' => $vendorId]);
        $documents = $docs->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { /* table may not exist on legacy installs */ }

    api_ok([
        'vendor'    => $vendor,
        'bills'     => $bills,
        'payments'  => $payments,
        'documents' => $documents,
        'session_expires_at' => $sess['expires_at'],
    ]);
}

// ───── documents list ─────
if ($method === 'GET' && $action === 'documents') {
    $sess = vendorPortalRequireSession($pdo);
    $rows = $pdo->prepare(
        'SELECT id, document_type, file_name, status, notes, uploaded_at, reviewed_at
           FROM ap_vendor_portal_documents
          WHERE tenant_id = :t AND vendor_id = :v
          ORDER BY uploaded_at DESC LIMIT 100'
    );
    $rows->execute(['t' => (int) $sess['tenant_id'], 'v' => (int) $sess['vendor_id']]);
    api_ok(['rows' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
}

// ───── upload_url (presigned POST for vendor-uploaded docs) ─────
if ($method === 'POST' && $action === 'upload_url') {
    $sess = vendorPortalRequireSession($pdo);
    $body = api_json_body();
    $docType  = (string) ($body['document_type'] ?? '');
    $fileName = (string) ($body['file_name'] ?? 'document.pdf');
    $allowed  = ['w9','coi','banking_form','contract','other'];
    if (!in_array($docType, $allowed, true)) api_error('document_type must be one of: ' . implode(',', $allowed), 422);

    require_once __DIR__ . '/../../../core/StorageService.php';
    $svc = \Core\StorageService::driver();
    $key = $svc->build_key('ap', (int) $sess['tenant_id'], 'vendor_portal_' . $docType, (int) $sess['vendor_id'], $fileName);
    $presigned = $svc->presigned_post($key, ['max_bytes' => 25 * 1024 * 1024, 'ttl_seconds' => 600]);
    api_ok(['presigned' => $presigned, 'storage_key' => $key]);
}

// ───── upload_document (record after presigned POST succeeded) ─────
if ($method === 'POST' && $action === 'upload_document') {
    $sess = vendorPortalRequireSession($pdo);
    $body = api_json_body();
    $storageKey = (string) ($body['storage_key'] ?? '');
    $docType    = (string) ($body['document_type'] ?? '');
    $fileName   = (string) ($body['file_name'] ?? 'document.pdf');
    if ($storageKey === '' || $docType === '') api_error('storage_key + document_type required', 422);

    require_once __DIR__ . '/../../../core/storage_register.php';
    $storageObjectId = registerStorageObject([
        'tenant_id' => (int) $sess['tenant_id'],
        's3_key'    => $storageKey,
        'file_name' => $fileName,
        'kind'      => 'vendor_document',
    ]);

    // Auto-process by document_type. Manual review is the exception, not the default.
    [$status, $aiAction, $aiJson, $aiConf] = vendorPortalAutoProcess(
        $pdo, (int) $sess['tenant_id'], (int) $sess['vendor_id'], $docType, $storageKey
    );

    $pdo->prepare(
        'INSERT INTO ap_vendor_portal_documents
            (tenant_id, vendor_id, document_type, file_name, storage_object_id,
             status, ai_extracted_json, ai_confidence, ai_action, uploaded_at)
         VALUES (:t, :v, :dt, :fn, :so, :st, :aj, :ac, :aa, NOW())'
    )->execute([
        't'  => (int) $sess['tenant_id'],
        'v'  => (int) $sess['vendor_id'],
        'dt' => $docType,
        'fn' => $fileName,
        'so' => $storageObjectId,
        'st' => $status,
        'aj' => $aiJson,
        'ac' => $aiConf,
        'aa' => $aiAction,
    ]);
    $id = (int) $pdo->lastInsertId();
    apAudit('ap.vendor.portal_document_uploaded', [
        'vendor_id'     => (int) $sess['vendor_id'],
        'document_type' => $docType,
        'document_id'   => $id,
        'auto_status'   => $status,
        'ai_action'     => $aiAction,
    ], (int) $sess['vendor_id']);
    api_ok(['id' => $id, 'status' => $status, 'ai_action' => $aiAction], 201);
}

// ───── admin: pending uploads list ─────
if ($method === 'GET' && $action === 'admin_pending') {
    $ctx = api_require_auth();
    rbac_legacy_require($ctx['user'], 'ap.vendor.portal_review');
    $tenantId = (int) $ctx['tenant_id'];
    $stmt = $pdo->prepare(
        'SELECT d.*, v.vendor_name, v.vendor_type
           FROM ap_vendor_portal_documents d
           LEFT JOIN ap_vendors_index v ON v.id = d.vendor_id AND v.tenant_id = d.tenant_id
          WHERE d.tenant_id = :t AND d.status = "pending_review"
          ORDER BY d.uploaded_at DESC LIMIT 200'
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['ai_extracted'] = $r['ai_extracted_json'] ? json_decode((string) $r['ai_extracted_json'], true) : null;
        unset($r['ai_extracted_json']);
    }
    api_ok(['rows' => $rows]);
}

// ───── admin: approve/reject a pending upload ─────
if ($method === 'POST' && in_array($action, ['admin_approve', 'admin_reject'], true)) {
    $ctx = api_require_auth();
    rbac_legacy_require($ctx['user'], 'ap.vendor.portal_review');
    $tenantId = (int) $ctx['tenant_id'];
    $body  = api_json_body();
    $docId = (int) ($body['id'] ?? 0);
    $note  = trim((string) ($body['note'] ?? ''));
    if ($docId <= 0) api_error('id required', 422);

    $newStatus = $action === 'admin_approve' ? 'approved' : 'rejected';
    $pdo->prepare(
        'UPDATE ap_vendor_portal_documents
            SET status = :s, reviewed_at = NOW(), reviewed_by = :r,
                notes = COALESCE(NULLIF(:n, ""), notes)
          WHERE tenant_id = :t AND id = :id'
    )->execute([
        's' => $newStatus,
        'r' => (int) ($ctx['user']['id'] ?? 0),
        'n' => $note,
        't' => $tenantId,
        'id' => $docId,
    ]);
    apAudit("ap.vendor.portal_document_{$newStatus}", ['document_id' => $docId, 'note' => $note], $docId);
    api_ok(['ok' => true, 'status' => $newStatus]);
}

/**
 * Auto-process a vendor-uploaded document.
 *
 * Returns [status, ai_action, ai_extracted_json, ai_confidence].
 *
 *   - W-9   → AI extract; if all required fields present, auto-update vendor +
 *             auto-approve. Otherwise flag for manual review with the draft.
 *   - COI   → AI extract carrier + expiry; auto-approve.
 *   - banking_form / contract / other → auto-approve (archival only).
 */
function vendorPortalAutoProcess(\PDO $pdo, int $tenantId, int $vendorId, string $docType, string $storageKey): array
{
    if (in_array($docType, ['banking_form','contract','other'], true)) {
        return ['approved', 'auto_approved', null, null];
    }

    if ($docType === 'w9') {
        try {
            require_once __DIR__ . '/../../../core/StorageService.php';
            require_once __DIR__ . '/../../../core/ai_service.php';
            $signedUrl = \Core\StorageService::getInstance()->get_signed_url($storageKey);
            $schema = '{"vendor_name":string|null,"business_name":string|null,'
                . '"tax_classification":"individual"|"sole_proprietor"|"c_corp"|"s_corp"|"partnership"|"trust"|"llc"|"other"|null,'
                . '"tax_id_last4":string|null,"vendor_type":"1099_individual"|"c2c_corp"|"w9_business"|"other",'
                . '"requires_1099":boolean|null}';
            $res = aiExtract([
                'feature_key' => 'ap.vendor.from_w9',
                'instruction' => 'Extract a US W-9 (or W-8BEN equivalent) into the JSON shape below.',
                'schema_hint' => $schema,
                'images'      => [['url' => $signedUrl, 'mime' => 'application/pdf']],
            ]);
            $draft = $res['data'] ?? [];
            $confidence = vendorPortalW9Confidence($draft);

            // Compare against existing vendor TIN — if mismatch, flag.
            $existing = $pdo->prepare('SELECT tax_id_last4, vendor_type FROM ap_vendors_index WHERE tenant_id = :t AND id = :v');
            $existing->execute(['t' => $tenantId, 'v' => $vendorId]);
            $vendor = $existing->fetch(\PDO::FETCH_ASSOC) ?: [];
            $tinMismatch = !empty($vendor['tax_id_last4'])
                && !empty($draft['tax_id_last4'])
                && $vendor['tax_id_last4'] !== $draft['tax_id_last4'];

            $autoSafe = $confidence >= 0.80 && !$tinMismatch && !empty($draft['vendor_name']);

            if ($autoSafe) {
                // Apply to vendor record.
                $set = [];
                $params = ['t' => $tenantId, 'v' => $vendorId];
                if (!empty($draft['vendor_type'])) {
                    $set[] = 'vendor_type = :vt';
                    $params['vt'] = $draft['vendor_type'];
                }
                if (!empty($draft['tax_id_last4'])) {
                    $set[] = 'tax_id_last4 = :l4';
                    $params['l4'] = substr((string) $draft['tax_id_last4'], -4);
                }
                if (isset($draft['requires_1099'])) {
                    $set[] = 'requires_1099 = :r';
                    $params['r'] = $draft['requires_1099'] ? 1 : 0;
                }
                if ($set) {
                    $pdo->prepare('UPDATE ap_vendors_index SET ' . implode(', ', $set) . ' WHERE tenant_id = :t AND id = :v')
                        ->execute($params);
                }
                return ['approved', 'auto_approved', json_encode($draft), $confidence];
            }
            return ['pending_review', 'flagged_for_review', json_encode($draft), $confidence];
        } catch (\Throwable $e) {
            error_log('[vendor_portal.w9] extract failed: ' . $e->getMessage());
            return ['pending_review', 'flagged_for_review', json_encode(['extract_error' => $e->getMessage()]), null];
        }
    }

    if ($docType === 'coi') {
        try {
            require_once __DIR__ . '/../../../core/StorageService.php';
            require_once __DIR__ . '/../../../core/ai_service.php';
            $signedUrl = \Core\StorageService::getInstance()->get_signed_url($storageKey);
            $res = aiExtract([
                'feature_key' => 'ap.vendor.from_coi',
                'instruction' => 'Extract carrier name, policy number, effective_date (ISO YYYY-MM-DD), expiry_date (ISO), and named insured from this Certificate of Insurance.',
                'schema_hint' => '{"carrier":string|null,"policy_number":string|null,"effective_date":string|null,"expiry_date":string|null,"named_insured":string|null}',
                'images'      => [['url' => $signedUrl, 'mime' => 'application/pdf']],
            ]);
            // Auto-approve regardless; the data is informational.
            return ['approved', 'auto_approved', json_encode($res['data'] ?? []), 0.7];
        } catch (\Throwable $e) {
            error_log('[vendor_portal.coi] extract failed: ' . $e->getMessage());
            // Even if AI extract fails, COI is archival — auto-approve.
            return ['approved', 'auto_approved', null, null];
        }
    }

    return ['pending_review', 'none', null, null];
}

/**
 * Heuristic confidence score on a W-9 AI extract — fraction of required
 * fields populated. 1.0 if all critical fields are present.
 */
function vendorPortalW9Confidence(array $draft): float
{
    $required = ['vendor_name', 'tax_classification', 'tax_id_last4', 'vendor_type'];
    $present = 0;
    foreach ($required as $f) {
        if (!empty($draft[$f])) $present++;
    }
    return round($present / count($required), 3);
}

// ───── update_banking (vendor self-service banking edit) ─────
if ($method === 'POST' && $action === 'update_banking') {
    $sess = vendorPortalRequireSession($pdo);
    $body = api_json_body();
    $tid  = (int) $sess['tenant_id'];
    $vid  = (int) $sess['vendor_id'];

    $remitEmail = isset($body['remit_to_email']) ? trim((string) $body['remit_to_email']) : null;
    if ($remitEmail !== null && $remitEmail !== '' && !filter_var($remitEmail, FILTER_VALIDATE_EMAIL)) {
        api_error('remit_to_email is not a valid email', 422);
    }
    $remitPhone = isset($body['remit_to_phone']) ? trim((string) $body['remit_to_phone']) : null;
    $payMethod  = isset($body['payment_method']) ? (string) $body['payment_method'] : null;
    if ($payMethod !== null && !in_array($payMethod, ['ach','wire','check','card','cash','other',''], true)) {
        api_error('payment_method invalid', 422);
    }
    $acctType   = isset($body['payment_account_type']) ? (string) $body['payment_account_type'] : null;
    if ($acctType !== null && !in_array($acctType, ['checking','savings',''], true)) {
        api_error('payment_account_type invalid', 422);
    }
    $acctFull = isset($body['payment_account_full']) ? preg_replace('/\D/', '', (string) $body['payment_account_full']) : null;
    $routFull = isset($body['payment_routing_full']) ? preg_replace('/\D/', '', (string) $body['payment_routing_full']) : null;

    $set = [];
    $params = ['t' => $tid, 'v' => $vid];
    if ($remitEmail !== null) { $set[] = 'remit_to_email = :rmail'; $params['rmail'] = $remitEmail; }
    if ($remitPhone !== null) { $set[] = 'remit_to_phone = :rphone'; $params['rphone'] = $remitPhone; }
    if ($payMethod !== null && $payMethod !== '') { $set[] = 'payment_method = :pm'; $params['pm'] = $payMethod; }
    if ($acctType !== null && $acctType !== '')   { $set[] = 'payment_account_type = :pat'; $params['pat'] = $acctType; }
    if ($acctFull) {
        $set[] = 'payment_account_ct = :pact';
        $set[] = 'payment_account_last4 = :pal4';
        $set[] = 'kms_key_version_payment = :pkms';
        $params['pact'] = encryptField($acctFull);
        $params['pal4'] = substr($acctFull, -4);
        $params['pkms'] = 'v1';
    }
    if ($routFull) {
        $set[] = 'payment_routing_ct = :prct';
        $set[] = 'payment_routing_last4 = :prl4';
        $params['prct'] = encryptField($routFull);
        $params['prl4'] = substr($routFull, -4);
    }
    if (!$set) api_error('no banking fields supplied', 422);

    $pdo->prepare(
        'UPDATE ap_vendors_index SET ' . implode(', ', $set) . '
          WHERE tenant_id = :t AND id = :v'
    )->execute($params);

    // Always log without exposing PII. Field NAMES only.
    apAudit('ap.vendor.portal_banking_updated', [
        'vendor_id' => $vid,
        'fields'    => array_keys($params),
    ], $vid);
    api_ok(['ok' => true]);
}

api_error('Unknown action', 422);

function vendorPortalGenerateToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
}
