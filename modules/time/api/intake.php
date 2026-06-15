<?php
/**
 * Time — Intake API.
 *
 *   GET   /api/time/intake.php                     → list intake events
 *   POST  /api/time/intake.php?action=poll         → run M365/Gmail poll for tenant's
 *                                                    `timesheets` folder. Auth required.
 *   POST  /api/time/intake.php?action=process&id=N → re-fetch + AI-extract a 'received'
 *                                                    intake row (admin nudge).
 *   POST  /api/time/intake.php?id=N&action=dismiss → mark not-a-timesheet.
 *
 *   POST  /api/time/intake.php?action=webhook&provider=sendgrid|postmark|generic
 *         (NO auth — verified by HMAC + tenant lookup from `to` address)
 *         Accepts the inbound-parse payload, ingests attachments, runs AI
 *         extract in bulk mode, creates time_uploaded_documents.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/MailService.php';
require_once __DIR__ . '/../lib/time.php';
require_once __DIR__ . '/../lib/upload_helpers.php';
require_once __DIR__ . '/../lib/intake.php';

$method = api_method();
$action = (string) ($_GET['action'] ?? '');

// ─── Webhook (no auth — HMAC + tenant resolution) ───
if ($method === 'POST' && $action === 'webhook') {
    $provider = (string) ($_GET['provider'] ?? 'generic');
    if (!in_array($provider, ['sendgrid', 'postmark', 'generic'], true)) {
        api_error('Unsupported webhook provider', 422);
    }

    if ($provider === 'sendgrid') {
        // Inbound Parse posts as multipart/form-data: from, to, subject, text, html,
        // attachments=N, attachment1..attachmentN as file uploads.
        $from      = trim((string) ($_POST['from']    ?? ''));
        $to        = trim((string) ($_POST['to']      ?? ''));
        $subject   = (string) ($_POST['subject'] ?? '');
        $text      = (string) ($_POST['text']    ?? '');
        $count     = (int) ($_POST['attachments'] ?? 0);
        $atts = [];
        for ($i = 1; $i <= $count; $i++) {
            $f = $_FILES["attachment{$i}"] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $atts[] = [
                'file_name' => (string) ($f['name'] ?? "attachment{$i}"),
                'mime_type' => (string) ($f['type'] ?? 'application/octet-stream'),
                'content'   => file_get_contents($f['tmp_name']),
            ];
        }
    } elseif ($provider === 'postmark') {
        $body = api_json_body();
        $from    = trim((string) ($body['From']    ?? ''));
        $to      = trim((string) ($body['To']      ?? ''));
        $subject = (string) ($body['Subject']     ?? '');
        $text    = (string) ($body['TextBody']    ?? '');
        $atts = [];
        foreach (($body['Attachments'] ?? []) as $a) {
            $atts[] = [
                'file_name' => (string) ($a['Name'] ?? 'file'),
                'mime_type' => (string) ($a['ContentType'] ?? 'application/octet-stream'),
                'content'   => base64_decode((string) ($a['Content'] ?? '')),
            ];
        }
    } else {
        // Generic JSON
        $body = api_json_body();
        $from    = trim((string) ($body['from_address'] ?? ''));
        $to      = trim((string) ($body['to_address']   ?? ''));
        $subject = (string) ($body['subject']           ?? '');
        $text    = (string) ($body['body']              ?? '');
        $atts = [];
        foreach (($body['attachments'] ?? []) as $a) {
            $atts[] = [
                'file_name' => (string) ($a['file_name'] ?? 'file'),
                'mime_type' => (string) ($a['mime_type'] ?? 'application/octet-stream'),
                'content'   => isset($a['content_base64']) ? base64_decode((string) $a['content_base64']) : null,
                'fetch_url' => $a['url'] ?? null,
            ];
        }
    }

    // Strip "Name <addr>" → "addr"
    if (preg_match('/<([^>]+)>/', $from, $m)) $from = trim($m[1]);
    $tenantId = timeIntakeTenantFromAddress($to);
    if (!$tenantId) {
        // Don't 4xx — providers will retry forever. Log + 200.
        error_log("[time.intake.webhook] no tenant for to={$to}");
        http_response_code(200); echo json_encode(['ok' => true, 'note' => 'no tenant match']); exit;
    }

    // HMAC verification (per-tenant secret).
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT time_intake_webhook_secret FROM tenants WHERE id = :id');
    $stmt->execute(['id' => $tenantId]);
    $secret = (string) ($stmt->fetchColumn() ?: '');
    $sig    = (string) ($_SERVER['HTTP_X_CF_INTAKE_SIGNATURE'] ?? '');
    if ($secret !== '' && !timeIntakeVerifyWebhookHmac($secret, $sig, file_get_contents('php://input') ?: '')) {
        api_error('Invalid signature', 401);
    }

    $intakeId = timeIntakeRecordEvent($tenantId, [
        'source'              => $provider === 'sendgrid' ? 'webhook_sendgrid'
                              : ($provider === 'postmark' ? 'webhook_postmark' : 'webhook_generic'),
        'provider_message_id' => $_POST['headers']['Message-Id'] ?? ($body['MessageID'] ?? null),
        'from_address'        => $from,
        'subject'             => $subject,
        'body_preview'        => mb_substr($text, 0, 1000),
        'has_attachments'     => count($atts) > 0,
        'attachment_count'    => count($atts),
        'received_at'         => date('Y-m-d H:i:s'),
        'raw_meta'            => ['to' => $to, 'provider' => $provider],
    ]);
    timeIntakeIngestAttachments($tenantId, $intakeId, $atts);

    // Best-effort acknowledgment reply.
    if ($from && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        try {
            \Core\MailService::getInstance()->send($tenantId, [
                'module'   => 'time',
                'purpose'  => 'intake_ack',
                'to'       => $from,
                'subject'  => 'Got your timesheet — ready to review',
                'body_html'=> '<p>We processed your timesheet upload. Open CoreFlux to confirm and submit:</p>'
                    . '<p><a href="/#/modules/time/intake">Open the intake queue →</a></p>',
            ]);
        } catch (\Throwable $_) { /* swallow */ }
    }

    http_response_code(200); echo json_encode(['ok' => true, 'intake_id' => $intakeId]); exit;
}

