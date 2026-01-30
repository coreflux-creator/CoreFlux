<?php
/**
 * Accounting Module Manifest
 * 
 * This manifest defines how the Accounting module integrates with CoreFlux.
 * Other modules should follow this same structure.
 */

return [
    'id' => 'accounting',
    'name' => 'Accounting',
    'icon' => '/assets/icons/icon-accounting.png',
    'description' => 'General ledger, accounts payable, accounts receivable, and financial reporting.',
    
    // Navigation items for the sidebar
    'navItems' => [
        ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
        ['name' => 'General Ledger', 'route' => 'general_ledger', 'icon' => 'icon-gl.png'],
        ['name' => 'Chart of Accounts', 'route' => 'chart_of_accounts', 'icon' => 'icon-coa.png'],
        ['name' => 'Journal Entries', 'route' => 'journal_entries', 'icon' => 'icon-journal.png'],
        ['name' => 'Accounts Payable', 'route' => 'accounts_payable', 'icon' => 'icon-ap.png'],
        ['name' => 'Accounts Receivable', 'route' => 'accounts_receivable', 'icon' => 'icon-ar.png'],
        ['name' => 'Bank Reconciliation', 'route' => 'bank_reconciliation', 'icon' => 'icon-bank.png'],
        ['name' => 'Period Close', 'route' => 'period_close', 'icon' => 'icon-period.png'],
        ['name' => 'Reports', 'route' => 'reports', 'icon' => 'icon-reporting.png'],
    ],
    
    // Hero configuration for the module overview page
    'hero' => [
        'eyebrow' => 'Financial Management',
        'title' => 'Accounting',
        'subtitle' => 'Manage your general ledger, accounts payable, accounts receivable, and generate comprehensive financial reports.',
        'actions' => [
            ['label' => 'New Journal Entry', 'href' => '?page=journal_new', 'primary' => true],
            ['label' => 'View Reports', 'href' => '?page=reports', 'primary' => false],
        ],
    ],
    
    // Feature cards for the overview page
    'features' => [
        [
            'title' => 'General Ledger',
            'description' => 'Chart of accounts, journal entries, and trial balance.',
            'icon' => '/assets/icons/icon-gl.png',
            'href' => '?page=general_ledger',
        ],
        [
            'title' => 'Accounts Payable',
            'description' => 'Vendor invoices, payments, and aging reports.',
            'icon' => '/assets/icons/icon-ap.png',
            'href' => '?page=accounts_payable',
        ],
        [
            'title' => 'Accounts Receivable',
            'description' => 'Customer invoices, receipts, and collections.',
            'icon' => '/assets/icons/icon-ar.png',
            'href' => '?page=accounts_receivable',
        ],
        [
            'title' => 'Financial Reports',
            'description' => 'Balance sheet, income statement, cash flow.',
            'icon' => '/assets/icons/icon-reporting.png',
            'href' => '?page=reports',
        ],
    ],
    
    // API endpoints exposed by this module
    'api' => [
        'prefix' => '/api/accounting',
        'endpoints' => [
            'GET /accounts' => 'List chart of accounts',
            'POST /accounts' => 'Create account',
            'GET /journal' => 'List journal entries',
            'POST /journal' => 'Create journal entry',
            'POST /journal/:id/post' => 'Post journal entry',
            'POST /journal/:id/reverse' => 'Reverse journal entry',
            'GET /reports/:type' => 'Generate report',
        ],
    ],
    
    // Permissions declared by this module
    'permissions' => [
        'accounting.view' => 'Access Accounting module',
        'accounting.coa.view' => 'View Chart of Accounts',
        'accounting.coa.edit' => 'Edit Chart of Accounts',
        'accounting.journal.view' => 'View Journal Entries',
        'accounting.journal.create' => 'Create Journal Entries',
        'accounting.journal.post' => 'Post Journal Entries',
        'accounting.journal.reverse' => 'Reverse Journal Entries',
        'accounting.ap.view' => 'View Accounts Payable',
        'accounting.ap.manage' => 'Manage AP Transactions',
        'accounting.ar.view' => 'View Accounts Receivable',
        'accounting.ar.manage' => 'Manage AR Transactions',
        'accounting.reports.view' => 'View Financial Reports',
        'accounting.reports.export' => 'Export Reports',
    ],
    
    // Audit events this module emits
    'audit_events' => [
        'accounting.account.created',
        'accounting.account.updated',
        'accounting.journal.created',
        'accounting.journal.posted',
        'accounting.journal.reversed',
        'accounting.period.closed',
        'accounting.period.reopened',
    ],
];
