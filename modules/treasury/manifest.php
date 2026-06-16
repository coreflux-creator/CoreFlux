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
    'version'     => '0.2.0',
    'depends_on'  => ['accounting'],
    'permissions' => [
        'treasury.view'             => 'View treasury dashboards',
        'treasury.view_bank_balances' => 'View bank balances and cash movement context',
        'treasury.deposit.manage'   => 'Manage deposit accounts',
        'treasury.liability.manage' => 'Manage liability accounts (credit cards, loans)',
        'treasury.feed.manage'      => 'Connect / sync bank feeds',
        'treasury.payment.view'     => 'View treasury payments and transfers',
        'treasury.payment.manage'   => 'Manage treasury payment operations',
        'treasury.create_payment'   => 'Create treasury payments',
        'treasury.approve_payment'  => 'Approve treasury payments',
        'treasury.execute_payment'  => 'Execute treasury payments and transfers',
        'treasury.create_transfer'  => 'Create treasury transfers',
        'treasury.approve_transfer' => 'Approve treasury transfers',
        'treasury.manage_forecast'  => 'Manage treasury forecasts and scenarios',
    ],
    'audit_events' => [
        'treasury.deposit.created',
        'treasury.deposit.updated',
        'treasury.deposit.deactivated',
        'treasury.liability.created',
        'treasury.liability.updated',
        'treasury.feed.synced',
        'treasury.forecast.run',
        'treasury.payment.created',
        'treasury.payment.submitted',
        'treasury.payment.workflow_started',
        'treasury.payment.workflow_start_failed',
        'treasury.payment.workflow_approved',
        'treasury.payment.workflow_rejected',
        'treasury.payment.approval_blocked',
        'treasury.payment.approval_rejected',
        'treasury.payment.approved',
        'treasury.payment.executed',
        'treasury.payment.execution_failed',
        'treasury.payment.voided',
        'treasury.recommendation.accepted',
        'treasury.recommendation.dismissed',
        'treasury.transfer.created',
        'treasury.transfer.submitted',
        'treasury.transfer.workflow_started',
        'treasury.transfer.workflow_start_failed',
        'treasury.transfer.workflow_approved',
        'treasury.transfer.workflow_rejected',
        'treasury.transfer.approval_blocked',
        'treasury.transfer.approval_rejected',
        'treasury.transfer.approved',
        'treasury.transfer.executed',
        'treasury.transfer.execution_failed',
        'treasury.transfer.voided',
        'treasury.transfer.completed',
        'treasury.intercompany.transfer.completed',
    ],
    'people_graph' => [
        'consumes' => true,
        'mode' => 'source_module_consumer',
        'object_types' => [
            'account' => [
                'responsibilities' => ['owner', 'operator', 'viewer', 'escalation_contact'],
            ],
            'payment' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver', 'operator', 'escalation_contact'],
                'approval_resource' => 'treasury.payment',
            ],
            'transfer' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver', 'operator', 'escalation_contact'],
                'approval_resource' => 'treasury.transfer',
            ],
            'sweep' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver', 'operator', 'ai_supervisor', 'escalation_contact'],
                'approval_resource' => 'treasury.sweep',
            ],
        ],
    ],
    'actions' => [
        ['name' => 'Overview',           'route' => 'overview',   'permission' => 'treasury.view'],
        ['name' => 'Deposit Accounts',   'route' => 'deposits',   'permission' => 'treasury.deposit.manage'],
        ['name' => 'Liability Accounts', 'route' => 'liabilities','permission' => 'treasury.liability.manage'],
        ['name' => 'Payments',           'route' => 'payments',   'permission' => 'treasury.payment.view'],
        ['name' => 'Transfers',          'route' => 'transfers',  'permission' => 'treasury.payment.view'],
    ],
];
