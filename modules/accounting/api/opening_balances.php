<?php
/** First-run, entity-scoped opening balance preview and posting. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/opening_balances.php';
require_once __DIR__ . '/../lib/opening_cutover.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$action = (string) ($_GET['action'] ?? '');
rbac_legacy_require($user, 'accounting.ledger.import');
rbac_legacy_require($user, 'accounting.entities.manage');

if (api_method() === 'GET' && $action === 'template') {
    $kind = (string) ($_GET['kind'] ?? 'balances');
    accountingOpeningRegisterSchema();
    accountingOpeningDocumentRegisterSchemas();
    $schema = match ($kind) {
        'balances' => 'accounting_opening_balances',
        'ar' => 'accounting_open_ar',
        'ap' => 'accounting_open_ap',
        default => null,
    };
    if ($schema === null) api_error('Choose balances, ar, or ap.', 422);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="accounting-opening-' . $kind . '.csv"');
    echo Core\CsvImportService::buildTemplate($schema);
    exit;
}
if (api_method() !== 'POST' || !in_array($action, ['preview', 'commit'], true)) {
    api_error('Use template, preview, or commit.', 405);
}
$body = api_json_body();
$entityId = (int) ($body['entity_id'] ?? 0);
$csv = (string) ($body['csv'] ?? '');
$arCsv = (string) ($body['ar_csv'] ?? '');
$apCsv = (string) ($body['ap_csv'] ?? '');
if ($entityId <= 0 || trim($csv) === '') api_error('Choose a legal entity and provide a CSV.', 422);
try {
    if ($action === 'preview') {
        api_ok(trim($arCsv) !== '' || trim($apCsv) !== ''
            ? accountingOpeningCutoverReview(getDB(), $tenantId, $entityId, $csv, $arCsv, $apCsv)
            : accountingOpeningReview(getDB(), $tenantId, $entityId, $csv));
    }
    rbac_legacy_require($user, 'accounting.je.post');
    $sourceDocuments = trim($arCsv) !== '' || trim($apCsv) !== '';
    $result = $sourceDocuments
        ? accountingOpeningCutoverCommit(getDB(), $tenantId, $entityId, $csv, $arCsv, $apCsv,
            (string) ($body['preview_token'] ?? ''), (int) ($user['id'] ?? 0) ?: null)
        : accountingOpeningCommit(getDB(), $tenantId, $entityId, $csv,
            (string) ($body['preview_token'] ?? ''), (int) ($user['id'] ?? 0) ?: null);
    if (!$result['idempotent_replay']) {
        accountingAudit($sourceDocuments ? 'accounting.opening_documents.posted' : 'accounting.opening_balances.posted', [
            'entity_id' => $entityId, 'journal_entry_id' => $result['journal_entry_id'],
            'cutover_id' => $result['cutover_id'] ?? null,
            'ar_count' => $result['ar_count'] ?? 0, 'ap_count' => $result['ap_count'] ?? 0,
        ], $result['journal_entry_id']);
    }
    api_ok($result, $result['idempotent_replay'] ? 200 : 201);
} catch (AccountingOpeningConflict $error) {
    api_error($error->getMessage(), 409);
} catch (InvalidArgumentException $error) {
    api_error($error->getMessage(), 422);
}
