<?php
/**
 * Accounting bank-rec facade for shared statement-line actions.
 *
 * The implementation lives with the Treasury account transactions API because
 * it handles both deposit and liability statement feeds. It is gated by
 * accounting.bank.manage, so exposing it through the accounting module keeps
 * the bank reconciliation UI on the v1 accounting API surface without
 * duplicating the posting workflow.
 */
require __DIR__ . '/../../treasury/api/account_transactions.php';
