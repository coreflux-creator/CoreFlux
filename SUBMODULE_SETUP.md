# CoreFlux Git Submodules Setup Guide

## Overview

CoreFlux uses Git submodules to keep modules in separate repositories while deploying as a single, seamless application. This gives you:

- **Separate repos** for independent development and version control
- **Single deployment** with no API latency between core and modules
- **Shared resources** (auth, shell, CSS) via PHP includes

---

## Repository Structure

```
coreflux/                      ← Main repo (this one)
├── core/                      ← Auth, shell, design system
├── modules/
│   ├── accounting/            ← Git submodule → github.com/yourorg/coreflux-accounting
│   ├── people/                ← Git submodule → github.com/yourorg/coreflux-people
│   └── ...
├── .gitmodules                ← Submodule configuration
└── dashboard.php
```

---

## Setting Up a New Module as a Submodule

### Step 1: Create the Module Repository

1. Create a new GitHub repository (e.g., `coreflux-accounting`)
2. Clone it locally or initialize it

### Step 2: Use the Module Template

Copy the template structure from `/modules/_template/` into your new repo:

```bash
# In your new module repo
cp -r /path/to/coreflux/modules/_template/* .
```

### Step 3: Add as Submodule to CoreFlux

```bash
# In the main coreflux repo
cd /path/to/coreflux

# Remove existing module folder if present
rm -rf modules/accounting

# Add as submodule
git submodule add https://github.com/yourorg/coreflux-accounting.git modules/accounting

# Commit
git add .gitmodules modules/accounting
git commit -m "Add accounting module as submodule"
```

---

## Working with Submodules

### Clone with Submodules

```bash
# Clone main repo with all submodules
git clone --recursive https://github.com/yourorg/coreflux.git

# Or if already cloned
git submodule update --init --recursive
```

### Update Submodules

```bash
# Pull latest changes in all submodules
git submodule update --remote

# Or update a specific submodule
cd modules/accounting
git pull origin main
cd ../..
git add modules/accounting
git commit -m "Update accounting module"
```

### Make Changes to a Module

```bash
# Navigate into the submodule
cd modules/accounting

# Make changes, commit, push
git add .
git commit -m "Add new feature"
git push origin main

# Go back to main repo and update reference
cd ../..
git add modules/accounting
git commit -m "Update accounting module reference"
git push
```

---

## Deployment (Cloudways)

When deploying via `git pull` on your Cloudways server:

```bash
# First time setup
git clone --recursive https://github.com/yourorg/coreflux.git public_html

# Subsequent updates
cd public_html
git pull
git submodule update --init --recursive
```

**Tip:** Create a deployment script:

```bash
#!/bin/bash
# deploy.sh
cd /home/master/applications/xxxxx/public_html
git pull origin main
git submodule update --init --recursive
echo "Deployed at $(date)"
```

---

## Module Requirements

Every module submodule MUST include:

1. **`manifest.php`** - Module metadata and configuration
2. **`views/`** - PHP view files
3. **`README.md`** - Module documentation

### Manifest Structure

```php
<?php
return [
    'id' => 'accounting',
    'name' => 'Accounting',
    'version' => '1.0.0',
    'core_version' => '>=1.0.0',  // Minimum core version required
    'icon' => '/assets/icons/icon-accounting.png',
    'description' => 'General ledger and financial reporting.',
    
    'navItems' => [...],
    'hero' => [...],
    'features' => [...],
    'permissions' => [...],
];
```

---

## Integration Points

Modules integrate with core via:

### 1. Authentication (Already Handled)
```php
// In module views, auth is already validated by dashboard.php
// Access user data via:
global $user, $tenant, $tenantId;
```

### 2. Shell Components
```php
// Include core UI components
require_once __DIR__ . '/../../../core/components/ui.php';

// Use framework components
cfPageHero($manifest['hero']);
cfCard(['title' => 'My Card'], function() { ... });
```

### 3. Design System
```php
// CSS is already loaded by dashboard.php
// Use cf-* classes from /assets/css/coreflux.css
```

### 4. Database
```php
// Get database connection
require_once __DIR__ . '/../../../core/db.php';
$pdo = getDbConnection();

// Module-specific tables should be prefixed
// e.g., acct_accounts, acct_journal_entries
```

---

## Troubleshooting

### Submodule shows "(modified content)"
```bash
cd modules/accounting
git status  # Check what changed
git checkout .  # Discard changes, or
git add . && git commit && git push  # Commit changes
```

### Submodule stuck on old commit
```bash
git submodule update --remote modules/accounting
```

### Need to remove a submodule
```bash
git submodule deinit modules/accounting
git rm modules/accounting
rm -rf .git/modules/modules/accounting
git commit -m "Remove accounting submodule"
```
