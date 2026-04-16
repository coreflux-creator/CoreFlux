# CoreFlux React SPA - Deployment Guide

## Overview

The React SPA dashboard has been built and is ready to deploy alongside your PHP backend on Cloudways.

## Files Created

- `/app/spa.php` - Entry point that serves the React SPA (requires authentication)
- `/app/session.php` - API endpoint returning user session data
- `/app/update_active_module.php` - API endpoint to update active module
- `/app/app/` - Built React assets (index.html, JS, CSS)
- `/app/dashboard/src/` - React source code

## Deployment Steps

### 1. Copy Files to Cloudways

Upload the following to your Cloudways server:

```bash
# Copy the built React app
scp -r /app/app/ user@your-cloudways-server:/path/to/coreflux/

# Copy the PHP endpoints (if not already updated)
scp /app/spa.php user@your-cloudways-server:/path/to/coreflux/
scp /app/session.php user@your-cloudways-server:/path/to/coreflux/
scp /app/update_active_module.php user@your-cloudways-server:/path/to/coreflux/

# Copy the React source for future builds
scp -r /app/dashboard/ user@your-cloudways-server:/path/to/coreflux/
```

### 2. Update Login Redirect (Optional)

If you want the React SPA to be the default dashboard, update `login.php`:

```php
// Change from:
header("Location: dashboard.php");

// To:
header("Location: spa.php");
```

### 3. Access the React Dashboard

After deployment, access the React SPA at:
- `https://www.corefluxapp.com/spa.php`

Or if you make it the default:
- Login → Automatically redirects to React SPA

## How It Works

1. User visits `spa.php`
2. PHP checks if user is authenticated (session)
3. If not logged in → redirects to `login.html`
4. If logged in → serves React SPA HTML
5. React SPA loads and fetches session from `/session.php`
6. React renders the dashboard with real user/module data

## API Endpoints

### GET /session.php
Returns current user session:
```json
{
  "user": {
    "id": 1,
    "first_name": "Kunal",
    "last_name": "",
    "email": "user@example.com",
    "role": "admin",
    "global_role": "tenant_admin"
  },
  "modules": [
    {
      "id": "accounting",
      "name": "Accounting",
      "icon": "/assets/icons/icon-accounting.png",
      "actions": [
        {"name": "Overview", "route": "overview"},
        {"name": "Chart of Accounts", "route": "chart_of_accounts"}
      ]
    }
  ],
  "tenant": "CoreFlux",
  "tenants": [{"id": 1, "name": "CoreFlux", "role": "admin"}],
  "active_module": {...}
}
```

### POST /update_active_module.php
Updates current module context:
```json
// Request
{"module": "accounting"}

// Response
{"success": true, "module": "Accounting"}
```

## Building Updates

If you modify the React code, rebuild:

```bash
cd /path/to/coreflux/dashboard
npm install
npm run build
cp -r dist/* ../app/
```

## Fallback

If the React SPA has issues, users can still access:
- `dashboard.php` - Original PHP dashboard (fully functional)
