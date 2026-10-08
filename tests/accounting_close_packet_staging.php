<?php
/** Hosted synthetic-only acceptance for close-packet trial balance and period scope. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaClosePacketStatus(string $path, string $method, string $cookie): int
{
    $curl = curl_init(QA_BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json',
            'X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, '{}');
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("{$method} {$path}: {$error}");
    return (int) $status;
}

$actor = null;
$cookie = null;
try {
    $cutover = qaOne($pdo, 'SELECT c.entity_id, c.posting_date FROM accounting_opening_document_cutovers c
        JOIN accounting_entities e ON e.id = c.entity_id AND e.tenant_id = c.tenant_id
        WHERE c.tenant_id = :t AND e.code LIKE "SIM-OPEN-%"
        ORDER BY c.id DESC LIMIT 1', ['t' => QA_TENANT]);
    if (!$cutover) throw new RuntimeException('Synthetic opening cutover fixture is unavailable.');
    $entityId = (int) $cutover['entity_id'];
    $period = qaOne($pdo, 'SELECT id FROM accounting_periods
        WHERE tenant_id = :t AND entity_id = :e AND start_date = :d AND end_date = :d2',
        ['t' => QA_TENANT, 'e' => $entityId,
            'd' => $cutover['posting_date'], 'd2' => $cutover['posting_date']]);
    if (!$period) throw new RuntimeException('Synthetic opening period is unavailable.');
    $periodId = (int) $period['id'];

    $actor = qaEnsureActor($pdo, 'close-packet');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-close-packet-');
    if ($cookie === false) throw new RuntimeException('Could not create an isolated test session.');
    qaLogin($actor, $cookie);

    $path = '/modules/accounting/api/close_packet.php?period_id=' . $periodId;
    $packet = qaRequest($path, 'GET', null, $cookie);
    $html = (string) ($packet['html'] ?? '');
    qaExpect((int) ($packet['period_id'] ?? 0) === $periodId
        && (int) ($packet['length'] ?? 0) === strlen($html),
        'close packet is scoped to the selected synthetic period');
    qaExpect(str_contains($html, '<td>1100</td>')
        && str_contains($html, '<td>2000</td>')
        && str_contains($html, '<td>3000</td>'),
        'close packet includes posted AR, AP, and opening equity accounts');
    qaExpect(str_contains($html,
        '<tr><th>Total</th><th></th><th class="r">1,230.00</th><th class="r">1,230.00</th></tr>'),
        'close packet trial balance totals equal the posted opening journal lines');

    $foreign = qaOne($pdo, 'SELECT p.id FROM accounting_periods p
        JOIN tenants t ON t.id = p.tenant_id
        WHERE p.tenant_id <> :tenant_id AND t.is_simulation = 1 LIMIT 1',
        ['tenant_id' => QA_TENANT]);
    $unavailableId = (int) ($foreign['id'] ?? 2147483647);
    $unavailablePath = '/modules/accounting/api/close_packet.php?period_id=' . $unavailableId;
    $before = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_close_packets
        WHERE tenant_id = :t AND period_id = :p',
        ['t' => QA_TENANT, 'p' => $unavailableId])['n'];
    qaExpect(qaClosePacketStatus($unavailablePath, 'GET', $cookie) === 404,
        'foreign or missing period cannot be read as a close packet');
    qaExpect(qaClosePacketStatus($unavailablePath . '&action=record', 'POST', $cookie) === 404,
        'foreign or missing period cannot receive a packet record');
    $after = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_close_packets
        WHERE tenant_id = :t AND period_id = :p',
        ['t' => QA_TENANT, 'p' => $unavailableId])['n'];
    qaExpect($before === $after, 'rejected packet record had no database effect');

    $record = qaRequest($path . '&action=record', 'POST', [], $cookie);
    $packetId = (int) ($record['id'] ?? 0);
    $stored = qaOne($pdo, 'SELECT tenant_id, period_id, file_format FROM accounting_close_packets
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $packetId]);
    qaExpect($packetId > 0 && $stored
        && (int) $stored['period_id'] === $periodId && $stored['file_format'] === 'html',
        'valid packet record stays within its legal entity and tenant');
    echo "Synthetic close-packet period {$periodId}, packet {$packetId}.\n";
} finally {
    if ($actor) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $actor['id']]);
    }
    if ($cookie && is_file($cookie)) unlink($cookie);
}
