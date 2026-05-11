<?php
/**
 * Smoke: Billing — Dunning (escalation, recipient resolution, AI suggestion).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "Migration 009_dunning.sql\n";
$migPath = __DIR__ . '/../modules/billing/migrations/009_dunning.sql';
$mig = (string) file_get_contents($migPath);
foreach (['tenant_dunning_policy','billing_client_contacts','billing_dunning_log'] as $t) {
    $a("creates {$t}",                                 str_contains($mig, "CREATE TABLE IF NOT EXISTS {$t}"));
}
$a('adds dunning_stage column to billing_invoices',    str_contains($mig, "COLUMN_NAME='dunning_stage'"));
$a('adds dunning_attempts',                            str_contains($mig, "COLUMN_NAME='dunning_attempts'"));
$a('adds dunning_last_sent_at',                        str_contains($mig, "COLUMN_NAME='dunning_last_sent_at'"));
$a('adds dunning_paused_until',                        str_contains($mig, "COLUMN_NAME='dunning_paused_until'"));
$a('escalate_to_client_contact_after_attempts col',    str_contains($mig, 'escalate_to_client_contact_after_attempts'));
$a('client_contacts has ar_primary + ar_escalation',   str_contains($mig, 'ar_primary_email') && str_contains($mig, 'ar_escalation_email'));
$a('dunning_log has stage + template_key + status',    str_contains($mig, 'stage      INT UNSIGNED NOT NULL') && str_contains($mig, 'template_key VARCHAR(40)') && str_contains($mig, "ENUM('sent','failed','suppressed')"));
$a('idempotent migration',                             substr_count($mig, 'information_schema') >= 4);

echo "\nLibrary: lib/dunning.php\n";
$libPath = __DIR__ . '/../modules/billing/lib/dunning.php';
require_once $libPath;
foreach (['billingDunningDefaultPolicy','billingDunningGetPolicy','billingDunningSavePolicy','billingDunningResolveRecipients','billingDunningPickStage','billingDunningEligibleInvoices','billingDunningRenderEmail','billingDunningRecordSend','billingDunningAiEscalationSuggestion','billingDunningWithinCadence','billingDunningIsWeekend'] as $fn) {
    $a("fn: {$fn}",                                    function_exists($fn));
}

echo "\ndefault policy shape\n";
$dp = billingDunningDefaultPolicy();
$a('default has 3 stages',                             count($dp['schedule']) === 3);
$a('default escalate threshold = 2',                   $dp['escalate_to_client_contact_after_attempts'] === 2);
$a('default cadence = 7d',                             $dp['cadence_days'] === 7);
$a('default skips weekends',                           $dp['skip_weekends'] === 1);

echo "\nbillingDunningPickStage()\n";
$policy = $dp;
$inv = ['due_date' => date('Y-m-d', strtotime('-5 days')), 'dunning_stage' => 0];
$s = billingDunningPickStage($inv, $policy, date('Y-m-d'));
$a('5d overdue → stage 1 (soft @3d)',                  $s && $s['stage_no'] === 1 && $s['template_key'] === 'soft');
$inv['due_date'] = date('Y-m-d', strtotime('-15 days'));
$s = billingDunningPickStage($inv, $policy, date('Y-m-d'));
$a('15d overdue → stage 2 (firm @14d)',                $s && $s['stage_no'] === 2 && $s['template_key'] === 'firm');
$inv['due_date'] = date('Y-m-d', strtotime('-35 days'));
$s = billingDunningPickStage($inv, $policy, date('Y-m-d'));
$a('35d overdue → stage 3 (final @30d)',               $s && $s['stage_no'] === 3 && $s['template_key'] === 'final');
$inv['dunning_stage'] = 3;
$a('already at stage 3 → null (no further escalation)',billingDunningPickStage($inv, $policy, date('Y-m-d')) === null);
$inv2 = ['due_date' => date('Y-m-d', strtotime('+2 days')), 'dunning_stage' => 0];
$a('future due_date → null',                           billingDunningPickStage($inv2, $policy, date('Y-m-d')) === null);

echo "\nbillingDunningResolveRecipients()\n";
$pol = $dp;
$inv = ['bill_to_json' => '{"email":"ar@client.com"}', 'client_name' => 'Acme'];
$r = billingDunningResolveRecipients(0, $inv, 1, $pol);
$a('primary = bill_to.email',                          $r['to'] === 'ar@client.com' && $r['reason'] === 'invoice.bill_to.email');
$r2 = billingDunningResolveRecipients(0, $inv, 2, $pol);
$a('attempts<threshold → no CC',                       empty($r2['cc']));
// Note: client_contacts lookup falls back silently if table is absent

echo "\nbillingDunningRenderEmail()\n";
$inv = ['invoice_number' => 'INV-001', 'amount_due' => 1500, 'currency' => 'USD', 'due_date' => '2026-02-01'];
$e = billingDunningRenderEmail('soft', $inv, ['name' => 'Acme']);
$a('soft subject contains "Friendly reminder"',        str_contains($e['subject'], 'Friendly reminder'));
$a('soft html includes INV-001',                       str_contains($e['html'], 'INV-001'));
$e = billingDunningRenderEmail('firm', $inv, ['name' => 'Acme']);
$a('firm subject is "Past due"',                       str_contains($e['subject'], 'Past due'));
$e = billingDunningRenderEmail('final', $inv, ['name' => 'Acme']);
$a('final subject = "FINAL NOTICE"',                   str_contains($e['subject'], 'FINAL NOTICE'));

echo "\nbillingDunningWithinCadence() + IsWeekend()\n";
$a('cadence within 7d if sent 2d ago',                 billingDunningWithinCadence(['dunning_last_sent_at' => date('Y-m-d H:i:s', strtotime('-2 days'))], 7) === true);
$a('cadence not within 7d if sent 10d ago',            billingDunningWithinCadence(['dunning_last_sent_at' => date('Y-m-d H:i:s', strtotime('-10 days'))], 7) === false);
$a('cadence false if never sent',                      billingDunningWithinCadence(['dunning_last_sent_at' => null], 7) === false);
$a('Saturday detected as weekend',                     billingDunningIsWeekend('2026-02-07') === true);   // 2026-02-07 = Sat
$a('Wednesday NOT weekend',                            billingDunningIsWeekend('2026-02-04') === false);  // 2026-02-04 = Wed

echo "\nAPI: api/dunning.php\n";
$apiSrc = (string) file_get_contents(__DIR__ . '/../modules/billing/api/dunning.php');
$a('parses',                                           (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../modules/billing/api/dunning.php') . ' >/dev/null 2>&1; echo $?') === 0);
foreach (['queue','policy','send_now','pause','resume','ai_suggest'] as $action) {
    $a("?action={$action}",                            str_contains($apiSrc, "\$action === '{$action}'"));
}
$a("queue returns rows + policy + today",              str_contains($apiSrc, "'rows' => \$out, 'policy' => \$policy, 'today' => \$today"));
$a("send_now uses cf_mail_bootstrap",                  str_contains($apiSrc, '$svc    = cf_mail_bootstrap();'));
$a('send_now records suppressed when no recipient',    str_contains($apiSrc, "billingDunningRecordSend(\$tid, \$id, \$stage, '', [], 'suppressed', 'no recipient resolved')"));
$a('send_now idempotency by (id, stage, date)',        str_contains($apiSrc, "'dunning-' . \$id . '-' . \$stage['stage_no'] . '-' . date('Y-m-d')"));

echo "\nCron: scripts/dunning_daily.php\n";
$cronSrc = (string) file_get_contents(__DIR__ . '/../scripts/dunning_daily.php');
$a('parses',                                           (int) shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../scripts/dunning_daily.php') . ' >/dev/null 2>&1; echo $?') === 0);
$a('respects skip_weekends',                           str_contains($cronSrc, 'billingDunningIsWeekend($today)'));
$a('respects tenant paused_until',                     str_contains($cronSrc, "\$row['paused_until'] >= \$today"));
$a('skips do_not_contact clients',                     str_contains($cronSrc, "in_array((string) \$inv['client_name'], \$dnc, true)"));
$a('respects cadence_days',                            str_contains($cronSrc, 'billingDunningWithinCadence($inv, (int) $policy[\'cadence_days\'])'));
$a('respects max_attempts',                            str_contains($cronSrc, "\$inv['dunning_attempts'] >= (int) \$policy['max_attempts']"));

echo "\nUI: ui/DunningQueue.jsx + BillingModule nav\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/DunningQueue.jsx');
foreach (['billing-dunning-queue','billing-dunning-table','billing-dunning-policy-open'] as $tid) {
    $a("testid: {$tid}",                               str_contains($ui, "data-testid=\"{$tid}\""));
}
$a('row actions: send/pause/resume',                   str_contains($ui, 'billing-dunning-send-') && str_contains($ui, 'billing-dunning-pause-') && str_contains($ui, 'billing-dunning-resume-'));
$a('AI suggestion modal',                              str_contains($ui, 'billing-dunning-ai-modal') && str_contains($ui, 'billing-dunning-ai-suggestion'));
$a('policy editor 3 stage rows',                       str_contains($ui, 'billing-dunning-policy-stage-'));
$a('policy editor: do_not_contact field',              str_contains($ui, 'billing-dunning-policy-dnc'));

$mod = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('imports DunningQueue',                             str_contains($mod, "import DunningQueue from './DunningQueue'"));
$a('routes /dunning',                                  str_contains($mod, '<Route path="dunning" element={<DunningQueue />}'));
$a('nav adds Dunning',                                 str_contains($mod, "label: 'Dunning'"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
