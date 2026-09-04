# CoreFlux API

CoreFlux exposes a versioned JSON API for tenant modules and compatibility
endpoints for authentication, administration, integrations, and webhooks.

## Package contents

- [`/api/openapi.json`](../../api/openapi.json) is the importable OpenAPI 3.1
  contract.
- [`/api/endpoints.json`](../../api/endpoints.json) is the complete,
  implementation-level endpoint inventory, including source file, preferred
  path, methods, audience, authentication model, and legacy aliases.
- [`scripts/build_api_package.php`](../../scripts/build_api_package.php)
  regenerates both artifacts deterministically.
- [`scripts/probe_api.php`](../../scripts/probe_api.php) performs a read-only
  reachability check against a local, staging, or production deployment.

The generated inventory covers every module and direct/namespaced PHP endpoint;
its top-level `counts` object records the current totals. HMAC bridges are
marked internal-only and intentionally excluded from the public OpenAPI
contract. The OpenAPI operation schemas are deliberately permissive for now;
the inventory is complete, while domain-specific request and response schemas
can be tightened incrementally without losing coverage.

## Authentication

The versioned tenant API accepts either:

1. A CoreFlux web session cookie for same-origin SPA requests.
2. A JWT bearer token for native and server-side clients.

Obtain a token:

```bash
curl -X POST https://www.corefluxapp.com/api/auth/mobile_login \
  -H "Content-Type: application/json" \
  -d '{"email":"developer@example.com","password":"...","tenant_code":"acme"}'
```

Use the returned access token:

```bash
curl https://www.corefluxapp.com/api/v1/people/employees \
  -H "Authorization: Bearer $COREFLUX_API_TOKEN" \
  -H "Accept: application/json"
```

Access tokens expire after eight hours. Exchange the rotating refresh token at
`POST /api/auth/mobile_refresh`. Keep access and refresh tokens out of source
control, URLs, logs, and generated probe reports.

The API fails closed unless the deployment provides a `JWT_SECRET` or
`APP_KEY` of at least 32 characters. The same secret must be configured on the
PHP API and GraphQL subgraphs before bearer authentication is enabled.

Tenant and role permissions are enforced after authentication. A valid token
can therefore receive `403 Forbidden` for endpoints outside its assigned
permissions.

## Versioning and compatibility

Use `/api/v1/<module>/<resource>` for new integrations. Unversioned module
routes remain available for the existing web application and return a
`Deprecation: true` response header. Existing direct `.php` URLs remain
supported; the endpoint inventory records those aliases.

Versioned responses include `X-CoreFlux-API-Version`, and routed responses link
to the OpenAPI description with the HTTP `Link` header.

## Errors

JSON errors use this minimum shape and may include additional diagnostic
fields:

```json
{
  "error": "Human-readable explanation",
  "status": 422
}
```

Common statuses are `400` for malformed requests, `401` for missing or invalid
authentication, `403` for insufficient permissions, `404` for missing
resources, `405` for unsupported methods, and `422` for validation failures.

## Webhooks and special authentication

Endpoints marked with `"auth": "custom"` do not use a normal user bearer
token. They may use HMAC signatures, OAuth state, single-use links, provider
signatures, or intentionally public liveness behavior. Consult the endpoint's
source header and integration setup before calling one. Never expose a webhook
endpoint without configuring its provider secret.

## Build and validate

Regenerate the contract after adding, removing, or renaming an endpoint:

```bash
php scripts/build_api_package.php
php scripts/build_api_package.php --check
php -d zend.assertions=1 tests/api_package_smoke.php
```

The smoke test fails when an implementation is absent from the inventory, a
preferred route no longer resolves, a path is duplicated, or generated files
are stale. It is automatically included by the repository's existing
`tests/*_smoke.php` CI runner.

## Verify a deployment

Run the non-destructive probe from a machine that can reach the deployment:

```bash
php scripts/probe_api.php \
  --base-url=https://www.corefluxapp.com \
  --strict \
  --json=api-reachability-report.json
```

For authenticated verification, set `COREFLUX_API_TOKEN` in the process
environment. The probe never writes the token to output or its JSON report.

The probe sends only `GET` requests. Authentication errors, permission errors,
method errors, and validation errors all prove that a route reached its
handler. Missing router paths, network failures, and ambiguous non-JSON 404s
fail verification. In `--strict` mode, application `5xx` responses fail too.

The production probe defaults to `--scope=public`; internal HMAC bridges should
not be exposed by public nginx. For a complete local route-level check, start
PHP's development server with the included rewrite adapter and use
`--scope=all`:

```bash
php -S 127.0.0.1:8080 -t . scripts/api_dev_router.php
php scripts/probe_api.php --base-url=http://127.0.0.1:8080 --scope=all
```

Use `--path-mode=fallback` during a migration audit to try each recorded legacy
alias when a preferred route is missing. A successful fallback proves the
implementation is deployed, but the preferred-path probe must still pass
before the packaged contract can be considered released.

## Browser access

CORS permits CoreFlux production and preview origins plus local development.
Allowed request headers include `Authorization`, `Content-Type`,
`Idempotency-Key`, and `X-Request-ID`. Third-party browser origins require an
explicit allow-list decision; server-to-server and native clients are not
subject to browser CORS enforcement.
