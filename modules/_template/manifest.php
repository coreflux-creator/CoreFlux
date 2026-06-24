<?php
/**
 * Module Manifest — copy this file to /modules/<your_module>/manifest.php
 *
 * Every CoreFlux module declares its identity, sidebar actions, and required
 * permissions here. Core reads these manifests to build navigation and access
 * control without any hardcoded module lists.
 */

return [
    'id'          => 'template',                          // url + key, lowercase_snake
    'name'        => 'Template Module',                   // display label
    'icon'        => '/assets/icons/icon-template.png',   // sidebar icon
    'description' => 'Short one-liner describing the module',
    'version'     => '0.1.0',

    // Sidebar actions shown inside the module
    'actions' => [
        ['name' => 'Overview', 'route' => 'overview', 'permission' => 'template.view'],
        // ['name' => 'Records', 'route' => 'records', 'permission' => 'template.records.view'],
    ],

    // Permission slugs this module introduces (registered in the permissions table)
    'permissions' => [
        'template.view',
        // 'template.records.view',
        // 'template.records.manage',
    ],

    // Roles that get module access by default (on top of explicit tenant_modules rows)
    'default_roles' => ['master_admin', 'tenant_admin', 'admin'],

    // Optional: declare source objects that consume the shared People Graph
    // authority layer for owners, preparers, reviewers, approvers, and routing.
    'people_graph' => [
        'consumes' => false,
        'mode' => 'source_module_consumer',
        'object_types' => [
            // 'record' => [
            //     'responsibilities' => ['owner', 'preparer', 'reviewer', 'approver'],
            // ],
        ],
    ],
];
