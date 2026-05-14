<?php
/**
 * Plaid mock — deterministic responses for accounts, transactions,
 * balances, items, and webhooks. No HTTP calls.
 *
 * Each mock function checks simShouldMock('plaid') so it can short-
 * circuit only when the harness is active. Production callers stay
 * untouched until they OPT IN by routing through these helpers.
 *
 * Coverage maps to the production /app/core/plaid_service.php surface:
 *   plaidGetAccounts(token)            → simMockPlaidGetAccounts()
 *   plaidSyncTransactions(token, cur)  → simMockPlaidSyncTransactions()
 *   plaidGetItem(token)                → simMockPlaidGetItem()
 *   plaidExchangePublicToken(public)   → simMockPlaidExchange()
 *
 * Deterministic: every response is seeded by the current sim seed +
 * the access_token string. Replaying with the same seed produces
 * byte-identical responses.
 */
declare(strict_types=1);

require_once __DIR__ . '/manager.php';

/** 3 synthetic checking + 1 savings account, all in USD. */
function simMockPlaidGetAccounts(string $accessToken): array {
    if (!simShouldMock('plaid')) throw new \RuntimeException('plaid mock not enabled');
    if (($f = simMockConsumeFault('plaid')) !== null) simMockApplyFault('plaid', $f);

    $tokHash = substr(hash('sha256', $accessToken), 0, 8);
    $accounts = [
        ['account_id' => "acc_{$tokHash}_chk_001", 'name' => 'Operating Checking', 'official_name' => 'Business Checking', 'mask' => '4582', 'type' => 'depository', 'subtype' => 'checking', 'balances' => ['available' => simRandFloat(50_000, 250_000),    'current' => simRandFloat(50_000, 250_000),    'iso_currency_code' => 'USD']],
        ['account_id' => "acc_{$tokHash}_chk_002", 'name' => 'Payroll Checking',   'official_name' => 'Business Checking', 'mask' => '4583', 'type' => 'depository', 'subtype' => 'checking', 'balances' => ['available' => simRandFloat(10_000,  80_000),    'current' => simRandFloat(10_000,  80_000),    'iso_currency_code' => 'USD']],
        ['account_id' => "acc_{$tokHash}_sav_001", 'name' => 'Reserve Savings',    'official_name' => 'Business Savings',  'mask' => '7891', 'type' => 'depository', 'subtype' => 'savings',  'balances' => ['available' => simRandFloat(100_000, 500_000),   'current' => simRandFloat(100_000, 500_000),   'iso_currency_code' => 'USD']],
        ['account_id' => "acc_{$tokHash}_cc_001",  'name' => 'Business Credit',    'official_name' => 'Business Visa',     'mask' => '0042', 'type' => 'credit',     'subtype' => 'credit card', 'balances' => ['available' => null, 'current' => simRandFloat(500, 12_000), 'iso_currency_code' => 'USD']],
    ];

    $resp = ['accounts' => $accounts, 'request_id' => 'sim_' . simRandId('REQ')];
    simMockRecordCall('plaid', 'get_accounts', ['access_token' => $tokHash], $resp);
    return $resp;
}

