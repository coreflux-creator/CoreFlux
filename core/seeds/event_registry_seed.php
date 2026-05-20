<?php
/**
 * Phase 1a — Event Registry seed.
 *
 * 51 canonical events for v1 (per /app/memory/EVENT_REGISTRY.md, user-
 * approved 2026-02-14).
 *
 * Run via the seed runner or manually:
 *   php core/seeds/event_registry_seed.php
 *
 * Idempotent: ON DUPLICATE KEY UPDATE keeps the row in sync with the doc
 * on every run. Removed events are NOT auto-deleted — they get a
 * `deprecated_at` stamp so existing events on disk keep validating.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function eventRegistrySeedRows(): array {
    // Convenience builders ----------------------------------------------------
    $req = fn (array $keys) => $keys;     // identity — readability only

    return [
        /* ---------- 1. Capital / Equity (2) ---------------------------------- */
        ['capital.contribution.received', 'capital',
            'Owner or investor injects capital into the entity.',
            $req(['amount','currency','contribution_type','equity_account_id']),
            ['memo','funding_round_id'],
            'investor', ['accounting'], [],
            'Dr cash / Cr equity (members capital).'],
        ['capital.distribution.paid', 'capital',
            'Owner takes a distribution out of the entity.',
            $req(['amount','currency','distribution_type','equity_account_id']),
            ['memo','recipient_partner_id'],
            'investor', ['accounting'], [],
            'Dr equity / Cr cash.'],

        /* ---------- 2. Sales / AR Cycle (6) ---------------------------------- */
        ['ar.invoice.drafted', 'ar',
            'AR invoice is created but not yet sent to the customer.',
            $req(['invoice_id','total','currency','lines']),
            ['draft_reason','expected_send_date'],
            'customer', ['accounting'], [],
            'No GL — staged for issue.'],
        ['ar.invoice.issued', 'ar',
            'AR invoice has been sent to the customer (the GL recognition event).',
            $req(['invoice_id','invoice_number','total','currency','lines','due_date']),
            ['client_company_id','client_name','po_reference'],
            'customer', ['accounting','treasury','collections'], ['ar.invoice.drafted'],
            'Dr AR / Cr revenue (per-line revenue account).'],
        ['ar.payment.received', 'ar',
            'Customer payment arrived (cash side); applications may follow.',
            $req(['payment_id','amount','currency','method']),
            ['client_company_id','reference','invoice_ids'],
            'customer', ['accounting','treasury'], [],
            'Dr cash / Cr AR (or unapplied cash if not yet matched).'],
        ['ar.cash.applied', 'ar',
            'Customer payment applied to a specific invoice.',
            $req(['payment_id','application_id','amount','invoice_id']),
            ['applied_by_user_id'],
            'customer', ['accounting'], ['ar.payment.received'],
            'Reclassifies unapplied cash to AR offset (no net GL impact).'],
        ['ar.credit_memo.issued', 'ar',
            'Credit memo issued against a customer.',
            $req(['memo_id','amount','currency','lines','reason']),
            ['original_invoice_id'],
            'customer', ['accounting'], ['ar.invoice.issued'],
            'Dr revenue / Cr AR.'],
        ['ar.writeoff.recorded', 'ar',
            'Customer invoice written off as uncollectable.',
            $req(['invoice_id','amount','currency','reason']),
            ['approver_user_id'],
            'customer', ['accounting'], ['ar.invoice.issued'],
            'Dr bad debt expense / Cr AR.'],

        /* ---------- 3. Procurement / AP Cycle (8) ---------------------------- */
        ['ap.po.issued', 'ap',
            'Formal purchase order issued to a vendor.',
            $req(['po_id','total','currency','lines','vendor_id']),
            ['expected_delivery_date','department','project_id'],
            'vendor', ['accounting','procurement'], [],
            'Memo / commitment tracking — no GL until bill received.'],
        ['ap.bill.received', 'ap',
            'Vendor bill received and entered but not yet approved.',
            $req(['bill_id','total','currency','lines']),
            ['vendor_company_id','bill_reference','received_date'],
            'vendor', ['accounting'], ['ap.po.issued'],
            'No GL — sits in pending review.'],
        ['ap.bill.approved', 'ap',
            'Vendor bill approved for payment (the GL recognition event).',
            $req(['bill_id','internal_ref','amount','currency','lines']),
            ['vendor_company_id','vendor_name','due_date'],
            'vendor', ['accounting','treasury'], ['ap.bill.received','ap.po.issued'],
            'Dr expense (per-line) / Cr AP.'],
        ['ap.bill.rejected', 'ap',
            'Vendor bill rejected and returned to vendor.',
            $req(['bill_id','reason']),
            ['rejector_user_id'],
            'vendor', ['accounting'], ['ap.bill.received'],
            'No GL.'],
        ['ap.payment.scheduled', 'ap',
            'Payment queued in treasury for a future date.',
            $req(['bill_id','payment_date','amount','currency','method']),
            ['scheduled_batch_id'],
            'vendor', ['treasury'], ['ap.bill.approved'],
            'Memo — does not move cash until executed.'],
        ['ap.payment.executed', 'ap',
            'Payment actually disbursed to the vendor.',
            $req(['payment_id','bill_ids','amount','currency','method']),
            ['bank_ref','bank_account_id'],
            'vendor', ['accounting','treasury'], ['ap.payment.scheduled','ap.bill.approved'],
            'Dr AP / Cr cash (or clearing account until cleared).'],
        ['ap.payment.cleared', 'ap',
            'Bank confirms the AP payment has cleared.',
            $req(['payment_id','cleared_date']),
            ['bank_transaction_id'],
            'vendor', ['accounting','treasury'], ['ap.payment.executed'],
            'Reclassifies clearing → cash if a clearing account was used.'],
        ['ap.vendor_credit.received', 'ap',
            'Vendor issued a credit memo against prior bills.',
            $req(['credit_id','amount','currency','reason']),
            ['original_bill_id'],
            'vendor', ['accounting'], ['ap.bill.approved'],
            'Dr AP / Cr expense (or vendor credits liability).'],

        /* ---------- 4. Treasury (10) ----------------------------------------- */
        ['treasury.transfer.completed', 'treasury',
            'Internal bank-to-bank transfer between two accounts of the same entity.',
            $req(['from_bank_account_id','to_bank_account_id','amount','currency']),
            ['bank_ref','description'],
            'bank', ['accounting'], [],
            'Dr cash (destination) / Cr cash (source).'],
        ['treasury.intercompany.transfer.completed', 'treasury',
            'Cash transfer between two entities under the same tenant.',
            $req(['from_entity_id','to_entity_id','amount','currency']),
            ['from_bank_account_id','to_bank_account_id','memo'],
            'internal_entity', ['accounting'], [],
            'Dr cash + IC receivable / Cr cash + IC payable.'],
        ['treasury.bank_transaction.matched', 'treasury',
            'Bank line reconciled to an internal record (bill, invoice, transfer, fee).',
            $req(['bank_txn_id','internal_ref','amount','currency','match_type']),
            ['confidence','matched_by_user_id'],
            'bank', ['accounting'], [],
            'Reclass or memo depending on match_type.'],
        ['treasury.bank_transaction.unmatched', 'treasury',
            'Bank line did not auto-match — goes to exception queue.',
            $req(['bank_txn_id','amount','currency','description']),
            ['suggested_match_id','confidence'],
            'bank', ['accounting'], [],
            'Suspense — Dr cash / Cr suspense (until classified).'],
        ['treasury.bank_fee.detected', 'treasury',
            'Bank service fee identified in a bank line.',
            $req(['bank_txn_id','fee_amount','currency','fee_type']),
            [],
            'bank', ['accounting'], [],
            'Dr bank fees expense / Cr cash.'],
        ['treasury.interest.received', 'treasury',
            'Interest income credited to a deposit account.',
            $req(['bank_txn_id','amount','currency']),
            [],
            'bank', ['accounting'], [],
            'Dr cash / Cr interest income.'],
        ['treasury.interest.paid', 'treasury',
            'Interest paid on a loan or credit facility.',
            $req(['loan_id','amount','currency','period']),
            [],
            'lender', ['accounting'], [],
            'Dr interest expense / Cr cash.'],
        ['treasury.loan.payment.made', 'treasury',
            'Loan repayment (principal + interest).',
            $req(['loan_id','principal','interest','total','currency']),
            ['payment_date','bank_ref'],
            'lender', ['accounting'], [],
            'Dr notes payable + interest exp / Cr cash.'],
        ['treasury.fx.revaluation.recorded', 'treasury',
            'Month-end FX revaluation on non-functional-currency balances.',
            $req(['pair','rate','exposure_amount','gain_or_loss']),
            ['reval_date','source'],
            'bank', ['accounting'], [],
            'Dr / Cr unrealized FX gain or loss.'],
        ['treasury.cash.received_uncategorized', 'treasury',
            'Cash arrived in a bank account with no obvious source — flagged for review.',
            $req(['bank_txn_id','amount','currency','description']),
            [],
            'unknown', ['accounting'], [],
            'Suspense — Dr cash / Cr suspense.'],
        ['treasury.bank_transaction.categorized', 'treasury',
            'Bank line categorized by a user (single category) OR split across multiple categories. Carries a fully-rendered balanced JE in payload.lines so the posting engine can passthrough-post.',
            $req(['bank_txn_id','amount','currency','direction','lines']),
            ['memo','ai_suggestion_id','split_count','counterpart_account_id'],
            'bank', ['accounting'], ['treasury.bank_transaction.matched'],
            'Passthrough: payload.lines is the rendered JE (Dr <category(s)> / Cr <bank>, or reverse for inflow).'],

        /* ---------- 5. Payroll (8) ------------------------------------------- */
        ['payroll.run.calculated', 'payroll',
            'Payroll run has been computed; awaiting approval.',
            $req(['run_id','period_start','period_end','gross','taxes','net','employee_count']),
            ['provider'],
            'employee', ['payroll','accounting'], [],
            'Memo.'],
        ['payroll.run.approved', 'payroll',
            'Payroll run approved — GL recognition for wage + tax liabilities.',
            $req(['run_id','gross','taxes','net','currency']),
            ['employer_tax_total'],
            'employee', ['accounting','treasury'], ['payroll.run.calculated'],
            'Dr wages + employer taxes / Cr accrued payroll + tax liab.'],
        ['payroll.cash.disbursed', 'payroll',
            'Net pay actually sent to employees.',
            $req(['run_id','amount','currency','bank_account_id']),
            ['method','bank_ref'],
            'employee', ['treasury','accounting'], ['payroll.run.approved'],
            'Dr accrued payroll / Cr cash.'],
        ['payroll.tax_liability.accrued', 'payroll',
            'Employer + withheld payroll taxes accrued (typically subsumed by run.approved).',
            $req(['run_id','jurisdiction','amount','currency']),
            [],
            'tax_authority', ['accounting'], ['payroll.run.approved'],
            'Folds into the JE created by payroll.run.approved.'],
        ['payroll.tax_liability.paid', 'payroll',
            'Payroll taxes actually remitted to a tax authority.',
            $req(['jurisdiction','period','amount','currency']),
            ['bank_ref','filing_id'],
            'tax_authority', ['treasury','accounting'], ['payroll.tax_liability.accrued'],
            'Dr payroll tax liab / Cr cash.'],
        ['payroll.fringe.accrued', 'payroll',
            'Fringe benefit (health, 401k, PTO) accrual.',
            $req(['run_id','fringe_type','amount','currency']),
            ['carrier_id'],
            'employee', ['accounting'], ['payroll.run.approved'],
            'Dr fringe expense / Cr accrued fringe.'],
        ['payroll.401k.contribution.remitted', 'payroll',
            '401k contributions sent to the custodian.',
            $req(['run_id','employee_portion','employer_match','custodian']),
            [],
            'employee', ['treasury','accounting'], ['payroll.fringe.accrued'],
            'Dr accrued 401k / Cr cash.'],
        ['payroll.ptd.adjustment', 'payroll',
            'Period-to-date adjustment (retro, true-up, PTO take, etc.).',
            $req(['employee_id','adjustment_type']),
            ['hours','amount','reason'],
            'employee', ['accounting'], [],
            'Dr or Cr accrued PTO / wages depending on type.'],

        /* ---------- 6. Staffing (5) ------------------------------------------ */
        ['staffing.worker.placed', 'staffing',
            'New worker placed on a client engagement.',
            $req(['placement_id','person_id','client_id','engagement_type','start_date','bill_rate','pay_rate']),
            ['recruiter_id','worksite_state','vendor_id'],
            'customer', ['staffing','accounting'], [],
            'Memo — starts the revenue lineage chain.'],
        ['staffing.worker_hours.submitted', 'staffing',
            'Weekly timesheet submitted by worker.',
            $req(['timesheet_id','person_id','period_start','period_end','total_hours']),
            ['placement_id'],
            'customer', ['staffing'], ['staffing.worker.placed'],
            'No GL — awaits approval.'],
        ['staffing.worker_hours.approved', 'staffing',
            'Timesheet approved — labor cost + revenue recognition.',
            $req(['timesheet_id','person_id','placement_id','hours']),
            ['revenue','cost','is_w2','is_1099','is_c2c','is_internal'],
            'customer', ['accounting','treasury'], ['staffing.worker_hours.submitted'],
            'W2: Dr unbilled-AR / Cr accrued payroll. 1099/C2C: / Cr accrued AP.'],
        ['staffing.placement.ended', 'staffing',
            'Placement closes (assignment end).',
            $req(['placement_id','person_id','end_date','reason']),
            [],
            'customer', ['staffing','accounting'], ['staffing.worker.placed'],
            'Memo.'],
        ['staffing.worker.classification_changed', 'staffing',
            'Worker classification flipped (W2 ↔ 1099 / C2C).',
            $req(['person_id','from_type','to_type','effective_date']),
            ['reason','approved_by_user_id'],
            'employee', ['staffing','accounting'], [],
            'Memo — flag for year-end tax re-issue review.'],

        /* ---------- 7. Fixed Assets (2) -------------------------------------- */
        ['fixed_asset.acquired', 'fixed_assets',
            'Fixed asset placed in service.',
            $req(['asset_id','cost','currency','useful_life_months','in_service_date']),
            ['vendor_id','category'],
            'vendor', ['accounting'], ['ap.bill.approved'],
            'Dr fixed asset / Cr cash or AP.'],
        ['fixed_asset.depreciation.recorded', 'fixed_assets',
            'Monthly depreciation booked.',
            $req(['asset_id','period','amount','method']),
            [],
            'system', ['accounting'], ['fixed_asset.acquired'],
            'Dr depreciation expense / Cr accumulated depreciation.'],

        /* ---------- 8. Tax (3) ----------------------------------------------- */
        ['tax.sales_tax.collected', 'tax',
            'Sales tax collected on an AR invoice.',
            $req(['invoice_id','jurisdiction','amount','currency','rate']),
            [],
            'tax_authority', ['accounting'], ['ar.invoice.issued'],
            'Folds into Dr AR / Cr sales tax liab leg of the invoice JE.'],
        ['tax.sales_tax.remitted', 'tax',
            'Sales tax filed and paid to a jurisdiction.',
            $req(['jurisdiction','period','amount','currency']),
            ['return_id','bank_ref'],
            'tax_authority', ['treasury','accounting'], ['tax.sales_tax.collected'],
            'Dr sales tax liab / Cr cash.'],
        ['tax.income_tax.estimated_payment', 'tax',
            'Quarterly estimated income-tax payment.',
            $req(['jurisdiction','period','amount','currency']),
            ['bank_ref'],
            'tax_authority', ['treasury','accounting'], [],
            'Dr prepaid taxes / Cr cash.'],

        /* ---------- 9. Period / Close (4) ------------------------------------ */
        ['period.close.initiated', 'period',
            'Month-end or quarter-end close kickoff.',
            $req(['period_start','period_end','initiated_by_user_id']),
            [],
            'system', ['accounting'], [],
            'Memo.'],
        ['period.close.locked', 'period',
            'Period locked — no further posting without override.',
            $req(['period_start','period_end','locked_by_user_id']),
            [],
            'system', ['accounting'], ['period.close.initiated'],
            'Memo.'],
        ['period.close.reopened', 'period',
            'Period reopened — requires explicit override.',
            $req(['period_start','period_end','reopened_by_user_id','reason']),
            ['approver_user_id'],
            'system', ['accounting'], ['period.close.locked'],
            'Memo (audit-critical).'],
        ['period.close.adjustment.recorded', 'period',
            'Top-side adjustment posted during close.',
            $req(['je_id','period_end','adjustment_type','amount']),
            [],
            'system', ['accounting'], ['period.close.initiated'],
            'Varies by adjustment type.'],

        /* ---------- 10. Reversals / Adjustments (3) -------------------------- */
        ['accounting.je.reversed', 'accounting',
            'Existing journal entry reversed in full.',
            $req(['original_je_id','reversal_je_id','reason']),
            ['reversed_by_user_id'],
            'system', ['accounting'], [],
            'Mirrors original with flipped Dr/Cr.'],
        ['accounting.je.corrected', 'accounting',
            'Existing journal entry corrected with a net-adjustment JE.',
            $req(['original_je_id','correction_je_id','reason','fields_changed']),
            ['corrected_by_user_id'],
            'system', ['accounting'], [],
            'Net adjustment.'],
        ['accounting.ai.interpretation_overridden', 'accounting',
            'Reviewer overrode the AI proposed JE.',
            $req(['event_id','ai_proposed_je_id','human_corrected_je_id','reviewer_user_id']),
            ['rationale'],
            'system', ['accounting','ai'], [],
            'Learning input — feeds AI memory store.'],
    ];
}

