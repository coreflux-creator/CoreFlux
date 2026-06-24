<?php
/**
 * CoreFlux Mailer — platform-wide send helper.
 *
 * One function every module uses. Wraps PHPMailer with the SMTP constants
 * already defined in core/config.php. No module instantiates PHPMailer directly.
 *
 * Usage:
 *   sendEmail([
 *     'to'          => 'x@y.com',              // string | [email, name] | array of either
 *     'subject'     => 'Your CoreFlux setup',
 *     'body_text'   => "Hi there...",          // plaintext (required)
 *     'body_html'   => '<p>Hi there...</p>',   // optional HTML version
 *     'reply_to'    => 'no-reply@x.com',       // optional
 *     'from_email'  => null,                   // optional override; defaults to SMTP_FROM_EMAIL
 *     'from_name'   => null,                   // optional override; defaults to SMTP_FROM_NAME
 *   ]);
 *
 * Returns ['ok' => true, 'message_id' => '...'] on success.
 * Throws RuntimeException on failure.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sim_mock_bridge.php';
require_once __DIR__ . '/tenant_mail.php';
require_once __DIR__ . '/mail/suppressions.php';

function sendEmail(array $args): array {
    if (empty($args['to']))       throw new InvalidArgumentException('sendEmail: to is required');
    if (empty($args['subject']))  throw new InvalidArgumentException('sendEmail: subject is required');
    if (empty($args['body_text']))throw new InvalidArgumentException('sendEmail: body_text is required');

    // Sim-mock short-circuit. Sim tenants OR env SIM_MODE=1 capture into
    // the mock log instead of opening an SMTP connection.
    if (simShouldMockIfLoaded('resend') || simShouldMockIfLoaded('email')) {
        require_once __DIR__ . '/../sim/mocks/email.php';
        return simMockSendEmail([
            'to'      => is_array($args['to']) ? $args['to'] : [$args['to']],
            'subject' => $args['subject'],
            'html'    => $args['body_html'] ?? '',
            'text'    => $args['body_text'] ?? '',
        ]);
    }

    // Lazy-load PHPMailer from the vendored copy
    $base = __DIR__ . '/../lib/PHPMailer/src';
    require_once $base . '/Exception.php';
    require_once $base . '/PHPMailer.php';
    require_once $base . '/SMTP.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = defined('SMTP_HOST')   ? SMTP_HOST   : '';
        $mail->Port       = defined('SMTP_PORT')   ? SMTP_PORT   : 587;
        $mail->SMTPAuth   = true;
        $mail->Username   = defined('SMTP_USER')   ? SMTP_USER   : '';
        $mail->Password   = defined('SMTP_PASS')   ? SMTP_PASS   : '';
        $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 15;

        $fromEmail = $args['from_email'] ?? (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : $mail->Username);
        $fromName  = $args['from_name']  ?? (defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : 'CoreFlux');
        $mail->setFrom($fromEmail, $fromName);

        foreach (_mailer_normalize_recipients($args['to']) as [$e, $n]) $mail->addAddress($e, $n);
        foreach (_mailer_normalize_recipients($args['cc']  ?? []) as [$e, $n]) $mail->addCC($e, $n);
        foreach (_mailer_normalize_recipients($args['bcc'] ?? []) as [$e, $n]) $mail->addBCC($e, $n);
        if (!empty($args['reply_to'])) {
            foreach (_mailer_normalize_recipients($args['reply_to']) as [$e, $n]) $mail->addReplyTo($e, $n);
        }

        $mail->Subject = $args['subject'];
        if (!empty($args['body_html'])) {
            $mail->isHTML(true);
            $mail->Body    = $args['body_html'];
            $mail->AltBody = $args['body_text'];
        } else {
            $mail->isHTML(false);
            $mail->Body = $args['body_text'];
        }

        // Attachments. Accepts:
        //   - ['/abs/path.pdf', '/abs/path2.pdf']
        //   - [['path' => '/abs/path.pdf', 'name' => 'invoice-123.pdf']]
        //   - [['data' => '<binary>',     'name' => 'inline.pdf',
        //      'type' => 'application/pdf']]
        foreach (($args['attachments'] ?? []) as $att) {
            if (is_string($att)) {
                if (is_file($att)) $mail->addAttachment($att);
                continue;
            }
            if (!is_array($att)) continue;
            $name = (string) ($att['name'] ?? '');
            $type = (string) ($att['type'] ?? '');
            if (!empty($att['path']) && is_file($att['path'])) {
                $mail->addAttachment($att['path'], $name, 'base64', $type ?: '');
            } elseif (!empty($att['data'])) {
                $mail->addStringAttachment($att['data'], $name ?: 'attachment.bin', 'base64', $type ?: 'application/octet-stream');
            }
        }

        $mail->send();
        return ['ok' => true, 'message_id' => $mail->getLastMessageID()];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        throw new RuntimeException('Mail send failed: ' . $mail->ErrorInfo, 0, $e);
    }
}

function _mailer_normalize_recipients($input): array {
    if (!$input) return [];
    // Accept: "a@b.com" | ["a@b.com","name"] | [["a@b","n1"], "c@d"]
    if (is_string($input))  return [[$input, '']];
    if (is_array($input) && isset($input[0]) && is_string($input[0]) && isset($input[1]) && is_string($input[1])
        && strpos($input[0], '@') !== false && strpos($input[1], '@') === false) {
        return [[$input[0], $input[1]]];
    }
    $out = [];
    foreach ((array) $input as $r) {
        if (is_string($r)) $out[] = [$r, ''];
        elseif (is_array($r) && isset($r[0])) $out[] = [$r[0], $r[1] ?? ''];
    }
    return $out;
}

/**
 * mailerSend — global delivery shim used by CFO reports, timesheet approver
 * notices, vendor portal invites, AP bill approvals, and Mercury payment
 * alerts. Was previously undefined (calls silently no-op'd). Now routes
 * every message through Core\MailService so:
 *
 *   - When RESEND_API_KEY is set (env var OR define() in config.local.php),
 *     ResendDriver delivers via Resend's transactional API + writes a row
 *     to mail_outbox for audit.
 *   - When the key is missing, LogDriver captures the envelope and
 *     mail_outbox still records the attempt. No exceptions raised.
 *   - If MailService can't be bootstrapped (missing tenant context, DB
 *     down, etc.), falls back to sendEmail() which uses the legacy
 *     PHPMailer/SMTP transport — same delivery either way.
 *
 * Call shape (unchanged from existing call sites):
 *   mailerSend([
 *     'to'        => 'user@example.com'  | [emails],
 *     'subject'   => 'Hello',
 *     'body_html' => '<p>...</p>',       // optional
 *     'body_text' => 'plain fallback',   // optional
 *     'reply_to'  => 'replies@x.com',    // optional
 *     'tenant_id' => 42,                 // optional — auto-derived if absent
 *     'module'    => 'cfo',              // optional — defaults to 'core'
 *     'purpose'   => 'cfo_report',       // optional — defaults to 'notification'
 *   ]);
 *
 * Returns ['ok' => true,  'message_id' => string|null, 'driver' => string]
 *      or ['ok' => false, 'error' => string,           'driver' => string].
 * Does NOT throw — preserves the existing try/catch contract at call sites
 * which expect mailer failures to be soft.
 */
