# CoreFlux - Product Requirements Document

## Original Problem Statement
Refactor a PHP-based multi-tenant enterprise application (`coreflux`) to support modularity with:
- Core as one app providing shell, auth, tenant management
- Modules as standalone apps (separate Git repos) integrated via submodules
- **Laravel + React architecture** (user confirmed choice)

## Current Status: WAITING FOR `composer install`

## Architecture

### Technology Stack
- **Frontend:** React 18 + Vite + Tailwind CSS ✅ COMPLETE
- **Backend:** Laravel 10 + Sanctum ✅ CODE WRITTEN (needs composer install)
- **Database:** MySQL (existing tables)
- **Modules:** Git submodules ✅ CONFIGURED

### Repository Structure
```
coreflux/
├── frontend/                  ✅ React SPA (17 files)
│   ├── src/
│   │   ├── components/layout/ (Header, Sidebar, DashboardLayout)
│   │   ├── pages/             (Login, Dashboard, Admin/*, Modules/*)
│   │   ├── hooks/             (useAuth, useModules)
│   │   └── lib/               (api.js, utils.js)
│   └── dist/                  ✅ BUILT
│
├── laravel/                   ✅ Laravel backend (27 files)
│   ├── app/
│   │   ├── Http/Controllers/  (Auth, Admin/Tenant, Admin/User, Admin/Module)
│   │   ├── Models/            (User, Tenant, Module, UserTenant, TenantModule)
│   │   └── Middleware/        (AdminMiddleware)
│   ├── routes/api.php
│   ├── config/                (app, auth, cors, database, sanctum)
│   ├── database/migrations/
│   └── composer.json
│
├── modules/                   ✅ Git submodules
│   ├── accounting/            → github.com/coreflux-creator/coreflux-accounting
│   └── people/                → github.com/coreflux-creator/coreflux-people
│
├── .gitmodules                ✅ CONFIGURED
└── LARAVEL_SETUP.md           ✅ SETUP GUIDE
```

## What's Complete

### ✅ React Frontend (17 files)
- Login page with auth flow
- Dashboard with welcome banner + module cards
- Master Admin panel:
  - Tenants CRUD (list, create, edit, delete)
  - Users CRUD (list, create, edit, delete, tenant assignment)
  - Modules toggle per tenant
- Module overview pages (Accounting, People)
- Header with tenant/module/user dropdowns
- Sidebar with dynamic nav per module/admin
- Auth context (login, logout, token management)
- Modules context (fetch enabled modules)
- Protected routes + admin route guard

### ✅ Laravel Backend (27 files)
- **Models:** User, Tenant, Module, UserTenant, TenantModule
- **Controllers:**
  - AuthController (login, me, logout)
  - TenantController (CRUD + module toggle)
  - UserController (CRUD + tenant assignment)
  - ModuleController (CRUD)
  - TenantModuleController (get tenant modules)
- **Routes:** All API routes with Sanctum protection
- **Middleware:** AdminMiddleware for master_admin only routes
- **Config:** App, Auth, CORS, Database, Sanctum
- **Migration:** personal_access_tokens for Sanctum

### ✅ Git Submodules
- `modules/accounting` linked to coreflux-accounting repo
- `modules/people` linked to coreflux-people repo

## Next Step: User Action Required

### Run `composer install` on your server:

```bash
cd /path/to/coreflux/laravel
composer install
cp .env.example .env
php artisan key:generate

# Edit .env with your database credentials:
# DB_HOST=127.0.0.1
# DB_DATABASE=your_database
# DB_USERNAME=your_user
# DB_PASSWORD=your_password

php artisan migrate
```

## Remaining Work (After composer install)

### Session 3-4: Accounting Module Rewrite
- Convert FastAPI → Laravel controllers
- Convert MongoDB → MySQL/Eloquent
- Point existing React frontend to new Laravel API

### Session 5: People Module Rewrite
- Convert PHP views → Laravel controllers
- Build React pages for People features

### Session 6: Integration Testing
- End-to-end testing
- Fix any integration issues

## API Endpoints

### Auth
- `POST /api/auth/login` → token + user + tenants
- `GET /api/auth/me` → current user + tenants
- `POST /api/auth/logout` → revoke token

### Tenant Modules
- `GET /api/tenants/{id}/modules` → enabled modules

### Admin (master_admin only)
- `GET|POST /api/admin/tenants`
- `GET|PUT|DELETE /api/admin/tenants/{id}`
- `GET /api/admin/tenants/{id}/modules`
- `POST /api/admin/tenants/{id}/modules/{moduleId}`
- `GET|POST /api/admin/users`
- `GET|PUT|DELETE /api/admin/users/{id}`
- `GET|POST /api/admin/modules`

## Database Schema (Using Existing Tables)
- `tenants` (id, name, parent_id, subdomain)
- `users` (id, name, first_name, last_name, email, password_hash, role)
- `user_tenants` (user_id, tenant_id, role)
- `modules` (id, name, key, description)
- `tenant_modules` (tenant_id, module_id, is_enabled)
- `personal_access_tokens` ← NEW (created by migration)
