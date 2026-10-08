<?php
/**
 * AR Statement library — renders a per-client open-invoice statement
 * and resolves which AR contacts it should be emailed to.
 *
 * Reuses entity-owned billing_client_contacts (ar_primary_email + ar_escalation_email)
 * so the same roster used by the dunning engine doubles as the statement
 * distribution list — no second source of truth.
 *
 * Pure functions. No DB writes (audit/log row is written by the API caller
 * via billingAudit() if needed). Designed for testability.
 */
declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/../../../core/tenant_branding.php';
require_once __DIR__ . '/../../../core/accounting/books_health_metrics.php';
require_once __DIR__ . '/entity_delivery.php';

function billingStatementEntity(int $tenantId, mixed $requested): array
{
    if ($requested === null || $requested === '' || $requested === 'all') {
        throw new InvalidArgumentException('Select one legal entity before preparing a customer statement.');
    }
    $entityId = booksHealthResolveEntity(getDB(), $tenantId, $requested);
    $query = getDB()->prepare('SELECT id, legal_name FROM accounting_entities WHERE tenant_id = :t AND id = :e');
    $query->execute(['t' => $tenantId, 'e' => $entityId]);
    $entity = $query->fetch(\PDO::FETCH_ASSOC);
    if (!$entity) throw new OutOfBoundsException('Legal entity not found.');
    return $entity;
}

function billingStatementIdempotencyKey(int $tenantId, int $entityId, string $clientName, string $sentDate): string
{
    $slug = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($clientName)) ?? '', '-'), 0, 48) ?: 'client';
    return "statement-{$tenantId}-{$entityId}-{$slug}-" . substr(hash('sha256', $clientName), 0, 12) . "-{$sentDate}";
}

/**
 * Pull every open invoice for $clientName, oldest first, with the
 * computed days-past-due column.
 *
 * @return array<int,array<string,mixed>>
 */
function billingStatementOpenInvoices(int $tenantId, string $clientName, string $asOf, ?int $entityId = null): array
{
    [$sourceSql, $params] = billingOpenInvoiceAsOfSource($tenantId, $asOf, $entityId, $clientName);
    $st = getDB()->prepare(
        'SELECT aged.*, GREATEST(0, DATEDIFF(:asof, aged.due_date)) AS days_overdue
           FROM (' . $sourceSql . ') aged
          ORDER BY aged.due_date ASC, aged.id ASC'
    );
    $st->execute($params + ['asof' => $asOf]);
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
function billingStatementResolveRecipients(int $tenantId, int $entityId, string $clientName): array
{
    try {
        $row = billingClientContactForEntity($tenantId, $entityId, $clientName);
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
function billingStatementRenderEmail(string $tenantName, string $clientName, array $invoices, array $buckets, string $asOf, ?array $branding = null, ?int $tenantId = null): array
{
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $money = fn ($n) => '$' . number_format((float) $n, 2);
    if ($branding === null) {
        $branding = $tenantId !== null ? cf_tenant_branding($tenantId)
            : ['logo_url' => null, 'accent_color' => '#0f172a', 'signature_html' => '', 'show_powered_by' => true];
    }
    $accent = (string) ($branding['accent_color'] ?? '#0f172a');
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
           . cf_branding_header_html($branding, 'Statement of account')
           . '<p style="margin:0 0 18px;color:#64748b;font-size:13px">' . $h($clientName) . ' &middot; as of ' . $h($asOf) . '</p>'
           . '<table style="background:#f8fafc;border-radius:8px;padding:14px;font-size:13px;line-height:1.6;width:100%;margin-bottom:16px;border-top:3px solid ' . $h($accent) . '">'
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
           . '<p style="margin-top:24px;color:#64748b;font-size:13px">Please contact the accounts receivable team if any invoice is in dispute or has been paid recently.</p>'
           . cf_branding_footer_html($branding, $tenantName)
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
