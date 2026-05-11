<?php
/**
 * AR Statement library — renders a per-client open-invoice statement
 * and resolves which AR contacts it should be emailed to.
 *
 * Reuses billing_client_contacts (ar_primary_email + ar_escalation_email)
 * so the same roster used by the dunning engine doubles as the statement
 * distribution list — no second source of truth.
 *
 * Pure functions. No DB writes (audit/log row is written by the API caller
 * via billingAudit() if needed). Designed for testability.
 */
declare(strict_types=1);

require_once __DIR__ . '/billing.php';

/**
 * Pull every open invoice for $clientName, oldest first, with the
 * computed days-past-due column.
 *
 * @return array<int,array<string,mixed>>
 */
function billingStatementOpenInvoices(int $tenantId, string $clientName, string $asOf): array
{
    $pdo = getDB();
    $st  = $pdo->prepare(
        'SELECT id, invoice_number, issue_date, due_date, amount_due, total, currency,
                GREATEST(0, DATEDIFF(:asof, due_date)) AS days_overdue
           FROM billing_invoices
          WHERE tenant_id   = :tid
            AND client_name = :cn
            AND status IN ("sent","partially_paid","approved","overdue")
            AND amount_due  > 0
          ORDER BY due_date ASC, id ASC'
    );
    $st->execute(['tid' => $tenantId, 'cn' => $clientName, 'asof' => $asOf]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Aggregate rows into aging buckets that match the AR Aging page exactly,
 * so a recipient sees the same numbers ops sees.
 */
function billingStatementBucket(array $invoices): array
{
    $b = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, '91_plus' => 0.0, 'total' => 0.0];
    foreach ($invoices as $inv) {
        $d = (int) ($inv['days_overdue'] ?? 0);
        $amt = (float) ($inv['amount_due'] ?? 0);
        if      ($d <= 0)            $b['current']  += $amt;
        elseif  ($d <= 30)           $b['1_30']     += $amt;
        elseif  ($d <= 60)           $b['31_60']    += $amt;
        elseif  ($d <= 90)           $b['61_90']    += $amt;
        else                         $b['91_plus']  += $amt;
        $b['total'] += $amt;
    }
    return $b;
}

/**
 * Resolve where the statement should be sent.
 *
 *   primary       = billing_client_contacts.ar_primary_email (required)
 *   escalation_cc = ar_escalation_email when present and distinct from primary
 *
 * Unlike dunning, statements are sent on-demand by a human pressing a
 * button — there is no attempt threshold. Escalation is always CC'd when
 * configured so the controller sees the same statement the AR clerk does.
 *
 * @return array{to: ?string, cc: array<int,string>, reason: string}
 */
function billingStatementResolveRecipients(int $tenantId, string $clientName): array
{
    try {
        $st = getDB()->prepare(
            'SELECT ar_primary_email, ar_escalation_email
               FROM billing_client_contacts
              WHERE tenant_id = :t AND client_name = :c LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'c' => $clientName]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { $row = null; }

    $primary = (!empty($row['ar_primary_email']) && filter_var($row['ar_primary_email'], FILTER_VALIDATE_EMAIL))
        ? (string) $row['ar_primary_email'] : null;
    $cc = [];
    if (!empty($row['ar_escalation_email'])
        && filter_var($row['ar_escalation_email'], FILTER_VALIDATE_EMAIL)
        && $row['ar_escalation_email'] !== $primary) {
        $cc[] = (string) $row['ar_escalation_email'];
    }
    return [
        'to'     => $primary,
        'cc'     => $cc,
        'reason' => $primary ? 'client_contacts.ar_primary_email' : 'no-contact-found',
    ];
}

/**
 * Render the statement email. Returns ['subject','html','text'].
 */
function billingStatementRenderEmail(string $tenantName, string $clientName, array $invoices, array $buckets, string $asOf): array
{
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $money = fn ($n) => '$' . number_format((float) $n, 2);
    $count = count($invoices);
    $subject = "Statement of account — {$count} open invoice" . ($count === 1 ? '' : 's')
             . ' totaling ' . $money($buckets['total']);

    $rowsHtml = '';
    $rowsText = '';
    foreach ($invoices as $inv) {
        $num   = (string) ($inv['invoice_number'] ?? ('#' . $inv['id']));
        $due   = (string) $inv['due_date'];
        $d     = (int) ($inv['days_overdue'] ?? 0);
        $amt   = $money($inv['amount_due']);
        $rowsHtml .= '<tr>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb">' . $h($num) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;color:#475569">' . $h($due) . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;' . ($d > 0 ? 'color:#b91c1c;font-weight:600' : '') . '">' . ($d > 0 ? $d . 'd' : 'current') . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:right;font-variant-numeric:tabular-nums">' . $h($amt) . '</td>'
            . '</tr>';
        $rowsText .= sprintf("  %-16s  due %s  %-10s  %s\n",
            $num, $due, $d > 0 ? "{$d}d past" : 'current', $amt);
    }

    $html  = '<div style="font-family:system-ui;max-width:640px;margin:0 auto;padding:24px;color:#0f172a">'
           . '<h2 style="margin:0 0 4px">Statement of account</h2>'
           . '<p style="margin:0 0 18px;color:#64748b;font-size:13px">' . $h($clientName) . ' &middot; as of ' . $h($asOf) . '</p>'
           . '<table style="background:#f8fafc;border-radius:8px;padding:14px;font-size:13px;line-height:1.6;width:100%;margin-bottom:16px">'
           . '<tr><td>Current</td><td style="text-align:right">'   . $h($money($buckets['current']))  . '</td></tr>'
           . '<tr><td>1–30 days past due</td><td style="text-align:right">'  . $h($money($buckets['1_30']))     . '</td></tr>'
           . '<tr><td>31–60 days past due</td><td style="text-align:right">' . $h($money($buckets['31_60']))    . '</td></tr>'
           . '<tr><td>61–90 days past due</td><td style="text-align:right;color:#b45309">' . $h($money($buckets['61_90'])) . '</td></tr>'
           . '<tr><td>91+ days past due</td><td style="text-align:right;color:#b91c1c;font-weight:600">' . $h($money($buckets['91_plus'])) . '</td></tr>'
           . '<tr style="border-top:2px solid #0f172a"><td style="font-weight:600;padding-top:8px">Total due</td><td style="text-align:right;font-weight:700;padding-top:8px">' . $h($money($buckets['total'])) . '</td></tr>'
           . '</table>'
           . '<table style="width:100%;border-collapse:collapse;font-size:13px">'
           . '<thead><tr style="background:#f1f5f9"><th style="text-align:left;padding:6px 8px">Invoice</th><th style="text-align:left;padding:6px 8px">Due</th><th style="text-align:right;padding:6px 8px">Age</th><th style="text-align:right;padding:6px 8px">Amount</th></tr></thead>'
           . '<tbody>' . $rowsHtml . '</tbody>'
           . '</table>'
           . '<p style="margin-top:24px;color:#64748b;font-size:13px">Reply to this email if any invoice on this statement is in dispute or has been paid recently and you need it reconciled.</p>'
           . '<p style="margin-top:4px;color:#94a3b8;font-size:12px">— ' . $h($tenantName) . ' AR Team</p>'
           . '</div>';

    $text  = "Statement of account — {$clientName} (as of {$asOf})\n\n"
           . sprintf("  Current:          %s\n", $money($buckets['current']))
           . sprintf("  1-30 past due:    %s\n", $money($buckets['1_30']))
           . sprintf("  31-60 past due:   %s\n", $money($buckets['31_60']))
           . sprintf("  61-90 past due:   %s\n", $money($buckets['61_90']))
           . sprintf("  91+ past due:     %s\n", $money($buckets['91_plus']))
           . sprintf("  TOTAL DUE:        %s\n\n", $money($buckets['total']))
           . "Open invoices:\n" . $rowsText . "\n"
           . "Reply if any of these are in dispute or paid recently.\n— {$tenantName} AR Team\n";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
