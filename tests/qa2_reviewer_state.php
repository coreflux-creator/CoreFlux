<?php
/** Read-only maker/checker evidence for the disposable CoreAccounting QA app. */
declare(strict_types=1);

const QA_ROOT = '/home/1516771.cloudwaysapps.com/aqdcpvafpj/public_html';
const QA_CONFIG = '/home/master/.coreaccounting-cleanqa2/db.local.php';
const QA_DATABASE = 'aqdcpvafpj';

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !in_array('--confirm-disposable-qa', $argv, true)
    || realpath(QA_ROOT) !== QA_ROOT
    || getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH') !== QA_CONFIG) {
    fwrite(STDERR, "Disposable QA CLI with its private database configuration only.\n");
    exit(2);
}

require_once QA_ROOT . '/core/db.php';
if (DB_NAME !== QA_DATABASE) {
    throw new RuntimeException('Configured database is not the disposable QA database.');
}

$pdo = getDB();
$pdo->exec('START TRANSACTION READ ONLY');
try {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== QA_DATABASE) {
        throw new RuntimeException('Connected database is not the disposable QA database.');
    }

    $members = $pdo->query(
        'SELECT u.id, u.is_active, ut.role, ut.status
           FROM users u
           JOIN user_tenants ut ON ut.user_id = u.id
          WHERE ut.tenant_id = 1 AND u.id IN (1, 10)
          ORDER BY u.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $invoice = $pdo->query(
        'SELECT tenant_id, created_by_user_id, approved_by_user_id, journal_entry_id
           FROM billing_invoices WHERE id = 2'
    )->fetch(PDO::FETCH_ASSOC);
    $bill = $pdo->query(
        'SELECT tenant_id, created_by_user_id, approved_by_user_id, journal_entry_id
           FROM ap_bills WHERE id = 3'
    )->fetch(PDO::FETCH_ASSOC);

    $activeIds = [];
    foreach ($members as $member) {
        if ((int) $member['is_active'] === 1 && $member['status'] === 'active'
            && in_array($member['role'], ['tenant_admin', 'admin', 'master_admin'], true)) {
            $activeIds[] = (int) $member['id'];
        }
    }
    sort($activeIds, SORT_NUMERIC);
    $separateApproval = static fn($document): bool => is_array($document)
        && (int) $document['tenant_id'] === 1
        && (int) $document['created_by_user_id'] === 1
        && (int) $document['approved_by_user_id'] === 10
        && (int) $document['journal_entry_id'] > 0;
    $result = [
        'database' => QA_DATABASE,
        'active_qa_administrators' => $activeIds,
        'invoice_maker_checker_posted' => $separateApproval($invoice),
        'bill_maker_checker_posted' => $separateApproval($bill),
    ];
    $pdo->rollBack();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit(($activeIds === [1, 10] && $result['invoice_maker_checker_posted']
        && $result['bill_maker_checker_posted']) ? 0 : 1);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
