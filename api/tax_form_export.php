<?php
/**
 * Tax-form export (Sprint 7f.2).
 *
 * Sums each posted JE line against `accounting_tax_mappings` so the
 * tenant can hand the totals to their tax preparer (or auto-fill a
 * Schedule C / 1120 / 1065 line by line).
 *
 *   GET /api/tax_form_export.php
 *        ?tax_form_code=US-1040-SCH-C
 *        &start=YYYY-MM-DD              default = Jan 1 of current year
 *        &end=YYYY-MM-DD                default = today
 *        &entity_id=N                   optional
 *        &format=json|csv               default json (UI preview), csv triggers download
 *
 * JSON response:
 *   {
 *     tax_form_code, tax_form_label, start, end, entity_id,
 *     totals_by_line:   [{ line, label, accounts: [{code,name}], total, debit, credit, normal_side, n }],
 *     unmapped_summary: { account_count, total_debit, total_credit, accounts: [{code,name,balance}] },
 *     mapped_count, unmapped_count, generated_at
 *   }
 *
 * CSV format (RFC4180):
 *   Tax form,Line,Label,Total,Accounts,Account codes
 *   US-1040-SCH-C,22,Supplies,12450.00,3,5100;5110;5120
 *   ...
 *   ,UNMAPPED,Revenue/expense not yet mapped,7800.00,...
 *
 * Sign convention follows `accounting_accounts.normal_side` so revenue
 * accounts emit positive totals when credited, expense accounts emit
 * positive totals when debited.
 *
 * RBAC: `accounting.coa.view`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

const TAX_FORMS_EXPORT = [
    'US-1040-SCH-C' => 'US — Form 1040 Schedule C (Sole proprietor)',
    'US-1120'       => 'US — Form 1120 (C-corp)',
    'US-1120-S'     => 'US — Form 1120-S (S-corp)',
    'US-1065'       => 'US — Form 1065 (Partnership)',
    'US-990'        => 'US — Form 990 (Non-profit)',
];

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'accounting.coa.view');

$form     = trim((string) (api_query('tax_form_code') ?? ''));
$start    = (string) (api_query('start') ?? date('Y-01-01'));
$end      = (string) (api_query('end')   ?? date('Y-m-d'));
$entityId = (int) (api_query('entity_id') ?? 0);
$format   = strtolower((string) (api_query('format') ?? 'json'));

if (!isset(TAX_FORMS_EXPORT[$form])) api_error('Unknown tax_form_code', 422);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) api_error('start must be YYYY-MM-DD', 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   api_error('end must be YYYY-MM-DD', 400);
if ($start > $end) api_error('start must be <= end', 400);
if (!in_array($format, ['json', 'csv'], true)) $format = 'json';

$pdo = getDB();

$entityWhere = $entityId ? 'AND je.entity_id = :eid' : '';

// Aggregate per (mapping line, account) so we can compose both per-line
// totals + per-line account lists in one pass.
$sql = "SELECT m.id            AS mapping_id,
               m.tax_form_line  AS line,
               m.tax_form_label AS label,
               a.id             AS account_id,
               a.code           AS account_code,
               a.name           AS account_name,
               a.normal_side,
               COALESCE(SUM(jl.debit), 0)  AS d,
               COALESCE(SUM(jl.credit), 0) AS c,
               COUNT(jl.id) AS n
          FROM accounting_tax_mappings m
          JOIN accounting_accounts a ON a.id = m.account_id AND a.tenant_id = m.tenant_id
     LEFT JOIN accounting_journal_lines jl
            ON jl.tenant_id  = m.tenant_id
           AND jl.account_id = m.account_id
     LEFT JOIN accounting_journal_entries je
            ON je.id = jl.journal_entry_id
           AND je.posting_date BETWEEN :start AND :end
           AND je.status = 'posted'
           {$entityWhere}
         WHERE m.tenant_id = :t AND m.tax_form_code = :f
      GROUP BY m.id, a.id
      ORDER BY m.tax_form_line, a.code";
$stmt = $pdo->prepare($sql);
$params = ['t' => $tid, 'f' => $form, 'start' => $start, 'end' => $end];
if ($entityId) $params['eid'] = $entityId;
$stmt->execute($params);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$byLine = []; // key: line|label
foreach ($rows as $r) {
    $key = (string) $r['line'] . '|' . (string) ($r['label'] ?? '');
    if (!isset($byLine[$key])) {
        $byLine[$key] = [
            'line'          => (string) $r['line'],
            'label'         => $r['label'] !== null ? (string) $r['label'] : null,
            'accounts'      => [],
            'debit'         => 0.0,
            'credit'        => 0.0,
            'total'         => 0.0,
            'normal_side'   => strtolower((string) $r['normal_side']),
            'n'             => 0,
        ];
    }
    $d = (float) $r['d'];
    $c = (float) $r['c'];
    $byLine[$key]['accounts'][] = [
        'code' => (string) $r['account_code'],
        'name' => (string) $r['account_name'],
    ];
    $byLine[$key]['debit']  += $d;
    $byLine[$key]['credit'] += $c;
    $byLine[$key]['n']      += (int) $r['n'];
}
foreach ($byLine as &$b) {
    $b['total'] = $b['normal_side'] === 'credit'
        ? round($b['credit'] - $b['debit'], 2)
        : round($b['debit']  - $b['credit'], 2);
    $b['debit']  = round($b['debit'],  2);
    $b['credit'] = round($b['credit'], 2);
}
unset($b);

$totalsByLine = array_values($byLine);

// Unmapped revenue/expense accounts in the same window — what didn't
// land on the form. Useful nag.
$unmappedSql = "SELECT a.id, a.code, a.name, a.normal_side,
                       COALESCE(SUM(jl.debit), 0)  AS d,
                       COALESCE(SUM(jl.credit), 0) AS c
                  FROM accounting_accounts a
             LEFT JOIN accounting_journal_lines jl
                    ON jl.tenant_id  = a.tenant_id
                   AND jl.account_id = a.id
             LEFT JOIN accounting_journal_entries je
                    ON je.id = jl.journal_entry_id
                   AND je.posting_date BETWEEN :start AND :end
                   AND je.status = 'posted'
                   {$entityWhere}
                 WHERE a.tenant_id = :t
                   AND a.active = 1
                   AND a.is_postable = 1
                   AND a.account_type IN ('revenue','expense','cost_of_goods_sold','other_income','other_expense','contra_revenue')
                   AND a.id NOT IN (
                         SELECT account_id FROM accounting_tax_mappings
                          WHERE tenant_id = :t2 AND tax_form_code = :f2
                   )
              GROUP BY a.id
                HAVING d > 0 OR c > 0
              ORDER BY a.code";
$uStmt = $pdo->prepare($unmappedSql);
$uParams = ['t' => $tid, 't2' => $tid, 'f2' => $form, 'start' => $start, 'end' => $end];
if ($entityId) $uParams['eid'] = $entityId;
$uStmt->execute($uParams);
$unmappedRows = $uStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$unmappedSummary = [
    'account_count' => 0,
    'total_debit'   => 0.0,
    'total_credit'  => 0.0,
    'total'         => 0.0,
    'accounts'      => [],
];
foreach ($unmappedRows as $u) {
    $d = (float) $u['d']; $c = (float) $u['c'];
    $bal = strtolower((string) $u['normal_side']) === 'credit'
        ? round($c - $d, 2) : round($d - $c, 2);
    $unmappedSummary['account_count']++;
    $unmappedSummary['total_debit']  += $d;
    $unmappedSummary['total_credit'] += $c;
    $unmappedSummary['total']        += $bal;
    $unmappedSummary['accounts'][]   = [
        'code'        => (string) $u['code'],
        'name'        => (string) $u['name'],
        'balance'     => $bal,
        'normal_side' => strtolower((string) $u['normal_side']),
    ];
}
$unmappedSummary['total_debit']  = round($unmappedSummary['total_debit'],  2);
$unmappedSummary['total_credit'] = round($unmappedSummary['total_credit'], 2);
$unmappedSummary['total']        = round($unmappedSummary['total'],        2);

if ($format === 'csv') {
    $filename = sprintf('tax_export_%s_%s_to_%s.csv', $form, $start, $end);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tax form', 'Line', 'Label', 'Total', 'Accounts', 'Account codes']);
    foreach ($totalsByLine as $b) {
        $codes = array_column($b['accounts'], 'code');
        fputcsv($out, [
            $form, $b['line'], (string) ($b['label'] ?? ''),
            number_format($b['total'], 2, '.', ''),
            count($b['accounts']),
            implode(';', $codes),
        ]);
    }
    if ($unmappedSummary['account_count'] > 0) {
        fputcsv($out, [
            $form, 'UNMAPPED', 'Revenue/expense not yet mapped to a form line',
            number_format($unmappedSummary['total'], 2, '.', ''),
            $unmappedSummary['account_count'],
            implode(';', array_column($unmappedSummary['accounts'], 'code')),
        ]);
    }
    fclose($out);
    exit;
}

api_ok([
    'tax_form_code'    => $form,
    'tax_form_label'   => TAX_FORMS_EXPORT[$form],
    'start'            => $start,
    'end'              => $end,
    'entity_id'        => $entityId ?: null,
    'totals_by_line'   => $totalsByLine,
    'mapped_count'     => count($totalsByLine),
    'unmapped_summary' => $unmappedSummary,
    'unmapped_count'   => $unmappedSummary['account_count'],
    'generated_at'     => date('c'),
]);
