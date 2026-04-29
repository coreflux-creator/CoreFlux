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
    ],

    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    'depends_on' => [],
];
