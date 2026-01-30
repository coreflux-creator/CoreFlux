# CoreFlux - Product Requirements Document

## Original Problem Statement
Refactor a PHP-based multi-tenant enterprise application (`coreflux`) to support modularity with:
- Core as one app providing shell, auth, tenant management
- Modules as standalone apps (separate Git repos) integrated via submodules
- Laravel + React architecture (user confirmed choice)

## Architecture

### Technology Stack (NEW)
- **Frontend:** React 18 + Vite + Tailwind CSS
- **Backend:** Laravel 10 + Sanctum (API tokens)
- **Database:** MySQL (existing tables)
- **Modules:** Git submodules

### Repository Structure
```
coreflux/                      ← Main repo (core)
├── frontend/                  ← React SPA ✅ BUILT
├── laravel/                   ← Laravel backend ✅ WRITTEN (needs composer install)
├── modules/
│   ├── accounting/            ← Git submodule ✅ LINKED
│   └── people/                ← Git submodule ✅ LINKED
├── .gitmodules                ✅ CONFIGURED
└── LARAVEL_SETUP.md           ✅ SETUP GUIDE
```

## What's Been Implemented

### ✅ Phase 1: React Frontend (COMPLETE)
- Full React app with Vite + Tailwind
- Login page with auth flow
- Dashboard with module cards
- Master Admin panel (Tenants, Users, Modules CRUD)
- Module overview pages (Accounting, People)
- Auth context with token management
- Multi-tenant switching
- Responsive layout with header + sidebar

### ✅ Phase 2: Laravel Backend Files (WRITTEN - needs composer install)
- Models: User, Tenant, Module, UserTenant, TenantModule
- Controllers: AuthController, TenantController, UserController, ModuleController
- Routes: api.php with auth and admin routes
- Middleware: AdminMiddleware for master_admin protection
- Config: Sanctum, CORS

### ✅ Git Submodules (CONFIGURED)
- `modules/accounting` → coreflux-creator/coreflux-accounting
- `modules/people` → coreflux-creator/coreflux-people

## Awaiting User Action

### 🔴 BLOCKING: Run `composer install`
The Laravel backend code is written but needs dependencies installed:

```bash
cd /path/to/coreflux/laravel
composer install
cp .env.example .env
php artisan key:generate
# Configure .env with DB credentials
php artisan migrate  # Creates personal_access_tokens table
```

## Remaining Tasks

### After composer install:
1. **Test auth flow** - Login with existing credentials
2. **Test admin panel** - CRUD operations
3. **Add Kernel middleware** - Register AdminMiddleware

### Phase 3: Accounting Module Rewrite (~2 sessions)
- Convert FastAPI → Laravel controllers
- Convert MongoDB → MySQL models
- Keep React frontend, point to new Laravel API

### Phase 4: People Module Rewrite (~1-2 sessions)
- Convert existing PHP → Laravel controllers
- Build React pages for People features

## Database Schema (Existing)
- `tenants`: {id, name, parent_id, subdomain}
- `users`: {id, name, email, password_hash, role}
- `user_tenants`: {user_id, tenant_id, role}
- `modules`: {id, name, key}
- `tenant_modules`: {tenant_id, module_id, is_enabled}

## Key Files Created This Session

### Frontend (/app/frontend/)
- `src/App.jsx` - Main routing
- `src/pages/LoginPage.jsx` - Login UI
- `src/pages/DashboardPage.jsx` - Main dashboard
- `src/pages/admin/*` - Admin panel pages
- `src/pages/modules/*` - Module overview pages
- `src/components/layout/*` - Header, Sidebar, Layout
- `src/hooks/useAuth.jsx` - Auth context
- `src/hooks/useModules.jsx` - Modules context
- `src/lib/api.js` - Axios instance

### Backend (/app/laravel/)
- `app/Models/*.php` - Eloquent models
- `app/Http/Controllers/*.php` - API controllers
- `app/Http/Middleware/AdminMiddleware.php`
- `routes/api.php` - API routes
- `composer.json` - Dependencies
- `.env.example` - Environment template

### Documentation
- `/app/LARAVEL_SETUP.md` - Complete setup guide
