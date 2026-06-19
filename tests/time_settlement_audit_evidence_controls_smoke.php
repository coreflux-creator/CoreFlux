<?php
/**
 * Time settlement audit evidence controls smoke
 *
 * Settlement stamps approved Time rows into Billing, AP, or Payroll. Those
 * stamps are material downstream handoffs, so every extraction path must use
 * the platform audit writer and preserve source-row before/after evidence.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$containsAll = static function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) return false;
    }
    return true;
};

$settlement = (string) file_get_contents($root . '/modules/time/lib/settlement.php');
$create = (string) file_get_contents($root . '/modules/time/lib/settlement_create.php');
$auditDoc = (string) file_get_contents($root . '/docs/AUDIT_GOVERNANCE.md');
$alignmentDoc = (string) file_get_contents($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "Settlement shared audit writer\n";
$a('settlement lib loads Time audit helpers',
    str_contains($settlement, "require_once __DIR__ . '/time.php'"));
$a('settlementAudit accepts platform audit options',
    str_contains($settlement, 'function settlementAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = [])'));
$a('settlementAudit delegates through timeAudit',
    $containsAll($settlement, [
        "'object_type' => 'time_settlement'",
        "'source' => \$meta['source'] ?? 'time'",
        'timeAudit($event, $meta, $targetId, $auditOpts)',
    ]));
$a('settlementAudit no longer inserts audit_log directly',
    !preg_match('/function settlementAudit[\s\S]*INSERT INTO audit_log/', $settlement));
$a('settlementAudit no longer depends on missing currentTenantContext',
    !str_contains($settlement, 'currentTenantContext'));

echo "\nSettlement source-row snapshots\n";
$a('settlement row snapshot helper selects full Time rows',
    $containsAll($settlement, [
        'function timeSettlementAuditRowsForTenant(int $tenantId, array $entryIds): array',
        'SELECT *',
        'FROM time_entries',
        'ORDER BY id ASC',
    ]));
$a('manual extract captures before and after rows',
    $containsAll($settlement, [
        '$beforeRows = timeSettlementAuditRowsForTenant($tenantId, $entryIds);',
        '$afterRows = timeSettlementAuditRowsForTenant($tenantId, $entryIds);',
        'settlementAudit("time.settlement.extracted_$target"',
        "'target_ref' => \$targetRef",
        "'before' => \$beforeRows",
        "'after' => \$afterRows",
    ]));
$a('un-extract captures before and after rows',
    $containsAll($settlement, [
        'settlementAudit("time.settlement.unextracted_$target"',
        "'reason' => \$reason",
        "'before' => \$beforeRows",
        "'after' => \$afterRows",
    ]));
$a('manual settlement audit carries tenant and actor evidence',
    $containsAll($settlement, [
        "'tenant_id' => \$tenantId",
        "'actor_user_id' => \$actorUserId",
    ]));

echo "\nAuto-create settlement evidence\n";
$a('billing/AP auto-create captures before and after source rows',
    $containsAll($create, [
        '$beforeRows = timeSettlementAuditRowsForTenant($tenantId, $entryIds);',
        '$afterRows = timeSettlementAuditRowsForTenant($tenantId, $entryIds);',
        'settlementAudit("time.settlement.auto_extracted_$target"',
        "'created' => \$created",
        "'before' => \$beforeRows",
        "'after' => \$afterRows",
    ]));
$a('payroll auto-create captures before and after source rows',
    $containsAll($create, [
        "settlementAudit('time.settlement.auto_extracted_payroll'",
        "'target'       => 'payroll'",
        "'skipped_count'=> count(\$skipped)",
        "'before' => \$beforeRows",
        "'after' => \$afterRows",
    ]));
$a('auto-create audit carries tenant and actor evidence',
    $containsAll($create, [
        "'tenant_id' => \$tenantId",
        "'actor_user_id' => \$actorUserId",
    ]));

echo "\nDocs\n";
$a('audit governance names settlement extraction',
    str_contains($auditDoc, 'Time entry/timesheet approvals and settlement extraction'));
$a('architecture alignment treats settlement as Time enterprise control',
    $containsAll($alignmentDoc, [
        'Settlement extraction and un-extraction are material downstream handoffs',
        'Manual settlement extract/un-extract and Billing/AP/Payroll auto-create',
        '`time_settlement` audit evidence',
    ]));

echo "\nTime settlement audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
