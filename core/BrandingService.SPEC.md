# Core BrandingService — Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Layer**: Core platform primitive — NOT a module. Lives in `/core/BrandingService.php`.
**Consumers**: every module that renders UI, every email template, every PDF document.

This SPEC defines the platform-wide white-labeling primitive. Tenants get their own subdomain, their own logo, their own colors, and a branded login experience. Modules MUST source visual identity through this service — no hardcoded colors / logos in module code.

---

## 1. Purpose

White-labeling makes CoreFlux feel like the tenant's product, not a generic SaaS. Specifically:

- Tenants land at `<slug>.corefluxapp.com` instead of a generic CoreFlux URL.
- Their logo replaces the CoreFlux logo everywhere it would otherwise appear.
- A theme palette derived from the logo (or hand-picked) drives the React UI.
- The login page is branded.
- Outbound emails and PDFs carry the tenant's identity.
- A small "Powered by CoreFlux" footer remains in place (locked, non-removable).

---

## 2. Decisions locked at sign-off

1. ✅ **Subdomain-only at MVP** — `<slug>.corefluxapp.com`. Custom domain (`acme.com` via CNAME) deferred to Phase B.
2. ✅ **Color customization = auto-extracted from logo + tenant overrides.** When a tenant uploads a logo, the system extracts dominant colors and proposes a theme palette. Tenant can accept the proposal or override individual color slots.
3. ✅ **Public landing at `<slug>.corefluxapp.com/` = branded login form only.** Unauthenticated visitors see "Welcome to <Tenant> — Login". Mini-marketing pages deferred to Phase B/C.
4. ✅ **Logo replaces the CoreFlux logo wherever the CoreFlux logo would appear** — sidebar, login page, outbound emails, outbound PDFs (invoices, pay stubs, statements, 1099s, etc.).
5. ✅ **Footer "Powered by CoreFlux" stays in place across all surfaces.** Non-removable; rendered by the platform.
6. ✅ **Slug**: auto-suggested from tenant name (e.g., "Acme Staffing Inc." → `acme-staffing`); tenant can edit at signup. Slug is changeable later with old-slug → new-slug redirect for 90 days.

---

## 3. Architecture overview

```
DNS:  *.corefluxapp.com  →  Cloudways app
SSL:  Wildcard cert via Let's Encrypt (auto-renew)

Request flow:
  Browser  → acme.corefluxapp.com/login
  Cloudways receives Host: acme.corefluxapp.com
  core/api_bootstrap.php:
    - extract subdomain → "acme"
    - resolve to tenant_id via tenants.slug
    - inject branding context into request
    - load CSS variables via /api/branding/{tenant_slug}/theme.css
  React SPA loads with branded theme + logo
```

---

## 4. Data model

### 4.1 Add to `tenants` table

```sql
ALTER TABLE tenants ADD COLUMN slug VARCHAR(63) UNIQUE NOT NULL;
ALTER TABLE tenants ADD COLUMN slug_changed_at DATETIME NULL;
```

DNS-safe slug: lowercase letters, digits, hyphens; 1–63 chars; no leading/trailing hyphen.
Reserved slugs (cannot be claimed): `www`, `app`, `api`, `admin`, `support`, `help`, `docs`, `status`, `mail`, `email`, `auth`, `login`, `dashboard`, `core`, `coreflux`, `master`, `system`, `static`, `assets`.

### 4.2 `tenant_branding`

| Column | Type | Notes |
|---|---|---|
| `tenant_id` | BIGINT PK FK | one row per tenant |
| `display_name` | VARCHAR(120) | shown in login page, emails, PDFs (defaults to tenant.name) |
| `logo_storage_object_id` | BIGINT NULL FK→`storage_objects.id` | uploaded logo (PNG/SVG) |
| `logo_dark_storage_object_id` | BIGINT NULL FK | optional alternate logo for dark mode |
| `favicon_storage_object_id` | BIGINT NULL FK | 32×32 PNG/ICO; auto-derived from logo if missing |
| `extracted_palette_json` | TEXT NULL | colors auto-extracted from logo at upload time |
| `theme_palette_json` | TEXT NULL | active palette (defaults to extracted; tenant overrides stored here) |
| `email_signature_html` | TEXT NULL | optional HTML appended to outbound email templates |
| `support_email` | VARCHAR(255) NULL | "Need help?" address shown in branded footer |
| `support_url` | VARCHAR(500) NULL | optional link |
| `created_at` / `updated_at` | DATETIME | |

