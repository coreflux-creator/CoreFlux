# CoreFlux - Product Requirements Document

## Original Problem Statement
Refactor a PHP-based multi-tenant enterprise application (`coreflux`) to support modularity. The goal is to have "core as one app and each function as a standalone app that's integrated; wiring in this accounting module will serve as the model."

**Key User Clarification:** Modules should be **separate Git repositories** that integrate with core via Git submodules. This allows independent development while maintaining a seamless, single-app user experience.

## Architecture

### Technology Stack
- **Backend:** PHP
- **Database:** MySQL (PDO)
- **Pattern:** Modular Monolith, Multi-tenancy, RBAC
- **Deployment:** Manual `git pull` to Cloudways server

### Repository Structure
```
coreflux/                      ← Main repo (core)
├── core/                      ← Auth, shell, design system
├── modules/
│   ├── accounting/            ← Git submodule → separate repo
│   ├── people/                ← Git submodule → separate repo
│   └── _template/             ← Template for new modules
├── .gitmodules                ← Submodule configuration
└── dashboard.php
```

### Integration Model
- **Authentication:** Core handles all auth; modules inherit via PHP session
- **UI Shell:** Core provides header/sidebar; modules render in content area
- **Design System:** Shared via `/assets/css/coreflux.css`
- **Database:** Each module uses prefixed tables (e.g., `acct_*`)
- **Routing:** Subpaths via `?module=xxx&page=yyy`

## What's Been Implemented

### ✅ Core Application (Completed - Jan 2025)
- Stable dashboard with demo mode and live mode
- Database-driven authentication system
- Multi-tenant system with parent/sub-tenant hierarchies
- Master Admin panel for managing tenants, users, roles, module subscriptions

### ✅ Framework Layer (Completed - Jan 2025)
- Design system (`assets/css/coreflux.css`)
- PHP shell components (`core/shell/`)
- UI primitives (`cfPageHero`, `cfCard`, etc.)
- Module contract system (`manifest.php`)
- Documentation (`FRAMEWORK_GUIDE.md`)

### ✅ Git Submodules Infrastructure (Completed - Jan 2025)
- Module template at `/modules/_template/`
- Submodule setup documentation (`SUBMODULE_SETUP.md`)
- Accounting module prepared for extraction to separate repo
- Database schema for accounting (`migrations/001_initial_schema.sql`)
- Helper functions (`includes/functions.php`)

### ✅ CI/CD Cleanup (Completed - Jan 2025)
- Removed failing GitHub Actions workflow (`deploy.yml`)
- User continues with manual git pull deployment

## Prioritized Backlog

### P0 - Next Steps (User Action Required)
1. **Create GitHub repo** for accounting module (e.g., `coreflux-accounting`)
2. **Push accounting module contents** to the new repo
3. **Add as submodule** in main CoreFlux repo:
   ```bash
   rm -rf modules/accounting
   git submodule add <repo-url> modules/accounting
   ```

### P1 - After Submodule Setup
- Build accounting module views using framework components
- Run database migration on tenant database
- Test module integration end-to-end

### P2 - Future
- UI/UX design refinements
- Wire up granular permissions system
- Additional modules (People, Finance, etc.) as submodules

## Key Database Schema

### Core Tables
- `tenants`: {id, name, parent_id, subdomain}
- `users`: {id, name, email, password_hash, role}
- `user_tenants`: {user_id, tenant_id, role}
- `modules`: {id, name}
- `tenant_modules`: {tenant_id, module_key, is_enabled}

### Accounting Module Tables (prefixed `acct_`)
- `acct_accounts`: Chart of accounts
- `acct_journal_entries`: Journal entry headers
- `acct_journal_lines`: Journal entry detail lines
- `acct_fiscal_periods`: Accounting periods
- `acct_vendors`: AP vendors
- `acct_customers`: AR customers
- `acct_ap_invoices`: Vendor invoices
- `acct_ar_invoices`: Customer invoices

## Key Files

### Core
- `/app/dashboard.php` - Main entry point
- `/app/login.php` - Authentication
- `/app/core/` - Core application logic
- `/app/assets/css/coreflux.css` - Design system

### Documentation
- `/app/FRAMEWORK_GUIDE.md` - Module development docs
- `/app/SUBMODULE_SETUP.md` - Git submodule setup guide

### Accounting Module (to be extracted)
- `/app/modules/accounting/manifest.php` - Module metadata
- `/app/modules/accounting/README.md` - Module docs
- `/app/modules/accounting/migrations/` - DB schema
- `/app/modules/accounting/includes/functions.php` - Helpers
