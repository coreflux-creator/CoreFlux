<?php
declare(strict_types=1);

$migration = (string) file_get_contents(dirname(__DIR__) . '/modules/ap/migrations/021_vendor_payment_defaults_repair.sql');
$workflow = (string) file_get_contents(dirname(__DIR__) . '/.github/workflows/deploy-light-workspace.yml');
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$assert('repair creates the missing vendor payment method',
    str_contains($migration, "COLUMN_NAME='payment_method'")
    && str_contains($migration, 'ADD COLUMN payment_method'));
$assert('repair accepts Mercury as a vendor default',
    str_contains($migration, '"plaid","mercury","other"'));
$assert('repair restores every vendor payment-support field',
    array_reduce([
        'vendor_category', 'remit_to_email', 'remit_to_phone',
        'payment_account_last4', 'payment_account_ct', 'kms_key_version_payment',
    ], static fn(bool $ok, string $column): bool => $ok && str_contains($migration, "COLUMN_NAME='{$column}'"), true));
$assert('repair restores the vendor-category index',
    str_contains($migration, 'idx_apv_tenant_category'));
$assert('deployment ships and verifies the repair migration',
    substr_count($workflow, 'modules/ap/migrations/021_vendor_payment_defaults_repair.sql') >= 3);
$assert('deployment ships and verifies the Mercury payment enum migration',
    substr_count($workflow, 'core/migrations/103_ap_payments_method_mercury.sql') >= 3);

echo "AP vendor payment defaults repair smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