if (!function_exists('mailerSend')) {
    function mailerSend(array $args): array {
        if (empty($args['to']))      throw new InvalidArgumentException('mailerSend: to is required');
        if (empty($args['subject'])) throw new InvalidArgumentException('mailerSend: subject is required');
        if (empty($args['body_html']) && empty($args['body_text'])) {
            throw new InvalidArgumentException('mailerSend: body_html or body_text is required');
        }

        // Flatten recipients to plain email strings — MailService validates each.
        $toList = [];
        foreach (_mailer_normalize_recipients($args['to']) as [$e, $_n]) {
            if ($e !== '') $toList[] = $e;
        }
        if (!$toList) throw new InvalidArgumentException('mailerSend: no valid recipients');

        $subject  = (string) $args['subject'];
        $bodyHtml = isset($args['body_html']) ? (string) $args['body_html'] : null;
        $bodyText = (string) ($args['body_text'] ?? _mailer_html_to_text($bodyHtml ?? $subject));

        // Pull tenant from caller hint → session → 0. MailService::send
        // requires tenant_id > 0; if we have nothing, fall back to PHPMailer.
        $tenantId = (int) ($args['tenant_id'] ?? 0);
        if ($tenantId <= 0 && function_exists('currentTenantId')) {
            try { $tenantId = (int) (currentTenantId() ?? 0); } catch (\Throwable $_) {}
        }

        // Tenant-scoped recipient suppression — Resend bounces /
        // complaints + manual admin entries get filtered here. If every
        // recipient ends up suppressed we short-circuit with a soft
        // failure so the outbox doesn't accumulate ghost sends.
        $suppressedDrops = [];
        if ($tenantId > 0) {
            $filter = cf_mail_filter_suppressed($tenantId, $toList);
            if (!empty($filter['suppressed'])) {
                $suppressedDrops = $filter['suppressed'];
                $toList          = $filter['delivered'];
            }
        }
        if (!$toList) {
            return [
                'ok'         => false,
                'driver'     => 'suppressed',
                'error'      => 'all_recipients_suppressed',
                'suppressed' => $suppressedDrops,
            ];
        }

        // Resolve per-purpose sender (display name + reply-to + enabled mute).
        // Caller-supplied `from_name`/`reply_to` always win — the resolver only
        // fills in when caller didn't specify. `enabled=false` short-circuits
        // delivery for that purpose with a soft failure so cron loops don't
        // crash and the outbox stays consistent.
        $purposeKey = (string) ($args['purpose'] ?? 'core');
        $sender     = cf_tenant_mail_sender($tenantId, $purposeKey);
        if (!($sender['enabled'] ?? true)) {
            return [
                'ok'     => false,
                'driver' => 'disabled',
                'error'  => 'purpose_disabled',
                'reason' => $purposeKey,
            ];
        }
        if (empty($args['from_name']) && !empty($sender['from_name'])) {
            $args['from_name'] = $sender['from_name'];
        }
        if (empty($args['reply_to']) && !empty($sender['reply_to'])) {
            $args['reply_to'] = $sender['reply_to'];
        }

        require_once __DIR__ . '/mail_bootstrap.php';
        try {
            $svc = cf_mail_bootstrap();
        } catch (\Throwable $e) {
            // MailService can't boot — fall back to legacy SMTP.
            return _mailer_fallback_smtp($args, $toList, $bodyHtml, $bodyText, 'bootstrap_failed:' . $e->getMessage());
        }

        if ($tenantId <= 0) {
            // No tenant context from the caller AND no session — common
            // for cron jobs, webhooks, and public auth flows (e.g.
            // forgot_password if it forgot to pass tenant_id). Falling
            // back to legacy PHPMailer SMTP here is what caused the
            // "Resend never sent a real email" regression in Feb-2026:
            // production SMTP creds aren't valid, so emails went to
            // /dev/null silently.
            //
            // Instead, resolve a SYSTEM tenant — the first active row in
            // `tenants`. Resend doesn't care about tenant scoping, but
            // MailService::send needs a positive tenant_id for outbox
            // tracking + suppression. This keeps every email routing
            // through the configured Resend driver.
            try {
                $pdo = function_exists('getDB') ? getDB() : null;
                if ($pdo) {
                    $sysTid = (int) $pdo->query(
                        'SELECT id FROM tenants WHERE COALESCE(is_active,1) = 1 ORDER BY id ASC LIMIT 1'
                    )->fetchColumn();
                    if ($sysTid > 0) $tenantId = $sysTid;
                }
            } catch (\Throwable $_) { /* fall through */ }
            if ($tenantId <= 0) {
                // Last-resort: SMTP. Logs the reason so we can audit
                // any leak past the system-tenant fallback.
                return _mailer_fallback_smtp($args, $toList, $bodyHtml, $bodyText, 'no_tenant_context_no_system_tenant');
            }
        }

        try {
            $res = $svc->send(
                $tenantId,
                (string) ($args['module']  ?? 'core'),
                (string) ($args['purpose'] ?? 'notification'),
                $toList,
                $subject,
                $bodyText,
                $bodyHtml,
                [],
                array_filter([
                    'from'      => $args['from_email']  ?? null,
                    'from_name' => $args['from_name']   ?? null,
                    'reply_to'  => $args['reply_to']    ?? null,
                ], static fn($v) => $v !== null && $v !== '')
            );
            if (($res['status'] ?? '') === 'sent') {
                return [
                    'ok'         => true,
                    'message_id' => $res['provider_message_id'] ?? null,
                    'driver'     => $res['driver'] ?? 'unknown',
                    'suppressed' => $suppressedDrops,
                ];
            }
            return [
                'ok'         => false,
                'error'      => (string) ($res['error'] ?? 'unknown send failure'),
                'driver'     => $res['driver'] ?? 'unknown',
                'suppressed' => $suppressedDrops,
            ];
        } catch (\Throwable $e) {
            return _mailer_fallback_smtp($args, $toList, $bodyHtml, $bodyText, 'mail_service_threw:' . $e->getMessage());
        }
    }
}

