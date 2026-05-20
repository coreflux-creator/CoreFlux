# JobDiva REST API Access — Setup Prerequisites

**TL;DR — `401 "Full authentication is required to access this resource"` from `/api/jobdiva/authenticate` is almost never a CoreFlux bug. It means JobDiva hasn't provisioned API access for the tenant yet.**

Verified 2026-02: every request shape (JSON body / query string / form-encoded / GET / with Basic Auth / without) returns the same 401 against an un-provisioned account, *before* JobDiva ever inspects the credentials. Spring Security's `AnonymousAuthenticationFilter` rejects the request because the JobDiva user lacks the API permission flag.

## What JobDiva requires before API calls work

Per JobDiva's integration docs (ZoomInfo Talent, Merge, Knit all corroborate):

1. **Client ID** — a separate API client identifier, *not* the company ID you see in the JobDiva UI. Issued only by JobDiva Support on request.
2. **Dedicated API user** — a service-account user, *not* a normal UI login. Best practice: create a new JobDiva user named `coreflux-api` (or similar) with a strong random password.
3. **`Only allow to access JobDiva API Calls` permission** — must be explicitly enabled on the API user's profile. Without this flag, the authenticate filter rejects every request with the 401 above.
4. **Tenant-level API access toggle** — JobDiva sometimes also requires a tenant-level enablement that only their support team can flip.

## Support request template

Email JobDiva Support / your account rep:

> Subject: REST API access provisioning for tenant `<your tenant name>`
>
> Hi — we're setting up an external integration (CoreFlux) that needs to call the JobDiva REST API at `https://api.jobdiva.com/api/jobdiva/authenticate`. We're getting `401 "Full authentication is required to access this resource"` on every call.
>
> Please:
>
> 1. Issue a **Client ID** for our account for API use.
> 2. Either confirm our existing API user (`<your username>`) has the **"Only allow to access JobDiva API Calls"** permission enabled, OR create a dedicated API-only service account named `coreflux-api` with that permission and send us its username + password.
> 3. Confirm REST API access is enabled at the tenant level.
>
> Once provisioned we'll paste the new Client ID + API user credentials into our integration settings.

## After JobDiva responds

Paste the new Client ID + API username + API password into `/admin/integrations/jobdiva` in CoreFlux. Click **Test connection (Ping)** — it should turn green ✓ within a second, and `jobdiva_connections.session_token_exp` will populate.

If you still see 401 after that:

- Verify you used the **API Client ID**, not the regular tenant client/company ID.
- Verify the API user's password hasn't been auto-rotated by JobDiva's password policy.
- Confirm the API permission flag is still set (JobDiva can silently drop it during password resets in some workflows).

## CoreFlux-side hardening (already in place)

- `jobdivaSessionToken()` detects the Spring Security "Full authentication is required" phrase and throws a remediation-aware exception pointing at this doc.
- `jobdivaRawRequest()` captures response headers (including `X-LI-UUID`, JobDiva's correlation id) so support tickets can include it.
- The credentials column in `jobdiva_connections` is AES-256-GCM encrypted; only `client_id` and `username` are recoverable via the UI (`password` and `webhook_secret` are one-way).
- Webhook verifier accepts JobDiva's actual `X-Hub-Signature` (HmacSHA1) plus `X-Hub-Signature-256` and the legacy `X-JobDiva-Signature` headers.
