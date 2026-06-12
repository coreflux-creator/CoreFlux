<?php
/**
 * Placements Module Manifest
 *
 * Commercial spine per /app/modules/placements/SPEC.md (JobDiva pattern).
 * Owns active deals: vendor chain, effective-dated rates, commissions, referrals,
 * C2C corp details, tokenized client approval contact.
 *
 * Source-of-truth: /app/modules/placements/SPEC.md.
 */

return [
    'id'          => 'placements',
    'name'        => 'Placements',
    'icon'        => '/assets/icons/icon-placements.png',
    'description' => 'Active engagements — bill/pay rates, vendor chain, commissions, referrals, C2C corp details.',
    'version'     => '0.1.0',

    'actions' => [
        ['name' => 'Active Placements', 'route' => 'list',         'permission' => 'placements.view'],
        ['name' => 'Expiring Soon',     'route' => 'expiring',     'permission' => 'placements.view'],
        ['name' => 'New Placement',     'route' => 'new',          'permission' => 'placements.manage'],
        ['name' => 'Commissions',       'route' => 'commissions',  'permission' => 'placements.commissions.view'],
        ['name' => 'Referrals',         'route' => 'referrals',    'permission' => 'placements.referrals.manage'],
        ['name' => 'Custom Fields',     'route' => 'custom_fields', 'permission' => 'placements.custom_fields.manage'],
        ['name' => 'Reports',           'route' => 'reports',      'permission' => 'placements.financials.view'],
    ],

    'permissions' => [
        'placements.view'                  => 'List + view placements',
        'placements.manage'                => 'Create / edit non-financial fields',
        'placements.financials.view'       => 'View rates, fees, margin',
        'placements.financials.manage'     => 'Create / draft rate rows',
        'placements.financials.approve'    => 'Approve rate rows (snapshot lock)',
        'placements.commissions.view'      => 'View commission splits',
        'placements.commissions.manage'    => 'Edit commission splits + plans',
        'placements.referrals.manage'      => 'Edit referral records',
        'placements.docs.view'             => 'View documents',
        'placements.docs.manage'           => 'Upload / delete documents',
        'placements.terminate'             => 'End / cancel placement',
        'placements.corp.view'             => 'View C2C corp details',
        'placements.corp.manage'           => 'Edit C2C corp details (encrypted EIN)',
        'placements.custom_fields.manage'  => 'Tenant custom fields',
        'placements.portal_credentials.view' => 'Reveal vendor portal credentials (audited)',
    ],

    'audit_events' => [
        'placement.created',
        'placement.updated',
        'placement.status_changed',
        'placement.ended',
        'placement.chain.updated',
        'placement.chain.portal.set',
        'placement.chain.portal.cleared',
        'placement.chain.portal.viewed',
        'placement.chain.contract_extracted',
        'placement.rate.drafted',
        'placement.rate.workflow_started',
        'placement.rate.workflow_start_failed',
        'placement.rate.workflow_approved',
        'placement.rate.workflow_snapshot_locked',
        'placement.rate.approval_blocked',
        'placement.rate.approval_rejected',
        'placement.rate.approved',
        'placement.rate.superseded',
        'placement.rate.auto_approve_pending_workflow',
        'placement.commission.added',
        'placement.commission.updated',
        'placement.commission.removed',
        'placement.commission_plan.created',
        'placement.commission_plan.updated',
        'placement.referral.added',
        'placement.referral.updated',
        'placement.financials.viewed',
        'placement.corp.viewed',
        'placement.corp.updated',
        'placement.document.uploaded',
        'placement.document.deleted',
        'placement.approval_contact.updated',
        'placement.csv_imported',
        'placement.exported',
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'custom_field_entities' => [
        [
            'entity_type'       => 'placements',
            'label'             => 'Placements',
            'definition_table'  => 'custom_fields',
            'value_table'       => 'custom_values',
            'record_id_key'     => 'placement_id',
            'view_permission'   => 'placements.view',
            'manage_permission' => 'placements.custom_fields.manage',
            'surfaces'          => ['forms', 'detail', 'lists', 'exports', 'reports'],
        ],
    ],

    'custom_field_layouts' => [
        'placements' => [
            'form_sections' => ['assignment', 'client', 'compliance'],
            'list_columns'  => ['field_label', 'field_type', 'is_required'],
        ],
    ],

    'people_graph' => [
        'consumes' => true,
        'mode' => 'source_module_consumer',
        'object_types' => [
            'placement' => [
                'responsibilities' => ['owner', 'accountable', 'preparer', 'reviewer', 'approver', 'notifier', 'escalation_contact'],
                'approval_resource' => 'placements.placement',
            ],
            'rate_snapshot' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver'],
                'approval_resource' => 'placements.rate_snapshot',
            ],
            'commission_plan' => [
                'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver'],
                'approval_resource' => 'placements.commission_plan',
            ],
            'approval_contact' => [
                'responsibilities' => ['owner', 'recipient', 'notifier'],
            ],
        ],
    ],

    'depends_on' => ['people'],
];
