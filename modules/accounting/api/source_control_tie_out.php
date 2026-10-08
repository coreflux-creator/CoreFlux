<?php
/** Read-only, entity-specific AR/AP subledger-to-control-account comparison. */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/source_control_tie_out.php';

$ctx = api_require_auth();
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($ctx['user'], 'accounting.reports.view');
$format = $_GET['format'] ?? 'json';
if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) {
    api_error('Choose JSON or CSV format.', 422);
}
if ($format === 'csv') rbac_legacy_require($ctx['user'], 'accounting.reports.export');

try {
    $rawEntityId = $_GET['entity_id'] ?? null;
    if ($rawEntityId !== null && !is_scalar($rawEntityId)) {
        api_error('Choose one legal entity for this comparison.', 422);
    }
    $entityId = accountingValidateActiveEntityId((int) $ctx['tenant_id'], $rawEntityId);
    if ($entityId === null) api_error('Choose one legal entity for this comparison.', 422);
    $asOf = $_GET['as_of'] ?? date('Y-m-d');
    if (!is_string($asOf)) api_error('Choose a valid as-of date.', 422);
    $report = accountingSourceControlTieOut((int) $ctx['tenant_id'], $entityId, $asOf);
    if ($format === 'json') api_ok($report);

    $safeText = static function (string $value): string {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    };
    $out = fopen('php://temp/maxmemory:1048576', 'w+');
    if (!$out) throw new \RuntimeException('Could not prepare the CSV.');
    try {
        fputcsv($out, ['entity_code', 'as_of', 'currency', 'account_code', 'control_account',
            'open_documents', 'posted_gl', 'difference_gl_minus_documents', 'matched',
            'foreign_currencies', 'posted_ledger_sources'], ',', '"', '');
        foreach ($report['controls'] as $control) {
            $sources = array_map(static fn (array $source): string =>
                $source['module'] . ': ' . number_format($source['net'], 2, '.', '')
                . ' (' . $source['journal_count'] . ' journals)', $control['sources']);
            fputcsv($out, [$safeText($report['entity_code']), $report['as_of'],
                $report['base_currency'], $control['account_code'], $safeText($control['label']),
                number_format($control['source_due'], 2, '.', ''),
                number_format($control['gl_balance'], 2, '.', ''),
                number_format($control['difference'], 2, '.', ''),
                $control['matched'] && !$report['foreign_currencies'] && $report['has_activity'] ? 'yes' : 'no',
                $safeText(implode('; ', $report['foreign_currencies'])),
                $safeText(implode('; ', $sources))], ',', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        if ($csv === false) throw new \RuntimeException('Could not read the CSV.');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="accounting-ar-ap-tie-out-'
            . $entityId . '-' . $asOf . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
    } finally {
        fclose($out);
    }
    exit;
} catch (\InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('[accounting.source_control_tie_out] ' . $e->getMessage());
    api_error('Unable to compare subledgers with the ledger right now.', 500);
}
