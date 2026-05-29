<?php
/**
 * /app/modules/treasury/api/import_csv.php
 *
 * Upload a bank statement CSV for an existing CoreFlux bank account
 * (accounting_bank_accounts). Rows are inserted into
 * accounting_bank_statement_lines under match_status='unmatched' —
 * the existing matching/reconciliation flow then picks them up
 * exactly as it does for Plaid-sourced lines.
 *
 *   POST /api/treasury/import_csv.php
 *     Content-Type: multipart/form-data
 *     bank_account_id (text, int): the CoreFlux bank account to import into
 *     file            (file):       the CSV
 *
 *   → 200 {
 *     ok, bank_account_id,
 *     rows_seen, rows_inserted, rows_duplicate, rows_skipped,
 *     date_range, errors[]
 *   }
 *
 * RBAC: accounting.bank.manage (same as the Plaid sync trigger).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/csv_import.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.bank.manage');
if (api_method() !== 'POST') api_error('POST required', 405);

$bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
if ($bankAccountId <= 0) api_error('bank_account_id (int) is required', 400);

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    api_error('csv file upload missing or failed (error code ' . (int) ($_FILES['file']['error'] ?? -1) . ')', 400);
}
$tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
if ($tmp === '' || !is_readable($tmp)) api_error('uploaded csv not readable on server', 500);
$size = (int) ($_FILES['file']['size'] ?? 0);
if ($size > 25 * 1024 * 1024) api_error('csv too large — split into chunks under 25 MB', 413);

$pdo = getDB();
$summary = treasuryImportBankCsv($pdo, $tid, $bankAccountId, $tmp);

api_ok([
    'ok'              => empty($summary['errors']) || $summary['rows_inserted'] > 0,
    'bank_account_id' => $bankAccountId,
    'rows_seen'       => (int) $summary['rows_seen'],
    'rows_inserted'   => (int) $summary['rows_inserted'],
    'rows_duplicate'  => (int) $summary['rows_duplicate'],
    'rows_skipped'    => (int) $summary['rows_skipped'],
    'date_range'      => $summary['date_range'],
    'errors'          => $summary['errors'],
]);
