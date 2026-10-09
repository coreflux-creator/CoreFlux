<?php
/** Hosted expiry acceptance for the disposable CoreAccounting QA 2 app only. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || !in_array('--confirm-disposable-qa', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting QA 2 CLI only.\n");
    exit(2);
}

const QA_WEBROOT = '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html';
const QA_DATABASE = 'aqdcpvafpj';
const QA_ORIGIN = 'https://phpstack-1516771-6717961.cloudwaysapps.com';
const QA_INVOICE_ID = 2;

if (realpath(QA_WEBROOT) !== QA_WEBROOT) {
    throw new RuntimeException('The pinned disposable QA webroot is unavailable.');
}
require_once QA_WEBROOT . '/core/db.php';
require_once QA_WEBROOT . '/modules/billing/lib/billing.php';

if (DB_NAME !== QA_DATABASE || APP_URL !== QA_ORIGIN) {
    throw new RuntimeException('Refusing a non-QA-2 database or public origin.');
}
$pdo = getDB();
if (!$pdo) throw new RuntimeException('Disposable QA database is unavailable.');
$invoiceStmt = $pdo->prepare('SELECT id, tenant_id, invoice_number, total, client_name, status
                                FROM billing_invoices WHERE id = :id');
$invoiceStmt->execute(['id' => QA_INVOICE_ID]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
if (in_array('--inspect', $argv, true)) {
    echo json_encode($invoice ? [
        'id' => (int) $invoice['id'],
        'tenant_id' => (int) $invoice['tenant_id'],
        'invoice_number' => $invoice['invoice_number'],
        'total' => $invoice['total'],
        'status' => $invoice['status'],
        'qa_client' => stripos((string) $invoice['client_name'], 'qa') !== false,
    ] : ['invoice_found' => false], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}
if (!$invoice || (int) $invoice['tenant_id'] !== 1
    || $invoice['invoice_number'] !== 'INV-2026-0001'
    || (int) round((float) $invoice['total'] * 100) !== 2500
    || stripos((string) $invoice['client_name'], 'qa') === false
    || !in_array($invoice['status'], ['approved', 'sent', 'partially_paid', 'paid'], true)) {
    throw new RuntimeException('The pinned synthetic invoice is absent or changed.');
}

function qaInvoiceLinkGet(string $url): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Could not initialize QA HTTP check.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    try {
        $response = curl_exec($curl);
        if ($response === false) throw new RuntimeException('QA HTTP check failed.');
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return [$status, substr($response, 0, $headerSize), substr($response, $headerSize)];
    } finally {
        curl_close($curl);
    }
}

$tokenId = 0;
try {
    $issued = billingIssueViewToken(1, QA_INVOICE_ID, 1);
    $tokenId = (int) $issued['token_id'];
    $tokenParams = ['id' => $tokenId, 'invoice_id' => QA_INVOICE_ID];
    $tokenRow = $pdo->prepare('SELECT view_count, expires_at, revoked_at,
                                    expires_at <= NOW() AS is_expired
                                 FROM billing_invoice_tokens
                                WHERE id = :id AND tenant_id = 1 AND invoice_id = :invoice_id');
    [$liveStatus, $liveHeaders, $liveBody] = qaInvoiceLinkGet($issued['url']);
    if ($liveStatus !== 200 || !str_contains($liveBody, 'data-testid="billing-invoice-view"')
        || !str_contains($liveBody, 'INV-2026-0001')
        || stripos($liveHeaders, 'no-store') === false
        || stripos($liveHeaders, 'no-referrer') === false) {
        throw new RuntimeException('A fresh QA invoice link did not open securely.');
    }
    $tokenRow->execute($tokenParams);
    $afterLive = $tokenRow->fetch(PDO::FETCH_ASSOC);
    if (!$afterLive || (int) $afterLive['view_count'] !== 1 || $afterLive['revoked_at'] !== null) {
        throw new RuntimeException('QA link view state was not recorded once.');
    }

    $expire = $pdo->prepare('UPDATE billing_invoice_tokens
                               SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
                             WHERE id = :id AND tenant_id = 1 AND invoice_id = :invoice_id');
    $expire->execute($tokenParams);
    if ($expire->rowCount() !== 1 || billingTokenFindByRaw($issued['token']) !== null) {
        throw new RuntimeException('Expired QA token remained valid in the source lookup.');
    }

    [$expiredStatus, $expiredHeaders, $expiredBody] = qaInvoiceLinkGet($issued['url']);
    $tokenRow->execute($tokenParams);
    $afterExpired = $tokenRow->fetch(PDO::FETCH_ASSOC);
    if ($expiredStatus !== 404
        || !str_contains($expiredBody, 'data-testid="billing-invoice-not-found"')
        || stripos($expiredHeaders, 'no-store') === false
        || !$afterExpired || (int) $afterExpired['view_count'] !== 1) {
        throw new RuntimeException('Expired QA invoice link was not rejected without a new view.');
    }
    $result = [
        'invoice_id' => QA_INVOICE_ID,
        'token_id' => $tokenId,
        'fresh_http' => $liveStatus,
        'expired_http' => $expiredStatus,
        'views_after_expiry' => (int) $afterExpired['view_count'],
    ];
} finally {
    if ($tokenId > 0) {
        $revoke = $pdo->prepare('UPDATE billing_invoice_tokens SET revoked_at = COALESCE(revoked_at, NOW())
                                  WHERE id = :id AND tenant_id = 1 AND invoice_id = :invoice_id');
        $revoke->execute($tokenParams);
    }
}
$tokenRow->execute($tokenParams);
$final = $tokenRow->fetch(PDO::FETCH_ASSOC);
if (!$final || (int) $final['view_count'] !== 1
    || (int) $final['is_expired'] !== 1 || $final['revoked_at'] === null) {
    throw new RuntimeException('QA link cleanup did not leave an expired, revoked token.');
}
$result['revoked_cleanup'] = true;
echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
