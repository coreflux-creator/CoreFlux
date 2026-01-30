# CoreFlux - Laravel + React Setup Guide

## Overview

CoreFlux has been rebuilt with:
- **Frontend**: React + Vite + Tailwind CSS
- **Backend**: Laravel 10 + Sanctum (API auth)
- **Database**: MySQL (using existing tables)

## Directory Structure

```
coreflux/
├── frontend/               # React SPA
│   ├── src/
│   │   ├── components/     # React components
│   │   ├── pages/          # Page components
│   │   ├── hooks/          # Custom hooks (useAuth, useModules)
│   │   └── lib/            # Utilities (api.js)
│   ├── package.json
│   └── vite.config.js
│
├── laravel/                # Laravel backend (move contents to root for deployment)
│   ├── app/
│   │   ├── Http/Controllers/
│   │   ├── Models/
│   │   └── Middleware/
│   ├── routes/api.php
│   ├── config/
│   └── composer.json
│
└── modules/                # Git submodules
    ├── accounting/         # coreflux-accounting repo
    └── people/             # coreflux-people repo
```

## Setup Instructions

### Step 1: Laravel Backend Setup

```bash
# Navigate to laravel folder
cd /path/to/coreflux/laravel

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Configure .env with your database credentials
# DB_HOST=127.0.0.1
# DB_DATABASE=your_database
# DB_USERNAME=your_user
# DB_PASSWORD=your_password
```

### Step 2: Database Setup

The app uses your existing MySQL tables. Run this migration to add the personal_access_tokens table for Sanctum:

```bash
php artisan migrate
```

Or manually create the table:

```sql
CREATE TABLE personal_access_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX (tokenable_type, tokenable_id)
);
```

### Step 3: Register Middleware

Add the admin middleware to `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    // ... existing middleware
    'admin' => \App\Http\Middleware\AdminMiddleware::class,
];
```

### Step 4: Frontend Setup

```bash
cd /path/to/coreflux/frontend

# Install dependencies
npm install

# Configure API URL in .env
echo "VITE_API_URL=http://localhost:8000" > .env

# Build for production
npm run build
```

### Step 5: Start Development Servers

**Laravel (Backend):**
```bash
cd laravel
php artisan serve --port=8000
```

**React (Frontend):**
```bash
cd frontend
npm run dev
```

### Step 6: Production Deployment (Cloudways)

1. **Copy Laravel files to server root** (where your current PHP files are)
2. **Run composer install** on the server
3. **Build React frontend** and copy `dist/` to `public/app/` or similar
4. **Configure nginx/apache** to:
   - Serve React app for non-API routes
   - Route `/api/*` to Laravel

Example nginx config addition:
```nginx
location / {
    try_files $uri $uri/ /index.html;
}

location /api {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## API Endpoints

### Authentication
- `POST /api/auth/login` - Login, returns token
- `GET /api/auth/me` - Get current user
- `POST /api/auth/logout` - Logout

### Tenant Modules
- `GET /api/tenants/{id}/modules` - Get enabled modules for a tenant

### Admin (requires master_admin role)
- `GET /api/admin/tenants` - List all tenants
- `POST /api/admin/tenants` - Create tenant
- `PUT /api/admin/tenants/{id}` - Update tenant
- `DELETE /api/admin/tenants/{id}` - Delete tenant
- `GET /api/admin/tenants/{id}/modules` - Get tenant's modules
- `POST /api/admin/tenants/{id}/modules/{moduleId}` - Toggle module

- `GET /api/admin/users` - List all users
- `POST /api/admin/users` - Create user
- `PUT /api/admin/users/{id}` - Update user
- `DELETE /api/admin/users/{id}` - Delete user

- `GET /api/admin/modules` - List all modules
- `POST /api/admin/modules` - Create module

## Next Steps After Setup

1. **Test login** with your existing credentials
2. **Verify admin panel** works (requires master_admin role)
3. **Rewrite accounting module** - Convert FastAPI → Laravel controllers
4. **Rewrite people module** - Convert PHP → Laravel controllers

## Troubleshooting

### "Token mismatch" errors
Make sure `SANCTUM_STATEFUL_DOMAINS` in `.env` includes your frontend domain.

### CORS errors
Check `config/cors.php` allows your frontend origin.

### 401 Unauthorized
- Verify token is being sent in Authorization header
- Check token hasn't expired
- Ensure user exists in database
