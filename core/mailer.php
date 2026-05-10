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

function sendEmail(array $args): array {
    if (empty($args['to']))       throw new InvalidArgumentException('sendEmail: to is required');
    if (empty($args['subject']))  throw new InvalidArgumentException('sendEmail: subject is required');
    if (empty($args['body_text']))throw new InvalidArgumentException('sendEmail: body_text is required');

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