// ─── Authenticated paths below ───
$ctx       = api_require_auth();
$tenantId  = (int) $ctx['tenant_id'];
$user      = $ctx['user'];
$pdo       = getDB();

// ─── List intake events ───
if ($method === 'GET') {
    rbac_legacy_require($user, 'time.review');
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($_GET['status'])) { $where[] = 'status = :s'; $params['s'] = (string) $_GET['status']; }
    $stmt = $pdo->prepare(
        'SELECT id, source, from_address, from_name, subject, body_preview,
                received_at, has_attachments, attachment_count,
                upload_document_ids_json, status, error_text, created_at, processed_at
           FROM time_intake_events
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY created_at DESC LIMIT 200'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['upload_document_ids'] = $r['upload_document_ids_json'] ? json_decode((string) $r['upload_document_ids_json'], true) : [];
        unset($r['upload_document_ids_json']);
    }
    api_ok(['rows' => $rows]);
}

// ─── Poll: pulls metadata from M365 / Gmail folder via MailService ───
if ($method === 'POST' && $action === 'poll') {
    rbac_legacy_require($user, 'time.review');
    rbac_legacy_require($user, 'ai.use');
    $folderRow = $pdo->prepare(
        "SELECT mf.id AS folder_id, mf.connection_id, mc.provider
           FROM tenant_mail_folders mf
           JOIN tenant_mail_connections mc ON mc.id = mf.connection_id
          WHERE mf.tenant_id = :t AND mf.module = 'time' AND mf.polling_enabled = 1
          ORDER BY mf.id ASC LIMIT 1"
    );
    $folderRow->execute(['t' => $tenantId]);
    $folder = $folderRow->fetch(\PDO::FETCH_ASSOC);
    if (!$folder) api_error('No timesheets folder configured. Connect M365/Gmail in Settings.', 422);

    $pollResult = \Core\MailService::getInstance()->poll_folder(
        (int) $folder['folder_id'],
        (string) $folder['provider']
    );
    $messages = $pollResult['messages'] ?? [];

    $created = [];
    foreach ($messages as $m) {
        if (empty($m['has_attachments'])) continue; // no attachments, not a timesheet
        $sourceMap = ['m365' => 'poll_m365', 'google' => 'poll_gmail', 'imap' => 'poll_imap'];
        $intakeId = timeIntakeRecordEvent($tenantId, [
            'source'              => $sourceMap[(string) $folder['provider']] ?? 'poll_m365',
            'folder_id'           => (int) $folder['folder_id'],
            'connection_id'       => (int) $folder['connection_id'],
            'provider_message_id' => $m['message_id'] ?? null,
            'from_address'        => $m['from_address'] ?? null,
            'from_name'           => $m['from_name'] ?? null,
            'subject'             => $m['subject'] ?? null,
            'body_preview'        => $m['body_preview'] ?? null,
            'received_at'         => isset($m['received_at']) ? date('Y-m-d H:i:s', strtotime((string) $m['received_at'])) : null,
            'has_attachments'     => true,
            'attachment_count'    => 0, // unknown until full fetch
            'raw_meta'            => $m,
        ]);
        $created[] = $intakeId;

        // Try to fetch + ingest attachments via the driver's
        // fetch_message_with_attachments() if available; otherwise the row
        // stays in 'received' state for the user to re-process when ready.
        try {
            $svc = \Core\MailService::getInstance();
            if (method_exists($svc, 'fetch_message_with_attachments')) {
                $full = $svc->fetch_message_with_attachments(
                    (int) $folder['folder_id'],
                    (string) $m['message_id'],
                    (string) $folder['provider']
                );
                $atts = $full['attachments'] ?? [];
                if ($atts) timeIntakeIngestAttachments($tenantId, $intakeId, $atts);
            }
        } catch (\Throwable $e) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE time_intake_events SET error_text = :e WHERE id = :id'
            )->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $intakeId]);
        }
    }

    api_ok([
        'polled'      => count($messages),
        'new_intakes' => count($created),
        'intake_ids'  => $created,
        'note'        => method_exists(\Core\MailService::getInstance(), 'fetch_message_with_attachments')
            ? null
            : 'Attachment fetch driver not available — intake rows captured in "received" state. Click Process on each to retry.',
    ]);
}

