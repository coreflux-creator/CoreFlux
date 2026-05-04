<?php
/**
 * Treasury module — central home for deposit accounts, liability accounts,
 * and (future) cash positioning, forecasting, and intercompany sweeps.
 *
 * Today's scope:
 *   - Deposit Accounts (bank/cash/savings) with Plaid connect + sync
 *   - Liability Accounts (credit cards, loans, lines of credit)
 *   - Overview dashboard (totals per class)
 *
 * Future scope (placeholders in UI):
 *   - Cash forecast (13-week rolling)
 *   - Intercompany sweeps + lockbox
 *   - Loan amortization + covenant tracking
 */
return [
    'id'          => 'treasury',
    'name'        => 'Treasury',
    'version'     => '0.1.0',
    'depends_on'  => ['accounting'],
    'permissions' => [
        'treasury.view'             => 'View treasury dashboards',
        'treasury.deposit.manage'   => 'Manage deposit accounts',
        'treasury.liability.manage' => 'Manage liability accounts (credit cards, loans)',
        'treasury.feed.manage'      => 'Connect / sync bank feeds',
    ],
    'audit_events' => [
        'treasury.deposit.created',
        'treasury.deposit.updated',
        'treasury.deposit.deactivated',
        'treasury.liability.created',
        'treasury.liability.updated',
        'treasury.feed.synced',
    ],
    'actions' => [
        ['name' => 'Overview',           'route' => 'overview',   'permission' => 'treasury.view'],
        ['name' => 'Deposit Accounts',   'route' => 'deposits',   'permission' => 'treasury.deposit.manage'],
        ['name' => 'Liability Accounts', 'route' => 'liabilities','permission' => 'treasury.liability.manage'],
    ],
];