### 4.3 `tenant_slug_redirects` (90-day grace)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `old_slug` | VARCHAR(63) UNIQUE | |
| `new_slug` | VARCHAR(63) | |
| `expires_at` | DATETIME | 90 days from change |

When a request comes in for `oldslug.corefluxapp.com` and an active redirect exists, respond `301` to `newslug.corefluxapp.com<path>`.

### 4.4 `tenant_branding_audit` (separate from platform audit — every change tracked)

```
id, tenant_id, actor_user_id, field_changed, before_json, after_json, changed_at, ip
```

---

## 5. Theme palette structure (`theme_palette_json`)

Stored as a JSON object with a fixed schema. The platform renders CSS variables from this:

```json
{
  "primary":         "#0F4C81",
  "primary_hover":   "#0B3D6B",
  "primary_active":  "#082E51",
  "primary_subtle":  "#E8F0F9",
  "primary_contrast":"#FFFFFF",
  "accent":          "#F7B500",
  "accent_hover":    "#E0A300",
  "accent_contrast": "#1A1A1A",
  "surface":         "#FFFFFF",
  "surface_subtle":  "#F7F9FB",
  "surface_inverted":"#0E1116",
  "border":          "#E2E6EB",
  "text_primary":    "#0E1116",
  "text_secondary":  "#5A6473",
  "text_inverted":   "#FFFFFF",
  "success":         "#1F9D55",
  "warning":         "#D97706",
  "error":           "#DC2626",
  "info":            "#2563EB"
}
```

The system auto-derives the variants (`*_hover`, `*_subtle`, `*_contrast`) using HSL math from a single chosen `primary` and `accent`. Tenants can override any individual slot.

Phase A scope: light theme only. Dark theme is Phase C (per BrandingService MVP cut list).

---

## 6. Color extraction from logo

When a tenant uploads a logo via `POST /api/branding/logo`:

1. File saved via Core StorageService at `branding/{tenant_id}/logo.{ext}`.
2. PHP processes the image:
   - Use GD or Imagick to sample non-transparent pixels.
   - Run k-means clustering (k=5) on RGB to find dominant colors.
   - Filter out near-white (lightness > 0.92) and near-black (lightness < 0.08) unless logo is monochrome.
   - Sort by frequency-weighted saturation.
3. Top 3 colors → propose as `primary`, `accent`, and either `info` or `success` slot.
4. Store proposal in `extracted_palette_json`.
5. UI shows the proposed palette to the tenant_admin with a preview canvas; they accept or override before saving to `theme_palette_json`.

PHP libraries: native GD is sufficient. For SVG logos, rasterize to a 256×256 PNG first via Imagick, then process. For very simple monochrome logos (single dominant color), system asks tenant to pick an accent manually.

---

## 7. Subdomain resolution (request lifecycle)

In `core/api_bootstrap.php`, very early:

```php
$host = $_SERVER['HTTP_HOST'] ?? '';
$slug = BrandingService::resolve_slug_from_host($host);  // "acme.corefluxapp.com" -> "acme"

if ($slug !== null) {
    $tenant = BrandingService::resolve_tenant_by_slug($slug);
    if ($tenant === null) {
        // Check redirect table
        $redirect = BrandingService::lookup_slug_redirect($slug);
        if ($redirect && $redirect->expires_at > now()) {
            header("Location: https://{$redirect->new_slug}.corefluxapp.com{$_SERVER['REQUEST_URI']}", 301);
            exit;
        }
        // Render generic "tenant not found" page
        BrandingService::render_unknown_tenant_page();
        exit;
    }
    BrandingContext::set($tenant);
}
```