/**
 * Deprecated aliases — keep legacy event_type strings valid for one release
 * cycle while emit sites migrate. Each row is a registry entry with
 * `deprecated_alias_for` set to the new canonical name.
 */
function eventRegistryAliasRows(): array {
    return [
        ['billing.invoice.sent',      'ar.invoice.issued'],
        ['billing.payment.received',  'ar.payment.received'],
        ['treasury.payment.executed', 'ap.payment.executed'],
    ];
}

function eventRegistrySeedRun(\PDO $pdo): array {
    $rows = eventRegistrySeedRows();
    $upsert = $pdo->prepare(
        "INSERT INTO event_registry
          (event_type, schema_version, domain, description, required_payload_keys,
           optional_payload_keys, counterparty_type, expected_consumers,
           parent_event_types, typical_accounting)
         VALUES (:t, 1, :d, :desc, :req, :opt, :cp, :consumers, :parents, :acct)
         ON DUPLICATE KEY UPDATE
           domain                = VALUES(domain),
           description           = VALUES(description),
           required_payload_keys = VALUES(required_payload_keys),
           optional_payload_keys = VALUES(optional_payload_keys),
           counterparty_type     = VALUES(counterparty_type),
           expected_consumers    = VALUES(expected_consumers),
           parent_event_types    = VALUES(parent_event_types),
           typical_accounting    = VALUES(typical_accounting)"
    );
    $count = 0;
    foreach ($rows as $r) {
        [$type, $domain, $desc, $req, $opt, $cp, $consumers, $parents, $acct] = $r;
        $upsert->execute([
            't'        => $type,
            'd'        => $domain,
            'desc'     => $desc,
            'req'      => json_encode($req),
            'opt'      => json_encode($opt),
            'cp'       => $cp,
            'consumers'=> json_encode($consumers),
            'parents'  => json_encode($parents),
            'acct'     => $acct,
        ]);
        $count++;
    }

    // Aliases: stamp deprecated_at = NOW() so the validator accepts the old
    // names but the AI / dashboard surface flags them for migration.
    $aliasUpsert = $pdo->prepare(
        "INSERT INTO event_registry
            (event_type, schema_version, domain, description,
             required_payload_keys, deprecated_at, deprecated_alias_for)
         VALUES (:t, 1, 'alias', CONCAT('Legacy alias of ', :canonical1),
                 JSON_ARRAY(), NOW(), :canonical2)
         ON DUPLICATE KEY UPDATE
             deprecated_at        = COALESCE(deprecated_at, NOW()),
             deprecated_alias_for = VALUES(deprecated_alias_for)"
    );
    $aliasCount = 0;
    foreach (eventRegistryAliasRows() as [$legacy, $canonical]) {
        $aliasUpsert->execute(['t' => $legacy, 'canonical1' => $canonical, 'canonical2' => $canonical]);
        $aliasCount++;
    }

    return ['events_seeded' => $count, 'aliases_seeded' => $aliasCount];
}

// CLI entrypoint.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $pdo = getDB();
    $res = eventRegistrySeedRun($pdo);
    echo "Seeded {$res['events_seeded']} canonical events + {$res['aliases_seeded']} aliases.\n";
}
