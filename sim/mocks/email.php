<?php
/**
 * Email mock — captures every email that would have been sent by the
 * production mailer.sendEmail() into the in-memory call log so
 * assertions can verify "exactly N emails sent" or inspect content.
 *
 * Never actually delivers — that's the whole point.
 */
declare(strict_types=1);

require_once __DIR__ . '/manager.php';

function simMockSendEmail(array $args): array {
    if (!simShouldMock('resend') && !simShouldMock('email')) {
        throw new \RuntimeException('email mock not enabled');
    }
    if (($f = simMockConsumeFault('resend')) !== null) simMockApplyFault('resend', $f);

    $toList  = (array)   ($args['to']      ?? []);
    $subject = (string)  ($args['subject'] ?? '');
    $html    = (string)  ($args['html']    ?? '');
    $text    = (string)  ($args['text']    ?? '');

    $resp = [
        'ok'         => true,
        'message_id' => 'sim_msg_' . simRandId('MSG'),
        'to_count'   => count($toList),
        'subject'    => $subject,
        'preview'    => substr(strip_tags($html ?: $text), 0, 120),
        'sim'        => true,
    ];
    simMockRecordCall('resend', 'send_email', [
        'to'           => $toList,
        'subject_hash' => simHash($subject),
        'body_hash'    => simHash($html ?: $text),
    ], $resp);
    return $resp;
}

/** Convenience: return every captured email matching a subject pattern. */
function simMockEmailsBySubject(string $pattern): array {
    $out = [];
    foreach (simMockCalls('resend') as $c) {
        if ($c['op'] === 'send_email') $out[] = $c;
    }
    return $out;
}
