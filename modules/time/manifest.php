<?php
/**
 * Time Module Manifest
 *
 * Per /app/modules/time/SPEC.md.
 * Source of truth for billable, non-billable, and absence hours across all placements.
 * Feeds AR (Billing), AP, Payroll, RevRec via time_downstream_feed bundles.
 * AI describes / humans decide — AI never approves entries.
 *
 * Source-of-truth: /app/modules/time/SPEC.md.
 */

return [
    'id'          => 'time',
    'name'        => 'Time',
    'icon'        => '/assets/icons/icon-time.png',
    'description' => 'Time entries, AI inbox parsing, tokenized client approvals, downstream feeds.',
    'version'     => '0.1.0',

    'actions' => [
        ['name' => 'My Time',            'route' => 'entries',    'permission' => 'time.entry.self'],
        ['name' => 'Upload Timesheet',   'route' => 'upload',     'permission' => 'time.entry.self'],
        ['name' => 'Intake Queue',       'route' => 'intake',     'permission' => 'time.review'],
        ['name' => 'Review Queue',       'route' => 'review',     'permission' => 'time.review'],
        ['name' => 'Settlement',         'route' => 'settlement', 'permission' => 'time.settlement.view.billing'],
        ['name' => 'Inbox (AI)',         'route' => 'inbox',      'permission' => 'time.review'],
        ['name' => 'Bulk Upload',        'route' => 'bulk',       'permission' => 'time.bulk_upload'],
        ['name' => 'Missing Timesheets', 'route' => 'missing',    'permission' => 'time.dashboard.missing'],
        ['name' => 'Pay Periods',        'route' => 'periods',    'permission' => 'time.period.close'],
        ['name' => 'Reports',            'route' => 'reports',    'permission' => 'time.view'],
    ],

    'permissions' => [
        'time.view'                     => 'View time data (gated by sub-perms)',
        'time.entry.self'               => 'Submit own time entries',
        'time.entry.create'             => 'Submit own time entries (alias for self / upload flow)',
        'time.entry.manage'             => 'Create / edit entries on behalf of others',
        'time.review'                   => 'Work the AI / intake review queue',
        'time.approve'                  => 'Approve entries (snapshot-lock the rate). Two-eye control.',
        'time.reject'                   => 'Reject entries with reason',
        'time.bulk_upload'              => 'Run bulk uploads',
        'time.tokenized_email.issue'    => 'Issue client-approval tokens',
        'time.tokenized_email.revoke'   => 'Revoke tokens before use',
        'time.period.close'             => 'Close a pay period',
        'time.feed.consume'             => 'System role — downstream modules consume bundles',
        'time.dashboard.missing'        => 'View Missing Timesheets dashboard',
        'time.categories.manage'        => 'Define tenant custom time categories',
        'time.audit.view'               => 'View time audit log',
        'time.settlement.view.billing'      => 'View ready-to-extract days for AR billing',
        'time.settlement.view.ap'           => 'View ready-to-extract days for AP (1099/C2C)',
        'time.settlement.view.payroll'      => 'View ready-to-extract days for payroll',
        'time.settlement.extract.billing'   => 'Mark approved days as extracted into an AR invoice',
        'time.settlement.extract.ap'        => 'Mark approved days as extracted into an AP bill',
        'time.settlement.extract.payroll'   => 'Mark approved days as extracted into a payroll line',
        'time.settlement.unextract.billing' => 'Reverse a billing extract (corrections)',
        'time.settlement.unextract.ap'      => 'Reverse an AP extract (corrections)',
        'time.settlement.unextract.payroll' => 'Reverse a payroll extract (corrections)',
    ],

    'audit_events' => [
        'time.entry.created',
        'time.entry.updated',
        'time.entry.submitted',
        'time.entry.approved',
        'time.entry.rejected',
        'time.entry.superseded',
        'time.timesheet.workflow_started',
        'time.timesheet.submitted',
        'time.timesheet.approved',
        'time.timesheet.rejected',
        'time.timesheet.approval_blocked',
        'time.intake.received',
        'time.intake.parsed',
        'time.intake.unreadable',
        'time.intake.error',
        'time.intake.converted',
        'time.intake.dismissed',
        'time.intake.sender_alias_recorded',
        'time.intake.flagged_unreadable',
        'time.bulk.uploaded',
        'time.upload.extracted',
        'time.upload.extract_failed',
        'time.upload.consumed',
        'time.token.issued',
        'time.token.responded',
        'time.token.revoked',
        'time.token.expired',
        'time.period.opened',
        'time.period.closed',
        'time.period.reopened',
        'time.feed.bundle_built',
        'time.feed.consumed',
        'time.feed.superseded',
        'time.category.created',
        'time.category.updated',
        'time.category.deactivated',
        'time.settlement.extracted_billing',
        'time.settlement.extracted_ap',
        'time.settlement.extracted_payroll',
        'time.settlement.unextracted_billing',
        'time.settlement.unextracted_ap',
        'time.settlement.unextracted_payroll',
    ],

    'people_graph' => [
        'consumes' => true,
        'mode' => 'source_module_consumer',
        'object_types' => [
            'entry' => [
                'responsibilities' => ['owner', 'requester', 'preparer', 'reviewer', 'approver', 'recipient', 'ai_supervisor'],
                'approval_resource' => 'time.entry',
            ],
            'timesheet' => [
                'responsibilities' => ['owner', 'requester', 'preparer', 'reviewer', 'approver', 'recipient', 'escalation_contact'],
                'approval_resource' => 'time.timesheet',
            ],
            'approval_token' => [
                'responsibilities' => ['owner', 'requester', 'recipient', 'notifier', 'escalation_contact'],
            ],
            'settlement_period' => [
                'responsibilities' => ['owner', 'reviewer', 'approver', 'operator', 'escalation_contact'],
            ],
        ],
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'depends_on' => ['people', 'placements'],
];
