<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../core/db.php';

$options = getopt('', ['tenant:']);
$tenantId = (int) ($options['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/check_mail_outbox.php --tenant=ID\n");
    exit(2);
}
$pdo = getDB();
$tenant = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if ((int) $tenant->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to inspect a non-simulation tenant.\n");
    exit(3);
}

$queries = [
    'count' => 'SELECT COUNT(*) FROM mail_outbox WHERE tenant_id = :t',
    'status' => 'SELECT status, COUNT(*) AS n FROM mail_outbox WHERE tenant_id = :t GROUP BY status',
    'driver' => 'SELECT driver, COUNT(*) AS n FROM mail_outbox WHERE tenant_id = :t GROUP BY driver',
    'daily' => 'SELECT DATE(created_at) AS day, SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS sent
                  FROM mail_outbox WHERE tenant_id = :t GROUP BY DATE(created_at)',
    'purposes' => 'SELECT purpose, SUM(CASE WHEN status = "sent" THEN 1 ELSE 0 END) AS sent,
                       SUM(CASE WHEN status IN ("failed","bounced","complaint") THEN 1 ELSE 0 END) AS failed
                    FROM mail_outbox WHERE tenant_id = :t GROUP BY purpose
                    ORDER BY SUM(CASE WHEN status IN ("sent","failed","bounced","complaint")
                                      THEN 1 ELSE 0 END) DESC LIMIT 5',
    'failures' => 'SELECT id FROM mail_outbox WHERE tenant_id = :t AND status IN ("failed","bounced","complaint")
                    ORDER BY id DESC LIMIT 5',
];
$result = [];
foreach ($queries as $name => $sql) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['t' => $tenantId]);
        $result[$name] = ['ok' => true, 'rows' => count($stmt->fetchAll(PDO::FETCH_ASSOC))];
    } catch (Throwable $e) {
        $result[$name] = ['ok' => false, 'error' => $e->getMessage()];
    }
}
$last = $pdo->prepare(
    'SELECT id, driver, purpose, status, provider_message_id
       FROM mail_outbox WHERE tenant_id = :t ORDER BY id DESC LIMIT 1'
);
try {
    $last->execute(['t' => $tenantId]);
    $result['latest'] = $last->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $result['latest'] = ['error' => $e->getMessage()];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(in_array(false, array_column(array_values(array_filter($result, 'is_array')), 'ok'), true) ? 1 : 0);