`BrandingContext` is a request-scoped singleton that downstream code reads:
```php
$brand = BrandingContext::current();   // tenant + branding row + theme palette
$tenantId = $brand->tenant_id;
```

Hosts that DON'T have a subdomain (`corefluxapp.com`, `app.corefluxapp.com`) render the generic CoreFlux marketing site / generic login that asks "Which company?" and redirects to the right subdomain.

---

## 8. PHP API surface

### 8.1 `Core\BrandingService`

```php
namespace Core;

class BrandingService {
    public static function resolve_slug_from_host(string $host): ?string;
    public static function resolve_tenant_by_slug(string $slug): ?Tenant;
    public static function lookup_slug_redirect(string $slug): ?SlugRedirect;
    public static function render_unknown_tenant_page(): void;

    public function get_branding(int $tenantId): TenantBranding;
    public function update_branding(int $tenantId, array $patch): TenantBranding;
    public function upload_logo(int $tenantId, string $localFilePath, string $variant = 'primary'): TenantBranding;  // 'primary'|'dark'|'favicon'
    public function extract_palette_from_logo(int $tenantId): array;
    public function set_palette(int $tenantId, array $palette): TenantBranding;
    public function reset_palette_to_extracted(int $tenantId): TenantBranding;

    public function change_slug(int $tenantId, string $newSlug): SlugChangeResult;  // creates redirect entry
    public function validate_slug(string $slug): SlugValidationResult;  // format + reserved + collision

    public function render_theme_css(int $tenantId): string;  // returns CSS with :root variables
    public function get_logo_signed_url(int $tenantId, string $variant = 'primary', int $ttlSeconds = 3600): ?string;
}
```

### 8.2 REST endpoints

- `GET /api/branding` — current tenant's branding (resolved from BrandingContext).
- `PATCH /api/branding` — update fields; permission `branding.manage`.
- `POST /api/branding/logo` — multipart upload; permission `branding.manage`.
- `POST /api/branding/logo/extract-palette` — re-run extraction on existing logo.
- `PUT /api/branding/palette` — explicit palette override.
- `POST /api/branding/palette/reset` — revert to extracted palette.
- `POST /api/branding/slug/validate` — body `{slug}`; checks format, reserved list, collision.
- `POST /api/branding/slug/change` — body `{new_slug}`; permission `branding.slug.change` (gated higher).
- `GET /api/branding/{slug}/theme.css` — **public** endpoint (no auth) returning the tenant's CSS variables; cached with `ETag` and 1-hour `Cache-Control`. The login page calls this BEFORE auth.
- `GET /api/branding/{slug}/logo` — **public** endpoint redirecting to a signed S3 URL for the active logo. Cached.

### 8.3 React side

- `useBranding()` hook reads tenant branding from a `BrandingProvider` populated at app boot from `/api/branding`.
- Initial CSS variables injected by `<link rel="stylesheet" href="/api/branding/{slug}/theme.css">` rendered by the platform's HTML shell (so first paint is already branded — no white flash).
- Logo component `<TenantLogo variant="primary"|"dark"|"favicon" />` resolves from branding context.

---

## 9. Where the logo appears (locked surfaces, MVP)

| Surface | Token replaced | Notes |
|---|---|---|
| React sidebar header | CoreFlux logo → tenant logo | dark/light variants if both uploaded |
| Login page | CoreFlux mark → tenant logo | branded greeting "Welcome to <Tenant>" |
| Browser favicon | CoreFlux favicon → tenant favicon | served per-subdomain |
| Outbound email templates (Core MailService) | CoreFlux header → tenant logo | passed as a token to all mail templates |
| Invoice PDFs | CoreFlux corner mark → tenant logo | rendered via PDF library |
| Pay stub PDFs | same | |
| Statement PDFs | same | |
| 1099 forms | tenant logo on the cover sheet only (form body is IRS-mandated) | |
| Token approval emails (Time, Billing) | tenant logo + tenant display_name | |
| Dunning emails | same | |

