# CoreFlux Architecture Analysis

## Pass 1: Current Architecture Understanding

### Overview
CoreFlux is a **hybrid PHP + React** multi-tenant enterprise platform with the following modules:
- **People** (HR, Timesheets, Directory)
- **Finance** (Budgets, Forecasts)
- **Accounting** (Transactions, Reports)
- **Tax**
- **Wealth Management**
- **Private Equity** (Cap Tables, Waterfall, Scenarios)
- **Master Admin Panel** (Multi-tenant management)

---

## Current Module Wiring

### 1. Authentication & Session
```
/login.php              → PHP session-based auth
/session.php            → Returns JSON: { user, modules, tenant, tenants, active_module }
/logout.php             → Destroys session
```

**Session Structure:**
```php
$_SESSION['user'] = [
  'first_name', 'last_name', 'email', 'role', 'avatar', 'tenants' => ['HQ', 'Branch 1']
];
$_SESSION['modules'] = [...];      // Role-based module access
$_SESSION['tenant'] = 'HQ';        // Current tenant context
$_SESSION['active_module'] = [...]; // Currently active module
```

### 2. Tenant Model
- **Multi-tenant**: Users can belong to multiple tenants
- **Tenant switching**: `/switch_tenant.php`, `/update_tenant.php`
- **Subtenant support**: Hierarchical tenant structure
- **Tenant-specific branding**: `/master_admin_panel/tenant_branding.php`

### 3. Module Structure (PHP)
```
/modules/
├── accounting/         # 5 files - placeholder stage
├── finance/           # 5 files - basic structure
├── master_admin_panel/ # 12 files - most complete
├── people/            # 16 files - most developed
├── private_equity/    # 50+ files - very developed
├── tax/               # 2 files - placeholder
└── wealth_management/ # 2 files - placeholder
```

**Module Pattern (PHP):**
```php
// modules/[module]/index.php
<?php include '../../partials/header.php'; ?>
<h1>Module Name</h1>
<?php include '../../partials/footer.php'; ?>
```

### 4. Module Structure (React)
```
/src/
├── App.jsx                    # Main router
├── main.jsx                   # Entry point
├── hooks/useSession.js        # Session hook → /session.php
├── layout/
│   ├── AppLayout.jsx          # Shell: Header + Sidebar + Main + Footer
│   ├── Header.jsx             # Top nav with module/tenant switching
│   ├── Sidebar.jsx            # Context-aware menu
│   └── Footer.jsx
├── modules/
│   ├── PeopleModule.jsx       # People module entry
│   ├── EmployeeDirectory.jsx
│   ├── Timesheets.jsx
│   ├── AccessControl.jsx
│   └── HiringPipeline.jsx
├── pages/
│   └── Login.jsx
└── config/
    └── People_module_actions.json
```

**Module Pattern (React):**
```jsx
// App.jsx routing
<Route path="/modules/people/*" element={<PeopleModule session={session} />} />
```

### 5. Design System
- **CSS**: `/assets/css/styles.css` - Basic styles
- **No component library** - Raw HTML/CSS
- **Responsive**: Grid-based, mobile-friendly
- **Brand Colors**: `#002c70` (dark blue), `#003366`, white

### 6. Database
```php
// /includes/config.php
$host = 'localhost:3306';
$admin_db = 'z2tpn3mqoatz6okk_master_admin';
$template_db = 'z2tpn3mqoatz6okk_coreflux_template';
```

- **MySQL** with separate databases per tenant/function
- **PDO** connections in `/core/db/db.php`

---

## Current Boundaries for Extraction

### Clear Module Boundaries:
| Module | PHP Path | React Path | Status |
|--------|----------|------------|--------|
| People | `/modules/people/` | `/src/modules/People*.jsx` | Active |
| Finance | `/modules/finance/` | - | Placeholder |
| Accounting | `/modules/accounting/` | - | Placeholder |
| Private Equity | `/modules/private_equity/` | - | Very developed |
| Tax | `/modules/tax/` | - | Placeholder |
| Wealth | `/modules/wealth_management/` | - | Placeholder |
| Master Admin | `/modules/master_admin_panel/` | - | Developed |

### Shared Dependencies:
```
/partials/          → header.php, sidebar.php, footer.php, nav.php
/includes/          → config.php (DB, SMTP)
/core/              → db_connection.php, functions_auth.php
/assets/            → CSS, icons, images
/views/             → Role-based dashboard views
```

---

## Key Observations

### Strengths:
1. **Clear module folders** - Already organized by domain
2. **Session-based module access** - Role-based permissions exist
3. **Tenant isolation** - Multi-tenant is built in
4. **React shell exists** - AppLayout provides host pattern

### Gaps:
1. **No module registry** - Modules hardcoded in routes/nav
2. **Mixed rendering** - Some PHP, some React, no clear boundary
3. **No module contract** - Each module is ad-hoc
4. **Tight coupling** - Modules include partials directly
5. **No API layer** - PHP pages return HTML, not JSON

---

## Pass 2: Modularization Design (Next Step)

### Proposed Architecture:

```
┌─────────────────────────────────────────────────────────┐
│                    CORE HOST APP                        │
│  - Shell (Header, Sidebar, Footer)                      │
│  - Auth/Session management                              │
│  - Tenant context                                       │
│  - Module registry & loader                             │
│  - Design system                                        │
│  - Global state                                         │
└─────────────────────────────────────────────────────────┘
                          │
          ┌───────────────┼───────────────┐
          ▼               ▼               ▼
    ┌───────────┐   ┌───────────┐   ┌───────────┐
    │ ACCOUNTING│   │  PEOPLE   │   │  FINANCE  │
    │  MODULE   │   │  MODULE   │   │  MODULE   │
    │           │   │           │   │           │
    │ Frontend  │   │ Frontend  │   │ Frontend  │
    │ Backend   │   │ Backend   │   │ Backend   │
    │ API       │   │ API       │   │ API       │
    └───────────┘   └───────────┘   └───────────┘
```

### Module Contract (Proposed):
```javascript
// Each module exports:
export default {
  id: 'accounting',
  name: 'Accounting',
  icon: '/assets/icons/icon-accounting.png',
  routes: [
    { path: '/overview', component: Overview },
    { path: '/transactions', component: Transactions },
    { path: '/reports', component: Reports },
  ],
  navItems: [
    { label: 'Overview', path: '/accounting/overview' },
    { label: 'Transactions', path: '/accounting/transactions' },
    { label: 'Reports', path: '/accounting/reports' },
  ],
  requiredPermissions: ['accounting.view'],
  featureFlag: 'accounting_enabled',
}
```

---

## Next Steps

1. **Define module contract interface**
2. **Create module registry in Core**
3. **Extract Accounting as model module**
4. **Document pattern for future modules**

---

*Generated: Jan 2026*
