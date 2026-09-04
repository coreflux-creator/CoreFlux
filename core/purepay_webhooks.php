<?php
/** Signed Pure//Pay webhook verification and lifecycle projection. */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/purepay_service.php';

function purepayWebhookVerify(string $secret, string $timestamp, string $rawBody, string $signature, int $toleranceSeconds = 300): array
{
    if ($secret === '') return ['ok'=>false,'error'=>'secret_missing','timestamp'=>null];
    if (!preg_match('/^\d{9,13}$/', $timestamp)) return ['ok'=>false,'error'=>'timestamp_invalid','timestamp'=>null];
    $ts = (int) $timestamp;
    if ($ts > 9999999999) $ts = (int) floor($ts / 1000);
    if (abs(time() - $ts) > $toleranceSeconds) return ['ok'=>false,'error'=>'timestamp_stale','timestamp'=>$ts];
    if (!str_starts_with($signature, 'sha256=')) return ['ok'=>false,'error'=>'signature_format','timestamp'=>$ts];
    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return [
        'ok' => hash_equals($expected, trim($signature)),
        'error' => hash_equals($expected, trim($signature)) ? null : 'signature_mismatch',
        'timestamp' => $ts,
    ];
}

function purepayWebhookRecent(int $tenantId, int $limit = 30): array
{
    try {
        $stmt = getDB()->prepare(
            'SELECT event_id, event_type, verified, verify_error, signature_timestamp,
                    processed_at, processing_error, created_at
               FROM purepay_webhook_events WHERE tenant_id=:t
              ORDER BY id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['t'=>$tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) { return []; }
}

function purepayWebhookRecord(int $tenantId, string $eventId, ?string $eventType, bool $verified,
    ?string $verifyError, ?int $signatureTs, ?array $payload, string $rawBody): bool
{
    $stmt = getDB()->prepare(
        'INSERT IGNORE INTO purepay_webhook_events
            (tenant_id,event_id,event_type,verified,verify_error,signature_timestamp,payload_json,raw_body)
         VALUES (:t,:id,:ty,:v,:e,:ts,:p,:r)'
    );
    $stmt->execute([
        't'=>$tenantId,'id'=>$eventId,'ty'=>$eventType,'v'=>$verified ? 1 : 0,
        'e'=>$verifyError,'ts'=>$signatureTs,
        'p'=>$payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
        'r'=>substr($rawBody, 0, 1048576),
    ]);
    return $stmt->rowCount() > 0;
}

function purepayWebhookProcess(int $tenantId, string $eventId, string $eventType, array $payload): array
{
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    $billId = (string) ($data['bill_id'] ?? $data['payable_id'] ?? '');
    $paymentId = (string) ($data['payment_id'] ?? '');
    $remoteStatus = (string) ($data['pay_status'] ?? $data['status'] ?? '');
    if ($remoteStatus === '') {
        $remoteStatus = $eventType === 'payment.settled' ? 'settled'
            : ($eventType === 'payment.failed' ? 'failed' : 'pending');
    }
    $pdo = getDB();
    $where = [];
    $params = ['t'=>$tenantId];
    if ($paymentId !== '') { $where[] = 'purepay_payment_id=:p'; $params['p']=$paymentId; }
    if ($billId !== '') { $where[] = 'purepay_bill_id=:b'; $params['b']=$billId; }
    if (!$where) return ['outcome'=>'ignored','reason'=>'no_bill_or_payment_id'];
    $stmt = $pdo->prepare(
        'SELECT * FROM purepay_payment_links WHERE tenant_id=:t AND (' . implode(' OR ', $where) . ') ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute($params);
    $link = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$link) return ['outcome'=>'unmatched','bill_id'=>$billId ?: null,'payment_id'=>$paymentId ?: null];

    $pdo->prepare(
        'UPDATE purepay_payment_links SET purepay_payment_id=COALESCE(NULLIF(:p,""),purepay_payment_id),
                status=:s, response_json=:j, last_error=NULL, last_error_json=NULL,
                last_synced_at=NOW(), updated_at=NOW() WHERE tenant_id=:t AND id=:id'
    )->execute([
        'p'=>$paymentId,'s'=>$remoteStatus,'j'=>json_encode($payload, JSON_UNESCAPED_SLASHES),
        't'=>$tenantId,'id'=>$link['id'],
    ]);

    $corePaymentId = (int) ($link['core_payment_id'] ?? 0);
    if ($corePaymentId > 0) {
        $canonical = strtolower($remoteStatus);
        $settled = in_array($canonical, ['settled','paid','completed','cleared'], true);
        $pdo->prepare(
            'UPDATE ap_payments SET rail_status=:s,
                    status=CASE WHEN :settled=1 AND status="sent" THEN "cleared" ELSE status END,
                    cleared_at=CASE WHEN :settled2=1 AND cleared_at IS NULL THEN NOW() ELSE cleared_at END
              WHERE tenant_id=:t AND id=:id AND disbursement_rail="purepay"'
        )->execute(['s'=>$remoteStatus,'settled'=>$settled?1:0,'settled2'=>$settled?1:0,'t'=>$tenantId,'id'=>$corePaymentId]);
        try {
            $pdo->prepare(
                'INSERT INTO audit_log (tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at)
                 VALUES (:t,NULL,:e,:id,:m,:ip,NOW())'
            )->execute([
                't'=>$tenantId,'e'=>'purepay.webhook.' . $eventType,'id'=>$corePaymentId,
                'm'=>json_encode(['event_id'=>$eventId,'bill_id'=>$billId,'payment_id'=>$paymentId,'status'=>$remoteStatus]),
                'ip'=>$_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $_) {}
    }
    return ['outcome'=>'updated','source_ref'=>$link['source_ref'],'status'=>$remoteStatus];
}

function purepayWebhookMarkProcessed(int $tenantId, string $eventId, ?string $error = null): void
{
    getDB()->prepare(
        'UPDATE purepay_webhook_events SET processed_at=NOW(), processing_error=:e
          WHERE tenant_id=:t AND event_id=:id'
    )->execute(['e'=>$error ? substr($error,0,500) : null,'t'=>$tenantId,'id'=>$eventId]);
}

