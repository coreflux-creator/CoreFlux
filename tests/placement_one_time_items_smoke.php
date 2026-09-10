<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('core/migrations/138_placement_one_time_items.sql');
$billingMigration = $read('modules/billing/migrations/012_economic_item_source.sql');
$apMigration = $read('modules/ap/migrations/019_economic_item_source.sql');
$economics = $read('modules/placements/lib/economics.php');
$api = $read('modules/placements/api/economics.php');
$ui = $read('modules/placements/ui/PlacementDetail.jsx');
$billing = $read('modules/billing/lib/billing.php');
$billingApi = $read('modules/billing/api/invoices.php');
$ap = $read('modules/ap/lib/ap.php');
$settlement = $read('modules/time/lib/settlement_create.php');

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL {$label}\n";
};

echo "Placement one-time economics\n";
$assert('migration creates canonical one-time items',
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS placement_economic_items')
    && str_contains($migration, "ENUM('ar','ap','payroll','none')")
    && str_contains($migration, "ENUM('charge','credit')"));
$assert('settlement lines and obligations identify economic items',
    substr_count($migration, "'economic_item'") >= 3
    && str_contains($migration, 'ar_invoice_id')
    && str_contains($billingMigration, "'economic_item'")
    && str_contains($apMigration, "'economic_item'"));
$assert('placement API creates and validates one-time items',
    str_contains($api, "\$action === 'item'")
    && str_contains($api, 'placementEconomicsCreateItem')
    && str_contains($api, 'The selected recipient does not match this item destination')
    && str_contains($api, 'One-time items must use the placement contract currency'));
$assert('placement UI exposes destination recipient date amount and effect',
    str_contains($ui, 'One-time items')
    && str_contains($ui, 'Client invoice')
    && str_contains($ui, 'Vendor bill')
    && str_contains($ui, 'Payroll earning')
    && str_contains($ui, 'Margin-only cost')
    && str_contains($ui, 'Apply on'));
$assert('hard-coded manual fixed-cost inputs are retired from the editor',
    !str_contains($ui, '<Field label="Background / onboarding cost">')
    && !str_contains($ui, '<Field label="Other fixed cost">'));
$assert('client invoice builders append due items',
    str_contains($billing, 'function billingAppendOneTimeItems')
    && substr_count($billing, 'billingAppendOneTimeItems(') >= 3
    && str_contains($billingApi, 'placementEconomicsRecordItemObligation'));
$assert('invoice void releases unconsumed one-time items',
    str_contains($billingApi, 'ar_invoice_id = :invoice_id')
    && str_contains($billingApi, 'SET status = "void"'));
$assert('vendor bills receive named economic item lines exactly once',
    str_contains($ap, "'source_type'             => \$economicItemId > 0 ? 'economic_item'")
    && str_contains($ap, '$seenEconomicItems'));
$assert('payroll receives one-time earnings through the obligation ledger',
    str_contains($settlement, '$seenPayrollItems')
    && str_contains($settlement, "\$economicItemId > 0 ? 'economic_item' : 'time_bundle'")
    && str_contains($settlement, 'VALUES (?, ?, "time", "labor"'));
$assert('margin model reports one-time revenue cost and net impact',
    str_contains($economics, 'function placementEconomicsDecorateModelWithItems')
    && str_contains($economics, "\$model['one_time_net_impact']")
    && str_contains($ui, 'One-time net impact'));

echo "Placement one-time economics smoke: {$pass} OK / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
