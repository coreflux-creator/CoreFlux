# CoreFlux Framework - Module Integration Guide

## Overview

CoreFlux provides a standardized shell and design system that modules plug into. This allows each module (Accounting, People, Wealth, etc.) to be developed independently while sharing:

> **Repository Strategy:** Each module lives in its own Git repository and is integrated into the main CoreFlux repo using **Git Subtree**. See `GIT_SUBTREE_SETUP.md` for setup and workflow details.

- **App Shell** - Header, sidebar, content area
- **Design Tokens** - Colors, spacing, typography
- **UI Components** - Hero, cards, forms, tables
- **Event System** - Navigation, toasts, API calls

---

## Quick Start

### 1. Create Module Manifest

Every module needs a `manifest.php` in `/modules/{module_id}/`:

```php
// /modules/accounting/manifest.php
return [
    'id' => 'accounting',
    'name' => 'Accounting',
    'icon' => '/assets/icons/icon-accounting.png',
    'description' => 'General ledger and financial reporting.',
    
    'navItems' => [
        ['name' => 'Overview', 'route' => 'overview'],
        ['name' => 'Journal Entries', 'route' => 'journal_entries'],
    ],
    
    'hero' => [
        'title' => 'Accounting',
        'subtitle' => 'Manage your financial data.',
    ],
    
    'features' => [
        ['title' => 'General Ledger', 'href' => '?page=gl', ...],
    ],
];
```

### 2. Create Module Views

Views go in `/modules/{module_id}/views/`:

```php
// /modules/accounting/views/overview.php
<?php
require_once __DIR__ . '/../../../core/components/ui.php';

// Use the manifest for hero/features
$manifest = require __DIR__ . '/../manifest.php';

// Render using framework components
cfPageHero($manifest['hero']);

cfSection(['title' => 'Quick Actions'], function() use ($manifest) {
    cfFeatureGrid(function() use ($manifest) {
        foreach ($manifest['features'] as $feature) {
            cfFeatureCard($feature);
        }
    });
});
```

### 3. Use the Shell

For standalone module rendering:

```php
<?php
require_once __DIR__ . '/core/shell/module.php';

$manifest = cfLoadModuleManifest('accounting');

cfRenderModuleShell($manifest, $page, [
    'tenant' => $tenant,
    'user' => $user,
    'modules' => $modules,
], function() use ($viewPath) {
    include $viewPath;
});
```

---

## Design Tokens

All styling uses CSS variables from `/assets/css/coreflux.css`:

### Colors
```css
--cf-primary: #002c70;      /* Brand blue */
--cf-accent: #0066cc;       /* Links, highlights */
--cf-success: #10b981;      /* Green */
--cf-warning: #f59e0b;      /* Orange */
--cf-danger: #ef4444;       /* Red */
--cf-info: #3b82f6;         /* Blue */
```

### Spacing
```css
--cf-space-1: 4px;
--cf-space-2: 8px;
--cf-space-4: 16px;
--cf-space-6: 24px;
--cf-space-8: 32px;
```

### Typography
```css
--cf-text-sm: 13px;
--cf-text-base: 14px;
--cf-text-lg: 16px;
--cf-text-xl: 18px;
--cf-text-2xl: 24px;
```

---

## UI Components

### Page Hero
```php
cfPageHero([
    'icon' => '/assets/icons/icon-accounting.png',
    'eyebrow' => 'Financial Management',
    'title' => 'Accounting',
    'subtitle' => 'Manage your general ledger.',
    'actions' => [
        ['label' => 'New Entry', 'href' => '?page=new', 'primary' => true],
    ],
]);
```

### Feature Card
```php
cfFeatureCard([
    'href' => '?page=journal',
    'icon' => '/assets/icons/icon-journal.png',
    'title' => 'Journal Entries',
    'description' => 'Create and post journal entries.',
    'badge' => '3 pending',
]);
```

### Section with Card
```php
cfSection(['title' => 'Recent Activity'], function() {
    cfCard(['title' => 'Latest Entries'], function() {
        // Table or content here
    });
});
```

### Empty State
```php
cfEmptyState([
    'title' => 'No journal entries',
    'description' => 'Create your first entry to get started.',
    'action' => ['label' => 'New Entry', 'href' => '?page=new'],
]);
```

### Badges
```php
cfBadge('Approved', 'success');
cfBadge('Pending', 'warning');
cfBadge('Rejected', 'danger');
```

---

## Event System (JavaScript)

### Navigation
```javascript
// AJAX navigation
CoreFlux.navigate('?page=journal_entries');

// Full page reload
CoreFlux.navigate('?page=journal_entries', false);
```

### Toast Notifications
```javascript
CoreFlux.toast('Entry saved successfully', 'success');
CoreFlux.toast('Please fill all required fields', 'warning');
CoreFlux.toast('Failed to save entry', 'danger');
```

### API Calls
```javascript
// GET request
const accounts = await CoreFlux.api.get('/accounting/accounts');

// POST request
const entry = await CoreFlux.api.post('/accounting/journal', {
    date: '2026-01-30',
    lines: [...],
});
```

### Custom Events
```javascript
// Dispatch event
CoreFlux.events.dispatch('cf:journal-posted', { id: 123 });

// Listen for event
CoreFlux.events.on('cf:journal-posted', (detail) => {
    console.log('Journal posted:', detail.id);
});
```

---

## File Structure

```
/app/
├── core/
│   ├── shell/
│   │   ├── header.php      # Header component
│   │   ├── sidebar.php     # Sidebar/nav component
│   │   ├── events.php      # JS event system
│   │   └── module.php      # Module contract & renderer
│   ├── components/
│   │   └── ui.php          # UI primitives (hero, cards, etc.)
│   ├── config.php          # Platform config
│   ├── auth.php            # Authentication
│   ├── data.php            # Data layer (tenants, modules)
│   └── db.php              # Database connection
│
├── modules/
│   ├── accounting/
│   │   ├── manifest.php    # Module manifest
│   │   ├── views/          # View files
│   │   └── api/            # API endpoints
│   ├── people/
│   │   ├── manifest.php
│   │   └── views/
│   └── ...
│
├── assets/
│   ├── css/
│   │   └── coreflux.css    # Design system
│   └── icons/
│
└── dashboard.php           # Main shell entrypoint
```

---

## Checklist for New Modules

- [ ] Create `/modules/{id}/manifest.php`
- [ ] Define `navItems` for sidebar
- [ ] Define `hero` for overview page
- [ ] Define `features` for quick action cards
- [ ] Create views in `/modules/{id}/views/`
- [ ] Use `cf-*` CSS classes from coreflux.css
- [ ] Use UI components from `core/components/ui.php`
- [ ] Register API endpoints in manifest
- [ ] Define permissions in manifest

---

## CSS Classes Reference

### Layout
- `.cf-shell` - Main shell wrapper
- `.cf-shell-header` - Fixed header
- `.cf-shell-sidebar` - Fixed sidebar
- `.cf-shell-main` - Scrollable content

### Components
- `.cf-page-hero` - Hero section
- `.cf-feature-grid` - Grid of feature cards
- `.cf-feature-card` - Clickable feature card
- `.cf-card` - Standard card
- `.cf-section` - Page section
- `.cf-btn` - Button base
- `.cf-badge` - Status badge

### Utilities
- `.cf-grid-2`, `.cf-grid-3`, `.cf-grid-4` - Grid columns
- `.cf-flex`, `.cf-gap-4` - Flexbox
- `.cf-text-center`, `.cf-text-muted` - Text
- `.cf-mt-4`, `.cf-mb-6` - Margins
