<?php
/**
 * People Module Manifest
 *
 * Talent system of record per /app/modules/people/SPEC.md.
 * Owns the talent pool (lifetime person records, classification w2/1099/c2c/temp/perm/candidate/alumni).
 * Does NOT own placements, time entries, or rates.
 *
 * Source-of-truth: /app/modules/people/SPEC.md (do not edit manifest without updating spec).
 */

return [
    'id'          => 'people',
    'name'        => 'People',
    'icon'        => '/assets/icons/icon-people.png',
    'description' => 'Talent system of record — directory, classification, work auth, skills, documents, hiring pipeline.',
    'version'     => '0.2.0',

    'actions' => [
        ['name' => 'Directory',          'route' => 'directory',          'permission' => 'people.view'],
        ['name' => 'Hiring Pipeline',    'route' => 'pipeline',           'permission' => 'people.view'],
        ['name' => 'Document Vault',     'route' => 'documents',          'permission' => 'people.docs.view'],
        ['name' => 'People Graph',       'route' => 'graph',              'permission' => 'people.graph.view'],
        ['name' => 'Access Reviews',     'route' => 'access_reviews',     'permission' => 'people.access_reviews.view'],
        ['name' => 'Custom Fields',      'route' => 'custom_fields',      'permission' => 'people.custom_fields.manage'],
        ['name' => 'PII Access Log',     'route' => 'audit_pii',          'permission' => 'people.pii.audit.view'],
    ],

    'permissions' => [
        'people.view'                     => 'List + view non-PII fields',
        'people.manage'                   => 'Create / edit person records (non-PII, non-comp)',
        'people.terminate'                => 'Set status to inactive / do_not_rehire',
        'people.merge'                    => 'Merge duplicate person records',
        'people.pii.view'                 => 'View DOB, SSN last 4, home address',
        'people.pii.manage'               => 'Edit PII',
        'people.pii.audit.view'           => 'View tenant PII access log (SOC2 self-serve)',
        'people.comp.view'                => 'Reserved — comp lives in placements',
        'people.comp.manage'              => 'Reserved',
        'people.tax.view'                 => 'View W-4 / tax setup',
        'people.tax.manage'               => 'Edit tax setup',
        'people.banking.view'             => 'View masked banking',
        'people.banking.manage'           => 'Edit banking',
        'people.docs.view'                => 'View document list',
        'people.docs.manage'              => 'Upload / replace / delete documents',
        'people.graph.view'               => 'View People Graph actors, relationships, responsibilities, delegations, and resolver answers',
        'people.graph.manage'             => 'Manage People Graph actors, teams, roles, relationships, and responsibility assignments',
        'people.graph.delegate'           => 'Create and revoke People Graph delegations',
        'people.access_reviews.view'      => 'View access review campaigns and certification items',
        'people.access_reviews.manage'    => 'Create access reviews, record certification decisions, and apply revocations',
        'people.timeoff.manage'           => 'Reserved (deferred)',
        'people.custom_fields.manage'     => 'Define tenant custom fields',
        'people.pipeline.substages.manage'=> 'Edit tenant-defined pipeline sub-stages',
    ],

    'audit_events' => [
        'people.created',
        'people.updated',
        'people.terminated',
        'people.merged',
        'people.csv_imported',
        'people.directory.exported',
        'people.pii.viewed',
        'people.banking.viewed',
        'people.banking.updated',
        'people.tax.updated',
        'people.document.uploaded',
        'people.document.deleted',
        'people.pipeline.stage_added',
        'people.pipeline.substage.created',
        'people.pipeline.substage.updated',
        'people.pipeline.substage.deactivated',
        'people.custom_field.defined',
        'people.custom_field.value_set',
        'people.graph.actor_linked',
        'people.graph.organization.created',
        'people.graph.team.upserted',
        'people.graph.role.upserted',
        'people.graph.relationship.created',
        'people.graph.responsibility.assigned',
        'people.graph.delegation.created',
        'people.graph.delegation.revoked',
        'people.graph.permission.granted',
        'people.graph.permission.revoked',
        'people.graph.permission.checked',
        'people.graph.approval_policy.upserted',
        'people.graph.approval_rule.created',
        'people.graph.resolved',
        'people.access_review.campaign.created',
        'people.access_review.campaign.opened',
        'people.access_review.campaign.snapshot',
        'people.access_review.item.decided',
        'people.access_review.revocation_failed',
        'people.access_review.campaign.completed',
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'custom_field_entities' => [
        [
            'entity_type'       => 'people',
            'label'             => 'People',
            'definition_table'  => 'people_custom_field_defs',
            'value_table'       => 'people_custom_field_values',
            'record_id_key'     => 'person_id',
            'view_permission'   => 'people.view',
            'manage_permission' => 'people.custom_fields.manage',
            'pii_permission'    => 'people.pii.view',
            'surfaces'          => ['forms', 'detail', 'lists', 'exports', 'reports'],
        ],
    ],

    'custom_field_layouts' => [
        'people' => [
            'form_sections' => ['profile', 'work', 'compliance'],
            'list_columns'  => ['field_label', 'field_type', 'required', 'pii'],
        ],
    ],

    'depends_on' => [],
];
