<?php
/**
 * Billing — Dunning engine.
 *
 * Public surface:
 *   billingDunningDefaultPolicy(): array        — sensible defaults for new tenants
 *   billingDunningGetPolicy(int $tenantId): array
 *   billingDunningSavePolicy(int $tenantId, array $policy, ?int $actorUserId = null): void
 *   billingDunningResolveRecipients(int $tenantId, array $invoice, int $attempts, array $policy): array
 *   billingDunningPickStage(array $invoice, array $policy, string $today): ?array
 *   billingDunningEligibleInvoices(int $tenantId, string $today): array
 *   billingDunningRenderEmail(string $templateKey, array $invoice, array $tenant): array
 *   billingDunningRecordSend(int $tenantId, int $invoiceId, array $stage, string $sentTo, array $cc, string $status, ?string $err = null): void
 *   billingDunningAiEscalationSuggestion(int $tenantId, string $clientName, array $policy): ?array
 *
 * The cron (scripts/dunning_daily.php) wires it all together.
 *
 * Recipient model (per the operator's spec):
 *   primary  = invoice.bill_to_json.email
 *              ↘ falls back to billing_client_contacts.ar_primary_email
 *              ↘ falls back to skipped (with a 'suppressed' log entry)
 *   cc       = empty until attempts >= escalate_to_client_contact_after_attempts,
 *              then billing_client_contacts.ar_escalation_email is added (if set).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

function billingDunningDefaultPolicy(): array {
    return [
        'is_enabled'  => 1,
        'schedule'    => [
            ['days_overdue' => 3,  'template_key' => 'soft',  'cc_client_contact' => false],
            ['days_overdue' => 14, 'template_key' => 'firm',  'cc_client_contact' => false],
            ['days_overdue' => 30, 'template_key' => 'final', 'cc_client_contact' => true],
        ],
        'max_attempts'  => 3,
        'cadence_days'  => 7,
        'skip_weekends' => 1,
        'escalate_to_client_contact_after_attempts' => 2,
        'paused_until'  => null,
        'do_not_contact'=> [],
    ];
}

function billingDunningGetPolicy(int $tenantId): array {
    $pdo = getDB();
    try {
        $st = $pdo->prepare('SELECT * FROM tenant_dunning_policy WHERE tenant_id = :t');
        $st->execute(['t' => $tenantId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { $row = null; }
    if (!$row) return billingDunningDefaultPolicy();
    return [
        'is_enabled'   => (int) $row['is_enabled'],
        'schedule'     => json_decode((string) $row['schedule_json'], true) ?: billingDunningDefaultPolicy()['schedule'],
        'max_attempts' => (int) $row['max_attempts'],
        'cadence_days' => (int) $row['cadence_days'],
        'skip_weekends'=> (int) $row['skip_weekends'],
        'escalate_to_client_contact_after_attempts' => (int) $row['escalate_to_client_contact_after_attempts'],
        'paused_until' => $row['paused_until'],
        'do_not_contact' => json_decode((string) ($row['do_not_contact_json'] ?? '[]'), true) ?: [],
    ];
}

function billingDunningSavePolicy(int $tenantId, array $policy, ?int $actorUserId = null): void {
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO tenant_dunning_policy
            (tenant_id, is_enabled, schedule_json, max_attempts, cadence_days,
             skip_weekends, escalate_to_client_contact_after_attempts, paused_until,
             do_not_contact_json, updated_by_user_id)
         VALUES (:t, :en, :s, :mx, :c, :w, :ea, :pu, :d, :u)
         ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled),
            schedule_json = VALUES(schedule_json),
            max_attempts = VALUES(max_attempts),
            cadence_days = VALUES(cadence_days),
            skip_weekends = VALUES(skip_weekends),
            escalate_to_client_contact_after_attempts = VALUES(escalate_to_client_contact_after_attempts),
            paused_until = VALUES(paused_until),
            do_not_contact_json = VALUES(do_not_contact_json),
            updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        't'  => $tenantId,
        'en' => $policy['is_enabled'] ?? 1,
        's'  => json_encode(array_values($policy['schedule'] ?? [])),
        'mx' => (int) ($policy['max_attempts'] ?? 3),
        'c'  => (int) ($policy['cadence_days'] ?? 7),
        'w'  => (int) ($policy['skip_weekends'] ?? 1),
        'ea' => (int) ($policy['escalate_to_client_contact_after_attempts'] ?? 2),
        'pu' => $policy['paused_until'] ?? null,
        'd'  => json_encode(array_values($policy['do_not_contact'] ?? [])),
        'u'  => $actorUserId,
    ]);
}

/**
 * Returns the matching stage for an invoice given today's date, or null
 * if the invoice doesn't yet qualify (or has been paused / muted).
 *
 * Stage matching rule: the largest `days_overdue` <= current days_overdue
 * that we have NOT already advanced past (i.e., stage > current).
 */
