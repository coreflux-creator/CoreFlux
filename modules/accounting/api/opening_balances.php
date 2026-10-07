<?php
/** First-run, entity-scoped opening balance preview and posting. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/opening_balances.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$action = (string) ($_GET['action'] ?? '');
rbac_legacy_require($user, 'accounting.ledger.import');
rbac_legacy_require($user, 'accounting.entities.manage');

if (api_method() === 'GET' && $action === 'template') {
    accountingOpeningRegisterSchema();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="accounting-opening-balances.csv"');
    echo Core\CsvImportService::buildTemplate('accounting_opening_balances');
    exit;
}
if (api_method() !== 'POST' || !in_array($action, ['preview', 'commit'], true)) {
    api_error('Use template, preview, or commit.', 405);
}
$body = api_json_body();
$entityId = (int) ($body['entity_id'] ?? 0);
$csv = (string) ($body['csv'] ?? '');
if ($entityId <= 0 || trim($csv) === '') api_error('Choose a legal entity and provide a CSV.', 422);
try {
    if ($action === 'preview') {
        api_ok(accountingOpeningReview(getDB(), $tenantId, $entityId, $csv));
    }
    rbac_legacy_require($user, 'accounting.je.post');
    $result = accountingOpeningCommit(getDB(), $tenantId, $entityId, $csv,
        (string) ($body['preview_token'] ?? ''), (int) ($user['id'] ?? 0) ?: null);
    if (!$result['idempotent_replay']) {
        accountingAudit('accounting.opening_balances.posted', [
            'entity_id' => $entityId, 'journal_entry_id' => $result['journal_entry_id'],
        ], $result['journal_entry_id']);
    }
    api_ok($result, $result['idempotent_replay'] ? 200 : 201);
} catch (AccountingOpeningConflict $error) {
    api_error($error->getMessage(), 409);
} catch (InvalidArgumentException $error) {
    api_error($error->getMessage(), 422);
}
