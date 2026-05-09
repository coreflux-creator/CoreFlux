<?php
/**
 * Accounting Phase 2 Sprint A.7 smoke test — Consolidation foundations.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration 007_consolidation.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/007_consolidation.sql');
$a('creates accounting_entity_relationships table',  $c($mig, 'CREATE TABLE IF NOT EXISTS accounting_entity_relationships'));
$a('ownership_pct DECIMAL(7,4)',                     $c($mig, 'ownership_pct      DECIMAL(7,4)'));
$a('relationship_type enum',                         $c($mig, "ENUM('subsidiary','affiliate','branch','jv','other')"));
$a('consolidation_method enum (full/equity/cost/none)', $c($mig, "ENUM('full','equity','cost','none')"));
$a('effective_from + effective_to (dated edges)',    $c($mig, 'effective_from') && $c($mig, 'effective_to'));
$a('unique on (tenant, parent, child, effective_from)', $c($mig, 'UNIQUE KEY uq_aer (tenant_id, parent_entity_id, child_entity_id, effective_from)'));
$a('adds entity_id on billing_invoices (idempotent)',$c($mig, 'billing_invoices') && $c($mig, 'ADD COLUMN entity_id BIGINT'));
$a('adds entity_id on people (idempotent)',          $c($mig, "TABLE_NAME   = 'people'") && $c($mig, 'ADD INDEX idx_people_tenant_entity'));
$a('adds entity_id on ap_bills (idempotent)',        $c($mig, "TABLE_NAME   = 'ap_bills'") && $c($mig, 'ADD COLUMN entity_id BIGINT'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',             $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nlib/consolidation.php — engine\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/consolidation.php');
$a('entityRelationshipList',                         $c($lib, 'function entityRelationshipList'));
$a('entityRelationshipUpsert',                       $c($lib, 'function entityRelationshipUpsert'));
$a('entityRelationshipResolveDescendants',           $c($lib, 'function entityRelationshipResolveDescendants'));
$a('consolidateTrialBalance',                        $c($lib, 'function consolidateTrialBalance'));
$a('consolidateIncomeStatement',                     $c($lib, 'function consolidateIncomeStatement'));
$a('consolidateBalanceSheet',                        $c($lib, 'function consolidateBalanceSheet'));
$a('validates relationship_type whitelist',          $c($lib, "['subsidiary','affiliate','branch','jv','other']"));
$a('validates consolidation_method whitelist',       $c($lib, "['full','equity','cost','none']"));
$a('validates ownership_pct 0..100',                 $c($lib, 'ownership_pct must be 0..100'));
$a('descendants respect effective_from/to',
    $c($lib, 'effective_from <= :asof_lo') && $c($lib, '(effective_to IS NULL OR effective_to >= :asof_hi)'));
$a('descendants drop cost + none methods',           $c($lib, "\$edge['consolidation_method'] === 'none' || \$edge['consolidation_method'] === 'cost'"));
$a('TB aggregates per-entity (IN clause)',           $c($lib, 'je.entity_id IN (') && $c($lib, 'GROUP BY a.id'));
$a('TB eliminations where both sides in scope',
    $c($lib, 'AND je.entity_id IN (') && $c($lib, 'AND l.counterparty_entity_id IN ('));
$a('TB returns gross/elim/net columns per row',
    $c($lib, "'debit_gross'") && $c($lib, "'debit_elim'") && $c($lib, "'debit_net'"));
$a('IS marks is_consolidated=true',                  $c($lib, "'is_consolidated'=> true"));
$a('BS reuses consolidateTrialBalance',              $c($lib, 'consolidateTrialBalance(') && $c($lib, "'assets'"));
$a('audits relationship_created',                    $c($lib, "'accounting.consolidation.relationship_created'"));
$a('audits relationship_updated',                    $c($lib, "'accounting.consolidation.relationship_updated'"));

// Pure function sanity on input validation
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/accounting/lib/consolidation.php';
try { entityRelationshipUpsert(1, ['parent_entity_id' => 0, 'child_entity_id' => 1]); $a('rejects missing parent', false); }
catch (\InvalidArgumentException $e) { $a('rejects missing parent', true); }
try { entityRelationshipUpsert(1, ['parent_entity_id' => 1, 'child_entity_id' => 1]); $a('rejects parent == child', false); }
catch (\InvalidArgumentException $e) { $a('rejects parent == child', true); }
try { entityRelationshipUpsert(1, ['parent_entity_id' => 1, 'child_entity_id' => 2, 'ownership_pct' => 150]); $a('rejects pct > 100', false); }
catch (\InvalidArgumentException $e) { $a('rejects pct > 100', true); }
try { entityRelationshipUpsert(1, ['parent_entity_id' => 1, 'child_entity_id' => 2, 'relationship_type' => 'weird']); $a('rejects invalid relationship_type', false); }
catch (\InvalidArgumentException $e) { $a('rejects invalid relationship_type', true); }
try { consolidateTrialBalance(1, [], '2026-02-01'); $a('TB rejects empty entityIds', false); }
catch (\InvalidArgumentException $e) { $a('TB rejects empty entityIds', true); }

echo "\napi/entity_relationships.php\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/entity_relationships.php');
$a('GET list gates on accounting.entities.view',     $c($api, "'accounting.entities.view'"));
$a('POST gates on accounting.entities.manage',       $c($api, "'accounting.entities.manage'"));
$a('GET action=descendants resolves tree',           $c($api, "\$action === 'descendants'") && $c($api, 'entityRelationshipResolveDescendants('));
$a('POST upserts',                                   $c($api, 'entityRelationshipUpsert('));
$a('DELETE deactivates',                             $c($api, "'active' => 0"));

echo "\napi/reports.php — consolidate=1 wiring\n";
$rp = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/reports.php');
$a('loads consolidation lib',                        $c($rp, "require_once __DIR__ . '/../lib/consolidation.php'"));
$a('parses ?consolidate=1 flag',                     $c($rp, "\$consolidate = !empty(\$_GET['consolidate'])"));
$a('accepts entity_ids=1,2,3',                       $c($rp, "\$_GET['entity_ids']"));
$a('accepts root_entity_id + tree resolution',
    $c($rp, "\$_GET['root_entity_id']") && $c($rp, 'entityRelationshipResolveDescendants('));
$a('IS falls through to consolidated when flagged',  $c($rp, 'consolidateIncomeStatement('));
$a('BS consolidated branch',                         $c($rp, 'consolidateBalanceSheet('));
$a('TB consolidated branch',                         $c($rp, 'consolidateTrialBalance('));
$a('errors when consolidate=1 but no entities',      $c($rp, 'consolidate=1 requires entity_ids=... or root_entity_id=...'));

echo "\nUI — Consolidation.jsx\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/Consolidation.jsx');
$a('root test-id',                                   $c($ui, 'data-testid="accounting-consolidation"'));
$a('relationships section',                          $c($ui, 'accounting-consol-relationships'));
$a('form test-ids (parent/child/pct/type/method)',
    $c($ui, 'accounting-consol-parent') &&
    $c($ui, 'accounting-consol-child')  &&
    $c($ui, 'accounting-consol-pct')    &&
    $c($ui, 'accounting-consol-type')   &&
    $c($ui, 'accounting-consol-method') &&
    $c($ui, 'accounting-consol-save'));
$a('edges table',                                    $c($ui, 'accounting-consol-edges-table'));
$a('consolidated report block',                      $c($ui, 'accounting-consol-report'));
$a('report type selector (IS/BS/TB)',                $c($ui, 'accounting-consol-report-type'));
$a('entity picker',                                  $c($ui, 'accounting-consol-entity-picker'));
$a('IS/BS/TB views render',
    $c($ui, 'accounting-consol-is') &&
    $c($ui, 'accounting-consol-bs') &&
    $c($ui, 'accounting-consol-tb'));

echo "\nAccountingModule.jsx — Consolidation tab\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports Consolidation',                          $c($mod, "from './Consolidation'"));
$a('Consolidation tab',                              $c($mod, 'to="consolidation"'));
$a('Consolidation route',                            $c($mod, 'path="consolidation"'));

echo "\nmanifest.php\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/accounting/manifest.php');
$a('consolidation.relationship_created audit',       $c($man, "'accounting.consolidation.relationship_created'"));
$a('consolidation.relationship_updated audit',       $c($man, "'accounting.consolidation.relationship_updated'"));
$a('consolidation.relationship_deactivated audit',   $c($man, "'accounting.consolidation.relationship_deactivated'"));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
