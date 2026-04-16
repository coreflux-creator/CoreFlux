<?php
/**
 * Module Manifest Template
 * 
 * Copy this template when creating a new module.
 * Replace all placeholder values with your module's configuration.
 */

return [
    // REQUIRED: Unique module identifier (lowercase, no spaces)
    'id' => 'your_module_id',
    
    // REQUIRED: Display name
    'name' => 'Your Module Name',
    
    // REQUIRED: Semantic version
    'version' => '1.0.0',
    
    // REQUIRED: Minimum core version this module works with
    'core_version' => '>=1.0.0',
    
    // REQUIRED: Module icon path (relative to web root)
    'icon' => '/assets/icons/icon-module.png',
    
    // REQUIRED: Short description
    'description' => 'Brief description of what this module does.',
    
    // REQUIRED: Sidebar navigation items
    'navItems' => [
        ['name' => 'Overview', 'route' => 'overview', 'icon' => 'icon-dashboard.png'],
        ['name' => 'Feature 1', 'route' => 'feature1', 'icon' => 'icon-feature.png'],
        ['name' => 'Settings', 'route' => 'settings', 'icon' => 'icon-settings.png', 'admin_only' => true],
    ],
    
    // REQUIRED: Hero section for module overview page
    'hero' => [
        'eyebrow' => 'Category',
        'title' => 'Your Module Name',
        'subtitle' => 'A longer description of the module and its purpose.',
        'actions' => [
            ['label' => 'Primary Action', 'href' => '?page=action', 'primary' => true],
            ['label' => 'Secondary Action', 'href' => '?page=secondary', 'primary' => false],
        ],
    ],
    
    // REQUIRED: Feature cards for overview page
    'features' => [
        [
            'title' => 'Feature 1',
            'description' => 'Description of this feature.',
            'icon' => '/assets/icons/icon-feature1.png',
            'href' => '?page=feature1',
        ],
        [
            'title' => 'Feature 2',
            'description' => 'Description of this feature.',
            'icon' => '/assets/icons/icon-feature2.png',
            'href' => '?page=feature2',
        ],
    ],
    
    // OPTIONAL: API endpoints this module exposes
    'api' => [
        'prefix' => '/api/your_module',
        'endpoints' => [
            'GET /items' => 'List all items',
            'POST /items' => 'Create item',
            'GET /items/:id' => 'Get item by ID',
            'PUT /items/:id' => 'Update item',
            'DELETE /items/:id' => 'Delete item',
        ],
    ],
    
    // OPTIONAL: Permissions this module declares
    'permissions' => [
        'your_module.view' => 'Access this module',
        'your_module.create' => 'Create records',
        'your_module.edit' => 'Edit records',
        'your_module.delete' => 'Delete records',
        'your_module.admin' => 'Administer module settings',
    ],
    
    // OPTIONAL: Database tables this module uses (for documentation)
    'tables' => [
        'mod_your_items' => 'Main data table',
        'mod_your_settings' => 'Module settings',
    ],
    
    // OPTIONAL: Audit events this module emits
    'audit_events' => [
        'your_module.item.created',
        'your_module.item.updated',
        'your_module.item.deleted',
    ],
];