function billingDunningPickStage(array $invoice, array $policy, string $today): ?array {
    if (empty($invoice['due_date'])) return null;
    $overdueDays = (int) round((strtotime($today) - strtotime($invoice['due_date'])) / 86400);
    if ($overdueDays < 0) return null;

    $currentStage = (int) ($invoice['dunning_stage'] ?? 0);
    $best = null; $bestIdx = null;
    foreach (($policy['schedule'] ?? []) as $i => $s) {
        $stageNo = $i + 1; // 1-based
        $dueFor  = (int) ($s['days_overdue'] ?? 0);
        if ($overdueDays < $dueFor) continue;
        if ($stageNo <= $currentStage) continue;
        if ($bestIdx === null || $dueFor > (int) ($best['days_overdue'] ?? 0)) {
            $best = $s + ['stage_no' => $stageNo]; $bestIdx = $i;
        }
    }
    return $best;
}

/**
 * SELECT all invoices that are candidates for dunning today. Caller still
 * runs each through `billingDunningPickStage()` + cadence guard.
 */
function billingDunningEligibleInvoices(int $tenantId, string $today): array {
    $pdo = getDB();
    $st = $pdo->prepare(
        "SELECT * FROM billing_invoices
          WHERE tenant_id = :t
            AND status IN ('sent','partially_paid','overdue')
            AND amount_due > 0.005
            AND due_date < :today
            AND (dunning_paused_until IS NULL OR dunning_paused_until < :today2)
          ORDER BY due_date ASC, id ASC"
    );
    $st->execute(['t' => $tenantId, 'today' => $today, 'today2' => $today]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Resolve recipients for THIS send. Returns:
 *   ['to' => string|null, 'cc' => string[], 'reason' => string]
 *
 * Per the operator's spec: primary contact = invoice.bill_to.email,
 * falling back to client_contacts.ar_primary_email; escalation contact
 * is added to CC once attempts >= policy.escalate_to_client_contact_after_attempts.
 */
function billingDunningResolveRecipients(int $tenantId, array $invoice, int $attempts, array $policy): array {
    $pdo = getDB();
    $primary = null; $reason = '';
    if (!empty($invoice['bill_to_json'])) {
        $bt = json_decode((string) $invoice['bill_to_json'], true) ?: [];
        if (!empty($bt['email']) && filter_var($bt['email'], FILTER_VALIDATE_EMAIL)) {
            $primary = (string) $bt['email']; $reason = 'invoice.bill_to.email';
        }
    }
    $clientContact = null;
    try {
        $st = $pdo->prepare(
            'SELECT ar_primary_email, ar_escalation_email FROM billing_client_contacts
              WHERE tenant_id = :t AND client_name = :c LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'c' => (string) $invoice['client_name']]);
        $clientContact = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { /* table not migrated yet */ }

    if (!$primary && !empty($clientContact['ar_primary_email']) && filter_var($clientContact['ar_primary_email'], FILTER_VALIDATE_EMAIL)) {
        $primary = (string) $clientContact['ar_primary_email'];
        $reason  = 'client_contacts.ar_primary_email (fallback)';
    }

    $cc = [];
    $threshold = (int) ($policy['escalate_to_client_contact_after_attempts'] ?? 999);
    if ($attempts >= $threshold && !empty($clientContact['ar_escalation_email'])
            && filter_var($clientContact['ar_escalation_email'], FILTER_VALIDATE_EMAIL)
            && $clientContact['ar_escalation_email'] !== $primary) {
        $cc[] = (string) $clientContact['ar_escalation_email'];
    }

    return ['to' => $primary, 'cc' => $cc, 'reason' => $reason ?: 'no-contact-found'];
}

/**
 * Render the email body. Three built-in templates: soft / firm / final.
 * Returns ['subject' => str, 'html' => str, 'text' => str].
 */
function billingDunningRenderEmail(string $templateKey, array $invoice, array $tenant): array {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $tplKey = in_array($templateKey, ['soft','firm','final'], true) ? $templateKey : 'soft';
    $tenantName = $tenant['name'] ?? 'CoreFlux';
    $amount = '$' . number_format((float) $invoice['amount_due'], 2);
    $overdueDays = max(0, (int) round((time() - strtotime($invoice['due_date'])) / 86400));
    $invoiceNum = $invoice['invoice_number'] ?? ('#' . $invoice['id']);

    [$subject, $tone, $body] = match ($tplKey) {
        'firm' => [
            "Past due — invoice {$invoiceNum} is {$overdueDays} days overdue",
            'firm',
            "We haven't received payment for invoice {$invoiceNum} ({$amount}). It's now {$overdueDays} days past due. Please reach out today if there's an issue with this invoice."
        ],
        'final' => [
            "FINAL NOTICE — invoice {$invoiceNum} {$overdueDays} days overdue",
            'final',
            "This is a final notice. Invoice {$invoiceNum} for {$amount} is {$overdueDays} days overdue. We will need to escalate this matter if payment is not received within 7 days."
        ],
        default => [
            "Friendly reminder — invoice {$invoiceNum}",
            'soft',
            "Just a quick reminder that invoice {$invoiceNum} for {$amount} was due on {$invoice['due_date']}. If you've already paid, please disregard this note. Otherwise we'd appreciate your prompt attention."
        ],
    };
    $color = ['soft' => '#0f172a', 'firm' => '#a16207', 'final' => '#dc2626'][$tone];
    $html  = '<div style="font-family:system-ui;max-width:560px;margin:0 auto;padding:24px;color:#111">'
           . '<h2 style="color:' . $color . ';margin:0 0 12px">' . $h($subject) . '</h2>'
           . '<p>' . $h($body) . '</p>'
           . '<table style="background:#f8fafc;border-radius:6px;padding:12px;font-size:13px;line-height:1.7">'
           . '<tr><td>Invoice</td><td><strong>' . $h($invoiceNum) . '</strong></td></tr>'
           . '<tr><td>Amount due</td><td><strong>' . $h($amount) . '</strong> ' . $h($invoice['currency'] ?? 'USD') . '</td></tr>'
           . '<tr><td>Due date</td><td>' . $h($invoice['due_date']) . '</td></tr>'
           . '<tr><td>Days overdue</td><td><strong>' . $overdueDays . '</strong></td></tr>'
           . '</table>'
           . '<p style="margin-top:24px;color:#64748b;font-size:13px">— ' . $h($tenantName) . ' AR Team</p>'
           . '</div>';
    $text = $body . "\n\nInvoice: {$invoiceNum}\nAmount due: {$amount}\nDue date: {$invoice['due_date']}\nDays overdue: {$overdueDays}\n\n— {$tenantName} AR Team";
    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}

function billingDunningRecordSend(int $tenantId, int $invoiceId, array $stage, string $sentTo, array $cc, string $status, ?string $err = null): void {
    $pdo = getDB();
    try {
        $pdo->prepare(
            'INSERT INTO billing_dunning_log
                (tenant_id, invoice_id, stage, template_key, sent_to_email, cc_emails_json, status, error_text)
             VALUES (:t, :i, :st, :tk, :to, :cc, :s, :err)'
        )->execute([
            't' => $tenantId, 'i' => $invoiceId,
            'st' => (int) $stage['stage_no'],
            'tk' => (string) ($stage['template_key'] ?? 'soft'),
            'to' => $sentTo,
            'cc' => json_encode(array_values($cc)),
            's' => $status,
            'err' => $err ? substr($err, 0, 500) : null,
        ]);
    } catch (\Throwable $_) { /* log table absent — non-fatal */ }
    if ($status === 'sent') {
        try {
            $pdo->prepare(
                'UPDATE billing_invoices
                    SET dunning_stage = :st,
                        dunning_attempts = dunning_attempts + 1,
                        dunning_last_sent_at = NOW()
                  WHERE tenant_id = :t AND id = :i'
            )->execute(['st' => (int) $stage['stage_no'], 't' => $tenantId, 'i' => $invoiceId]);
        } catch (\Throwable $_) { /* schema absence */ }
    }
}

/**
 * Lightweight (no-LLM) AI-style suggestion engine: when should a tenant
 * escalate to the client-level escalation contact? Heuristic:
 *   - If this client has 3+ invoices that reached stage 2+ in last 12mo,
 *     suggest dropping `escalate_to_client_contact_after_attempts` from
 *     `policy.value` → 1 (escalate immediately on stage 1).
 *   - If this client paid all dunned invoices within 5 days of stage 1,
 *     suggest raising the threshold to 3 (don't bother escalating).
 * Returns ['suggestion' => str, 'rationale' => str, 'metric' => array] or null.
 */
function billingDunningAiEscalationSuggestion(int $tenantId, string $clientName, array $policy): ?array {
    $pdo = getDB();
    try {
        $st = $pdo->prepare(
            "SELECT i.id, i.dunning_stage, i.status, i.due_date,
                    (SELECT MAX(p.received_at) FROM billing_payment_allocations a
                       JOIN billing_payments p ON p.id = a.payment_id
                      WHERE a.invoice_id = i.id) AS paid_at
               FROM billing_invoices i
              WHERE i.tenant_id = :t AND i.client_name = :c
                AND i.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                AND i.dunning_stage IS NOT NULL"
        );
        $st->execute(['t' => $tenantId, 'c' => $clientName]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) { return null; }

    if (empty($rows)) return null;

    $stage2Plus = 0;
    $paidWithin5OfStage1 = 0; $stage1Total = 0;
    foreach ($rows as $r) {
        $stage = (int) $r['dunning_stage'];
        if ($stage >= 2) $stage2Plus++;
        if ($stage === 1 && !empty($r['paid_at'])) {
            $stage1Total++;
            // Heuristic only; we don't have stage_sent_at handy without a JOIN.
            // Use due_date + 8 days as a proxy (stage 1 fires after ~3d).
            $stage1Sent = strtotime($r['due_date'] . ' +8 days');
            if (strtotime($r['paid_at']) - $stage1Sent <= 5 * 86400) $paidWithin5OfStage1++;
        }
    }

    $current = (int) ($policy['escalate_to_client_contact_after_attempts'] ?? 2);
    if ($stage2Plus >= 3 && $current > 1) {
        return [
            'suggestion' => "Escalate client-level contact starting at stage 1 for {$clientName}",
            'rationale'  => "{$clientName} has had {$stage2Plus} invoices reach stage 2+ in the last 12 months — escalating earlier may shorten DSO.",
            'metric'     => ['stage2_plus_count' => $stage2Plus],
        ];
    }
    if ($stage1Total >= 3 && $paidWithin5OfStage1 >= max(2, (int) round($stage1Total * 0.7)) && $current < 3) {
        return [
            'suggestion' => "Raise escalation threshold to 3 attempts for {$clientName}",
            'rationale'  => "{$clientName} consistently pays within 5 days of the first reminder — earlier escalation may strain a healthy relationship.",
            'metric'     => ['stage1_paid_fast' => $paidWithin5OfStage1, 'stage1_total' => $stage1Total],
        ];
    }
    return null;
}

/**
 * Should this send be skipped because of cadence_days?
 */
function billingDunningWithinCadence(array $invoice, int $cadenceDays): bool {
    if (empty($invoice['dunning_last_sent_at'])) return false;
    return (time() - strtotime((string) $invoice['dunning_last_sent_at'])) < $cadenceDays * 86400;
}

/**
 * Weekend skip helper.
 */
function billingDunningIsWeekend(string $today): bool {
    $dow = (int) date('N', strtotime($today)); // 1=Mon, 7=Sun
    return $dow >= 6;
}
