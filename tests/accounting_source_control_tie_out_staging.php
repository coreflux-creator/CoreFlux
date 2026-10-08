<?php
/** Hosted, simulation-only acceptance for the AR/AP control-total report. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../modules/accounting/lib/source_control_tie_out.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

$actor = null;
$cookie = null;
$checks = [];
$expect = static function (bool $ok, string $label) use (&$checks): void {
    qaExpect($ok, $label);
    $checks[] = $label;
};

try {
    $actor = qaEnsureActor($pdo, 'source-control');
    $cookie = tempnam(sys_get_temp_dir(), 'cf-source-control-');
    qaLogin($actor, $cookie);

    $asOf = '2026-12-31';
    foreach (['SIM-CUTOVER-QA', QA_ENTITY_CODE] as $code) {
        $entity = qaOne($pdo, 'SELECT id FROM accounting_entities
            WHERE tenant_id = :t AND code = :code AND active = 1',
            ['t' => QA_TENANT, 'code' => $code]);
        $expect($entity !== null, "{$code} is available in the simulation tenant");
        $entityId = (int) $entity['id'];
        $url = '/modules/accounting/api/source_control_tie_out.php?entity_id=' . $entityId
            . '&as_of=' . $asOf;
        $http = qaRequest($url, 'GET', null, $cookie);
        $direct = accountingSourceControlTieOut(QA_TENANT, $entityId, $asOf);
        $expect($http === $direct, "{$code} HTTP and library totals use the same snapshot");
        $expect(!empty($http['matched']) && empty($http['foreign_currencies'])
            && $http['controls']['ar']['matched'] && $http['controls']['ap']['matched'],
            "{$code} posted AR/AP sources match the GL");
        $expect($http['controls']['ar']['account_code'] === '1100'
            && $http['controls']['ap']['account_code'] === '2000',
            "{$code} compares canonical control accounts");
    }

    $curl = curl_init(QA_BASE_URL . $url . '&format=csv');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_HTTPHEADER => ['X-CoreFlux-Tenant-Id: ' . QA_TENANT]]);
    $csv = curl_exec($curl);
    $csvStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $csvType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);
    $csvRows = is_string($csv) ? array_map(static fn (string $line): array =>
        str_getcsv($line, ',', '"', ''), preg_split('/\r?\n/', trim($csv))) : [];
    $expect($csvStatus === 200 && str_contains((string) $csvType, 'text/csv')
        && count($csvRows) === 3 && $csvRows[0][0] === 'entity_code'
        && $csvRows[1][3] === '1100' && $csvRows[2][3] === '2000',
        'authorized CSV contains exactly the two scoped controls');
    $expect($csvRows[1][5] === number_format($http['controls']['ar']['source_due'], 2, '.', '')
        && $csvRows[2][6] === number_format($http['controls']['ap']['gl_balance'], 2, '.', ''),
        'CSV balances agree with the displayed JSON snapshot');

    foreach (['entity_id=all', 'entity_id=999999999',
        'entity_id=' . $entityId . '&as_of=2026-02-30'] as $query) {
        try {
            qaRequest('/modules/accounting/api/source_control_tie_out.php?' . $query, 'GET', null, $cookie);
            throw new RuntimeException("Invalid scope/date accepted: {$query}");
        } catch (RuntimeException $e) {
            $expect(str_contains($e->getMessage(), 'HTTP 422'), "{$query} is refused with 422");
        }
    }
    $expect(accountingControlCents('0.01') === 1 && accountingControlCents('-123.45') === -12345,
        'decimal cents do not use floating-point accumulation');

    $entityId = (int) qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = :code', ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE])['id'];
    $baseline = accountingSourceControlTieOut(QA_TENANT, $entityId, $asOf);
    $period = qaOne($pdo, 'SELECT id FROM accounting_periods
        WHERE tenant_id = :t AND entity_id = :e AND start_date <= :date_start AND end_date >= :date_end
        ORDER BY id DESC LIMIT 1',
        ['t' => QA_TENANT, 'e' => $entityId, 'date_start' => $asOf, 'date_end' => $asOf]);
    $ar = qaOne($pdo, 'SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = "1100"',
        ['t' => QA_TENANT]);
    $revenue = qaOne($pdo, 'SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = "4000"',
        ['t' => QA_TENANT]);
    $expect($period !== null && $ar !== null && $revenue !== null,
        'rollback-only mismatch fixture has an existing period and two accounts');

    $pdo->beginTransaction();
    try {
        $number = 'QA-TIE-' . strtoupper(bin2hex(random_bytes(5)));
        $insert = $pdo->prepare('INSERT INTO accounting_journal_entries
            (tenant_id, entity_id, period_id, je_number, posting_date, source_module,
             status, currency, total_debit, total_credit, memo, posted_at)
            VALUES (:t, :e, :p, :number, :date, "manual", "posted", "USD", 1.23, 1.23,
                    "Rollback-only control mismatch fixture", NOW())');
        $insert->execute(['t' => QA_TENANT, 'e' => $entityId, 'p' => $period['id'],
            'number' => $number, 'date' => $asOf]);
        $jeId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare('INSERT INTO accounting_journal_entry_lines
            (tenant_id, je_id, line_no, account_id, debit, credit)
            VALUES (:t, :je, :line, :account, :debit, :credit)');
        $line->execute(['t' => QA_TENANT, 'je' => $jeId, 'line' => 1,
            'account' => $ar['id'], 'debit' => '1.23', 'credit' => '0.00']);
        $line->execute(['t' => QA_TENANT, 'je' => $jeId, 'line' => 2,
            'account' => $revenue['id'], 'debit' => '0.00', 'credit' => '1.23']);

        $mismatch = accountingSourceControlTieOut(QA_TENANT, $entityId, $asOf);
        $expect(!$mismatch['matched'] && !$mismatch['controls']['ar']['matched']
            && $mismatch['controls']['ap']['matched']
            && $mismatch['controls']['ar']['difference'] === 1.23,
            'a balanced journal with an unsupported AR line creates a visible $1.23 difference');
    } finally {
        $pdo->rollBack();
    }
    $expect(accountingSourceControlTieOut(QA_TENANT, $entityId, $asOf) === $baseline,
        'rollback restores the exact prior report');

    echo json_encode(['checks' => count($checks), 'passed' => $checks], JSON_PRETTY_PRINT), "\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_string($cookie) && file_exists($cookie)) unlink($cookie);
    if ($actor) {
        $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
            WHERE id = :id AND tenant_id = :t')
            ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
    }
}
