<?php
/** Display only a validated callback destination for this installation. */
declare(strict_types=1);

function purepayCallbackUrl(int $tenantId, string $origin, bool $staging): ?string
{
    if ($tenantId <= 0) return null;
    $origin = rtrim(trim($origin), '/');
    if ($origin === '') {
        if ($staging) return null;
        $origin = 'https://www.corefluxapp.com';
    }
    $parts = parse_url($origin);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))
        || preg_match('/[\x00-\x20\x7f]/', $origin)) return null;
    $host = strtolower($parts['host']);
    if ($staging && !preg_match('/^phpstack-\d+-\d+\.cloudwaysapps\.com$/D', $host)
        && !in_array($host, ['stage.corefluxapp.com', 'staging.corefluxapp.com'], true)) return null;
    return $origin . '/api/webhooks/purepay.php?t=' . $tenantId;
}
