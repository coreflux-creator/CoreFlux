# CoreFlux - Product Requirements Document

## Original Problem Statement
Refactor a PHP-based multi-tenant enterprise application (`coreflux`) to support modularity. The goal is to have "core as one app and each function as a standalone app that's integrated; wiring in this accounting module will serve as the model."

## Architecture
- **Backend:** PHP
- **Database:** MySQL (PDO)
- **Pattern:** Modular Monolith, Multi-tenancy, RBAC
- **Deployment:** Manual `git pull` to Cloudways server

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

### ✅ CI/CD Cleanup (Completed - Jan 2025)
- Removed failing GitHub Actions workflow (`deploy.yml`)
- User continues with manual git pull deployment

## Prioritized Backlog

### P0 - Next Up
- **Accounting Module Implementation**
  - Database schema (Chart of Accounts, Journal Entries, GL)
  - PHP views using framework components
  - Module manifest and contract adherence

### P1 - Upcoming
- UI/UX design refinements (user noted "design needs work")

### P2 - Future
- Wire up granular permissions system (tables exist, logic not enforced)
- Additional modules as needed

## Key Database Schema
- `tenants`: {id, name, parent_id, subdomain}
- `users`: {id, name, email, password_hash, role}
- `user_tenants`: {user_id, tenant_id, role}
- `modules`: {id, name}
- `tenant_modules`: {tenant_id, module_key, is_enabled}
- `permissions`: {id, slug}
- `roles`: {id, name, slug}

## Key Files
- `/app/dashboard.php` - Main entry point
- `/app/login.php` - Authentication
- `/app/core/` - Core application logic
- `/app/modules/` - Pluggable business modules
- `/app/assets/css/coreflux.css` - Design system
- `/app/FRAMEWORK_GUIDE.md` - Module development docs
