<?php
/** Hosted synthetic-only acceptance for retained close-packet versions and scope. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaClosePacketStatus(string $path, string $method, string $cookie, array $body = []): int
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
    if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("{$method} {$path}: {$error}");
    return (int) $status;
}

$actor = null;
$cookie = null;
$testTaskId = null;
$flowPeriodId = null;
try {
    $cutover = qaOne($pdo, 'SELECT c.entity_id, c.posting_date FROM accounting_opening_document_cutovers c
        JOIN accounting_entities e ON e.id = c.entity_id AND e.tenant_id = c.tenant_id
        WHERE c.tenant_id = :t AND e.code LIKE "SIM-OPEN-%"
        ORDER BY c.id DESC LIMIT 1', ['t' => QA_TENANT]);
    if (!$cutover) throw new RuntimeException('Synthetic opening cutover fixture is unavailable.');
    $entityId = (int) $cutover['entity_id'];
    $period = qaOne($pdo, 'SELECT id, status FROM accounting_periods
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
    qaExpect(qaClosePacketStatus('/modules/accounting/api/close_tasks.php?period_id=' . $unavailableId,
        'GET', $cookie) === 404, 'foreign or missing close checklist is not readable');
    qaExpect(qaClosePacketStatus('/modules/accounting/api/close_tasks.php?action=seed',
        'POST', $cookie, ['period_id' => $unavailableId]) === 404,
        'foreign or missing period cannot receive a seeded checklist');

    $flowDate = '2016-12-31';
    if (qaOne($pdo, 'SELECT id FROM accounting_periods WHERE tenant_id = :t AND entity_id = :e
        AND start_date <= :d AND end_date >= :d',
        ['t' => QA_TENANT, 'e' => $entityId, 'd' => $flowDate])) {
        throw new RuntimeException('Disposable synthetic close-period date is already in use.');
    }
    $pdo->prepare('INSERT INTO accounting_periods
        (tenant_id, entity_id, period_number, start_date, end_date, status)
        VALUES (:t, :e, 12, :d1, :d2, "open")')
        ->execute(['t' => QA_TENANT, 'e' => $entityId, 'd1' => $flowDate, 'd2' => $flowDate]);
    $flowPeriodId = (int) $pdo->lastInsertId();
    $flowPath = '/modules/accounting/api/periods.php?action=close&id=' . $flowPeriodId;
    qaRequest('/modules/accounting/api/close_tasks.php?action=seed', 'POST',
        ['period_id' => $flowPeriodId], $cookie);
    qaExpect(qaClosePacketStatus($flowPath, 'POST', $cookie) === 409,
        'open review tasks block close');
    $reviewTasks = $pdo->prepare('SELECT id FROM accounting_close_tasks
        WHERE tenant_id = :t AND period_id = :p AND task_key NOT IN ("lock_period", "build_packet")');
    $reviewTasks->execute(['t' => QA_TENANT, 'p' => $flowPeriodId]);
    $taskIds = $reviewTasks->fetchAll(PDO::FETCH_COLUMN);
    qaExpect(count($taskIds) === 7, 'close checklist has seven pre-close review tasks');
    foreach ($taskIds as $taskId) {
        qaRequest('/modules/accounting/api/close_tasks.php?action=complete&id=' . (int) $taskId,
            'POST', [], $cookie);
    }
    qaRequest($flowPath, 'POST', [], $cookie);
    $flowState = qaOne($pdo, 'SELECT status FROM accounting_periods WHERE tenant_id = :t AND id = :p',
        ['t' => QA_TENANT, 'p' => $flowPeriodId]);
    qaExpect($flowState['status'] === 'closed',
        'period closes with review complete and the two action tasks still pending');
    $closedPacket = qaRequest('/modules/accounting/api/close_packet.php?period_id=' . $flowPeriodId
        . '&action=record', 'POST', [], $cookie);
    $packetTask = qaOne($pdo, 'SELECT status FROM accounting_close_tasks
        WHERE tenant_id = :t AND period_id = :p AND task_key = "build_packet"',
        ['t' => QA_TENANT, 'p' => $flowPeriodId]);
    qaExpect((int) $closedPacket['id'] > 0 && $packetTask['status'] === 'done',
        'saving the closed packet completes the packet action task');
    qaRequest('/modules/accounting/api/periods.php?action=lock&id=' . $flowPeriodId,
        'POST', ['reason' => 'Synthetic QA close flow'], $cookie);
    $lockTask = qaOne($pdo, 'SELECT status FROM accounting_close_tasks
        WHERE tenant_id = :t AND period_id = :p AND task_key = "lock_period"',
        ['t' => QA_TENANT, 'p' => $flowPeriodId]);
    qaExpect($lockTask['status'] === 'done', 'reasoned lock completes the lock action task');
    $lockedPacket = qaRequest('/modules/accounting/api/close_packet.php?period_id=' . $flowPeriodId
        . '&action=record', 'POST', [], $cookie);
    qaExpect((int) $lockedPacket['id'] > (int) $closedPacket['id']
        && $lockedPacket['period_status'] === 'locked',
        'locked period can retain a final version without replacing the closed version');

    if (!in_array($period['status'], ['closed', 'locked'], true)) {
        qaExpect(qaClosePacketStatus($path . '&action=record', 'POST', $cookie) === 409,
            'open period can be previewed but cannot save a packet');
        qaRequest('/modules/accounting/api/periods.php?action=close&id=' . $periodId,
            'POST', [], $cookie);
    }

    $record = qaRequest($path . '&action=record', 'POST', [], $cookie);
    $packetId = (int) ($record['id'] ?? 0);
    $stored = qaOne($pdo, 'SELECT p.tenant_id, p.period_id, p.file_format,
            s.html_snapshot, s.content_sha256
        FROM accounting_close_packets p
        JOIN accounting_close_packet_snapshots s ON s.packet_id = p.id
        WHERE p.tenant_id = :t AND p.id = :id', ['t' => QA_TENANT, 'id' => $packetId]);
    qaExpect($packetId > 0 && $stored
        && (int) $stored['period_id'] === $periodId && $stored['file_format'] === 'html',
        'valid packet saves a retained version within the intended period and tenant');
    qaExpect(hash('sha256', (string) $stored['html_snapshot']) === $stored['content_sha256']
        && $record['content_sha256'] === $stored['content_sha256'],
        'saved content and returned checksum match');
    $saved = qaRequest($path . '&packet_id=' . $packetId, 'GET', null, $cookie);
    qaExpect($saved['recorded'] === true && $saved['html'] === $stored['html_snapshot'],
        'saved packet endpoint returns the exact stored HTML');
    qaExpect(qaClosePacketStatus($path . '&packet_id=2147483647', 'GET', $cookie) === 404,
        'a packet outside this period is not available');

    $taskKey = 'qa_packet_snapshot';
    $pdo->prepare('INSERT INTO accounting_close_tasks
        (tenant_id, period_id, task_key, title, description, sort_order, status)
        VALUES (:t, :p, :k, "QA packet changed after save", "Synthetic test item", 999, "done")
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), title = VALUES(title), status = VALUES(status)')
        ->execute(['t' => QA_TENANT, 'p' => $periodId, 'k' => $taskKey]);
    $testTaskId = (int) $pdo->lastInsertId();
    $liveAfter = qaRequest($path, 'GET', null, $cookie);
    $savedAfter = qaRequest($path . '&packet_id=' . $packetId, 'GET', null, $cookie);
    qaExpect(str_contains($liveAfter['html'], 'QA packet changed after save')
        && !str_contains($savedAfter['html'], 'QA packet changed after save')
        && $savedAfter['html'] === $saved['html'],
        'later checklist activity changes the preview, not the saved version');
    $newer = qaRequest($path . '&action=record', 'POST', [], $cookie);
    qaExpect((int) $newer['id'] > $packetId
        && $newer['content_sha256'] !== $record['content_sha256'],
        'another save appends a distinct version without overwriting the first');
    $versions = qaRequest($path . '&action=list', 'GET', null, $cookie);
    qaExpect((int) ($versions['rows'][0]['id'] ?? 0) === (int) $newer['id']
        && (int) ($versions['rows'][1]['id'] ?? 0) === $packetId,
        'the version list returns both saved packets newest first');
    echo "Synthetic close-packet period {$periodId}, packet {$packetId}.\n";
} finally {
    if ($flowPeriodId) {
        $check = qaOne($pdo, 'SELECT id FROM accounting_periods WHERE tenant_id = :t
            AND entity_id = :e AND id = :p AND start_date = "2016-12-31"',
            ['t' => QA_TENANT, 'e' => $entityId, 'p' => $flowPeriodId]);
        if ($check) {
            $pdo->prepare('DELETE FROM accounting_close_packet_snapshots
                WHERE tenant_id = :t AND period_id = :p')->execute(['t' => QA_TENANT, 'p' => $flowPeriodId]);
            $pdo->prepare('DELETE FROM accounting_close_packets
                WHERE tenant_id = :t AND period_id = :p')->execute(['t' => QA_TENANT, 'p' => $flowPeriodId]);
            $pdo->prepare('DELETE FROM accounting_close_tasks
                WHERE tenant_id = :t AND period_id = :p')->execute(['t' => QA_TENANT, 'p' => $flowPeriodId]);
            $pdo->prepare('DELETE FROM accounting_periods
                WHERE tenant_id = :t AND entity_id = :e AND id = :p')
                ->execute(['t' => QA_TENANT, 'e' => $entityId, 'p' => $flowPeriodId]);
        }
    }
    if ($testTaskId) {
        $pdo->prepare('DELETE FROM accounting_close_tasks WHERE id = :id AND tenant_id = :t
            AND task_key = "qa_packet_snapshot"')
            ->execute(['id' => $testTaskId, 't' => QA_TENANT]);
    }
    if ($actor) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $actor['id']]);
    }
    if ($cookie && is_file($cookie)) unlink($cookie);
}
