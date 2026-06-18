<?php
/**
 * Engagements module manifest.
 *
 * Loaded via /app/modules/index.php style discovery (mirrors AP, AR,
 * billing modules). Declares the module's metadata + the migration set
 * the module migrator picks up on every API hit.
 */
declare(strict_types=1);

return [
    'id'              => 'engagements',
    'name'            => 'Engagements',
    'label'           => 'Engagements',
    'description'     => 'Fixed-fee project accounting — milestones, revenue recognition, invoicing.',
    'icon'            => 'briefcase',
    'order'           => 38,
    'rbac_module_key' => 'engagements',
    'sidebar_routes'  => [
        ['path' => '/modules/engagements',          'label' => 'Engagements',   'icon' => 'briefcase'],
    ],
    'migrations_dir'  => __DIR__ . '/migrations',
    'api_dir'         => __DIR__ . '/api',
    'ui_dir'          => __DIR__ . '/ui',
    'lib_dir'         => __DIR__ . '/lib',
];
