<?php
/** Validation for the isolated Meridian callback acceptance fixture. */
declare(strict_types=1);

function coreDemoCallbackCanonical(mixed $value): string
{
    $sort = static function(mixed $item) use (&$sort): mixed {
        if (!is_array($item)) return $item;
        if (!array_is_list($item)) ksort($item, SORT_STRING);
        return array_map($sort, $item);
    };
    return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function coreDemoCallbackPlan(array $plan, int $now, bool $requireLive = true): array
{
    $review = $plan['review'] ?? null;
    $fail = static function(): never { throw new DomainException('The exact reviewed synthetic callback plan is required'); };
    if (!is_array($review) || ($review['organization_id'] ?? null) !== 'org_purepay_demo'
        || ($review['callback_url'] ?? null) !== 'https://phpstack-1516771-6707602.cloudwaysapps.com/api/webhooks/purepay.php?t=999'
        || ($review['event_type'] ?? null) !== 'payment.settled' || ($review['amount'] ?? null) !== '1.00'
        || ($review['currency'] ?? null) !== 'USD' || !is_int($plan['created_at'] ?? null)
        || !is_int($review['expires_at'] ?? null) || $review['expires_at'] !== $plan['created_at'] + 600
        || ($requireLive && ($now < $plan['created_at'] || $now >= $review['expires_at']))) $fail();
    foreach (['payment_id'=>'/^core_demo_callback_[a-f0-9]{32}$/D', 'endpoint_id'=>'/^wh_[a-f0-9]{12}$/D',
              'event_id'=>'/^evt_pmt_[a-f0-9]{64}$/D', 'body_sha256'=>'/^[a-f0-9]{64}$/D',
              'endpoint_sha256'=>'/^[a-f0-9]{64}$/D'] as $key=>$pattern) {
        if (!is_string($review[$key] ?? null) || !preg_match($pattern, $review[$key])) $fail();
    }
    if (($review['bill_id'] ?? null) !== 'bill_'.$review['payment_id']
        || !is_string($plan['database'] ?? null) || !preg_match('/^core_hosted_callback_[a-f0-9]{32}$/D', $plan['database'])
        || !is_string($plan['owner'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $plan['owner'])
        || ($plan['source_manifest']['commit'] ?? null) !== 'ae69ba56a3620bcef385b2d148b73a1f838a74ca') $fail();
    $data = ['bill_id'=>$review['bill_id'], 'payment_id'=>$review['payment_id'],
             'pay_status'=>'settled', 'amount'=>'1.00', 'currency'=>'USD'];
    $event = 'evt_pmt_'.hash('sha256',coreDemoCallbackCanonical(['org_purepay_demo',$review['endpoint_id'],'payment.settled',$data]));
    $body = coreDemoCallbackCanonical(['id'=>$event,'type'=>'payment.settled','data'=>$data]);
    if (!hash_equals($event,$review['event_id']) || !hash_equals(hash('sha256',$body),$review['body_sha256'])) $fail();
    return $review;
}

function coreDemoCallbackEndpointHash(string $id, string $secret): string
{
    if (!preg_match('/^wh_[a-f0-9]{12}$/D',$id) || !str_starts_with($secret,'whsec_') || strlen($secret)<20) {
        throw new DomainException('An existing reviewed callback subscription is required');
    }
    $events=['payment.settled','payment.failed','payment.returned','payment.cancelled'];sort($events,SORT_STRING);
    return hash('sha256',coreDemoCallbackCanonical(['id'=>$id,'organization_id'=>'org_purepay_demo','active'=>true,
        'url'=>'https://phpstack-1516771-6707602.cloudwaysapps.com/api/webhooks/purepay.php?t=999',
        'events'=>$events,'secret_digest'=>hash('sha256',$secret)]));
}