The "Powered by CoreFlux" footer is rendered by the platform on every surface above. Locked. Non-configurable per HARD_RULES (this fork).

---

## 10. RBAC

| Slug | Description |
|---|---|
| `branding.view` | View branding settings |
| `branding.manage` | Update logo, palette, display name, support links |
| `branding.slug.change` | Change tenant slug (high-impact — gated separately) |

Default roles for `branding.manage`: `tenant_admin`, `master_admin`. Default for `branding.slug.change`: `tenant_admin`, `master_admin`.

---

## 11. Audit events

`branding.updated` (fields diff)
`branding.logo.uploaded` / `branding.logo.deleted`
`branding.palette.changed` / `branding.palette.reset`
`branding.slug.changed` (with old_slug, new_slug, redirect_expires_at)

---

## 12. Multi-tenancy + isolation

- All branding data scoped by `tenant_id`.
- Logo files isolated under `branding/{tenant_id}/` in S3.
- Subdomain-to-tenant resolution is the primary tenant-isolation mechanism for unauthenticated requests (e.g. login).
- Cross-tenant logo / theme reads disallowed (master_admin override audit-logged).

---

## 13. Performance

- `theme.css` cached 1 hour browser-side + ETag on server.
- Logo signed URLs cached at the edge for 1 hour.
- `BrandingContext` resolution adds one indexed `tenants.slug` lookup per request — negligible (<1ms).

---

## 14. Validation rules

- Slug: regex `^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`, not in reserved list, not currently in `tenants.slug`, not currently active in `tenant_slug_redirects.old_slug`.
- Logo upload: PNG / SVG / JPG; max 2 MB; min 256×256 (or vector); square-ish aspect (between 1:2 and 2:1) — warn outside.
- Palette: every color is a valid 6-digit hex; contrast ratios checked (primary vs primary_contrast must meet WCAG AA 4.5:1) — warn on fail.

---

## 15. MVP cut list

**Phase A (with Phase 1 manifest extension):**
- `tenants.slug` column + reserved-slug enforcement
- `tenant_branding` table + audit
- `BrandingService` core class
- Subdomain resolution in `api_bootstrap.php`
- Wildcard SSL (Let's Encrypt) on Cloudways
- `*.corefluxapp.com` DNS wildcard
- React `BrandingProvider` + `useBranding` + `<TenantLogo />`
- `theme.css` public endpoint with CSS variables
- Logo upload UI + color extraction (k-means dominant colors)
- Palette editor UI with live preview
- Branded login page
- Logo in sidebar, login, favicon
- "Powered by CoreFlux" footer on login + sidebar

**Phase B:**
- Custom domain (`acme.com` via CNAME with per-tenant SSL via ACM or Caddy)
- Logo in outbound emails (Core MailService template token)
- Logo in outbound PDFs (invoices, pay stubs, statements, dunning)
- Mini-marketing landing page options at `<slug>.corefluxapp.com/`
- Slug-change redirect with 90-day grace (Phase A could ship without this if rare)

**Phase C:**
- Dark theme variants
- Per-surface palette overrides (e.g., separate palette for customer-facing emails vs internal sidebar)

---

## 16. Open questions

(All MVP-blocking ones answered. Below are deferrable.)

1. **Cloudways wildcard SSL** — confirm Cloudways supports Let's Encrypt wildcard certs natively, or do we need a custom solution? (Operations question, resolve at infra setup time.)
2. **Custom domain Phase B vendor** — Caddy reverse-proxy in front of Cloudways, or per-tenant ACM cert? (Decide at Phase B kickoff.)
3. **Logo CDN** — serve logos via CloudFront in front of S3 for global tenants? (Phase B at scale.)

---

*Binding once signed off.*
