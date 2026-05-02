<?php
/**
 * Payment Rails smoke test.
 *
 *  - Interface contract + driver registry
 *  - Driver resolution (paymentRailsResolveRail)
 *  - NACHA driver:
 *      * validates required fields & SEC code & ABA checksum
 *      * file structure: 94-char records, type codes 1/5/6/8/9, 10-record blocks
 *      * record counts / hashes / credit totals correct
 *      * splits PPD vs CCD into separate batches
 *  - Plaid Transfer scaffold:
 *      * isConfigured() honours env vars
 *      * originate() throws PaymentRailsNotConfigured when keys absent
 *      * exposes the playbook endpoint constants
 *  - Migration declares all expected columns
 *  - paymentRailsList() returns both rails for admin UIs
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/payment_rails.php';

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Interface + registry\n";
$a('paymentRailsGetDriver(nacha) returns NachaDriver',
    paymentRailsGetDriver('nacha') instanceof NachaDriver);
$a('paymentRailsGetDriver(plaid_transfer) returns PlaidTransferDriver',
    paymentRailsGetDriver('plaid_transfer') instanceof PlaidTransferDriver);
$a('PaymentRailsDriver interface declares originate / getStatus / isConfigured / name',
    method_exists(NachaDriver::class, 'originate')
    && method_exists(NachaDriver::class, 'getStatus')
    && method_exists(NachaDriver::class, 'isConfigured')
    && method_exists(NachaDriver::class, 'name'));

try { paymentRailsGetDriver('not_a_real_rail'); $a('unknown rail throws', false); }
catch (\InvalidArgumentException $e) { $a('unknown rail throws InvalidArgumentException', true); }

$list = paymentRailsList();
$a('paymentRailsList returns both rails',
    count(array_filter($list, fn($r) => $r['id'] === 'nacha')) === 1 &&
    count(array_filter($list, fn($r) => $r['id'] === 'plaid_transfer')) === 1);

echo "\nRail resolution precedence\n";
$a('per-row override wins',
    paymentRailsResolveRail('ap',     ['disbursement_rail' => 'plaid_transfer'], ['disbursement_rail' => 'nacha']) === 'plaid_transfer');
$a('tenant setting wins over default',
    paymentRailsResolveRail('payroll',[],                                       ['disbursement_rail' => 'plaid_transfer']) === 'plaid_transfer');
$a('falls back to nacha when nothing set',
    paymentRailsResolveRail('ap',     [], []) === 'nacha');
$a('blank override falls through',
    paymentRailsResolveRail('ap',     ['disbursement_rail' => '   '],         ['disbursement_rail' => 'nacha']) === 'nacha');

echo "\nNACHA driver — validation\n";
$nacha = new NachaDriver();
$a('isConfigured() always true for nacha',     $nacha->isConfigured() === true);
$a('name() === "nacha"',                       $nacha->name() === 'nacha');

try { $nacha->originate([], []); $a('empty batch rejected', false); }
catch (PaymentRailsOriginateException $e) { $a('empty batch rejected', true); }

try {
    $nacha->originate([
        ['external_ref' => 'x', 'recipient_name' => 'Y', 'account_routing' => '12345',
         'account_number' => '1', 'account_type' => 'checking', 'amount_cents' => 100, 'sec_code' => 'ppd'],
    ], []);
    $a('short routing rejected', false);
} catch (PaymentRailsOriginateException $e) { $a('short routing rejected', true); }

try {
    $nacha->originate([
        ['external_ref' => 'x', 'recipient_name' => 'Y', 'account_routing' => '999999999', // invalid checksum
         'account_number' => '1', 'account_type' => 'checking', 'amount_cents' => 100, 'sec_code' => 'ppd'],
    ], []);
    $a('bad routing checksum rejected', false);
} catch (PaymentRailsOriginateException $e) { $a('bad routing checksum rejected', true); }

try {
    $nacha->originate([
        ['external_ref' => 'x', 'recipient_name' => 'Y', 'account_routing' => '021000021',
         'account_number' => '1', 'account_type' => 'checking', 'amount_cents' => 0, 'sec_code' => 'ppd'],
    ], []);
    $a('zero amount rejected', false);
} catch (PaymentRailsOriginateException $e) { $a('zero amount rejected', true); }

try {
    $nacha->originate([
        ['external_ref' => 'x', 'recipient_name' => 'Y', 'account_routing' => '021000021',
         'account_number' => '1', 'account_type' => 'gold_bar', 'amount_cents' => 100, 'sec_code' => 'ppd'],
    ], []);
    $a('bad account_type rejected', false);
} catch (PaymentRailsOriginateException $e) { $a('bad account_type rejected', true); }

echo "\nNACHA driver — file structure\n";
$result = $nacha->originate([
    // PPD (consumer / payroll DD) — uses ABA 021000021 (JPMorgan Chase NY) which has a valid checksum
    ['external_ref' => 'payroll_line:1001', 'recipient_name' => 'JOHN W2 EMPLOYEE',
     'account_routing' => '021000021', 'account_number' => '987654321', 'account_type' => 'checking',
     'amount_cents' => 312500, 'sec_code' => 'ppd'],
    ['external_ref' => 'payroll_line:1002', 'recipient_name' => 'JANE W2 EMPLOYEE',
     'account_routing' => '021000021', 'account_number' => '111222333', 'account_type' => 'savings',
     'amount_cents' => 245000, 'sec_code' => 'ppd'],
    // CCD (business / vendor)
    ['external_ref' => 'ap_payment:7', 'recipient_name' => 'ACME CONSULTING LLC',
     'account_routing' => '021000021', 'account_number' => 'ACME-9988', 'account_type' => 'checking',
     'amount_cents' => 500000, 'sec_code' => 'ccd'],
], [
    'company_name'   => 'CoreFlux Demo',
    'company_id'     => '1234567890',
    'origin_routing' => '021000021',
    'effective_date' => '2026-03-15',
]);

$a('result has batch_id',                       isset($result['batch_id']) && $result['batch_id'] !== '');
$a('result.status = queued',                    $result['status'] === 'queued');
$a('per-item rail_external_ref populated',
    count($result['items']) === 3 &&
    !empty($result['items'][0]['rail_external_ref']) &&
    !empty($result['items'][1]['rail_external_ref']) &&
    !empty($result['items'][2]['rail_external_ref']));
$a('payload mime = text/plain',                 ($result['payload']['mime'] ?? '') === 'text/plain');
$a('payload filename starts with nacha-',       str_starts_with($result['payload']['filename'] ?? '', 'nacha-'));
$a('payload entries count = 3',                 ($result['payload']['entries'] ?? 0) === 3);
$a('payload batches count = 2 (PPD + CCD)',     ($result['payload']['batches'] ?? 0) === 2);
$a('payload credit_cents totals correctly',     ($result['payload']['credit_cents'] ?? 0) === 1057500);

$body  = (string) ($result['payload']['content'] ?? '');
$lines = array_values(array_filter(explode("\n", $body), fn($l) => $l !== ''));
$a('every record is exactly 94 chars',
    !empty($lines) && count(array_filter($lines, fn($l) => strlen($l) !== 94)) === 0);
$a('record count is a multiple of 10',          count($lines) % 10 === 0);
$a('first record is File Header (type 1)',      str_starts_with($lines[0], '1'));
$a('last  record is filler (9...) or File Control',
    str_starts_with($lines[count($lines)-1], '9'));

// Should contain: 1 file header + 2 batch headers + 3 entries + 2 batch controls + 1 file control = 9 records,
// padded with 1 filler to reach 10.
$counts = ['1'=>0,'5'=>0,'6'=>0,'8'=>0,'9'=>0];
foreach ($lines as $l) {
    $t = $l[0] ?? '';
    if (isset($counts[$t])) $counts[$t]++;
}
$a('exactly 1 File Header',                     $counts['1'] === 1);
$a('exactly 2 Batch Headers (PPD + CCD)',       $counts['5'] === 2);
$a('exactly 3 Entry Detail records',            $counts['6'] === 3);
$a('exactly 2 Batch Control records',           $counts['8'] === 2);
$a('contains File Control (one type 9, rest = filler)',
    $counts['9'] >= 1);

// SEC codes appear in batch headers — find them
$ppdHeader = false; $ccdHeader = false;
foreach ($lines as $l) {
    if ($l[0] !== '5') continue;
    // SEC code is at position 50 (0-indexed) — 3 chars
    $sec = substr($l, 50, 3);
    if ($sec === 'PPD') $ppdHeader = true;
    if ($sec === 'CCD') $ccdHeader = true;
}
$a('PPD batch header emitted',                  $ppdHeader);
$a('CCD batch header emitted',                  $ccdHeader);

// Find the entry-detail records & verify amount + transaction codes (22 = checking credit, 32 = savings credit)
$entryAmounts = [];
$txCodes      = [];
foreach ($lines as $l) {
    if ($l[0] !== '6') continue;
    $txCodes[]      = substr($l, 1, 2);
    $entryAmounts[] = (int) substr($l, 29, 10); // amount field at offset 29 (after txcode 2 + tdb 8 + cd 1 + account 17), length 10
}
$a('entry detail emits transaction code 22 (checking credit)', in_array('22', $txCodes, true));
$a('entry detail emits transaction code 32 (savings credit)',  in_array('32', $txCodes, true));
$a('entry detail amounts sum to total credit',                 array_sum($entryAmounts) === 1057500);

// Recipient name field at offset 54, 22 chars
$names = [];
foreach ($lines as $l) {
    if ($l[0] !== '6') continue;
    $names[] = trim(substr($l, 54, 22));
}
$a('recipient JOHN W2 EMPLOYEE present',        in_array('JOHN W2 EMPLOYEE', $names, true));
$a('recipient ACME CONSULTING LLC present',     in_array('ACME CONSULTING LLC', $names, true));

echo "\nPlaid Transfer driver scaffold\n";
$plaid = new PlaidTransferDriver();
$a('name() === "plaid_transfer"',               $plaid->name() === 'plaid_transfer');

// Wipe env to test isConfigured()
putenv('PLAID_CLIENT_ID');
putenv('PLAID_SECRET_SANDBOX');
putenv('PLAID_SECRET_PRODUCTION');
$a('isConfigured() false when env unset',       $plaid->isConfigured() === false);

try {
    $plaid->originate([
        ['external_ref' => 'x', 'recipient_name' => 'Y', 'account_routing' => '021000021',
         'account_number' => '1', 'account_type' => 'checking', 'amount_cents' => 100, 'sec_code' => 'ppd'],
    ], []);
    $a('originate throws PaymentRailsNotConfigured when env unset', false);
} catch (PaymentRailsNotConfiguredException $e) {
    $a('originate throws PaymentRailsNotConfigured when env unset', true);
}

$a('getStatus returns "unknown" when env unset', $plaid->getStatus('plaid_xfr_abc') === 'unknown');

// Simulate env vars being set — isConfigured should flip true (originate() still throws because Phase B not wired)
putenv('PLAID_CLIENT_ID=test_client_id');
putenv('PLAID_SECRET_SANDBOX=test_secret_sandbox');
putenv('PLAID_ENV=sandbox');
$a('isConfigured() true when sandbox env set',  $plaid->isConfigured() === true);
$a('host() returns sandbox URL',                $plaid->host() === 'https://sandbox.plaid.com');
putenv('PLAID_ENV=production');
putenv('PLAID_SECRET_PRODUCTION=test_secret_prod');
$a('host() returns production URL when env=production', $plaid->host() === 'https://production.plaid.com');

try {
    $plaid->originate([
        ['external_ref' => 'x', 'recipient_name' => 'Y', 'account_routing' => '021000021',
         'account_number' => '1', 'account_type' => 'checking', 'amount_cents' => 100, 'sec_code' => 'ppd'],
    ], []);
    $a('originate throws even when configured (Phase B not wired yet)', false);
} catch (PaymentRailsNotConfiguredException $e) {
    $a('originate throws even when configured (Phase B not wired yet)', true);
}

// Endpoint constants exposed (Phase B will use them)
$a('endpoint constant: link/token/create',         PlaidTransferDriver::ENDPOINT_LINK_TOKEN_CREATE      === '/link/token/create');
$a('endpoint constant: item/public_token/exchange',PlaidTransferDriver::ENDPOINT_PUBLIC_TOKEN_EXCHANGE  === '/item/public_token/exchange');
$a('endpoint constant: transfer/authorization',    PlaidTransferDriver::ENDPOINT_AUTHORIZATION_CREATE   === '/transfer/authorization/create');
$a('endpoint constant: transfer/create',           PlaidTransferDriver::ENDPOINT_TRANSFER_CREATE        === '/transfer/create');
$a('endpoint constant: transfer/get',              PlaidTransferDriver::ENDPOINT_TRANSFER_GET           === '/transfer/get');
$a('endpoint constant: transfer/event/sync',       PlaidTransferDriver::ENDPOINT_TRANSFER_EVENT_SYNC    === '/transfer/event/sync');
$a('endpoint constant: webhook_verification_key',  PlaidTransferDriver::ENDPOINT_WEBHOOK_VERIFICATION   === '/webhook_verification_key/get');
$a('host constant: sandbox',                       PlaidTransferDriver::HOST_SANDBOX                    === 'https://sandbox.plaid.com');
$a('host constant: production',                    PlaidTransferDriver::HOST_PRODUCTION                 === 'https://production.plaid.com');

// Reset env so other tests aren't polluted
putenv('PLAID_CLIENT_ID');
putenv('PLAID_SECRET_SANDBOX');
putenv('PLAID_SECRET_PRODUCTION');
putenv('PLAID_ENV');

echo "\nMigration 005_payment_rails.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/005_payment_rails.sql');
$a('creates ap_settings table',                  strpos($mig, 'CREATE TABLE IF NOT EXISTS ap_settings') !== false);
$a('payroll_settings.disbursement_rail',         strpos($mig, "ALTER TABLE payroll_settings") !== false &&
                                                  strpos($mig, 'disbursement_rail') !== false);
$a('ap_payments adds rail columns',
    strpos($mig, 'ALTER TABLE ap_payments') !== false &&
    strpos($mig, 'rail_external_ref') !== false &&
    strpos($mig, 'rail_status') !== false &&
    strpos($mig, 'rail_originated_at') !== false);
$a('payroll_runs adds rail columns',             strpos($mig, 'ALTER TABLE payroll_runs') !== false);
$a('tenant_payment_rails table for Phase B',
    strpos($mig, 'CREATE TABLE IF NOT EXISTS tenant_payment_rails') !== false &&
    strpos($mig, 'access_token_ct') !== false);
$a('uses utf8mb4_unicode_ci collation',          strpos($mig, 'utf8mb4_unicode_ci') !== false &&
                                                  stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