/** Generate N deterministic transactions for the calling tenant. */
function simMockPlaidSyncTransactions(string $accessToken, ?string $cursor, int $count = 50): array {
    if (!simShouldMock('plaid')) throw new \RuntimeException('plaid mock not enabled');
    if (($f = simMockConsumeFault('plaid')) !== null) simMockApplyFault('plaid', $f);

    $tokHash = substr(hash('sha256', $accessToken), 0, 8);
    $merchants = ['Amazon Web Services', 'Verizon Wireless', 'DELTA AIR LINES', 'Stripe Payouts',
                  'Slack Technologies', 'WeWork', 'Postmates', 'Office Depot',
                  'United Airlines', 'Uber for Business', 'Whole Foods', 'Sysco Foods'];
    $categories = [
        ['Service','Software','Cloud computing'],
        ['Service','Telecommunication'],
        ['Travel','Airlines'],
        ['Transfer','Deposit'],
        ['Service','Software','Communication'],
        ['Service','Real Estate','Coworking'],
        ['Food and Drink','Food Delivery'],
        ['Shops','Office Supplies'],
    ];

    $added = [];
    for ($i = 0; $i < $count; $i++) {
        $merchant = simRandPick($merchants);
        $cat      = simRandPick($categories);
        $isCredit = simRandInt(0, 9) === 0;   // 10% credits, 90% debits
        $amount   = $isCredit ? -1 * simRandFloat(50, 5_000) : simRandFloat(5, 4_000);
        $added[] = [
            'transaction_id'   => "tx_{$tokHash}_" . sprintf('%05d', $i),
            'account_id'       => "acc_{$tokHash}_chk_001",
            'amount'           => $amount,
            'iso_currency_code'=> 'USD',
            'date'             => simNow('Y-m-d'),
            'datetime'         => simNow('Y-m-d') . 'T' . sprintf('%02d:%02d:00Z', simRandInt(8, 22), simRandInt(0, 59)),
            'name'             => $merchant,
            'merchant_name'    => $merchant,
            'pending'          => simRandInt(0, 19) === 0,
            'category'         => $cat,
            'payment_channel'  => simRandPick(['online','in store','other']),
        ];
        simAdvance('+' . simRandInt(0, 6) . ' hours');
    }
    $resp = [
        'added' => $added, 'modified' => [], 'removed' => [],
        'next_cursor' => 'cur_' . simRandId('CUR'),
        'has_more' => false,
        'request_id' => 'sim_' . simRandId('REQ'),
    ];
    simMockRecordCall('plaid', 'sync_transactions', ['access_token' => $tokHash, 'cursor' => $cursor, 'count' => $count], $resp);
    return $resp;
}

function simMockPlaidGetItem(string $accessToken): array {
    if (!simShouldMock('plaid')) throw new \RuntimeException('plaid mock not enabled');
    $tokHash = substr(hash('sha256', $accessToken), 0, 8);
    $resp = [
        'item' => [
            'item_id'                      => "item_{$tokHash}",
            'institution_id'               => 'ins_sim_chase',
            'webhook'                      => 'https://sim.local/plaid/webhook',
            'error'                        => null,
            'available_products'           => ['auth','transactions','identity','liabilities'],
            'billed_products'              => ['auth','transactions'],
            'consent_expiration_time'      => null,
            'update_type'                  => 'background',
        ],
        'status' => ['transactions' => ['last_successful_update' => simNow('c')]],
        'request_id' => 'sim_' . simRandId('REQ'),
    ];
    simMockRecordCall('plaid', 'get_item', ['access_token' => $tokHash], $resp);
    return $resp;
}

function simMockPlaidExchange(string $publicToken): array {
    if (!simShouldMock('plaid')) throw new \RuntimeException('plaid mock not enabled');
    $hash = substr(hash('sha256', $publicToken), 0, 16);
    $resp = [
        'access_token' => 'access-sim-' . $hash,
        'item_id'      => 'item_sim_' . substr($hash, 0, 8),
        'request_id'   => 'sim_' . simRandId('REQ'),
    ];
    simMockRecordCall('plaid', 'exchange_public_token', ['public_token' => substr($publicToken, 0, 8)], $resp);
    return $resp;
}

/** Webhook fixture builder — used by scenarios that simulate duplicate
 *  or out-of-order webhook deliveries. */
function simMockPlaidWebhook(string $type, string $code, array $extra = []): array {
    $w = array_merge([
        'webhook_type'   => $type,
        'webhook_code'   => $code,
        'item_id'        => 'item_sim_' . simRandId(),
        'environment'    => 'sandbox',
    ], $extra);
    simMockRecordCall('plaid', 'webhook_built', $w, $w);
    return $w;
}
