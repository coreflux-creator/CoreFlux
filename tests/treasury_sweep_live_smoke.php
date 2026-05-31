<?php
/**
 * treasury_sweep_live_smoke.php
 *
 * Treasury Sweep go-live wiring — internal-transfer recipient model.
 *
 *   • migration 086 — payment_instructions.is_internal_transfer flag
 *   • core/mercury_recipients.php —
 *       - mercurySweepDestinationSetCounterparty() helper
 *       - sweep_destination rejects bank-detail push (paste model)
 *   • core/mercury_payments.php —
 *       - mpCreate stamps source_mercury_account_id +
 *         is_internal_transfer=1 for sweep_destination recipients
 *       - mpAdvance routes through mpOriginateInternalTransfer
 *       - mpOriginateInternalTransfer: single-leg POST to Mercury,
 *         skips Funding state, lands at Submitted
 *   • core/sweep_rules.php — destination_recipient_id wired into
 *     SELECT, upsert, and validated against mercury_recipients.kind
 *   • core/treasury_sweep_engine.php — Layer 3c passes
 *     source_mercury_account_id from the rule into mpCreate
 *   • api/mercury_recipients.php — set_sweep_counterparty action
 *   • modules/treasury/ui/MercuryRecipients.jsx —
 *       sweep_destination badge, kind option in create modal,
 *       SetSweepCounterpartyModal
 *   • modules/treasury/ui/SweepRulesAdmin.jsx — destination_recipient_id
 *     picker fed by /api/mercury_recipients.php?kind=sweep_destination
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Treasury Sweep Live — internal-transfer model smoke\n";
echo "====================================================\n\n";

$ROOT = dirname(__DIR__);

// --- migration 086 ------------------------------------------------
echo "core/migrations/086_payment_instruction_internal_transfer.sql\n";
$mig = $read("{$ROOT}/core/migrations/086_payment_instruction_internal_transfer.sql");
$a('migration file exists',                       $mig !== '');
$a('checks COLUMN_NAME via information_schema',   str_contains($mig, "AND COLUMN_NAME  = 'is_internal_transfer'"));
$a('adds is_internal_transfer TINYINT(1)',        str_contains($mig, 'ADD COLUMN is_internal_transfer TINYINT(1)'));
$a('default 0',                                    str_contains($mig, 'NOT NULL DEFAULT 0'));
$a('positions AFTER source_ref',                  str_contains($mig, 'AFTER source_ref'));
$a('idempotent (DO 0 when present)',              str_contains($mig, "'DO 0'"));

// --- core/mercury_recipients.php ---------------------------------
echo "\ncore/mercury_recipients.php\n";
$rec = $read("{$ROOT}/core/mercury_recipients.php");
$a('declares mercurySweepDestinationSetCounterparty',
    str_contains($rec, 'function mercurySweepDestinationSetCounterparty'));
$a('  validates kind = sweep_destination',
    str_contains($rec, "\$rec['kind'] !== 'sweep_destination'"));
$a('  requires non-empty counterparty_id',
    str_contains($rec, 'counterparty_id required (paste the value from Mercury)'));
$a('  upserts mercury_recipient_mappings row idempotently',
    str_contains($rec, 'INSERT INTO mercury_recipient_mappings')
    && str_contains($rec, 'ON DUPLICATE KEY UPDATE')
    && str_contains($rec, 'mercury_id      = VALUES(mercury_id)'));
$a('  stores mapping with kind=counterparty',
    str_contains($rec, '"counterparty"'));
$a('mercuryRecipientPushToMercury rejects sweep_destination',
    str_contains($rec, "sweep_destination recipients are not pushed via API"));

// --- core/mercury_payments.php — mpCreate -----------------------
echo "\ncore/mercury_payments.php — mpCreate\n";
$pay = $read("{$ROOT}/core/mercury_payments.php");
$a('detects sweep_destination kind in mpCreate',
    str_contains($pay, "\$isInternalTransfer = \$rec['kind'] === 'sweep_destination' ? 1 : 0;"));
$a('requires source_mercury_account_id for internal transfers',
    str_contains($pay, "source_mercury_account_id required for sweep_destination instructions"));
$a('validates source mercury account belongs to tenant',
    str_contains($pay, 'SELECT id FROM mercury_accounts WHERE tenant_id = :t AND mercury_account_id = :m'));
$a('INSERT writes is_internal_transfer column',
    str_contains($pay, 'is_internal_transfer,'));
$a('INSERT stamps operating_mercury_account_id at create-time',
    str_contains($pay, ':it, :r, :a, :cur, :d, :n, :oma, :u')
    && str_contains($pay, "'oma' => \$sourceMercuryAccountId,"));

// --- core/mercury_payments.php — mpAdvance + originate ----------
echo "\ncore/mercury_payments.php — mpAdvance + internal transfer\n";
$a('mpAdvance branches on is_internal_transfer',
    str_contains($pay, "if ((int) (\$row['is_internal_transfer'] ?? 0) === 1)")
    && str_contains($pay, 'return mpOriginateInternalTransfer($tenantId, $row, $apiToken);'));
$a('legacy Approved → mpOriginateFunding still wired for vendors',
    str_contains($pay, 'return mpOriginateFunding($tenantId, $row, $apiToken, $defaults);'));
$a('mpOriginateInternalTransfer function declared',
    str_contains($pay, 'function mpOriginateInternalTransfer('));
$a('  reads source account from operating_mercury_account_id',
    str_contains($pay, "\$sourceAcctId = (string) (\$row['operating_mercury_account_id'] ?? '');"));
$a('  fails fast when source account missing',
    str_contains($pay, "internal transfer missing source Mercury account"));
$a('  looks up sweep_destination counterparty mapping',
    str_contains($pay, "mercury_kind = \"counterparty\"")
    && str_contains($pay, "(int) \$row['recipient_id']"));
$a('  fails fast when counterparty mapping missing',
    str_contains($pay, 'sweep_destination has no Mercury counterparty mapping'));
$a('  idempotency key suffix :transfer',
    str_contains($pay, "':transfer'"));
$a('  calls mercuryCreatePayment with source + counterparty',
    str_contains($pay, 'mercuryCreatePayment($apiToken, $sourceAcctId, ['));
$a('  transitions Approved → Submitted (skips Funding)',
    str_contains($pay, "mpTransition(\$tenantId, (int) \$row['id'], 'Submitted', 'internal transfer originated'"));
$a('  stamps funding_settled_at + skip marker for reporting parity',
    str_contains($pay, "'funding_settled_at'     => date('Y-m-d H:i:s'),")
    && str_contains($pay, "'funding_mercury_status' => 'internal_transfer_skip',"));
$a('  fails on missing Mercury txn id response',
    str_contains($pay, 'Mercury did not return a transaction id for internal transfer'));

// --- core/sweep_rules.php ---------------------------------------
echo "\ncore/sweep_rules.php\n";
$sr = $read("{$ROOT}/core/sweep_rules.php");
$a('SELECT lists destination_recipient_id',
    str_contains($sr, 'destination_recipient_id,'));
$a('list normalises destination_recipient_id to int',
    str_contains($sr, "'destination_recipient_id',"));
$a('upsert reads destination_recipient_id payload',
    str_contains($sr, "\$destRecipientId = !empty(\$data['destination_recipient_id'])"));
$a('upsert validates recipient exists in tenant',
    str_contains($sr, 'destination_recipient_id not found in this tenant'));
$a('upsert validates recipient kind = sweep_destination',
    str_contains($sr, 'destination_recipient_id must point at a recipient of kind=sweep_destination'));
$a('UPDATE writes destination_recipient_id column',
    str_contains($sr, 'destination_recipient_id = :drid'));
$a('INSERT writes destination_recipient_id column',
    str_contains($sr, '(:t, :n, :en, :src, :dst, :drid'));

// --- core/treasury_sweep_engine.php -----------------------------
echo "\ncore/treasury_sweep_engine.php\n";
$eng = $read("{$ROOT}/core/treasury_sweep_engine.php");
$a('Layer 3c passes source_mercury_account_id to mpCreate',
    str_contains($eng, "'source_mercury_account_id' => (string) \$rule['source_account_id'],"));
$a('still calls mpCreate with the same recipient_id',
    str_contains($eng, "'recipient_id'    => \$destRecipientId,"));
$a('still uses idempotency key sweep:{rule}:{date}',
    str_contains($eng, "sprintf('sweep:%d:%s', \$ruleId, \$now->format('Y-m-d'))"));

// --- api/mercury_recipients.php ---------------------------------
echo "\napi/mercury_recipients.php\n";
$apiR = $read("{$ROOT}/api/mercury_recipients.php");
$a('set_sweep_counterparty action handled',
    str_contains($apiR, "if (\$method === 'POST' && \$action === 'set_sweep_counterparty') {"));
$a('  validates recipient_id + counterparty_id present',
    str_contains($apiR, "if (\$recipientId <= 0)        api_error('recipient_id required', 422);")
    && str_contains($apiR, "if (\$counterpartyId === '')   api_error('counterparty_id required', 422);"));
$a('  invokes mercurySweepDestinationSetCounterparty',
    str_contains($apiR, 'mercurySweepDestinationSetCounterparty($tenantId, $recipientId, $counterpartyId'));
$a('  audits mercury.sweep_destination.counterparty_set',
    str_contains($apiR, "'mercury.sweep_destination.counterparty_set'"));

// --- modules/treasury/ui/MercuryRecipients.jsx ------------------
echo "\nmodules/treasury/ui/MercuryRecipients.jsx\n";
$mr = $read("{$ROOT}/modules/treasury/ui/MercuryRecipients.jsx");
$a('sweep_destination kind option in create modal',
    str_contains($mr, '<option value="sweep_destination">Sweep destination'));
$a('sweep_destination badge label rendered',
    str_contains($mr, "r.kind === 'sweep_destination' ? 'sweep dest' : 'vendor'"));
$a('Set Mercury counterparty action button',
    str_contains($mr, "data-testid={`mercury-set-sweep-counterparty-\${r.id}`}"));
$a('button label switches based on existing mercury_id',
    str_contains($mr, "{r.mercury_id ? 'Update counterparty' : 'Set Mercury counterparty'}"));
$a('SetSweepCounterpartyModal mounted via showSetCounterparty state',
    str_contains($mr, 'const [showSetCounterparty, setShowSetCounterparty]')
    && str_contains($mr, '<SetSweepCounterpartyModal'));
$a('Modal posts to set_sweep_counterparty endpoint',
    str_contains($mr, "'/api/mercury_recipients.php?action=set_sweep_counterparty'"));
$a('Modal has counterparty_id input testid',
    str_contains($mr, 'data-testid="mercury-sweep-counterparty-input"'));
$a('Modal save button testid',
    str_contains($mr, 'data-testid="mercury-set-sweep-counterparty-save"'));

// --- modules/treasury/ui/SweepRulesAdmin.jsx --------------------
echo "\nmodules/treasury/ui/SweepRulesAdmin.jsx\n";
$adm = $read("{$ROOT}/modules/treasury/ui/SweepRulesAdmin.jsx");
$a('blankForm initialises destination_recipient_id',
    str_contains($adm, 'destination_recipient_id:'));
$a('loads sweep_destination recipients on mount',
    str_contains($adm, "/api/mercury_recipients.php?kind=sweep_destination"));
$a('payload posts destination_recipient_id (null on blank)',
    str_contains($adm, 'destination_recipient_id:   form.destination_recipient_id   === \'\' ? null : Number(form.destination_recipient_id)'));
$a('renders empty-state CTA when no sweep recipients',
    str_contains($adm, 'data-testid="sweep-rule-no-recipients"'));
$a('renders select when sweep recipients available',
    str_contains($adm, 'data-testid="sweep-rule-destination-recipient-select"'));
$a('disables recipients with no Mercury counterparty id',
    str_contains($adm, 'disabled={!rec.mercury_id}'));
$a('table row flags missing counterparty link',
    str_contains($adm, 'sweep-rule-missing-recipient-')
    && str_contains($adm, 'no counterparty linked'));

// --- PHP syntax checks ------------------------------------------
echo "\nPHP syntax checks\n";
foreach ([
    'core/mercury_payments.php',
    'core/mercury_recipients.php',
    'core/sweep_rules.php',
    'core/treasury_sweep_engine.php',
    'api/mercury_recipients.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                           is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Treasury Sweep go-live: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
