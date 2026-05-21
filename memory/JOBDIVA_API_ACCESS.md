# JobDiva REST API Access — Setup Prerequisites

**TL;DR — until 2026-02 we were calling the wrong path. Fixed in `client.php` as part of the auth diagnosis cycle. If you still see `401 "Full authentication is required"` after that fix, the route is wrong; if you now see `500 "Invalid username/password"`, your credentials need to be re-checked or your tenant needs provisioning.**

## What changed

Earlier docs (and earlier `client.php`) pointed at `POST /api/jobdiva/authenticate`. That path **does not exist** on `api.jobdiva.com` — Spring Security's `AnonymousAuthenticationFilter` rejects every request to unmatched routes with a generic `401 "Full authentication is required to access this resource"`, which made it look like a provisioning/auth problem. It wasn't.

JobDiva V2 spec (pulled live from <https://api.jobdiva.com/swagger?group=Version%202>):

```
GET /apiv2/v2/authenticate?clientid=<int64>&username=<email>&password=<pw>
→ 200 { "token": "<jwt>", "refreshtoken": "<jwt>" }   (JwtV2Response)
→ 500 "Invalid username/password"                     (real auth failure)
```

Things that are **NOT** required on this endpoint:
- `Authorization` header (this is the bootstrap call)
- `Content-Type` header (no body)
- a body of any kind — credentials live in the query string

## After fixing the path, what does each response mean?

| HTTP | JobDiva `message` | Likely cause | Action |
|------|-------------------|--------------|--------|
| 200  | (none)            | Working ✓    | Token cached in `jobdiva_connections.session_token_enc` |
| 401  | "Full authentication is required to access this resource" | Path is wrong AGAIN | Re-confirm `JOBDIVA_AUTH_PATH = '/apiv2/v2/authenticate'`. If the path is right, the API user has lost the **"Only allow to access JobDiva API Calls"** permission — re-enable via JobDiva Support. |
| 500  | "Invalid username/password" | Bad creds OR un-provisioned tenant | Re-check Client ID is the numeric **API** Client ID (not the tenant/company UI ID), and that the password hasn't been auto-rotated. |
| 500  | other             | JobDiva-side incident | Quote `x-li-uuid` in a support ticket. |

## What JobDiva still requires before the API user works at all

Per JobDiva's integration docs (ZoomInfo Talent, Merge, Knit all corroborate):

1. **Client ID** — a separate numeric API client identifier, **not** the alphanumeric tenant/company ID you see in the JobDiva UI. Issued only by JobDiva Support on request.
2. **Dedicated API user** — a service-account user, **not** a normal UI login. Best practice: create a new JobDiva user named `coreflux-api` (or similar) with a strong random password.
3. **`Only allow to access JobDiva API Calls` permission** — must be explicitly enabled on the API user's profile.
4. **Tenant-level API access toggle** — JobDiva sometimes also requires a tenant-level enablement that only their support team can flip.

## Support request template

Email JobDiva Support / your account rep:

> Subject: REST API access provisioning for tenant `<your tenant name>`
>
> Hi — we're integrating CoreFlux against the JobDiva V2 REST API
> (`GET https://api.jobdiva.com/apiv2/v2/authenticate`). We're getting
> `500 "Invalid username/password"` despite the credentials being correct.
>
> Please:
>
> 1. Issue/confirm the numeric **API Client ID** for our account.
> 2. Either confirm our existing API user (`<your username>`) has the
>    **"Only allow to access JobDiva API Calls"** permission enabled, OR
>    create a dedicated API-only service account named `coreflux-api`
>    with that permission and send us its username + password.
> 3. Confirm REST API access is enabled at the tenant level.

## After JobDiva responds

Paste the new Client ID + API username + API password into `/admin/integrations/jobdiva` in CoreFlux. Click **Test connection (Ping)** — it should turn green ✓ within a second, and `jobdiva_connections.session_token_exp` will populate (default 60-minute TTL).

## CoreFlux-side hardening (already in place)

- `jobdivaSessionToken()` now differentiates **path mismatch** (401), **bad creds** (500 "Invalid username/password"), and **provisioning** (legacy 401 case) — each surfaces a tailored remediation hint in the UI.
- `jobdivaRawRequest()` captures response headers (including `X-LI-UUID`, JobDiva's correlation id) so support tickets can include it.
- The credentials column in `jobdiva_connections` is AES-256-GCM encrypted; only `client_id` and `username` are recoverable via the UI (`password` and `webhook_secret` are one-way).
- Webhook verifier accepts JobDiva's actual `X-Hub-Signature` (HmacSHA1) plus `X-Hub-Signature-256` and the legacy `X-JobDiva-Signature` headers.

## Sync paths (separate follow-up)

`core/jobdiva/sync.php` and `sync_time.php` still hit `/api/jobdiva/companies`, `/api/jobdiva/contacts`, `/api/jobdiva/placements`, `/api/jobdiva/timesheets`. None of those paths exist either. Once authenticate is confirmed working with real credentials, the next pass should map them to the V2 BI endpoints (`/apiv2/bi/CompaniesDetail`, `/apiv2/bi/ContactsDetail`, etc.) — see Swagger group `Version 2`.

## Known follow-up issues already resolved in code (need prod ALTER)

After fixing the auth path, the next failure was:

```
SQLSTATE[22001]: String data, right truncated: 1406
Data too long for column 'session_token_enc' at row 1
```

JobDiva's V2 JWT is ~1.2 KB raw, and after AES-256-GCM wrapping
(`12-byte nonce + 16-byte tag + ciphertext`) it overflows the original
`VARBINARY(1024)`. Migration **066** widens the column to
`VARBINARY(4096)`. Run on prod:

```sql
ALTER TABLE jobdiva_connections
    MODIFY COLUMN session_token_enc VARBINARY(4096) DEFAULT NULL;
```

Or apply the file via your usual migration runner:
`core/migrations/066_jobdiva_session_token_width.sql`. Idempotent —
safe to re-run.

