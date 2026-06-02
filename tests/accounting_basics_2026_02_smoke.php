<?php
/**
 * Accounting basics batch (2026-02) — smoke
 * =========================================
 * Locks the contract for the four un-blocking fixes the user called
 * out on the Bookkeeping / Bank Rec / Consolidation screens:
 *
 *   1. Migration 101 — recon column aliases (statement_end_date,
 *      reconciled_through_date) + CoA backfill from
 *      accounting_bank_accounts / treasury_liability_accounts.
 *   2. Migration 102 — sub-tenant entity seed + parent_entity_id wiring.
 *   3. core/active_entity.php — cross-tenant entity surface for parent
 *      tenants (parent's session sees sub-tenant entities).
 *   4. Migration 103 — ap_payments.method ENUM gains 'mercury' + the
 *      AP PaymentsList.jsx surfaces it on the Record-payment modal.
 *
 *   php -d zend.assertions=1 /app/tests/accounting_basics_2026_02_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ────────────────────────────────────────── 1) migration 101
echo "Migration 101 — recon aliases + CoA backfill\n";
$m101 = (string) file_get_contents($ROOT . '/core/migrations/101_accounting_recon_aliases_and_coa_backfill.sql');
$a('migration 101 exists',                                $m101 !== '');
$a('adds statement_end_date column (idempotent)',         $c($m101, "TABLE_NAME   = 'accounting_reconciliations'") && $c($m101, 'ADD COLUMN statement_end_date DATE NULL'));
$a('adds reconciled_through_date column',                 $c($m101, 'ADD COLUMN reconciled_through_date DATE NULL'));
$a('backfills statement_end_date from period_end',        $c($m101, 'SET statement_end_date = period_end'));
$a('backfills reconciled_through_date from period_end',   $c($m101, 'SET reconciled_through_date = period_end'));
$a('creates lookup index for books_health query',         $c($m101, 'CREATE INDEX idx_arec_tenant_status_end ON accounting_reconciliations'));
$a('CoA backfill INSERT … SELECT from bank_accounts',     $c($m101, 'INSERT INTO accounting_accounts') && $c($m101, 'FROM accounting_bank_accounts aba'));
$a('CoA backfill is idempotent (NOT EXISTS guard)',       substr_count($m101, 'NOT EXISTS') >= 2);
$a('CoA backfill includes treasury_liability_accounts',   $c($m101, 'FROM treasury_liability_accounts tla'));
$a('liability backfill tags account_type=liability',      $c($m101, "'liability' AS account_type"));
$a('liability backfill tags normal_side=credit',          $c($m101, "'credit'    AS normal_side"));
$a('bank backfill tags account_type=asset',               $c($m101, "'asset'  AS account_type"));
$a('bank backfill labels with last4 suffix',              $c($m101, "CONCAT(' …', aba.last4)"));

// ────────────────────────────────────────── 2) migration 102
echo "\nMigration 102 — sub-tenant entity seed\n";
$m102 = (string) file_get_contents($ROOT . '/core/migrations/102_subtenant_entity_seed_and_parent_wiring.sql');
$a('migration 102 exists',                                $m102 !== '');
$a('INSERT IGNORE into accounting_entities',              $c($m102, 'INSERT IGNORE INTO accounting_entities'));
$a('uses tenant.name as legal_name',                      $c($m102, 't.name AS legal_name'));
$a('derives 4-letter code (MySQL 5.7-compatible)',        $c($m102, "REPLACE(REPLACE(REPLACE(REPLACE("));
$a('skips inactive tenants',                              $c($m102, 't.is_active = 1'));
$a('NOT EXISTS guard (idempotent)',                       $c($m102, 'NOT EXISTS') && substr_count($m102, 'FROM accounting_entities ae') >= 2);
$a('renames mis-named single-entity tenants',             $c($m102, 'SET ae.legal_name = t.name') && $c($m102, 'WHERE ae.legal_name <> t.name'));
$a('wires parent_entity_id for sub-tenants',              $c($m102, 'SET ae_sub.parent_entity_id = p.parent_entity_id'));
$a('parent_entity_id sourced from parent tenant',         $c($m102, 'MIN(ae_parent.id) AS parent_entity_id'));
$a('only touches NULL parent_entity_id (idempotent)',     $c($m102, 'WHERE ae_sub.parent_entity_id IS NULL'));

// ────────────────────────────────────────── 3) sub_tenants.php inline seed
echo "\ncore/sub_tenants.php — inline entity seed at provisioning\n";
$st = (string) file_get_contents($ROOT . '/core/sub_tenants.php');
$a('subTenantProvision seeds accounting_entities',        $c($st, 'INSERT INTO accounting_entities') && $c($st, "ON DUPLICATE KEY UPDATE legal_name = VALUES(legal_name)"));
$a('inline seed resolves parent_entity_id first',         $c($st, "MIN(id) FROM accounting_entities WHERE tenant_id = :p"));
$a('inline seed derives 4-letter code',                   $c($st, "preg_replace('/[^A-Za-z0-9]/', '', \$codeSrc)"));
$a('inline seed is best-effort (try/catch)',              $c($st, 'Migration 102 will catch this on next deploy'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/sub_tenants.php') . ' 2>&1', $o, $rc);
$a('php -l sub_tenants.php',                              $rc === 0);

// ────────────────────────────────────────── 4) active_entity.php cross-tenant
echo "\ncore/active_entity.php — cross-tenant entity surface\n";
$ae = (string) file_get_contents($ROOT . '/core/active_entity.php');
$a('activeEntityAvailable takes optional userId',         $c($ae, 'function activeEntityAvailable(int $tenantId, ?int $userId = null)'));
$a('helper activeEntityResolveAllowedTenantIds',          $c($ae, 'function activeEntityResolveAllowedTenantIds(int $tenantId)'));
$a('master tenant includes sub-tenants',                  $c($ae, "((string) (\$t['tenant_type'] ?? '')) === 'master'"));
$a('sub-tenants pulled by parent_id',                     $c($ae, 'WHERE parent_id = :p AND is_active = 1'));
$a('sub-tenant scope stays narrow',                       $c($ae, 'Sub-tenant: only its own entities by default'));
$a('result rows carry tenant_id + tenant_name',           $c($ae, 't.name AS tenant_name'));
$a('result rows carry tenant_kind',                       $c($ae, 't.tenant_type AS tenant_kind'));
$a('result rows tag is_active_tenant',                    $c($ae, "\$r['is_active_tenant'] = \$r['tenant_id'] === \$tenantId"));
$a('activeEntitySet validates against the allowed set',   $c($ae, 'activeEntityResolveAllowedTenantIds') && $c($ae, '$placeholders = implode'));
$a('ORDER BY active tenant first, then master',           $c($ae, "CASE WHEN ae.tenant_id = ? THEN 0 ELSE 1 END") && $c($ae, "CASE WHEN t.tenant_type = 'master'"));
$a('tenant-leak-allow comment present',                   $c($ae, 'tenant-leak-allow: cross-tenant by design'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/active_entity.php') . ' 2>&1', $o, $rc);
$a('php -l active_entity.php',                            $rc === 0);

// ────────────────────────────────────────── 5) migration 103 — mercury method enum
echo "\nMigration 103 — ap_payments.method enum + mercury\n";
$m103 = (string) file_get_contents($ROOT . '/core/migrations/103_ap_payments_method_mercury.sql');
$a('migration 103 exists',                                $m103 !== '');
$a('extends ap_payments.method ENUM with mercury',        $c($m103, "ENUM('ach','wire','check','card','cash','plaid','mercury','other')") && $c($m103, "TABLE_NAME   = 'ap_payments'"));
$a('mirrors change to ap_vendors_index.payment_method',   $c($m103, "TABLE_NAME   = 'ap_vendors_index'"));
$a('idempotent (guards via information_schema)',          substr_count($m103, 'PREPARE s FROM @sql') >= 2);

// ────────────────────────────────────────── 6) AP UI surfaces mercury
echo "\nmodules/ap/ui/PaymentsList.jsx — mercury as first-class method\n";
$ui = (string) file_get_contents($ROOT . '/modules/ap/ui/PaymentsList.jsx');
$a('RecordPaymentModal takes mercuryEnabled prop',        $c($ui, 'function RecordPaymentModal({ onClose, onCreated, plaidEnabled, mercuryEnabled })'));
$a('parent invocation passes mercuryConnected',           $c($ui, 'mercuryEnabled={mercuryConnected}'));
$a('method dropdown shows mercury option',                $c($ui, '<option value="mercury" disabled={!mercuryEnabled}'));
$a('mercury disabled label when not connected',           $c($ui, "{mercuryEnabled ? '' : ' (not connected)'}"));
$a('mercury helper card surfaces draft note',             $c($ui, 'data-testid="ap-pay-mercury-helper"'));
$a('mercury auto-routes to send_via_mercury after create',$c($ui, '/modules/ap/api/payments.php?action=send_via_mercury&id=${r.id}'));
$a('mercury auto-route is best-effort (try/catch)',       $c($ui, '/* surfaced on row-level chip anyway */'));

// ────────────────────────────────────────── summary
echo "\n=========================================\n";
echo "Accounting basics (2026-02) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
