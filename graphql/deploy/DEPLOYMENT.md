# CoreFlux GraphQL — Cloudways Deployment

Production deploy of the GraphQL Federation alongside the existing PHP-FPM
stack. Four long-running processes managed by systemd, fronted by nginx
on the same box.

```
                  ┌──── nginx :443 ────┐
                  │                    │
   corefluxapp.com│  /api/*  ─►  php-fpm (existing)
                  │  /graphql ─►  apollo-router  :4000
                  │  /mcp     ─►  mcp-server     :4100
                  └────────────────────┘
                              │
                              ▼
                  ┌─ apollo-router ─┐
                  │      :4000      │
                  └────────┬────────┘
                           │
                ┌──────────┴──────────┐
                ▼                     ▼
        subgraph-coreflux       subgraph-jobdiva
              :4001                  :4002
                │                     │
                ▼                     ▼
              php-fpm  (existing PHP REST API)
```

## One-time host prep

```bash
# 1. Create the service user (PHP-FPM and the Node services share this).
useradd --system --shell /usr/sbin/nologin --create-home --home /opt/coreflux coreflux

# 2. Install Node 20.x (Cloudways ships with older Node by default).
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

# 3. Install the Apollo Router Rust binary.
curl -sSL https://router.apollo.dev/download/nix/v1.55.0 | sh
mv router /usr/local/bin/router && chmod +x /usr/local/bin/router

# 4. Layout.
install -d -o coreflux -g coreflux /opt/coreflux/graphql
install -d -o root     -g coreflux /etc/coreflux
```

## Deploy step (every release)

```bash
# 1. Sync the graphql/ directory to /opt/coreflux/graphql/.
rsync -a --delete /app/graphql/ /opt/coreflux/graphql/ \
  --exclude node_modules --exclude '*.log'

# 2. Install Node deps + build each TS package.
for d in subgraph-coreflux subgraph-jobdiva mcp-server; do
  (cd /opt/coreflux/graphql/$d && yarn install --frozen-lockfile && yarn build)
done

# 3. Re-compose the supergraph SDL (Apollo Router watches the file).
(cd /opt/coreflux/graphql/router && yarn install --frozen-lockfile && node compose.mjs)

# 4. Fix ownership.
chown -R coreflux:coreflux /opt/coreflux/graphql
```

## First-time setup

```bash
# 1. Drop secrets into /etc/coreflux/graphql.env (root:coreflux, 0640).
cp /app/graphql/deploy/etc/graphql.env.example /etc/coreflux/graphql.env
$EDITOR /etc/coreflux/graphql.env    # fill in JWT_SECRET, INTERNAL_HMAC_SECRET, etc.
chown root:coreflux /etc/coreflux/graphql.env
chmod 0640         /etc/coreflux/graphql.env

# 2. Install the systemd units.
install -m 0644 /app/graphql/deploy/systemd/coreflux-*.service /etc/systemd/system/
systemctl daemon-reload

# 3. Drop the nginx location block into the existing server config.
#    Edit /etc/nginx/sites-available/corefluxapp.com and `include` it
#    inside the `server { … }` block:
#
#      include /opt/coreflux/graphql/deploy/nginx/coreflux-graphql.conf;
#
#    Or copy the contents directly. Then:
nginx -t && systemctl reload nginx

# 4. Boot the stack. The Requires= chain takes care of start order.
systemctl enable --now coreflux-router

# 5. Verify.
curl -s http://127.0.0.1:8088/health        # router health
curl -sX POST http://127.0.0.1:4000/ \
     -H "Content-Type: application/json" \
     -d '{"query":"{ __schema { queryType { name } } }"}'
```

## Ongoing operations

| Task                              | Command |
|-----------------------------------|---------|
| Restart everything                | `systemctl restart coreflux-router` (chain cascades) |
| Restart only one subgraph         | `systemctl restart coreflux-subgraph-jobdiva` |
| Live logs                         | `journalctl -u coreflux-router -f` |
| Update schema (zero downtime)     | rerun `node compose.mjs` — router hot-reloads `supergraph.graphql` |
| Rotate JWT_SECRET                 | update `/etc/coreflux/graphql.env`, then `systemctl restart coreflux-router` (cascades) |
| Switch MCP transport for an agent | edit `coreflux-mcp.service`, change `MCP_TRANSPORT=stdio` ↔ `http` |

## Apollo Router free tier — what's missing

The router's GraphOS-only features are intentionally NOT used:
- JWT verification at the gateway → done in each Node subgraph instead.
- Operation depth/height/aliases limits → would need an Apollo Server
  plugin in each subgraph (TODO when AI agents start crafting queries).
- Advanced telemetry attributes → basic JSON-to-journald logging only.

This is a deliberate trade-off: the router stays free, blazing fast,
and dependency-free. JWT verification happens once per subgraph per
request, which is microseconds.

## Health probes

| Endpoint                           | Listener         | Use |
|------------------------------------|------------------|-----|
| `GET /healthz/graphql` (via nginx) | router :8088     | Cloudways health monitor |
| `GET /graphql` (introspection)     | router :4000     | MCP server schema warmup |
| `node dist/index.js --version`     | each subgraph    | smoke for CI |

## Rollback

```bash
# Schema rollback only (most common):
cp /opt/coreflux/graphql/router/supergraph.graphql.prev \
   /opt/coreflux/graphql/router/supergraph.graphql
# (router hot-reloads in <1s — no service restart needed)

# Full code rollback:
git -C /opt/coreflux checkout <prev-sha> -- graphql/
systemctl restart coreflux-router
```

## Smoke tests

Before flipping nginx over to the new upstream, run:

```bash
# 1. Subgraph + router round-trip with fixture data.
php -d zend.assertions=1 /app/tests/graphql_router_e2e_smoke.php

# 2. MCP server boot + tool registration.
php -d zend.assertions=1 /app/tests/graphql_federation_smoke.php

# 3. HMAC bridge integrity.
php -d zend.assertions=1 /app/tests/internal_hmac_bridge_smoke.php
```

All three should return exit code 0.
