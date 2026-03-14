# CoreFlux Platform - Product Requirements Document

## Original Problem Statement
Refactor a PHP-based multi-tenant application named `coreflux` into a modular architecture, with a core app and standalone functional modules. This evolved into a complete architectural overhaul to rebuild the entire application using a modern tech stack: **Laravel** for the backend and **React** for the frontend.

## Current Architecture

### Tech Stack
- **Frontend:** React 18 + Vite + TailwindCSS
- **Backend:** Laravel 10 (Sanctum for API authentication)
- **Database:** MySQL (existing schema)
- **Deployment:** Cloudways server via Git

### Directory Structure
```
/app
├── frontend/                  # React App (source)
│   ├── src/
│   │   ├── components/       # UI components
│   │   ├── pages/            # Page components
│   │   ├── hooks/            # React hooks
│   │   ├── lib/              # Utilities
│   │   └── styles/           # CSS
│   └── dist/                 # Build output
├── laravel/                   # Laravel Backend
│   ├── app/Http/Controllers/ # API controllers
│   └── app/Models/           # Eloquent models
├── modules/                   # Git Submodules
│   ├── accounting/           # (to be rewritten)
│   └── people/               # (to be rewritten)
└── app/                       # Deployed React build
```

## Brand Identity (Updated: March 2026)

### Colors
- **Core Navy:** `#0A2540` - Primary brand color
- **Flux Blue:** `#007FFF` - Accent color
- **Soft Gray:** `#F5F7FA` - Background
- **Dark Gray:** `#3A3F45` - Body text

### Typography
- **Primary Font:** Montserrat (fallback: Inter)
- **H1:** 32px / Bold / Navy
- **H2:** 24px / SemiBold / Navy
- **Body:** 16px / Regular / Dark Gray

### Tagline
"Power Your Core. Evolve with Flux."

---

## Implementation Status

### Completed Work
- [x] Laravel 10 backend setup with Sanctum authentication
- [x] React frontend with Vite build system
- [x] Authentication flow (login API)
- [x] Admin CRUD for tenants, users, modules
- [x] Git submodule integration
- [x] Server routing configuration (.htaccess)
- [x] **Brand identity applied to React frontend (March 2026)**
  - New color scheme implemented
  - Logo component with SVG swirl emblem
  - Typography with Montserrat font
  - Updated Login, Dashboard, Header, Sidebar components

### API Endpoints
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/auth/login` | POST | User authentication |
| `/api/auth/me` | GET | Current user data |
| `/api/admin/tenants` | GET | List all tenants |
| `/api/admin/users` | GET | List all users |
| `/api/admin/modules` | GET | List all modules |

---

## Roadmap

### P0 - Immediate Priority
1. **Deploy branded frontend** - Copy built React files to `/app` on Cloudways
2. **Connect React to API** - Wire login form to Laravel API
3. **Legacy login redirect** - Redirect old login to new React app

### P1 - High Priority
1. Rewrite Accounting Module to Laravel + React
2. Rewrite People Module to Laravel + React
3. Implement granular RBAC permissions

### P2 - Medium Priority
1. UI/UX refinements
2. Dashboard widgets with real data
3. Module-specific settings pages

### P3 - Future
1. Email notifications
2. Audit logging
3. API rate limiting
4. Multi-language support

---

## Deployment Instructions

### To deploy the branded frontend to Cloudways:

**Step 1: SSH into your Cloudways server**
```bash
ssh master_user@your-cloudways-ip
cd ~/public_html
```

**Step 2: Pull latest changes**
```bash
git pull origin main
```

**Step 3: Build the frontend**
```bash
cd frontend
yarn install  # or npm install
yarn build    # or npm run build
```

**Step 4: Deploy to the app directory**
```bash
cp -r dist/* ../app/
```

**Step 5: Verify the deployment**
- Visit `https://corefluxapp.com/` - Should show the marketing site
- Visit `https://corefluxapp.com/app/` - Should show the new CoreFlux React app with branding

### Key URLs
- **Marketing site:** `https://corefluxapp.com/`
- **React app:** `https://corefluxapp.com/app/`
- **API:** `https://corefluxapp.com/api/`

### Environment Variables (frontend/.env)
```
VITE_API_URL=https://corefluxapp.com
```

---

## Files Reference
- `/app/frontend/src/` - React source code
- `/app/frontend/tailwind.config.js` - Tailwind configuration with brand colors
- `/app/frontend/src/styles/index.css` - Global styles with brand typography
- `/app/frontend/src/components/ui/Logo.jsx` - Logo component
- `/app/design_guidelines.md` - Complete brand guidelines

---

*Last Updated: March 14, 2026*
