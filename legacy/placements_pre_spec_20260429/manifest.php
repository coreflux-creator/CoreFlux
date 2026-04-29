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
    ],

    'audit_events' => [
        'placement.created',
        'placement.updated',
        'placement.status_changed',
        'placement.ended',
        'placement.chain.updated',
        'placement.rate.drafted',
        'placement.rate.approved',
        'placement.rate.superseded',
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
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'depends_on' => ['people'],
];