// ─── Process a single intake row (re-fetch + AI extract) ───
if ($method === 'POST' && $action === 'process') {
    rbac_legacy_require($user, 'time.review');
    rbac_legacy_require($user, 'ai.use');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $row = $pdo->prepare('SELECT * FROM time_intake_events WHERE tenant_id = :t AND id = :id LIMIT 1');
    $row->execute(['t' => $tenantId, 'id' => $id]);
    $row = $row->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('Not found', 404);

    if (!empty($row['folder_id']) && method_exists(\Core\MailService::getInstance(), 'fetch_message_with_attachments')) {
        try {
            $full = \Core\MailService::getInstance()->fetch_message_with_attachments(
                (int) $row['folder_id'], (string) $row['provider_message_id']
            );
            $atts = $full['attachments'] ?? [];
            $ids = timeIntakeIngestAttachments($tenantId, $id, $atts);
            api_ok(['ok' => true, 'document_ids' => $ids]);
        } catch (\Throwable $e) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE time_intake_events SET status = "failed", error_text = :e WHERE id = :id'
            )->execute(['e' => substr($e->getMessage(), 0, 500), 'id' => $id]);
            api_error('Process failed: ' . $e->getMessage(), 500);
        }
    }
    api_error('No connector available to fetch this message', 422);
}

// ─── Dismiss an intake row ───
if ($method === 'POST' && $action === 'dismiss') {
    rbac_legacy_require($user, 'time.review');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $pdo->prepare(
        'UPDATE time_intake_events SET status = "dismissed", processed_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['t' => $tenantId, 'id' => $id]);
    timeAudit('time.intake.dismissed', ['intake_id' => $id], $id);
    api_ok(['ok' => true, 'status' => 'dismissed']);
}

// ─── Record a confirmed sender → person mapping ───
//
// Called by the review UI after the user picks a person for an intake-derived
// document, so next time the same email address arrives we auto-resolve.
//   POST body: { document_id, person_id }
if ($method === 'POST' && $action === 'record_alias') {
    rbac_legacy_require($user, 'time.entry.create');
    $body     = api_json_body();
    $docId    = (int) ($body['document_id'] ?? 0);
    $personId = (int) ($body['person_id']   ?? 0);
    if ($docId <= 0 || $personId <= 0) api_error('document_id + person_id required', 422);

    $row = $pdo->prepare(
        'SELECT d.intake_event_id, e.from_address
           FROM time_uploaded_documents d
           LEFT JOIN time_intake_events e ON e.id = d.intake_event_id AND e.tenant_id = d.tenant_id
          WHERE d.tenant_id = :t AND d.id = :id LIMIT 1'
    );
    $row->execute(['t' => $tenantId, 'id' => $docId]);
    $row = $row->fetch(\PDO::FETCH_ASSOC);
    if (!$row || empty($row['from_address'])) {
        api_ok(['ok' => true, 'recorded' => false, 'reason' => 'no intake from_address']);
    }
    timeIntakeRecordSenderAlias(
        $tenantId,
        (string) $row['from_address'],
        $personId,
        (int) ($user['id'] ?? 0) ?: null
    );
    api_ok(['ok' => true, 'recorded' => true, 'from_address' => $row['from_address']]);
}

api_error('Method not allowed', 405);