/** Internal: strip HTML to a serviceable plaintext when caller omits body_text. */
function _mailer_html_to_text(?string $html): string {
    if (!$html) return '';
    $text = strip_tags(preg_replace('/<\s*br\s*\/?\s*>/i', "\n", (string) $html) ?? '');
    return trim(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Internal: legacy PHPMailer fallback when MailService can't deliver. */
function _mailer_fallback_smtp(array $args, array $toList, ?string $bodyHtml, string $bodyText, string $reason): array {
    try {
        $res = sendEmail([
            'to'         => $toList,
            'subject'    => (string) $args['subject'],
            'body_text'  => $bodyText,
            'body_html'  => $bodyHtml,
            'reply_to'   => $args['reply_to']   ?? null,
            'from_email' => $args['from_email'] ?? null,
            'from_name'  => $args['from_name']  ?? null,
        ]);
        return [
            'ok'         => (bool) ($res['ok'] ?? false),
            'message_id' => $res['message_id'] ?? null,
            'driver'     => 'phpmailer_smtp',
            'fallback'   => $reason,
        ];
    } catch (\Throwable $e) {
        return [
            'ok'       => false,
            'error'    => $e->getMessage(),
            'driver'   => 'phpmailer_smtp',
            'fallback' => $reason,
        ];
    }
}
