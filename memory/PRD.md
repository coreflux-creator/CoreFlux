# CoreFlux Product Requirements Document

## Original Problem Statement
Refactor a monolithic PHP application, CoreFlux, into a modular architecture. The core application provides a standardized shell, design system, and services (like authentication and multi-tenancy). Each business function (e.g., Accounting, People, Finance) is a separate, self-contained module that "plugs into" this core shell.

## Product Requirements
- A single, unified CoreFlux application (not separate apps connected by SSO)
- A core shell providing a consistent header, navigation, and design system
- Modules developed in separate repositories but integrated for deployment
- Multi-tenant architecture where tenants can subscribe to specific modules
- Support for parent/sub-tenant relationships
- Master admin role with platform-wide administrative panel

## Tech Stack
- **Backend:** PHP, MySQL
- **Architecture:** Modular Monolith with Git Subtree
- **Hosting:** Cloudways
- **Module Repos:** GitHub (coreflux-people, coreflux/accounts)

## Core Features (Completed)
- [x] Multi-tenant dashboard with dynamic tenant/module loading
- [x] Database connection to Cloudways MySQL
- [x] Secure login flow with password_verify()
- [x] Master admin panel for tenants, users, roles, module subscriptions
- [x] Framework layer (coreflux.css, shell components, ui.php)
- [x] FRAMEWORK_GUIDE.md documentation
- [x] Git Subtree setup guide and scripts

## In Progress
- [x] React SPA connected to PHP backend (tested with mock server)

## Deployment Required
- [ ] Deploy React SPA to Cloudways (copy `/app/app/` folder and PHP endpoints)
- [ ] Update login.php to redirect to spa.php (optional)

## Backlog (P1)
- [ ] Fix GitHub Actions CI/CD (use SSH + git pull instead of scp-action)
- [ ] Build Accounting module with full CRUD operations
- [ ] Clean up sidebar_items table duplicates

## Backlog (P2)
- [ ] UI/UX design refinement (consolidate CSS)
- [ ] Wire up granular permissions at view/action level
- [ ] Consolidate dashboard.css into coreflux.css

## Key Files
- `/app/core/config.php` - Database credentials
- `/app/core/data.php` - Data layer functions
- `/app/dashboard.php` - Main application shell
- `/app/login.php` - Authentication
- `/app/FRAMEWORK_GUIDE.md` - Module development guide
- `/app/GIT_SUBTREE_SETUP.md` - Git Subtree setup instructions
- `/app/scripts/setup-subtrees.sh` - Initial subtree setup script
- `/app/scripts/update-modules.sh` - Module update script

## Database Schema
- users: {id, name, email, password_hash, role, tenant_id}
- tenants: {id, name, parent_id, slug, domain}
- user_tenants: {user_id, tenant_id, role}
- modules: {id, name}
- tenant_modules: {tenant_id, module_key, is_enabled}
- permissions: {id, slug, description}
- role_permissions: {role_id, permission_id}

## Module Repositories
| Module | Repository | Subtree Prefix |
|--------|------------|----------------|
| People | coreflux-people | modules/people |
| Accounting | coreflux/accounts | modules/accounting |

---
*Last Updated: 2025-03-23*
