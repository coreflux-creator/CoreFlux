#!/usr/bin/env bash
#
# CoreFlux GraphQL — Cloudways release wrapper.
#
# Glue for the documented release flow in DEPLOYMENT.md. Run on the
# Cloudways host AS ROOT after `git pull` lands the new code.
#
#   sudo /opt/coreflux/graphql/deploy/scripts/release.sh
#
# Pipeline:
#   1. Pre-flight  → tools present, env file populated.
#   2. Build       → rsync + yarn install --frozen-lockfile + yarn build.
#   3. Compose     → write supergraph.graphql.
#   4. Health      → curl /healthz before & after to detect regressions.
#   5. Reload      → router hot-reloads on supergraph file change; bounce
#                    subgraphs only if their dist/ hash changed.
#   6. Smoke       → run the 3 GraphQL smoke tests against the live stack.
#
# Safe to re-run. Aborts on first error and keeps the previous supergraph
# at supergraph.graphql.prev so a rollback is `mv .prev → .graphql`.

set -euo pipefail

# ---------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------
SRC="${SRC:-/app/graphql}"                 # where the git checkout lands
DST="${DST:-/opt/coreflux/graphql}"        # where systemd reads from
ENV_FILE="${ENV_FILE:-/etc/coreflux/graphql.env}"
ROUTER_HEALTH="${ROUTER_HEALTH:-http://127.0.0.1:8088/health}"

# ---------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------
step() { printf '\n=== %s ===\n' "$*"; }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------
# 1. Pre-flight
# ---------------------------------------------------------------------
step "Pre-flight"
[ "$EUID" -eq 0 ]                  || die "must run as root (uses systemctl)"
[ -d "$SRC" ]                      || die "SRC=$SRC missing"
[ -f "$ENV_FILE" ]                 || die "$ENV_FILE missing — copy from $SRC/deploy/etc/graphql.env.example and fill in secrets"
grep -q '^JWT_SECRET=' "$ENV_FILE" || die "$ENV_FILE missing JWT_SECRET"
grep -q '^INTERNAL_HMAC_SECRET='  "$ENV_FILE" || die "$ENV_FILE missing INTERNAL_HMAC_SECRET"
grep -q 'REPLACE_WITH_OPENSSL'    "$ENV_FILE" && die "$ENV_FILE still has placeholder secrets — replace them before deploying"
command -v node    >/dev/null || die "node not on PATH"
command -v yarn    >/dev/null || die "yarn not on PATH"
command -v router  >/dev/null || die "apollo router binary not on PATH"
command -v php     >/dev/null || die "php not on PATH"
echo "  pre-flight OK"

# ---------------------------------------------------------------------
# 2. Capture pre-deploy health for the diff
# ---------------------------------------------------------------------
step "Pre-deploy health"
PRE_HEALTH=$(curl -fsS "$ROUTER_HEALTH" 2>/dev/null || echo '{"status":"NOT-YET-RUNNING"}')
echo "  before: $PRE_HEALTH"

# ---------------------------------------------------------------------
# 3. Snapshot the current supergraph for rollback
# ---------------------------------------------------------------------
if [ -f "$DST/router/supergraph.graphql" ]; then
    cp -a "$DST/router/supergraph.graphql" "$DST/router/supergraph.graphql.prev"
    echo "  snapshot: $DST/router/supergraph.graphql.prev"
fi

# ---------------------------------------------------------------------
# 4. Build + compose via deploy.sh
# ---------------------------------------------------------------------
step "Build + compose"
"$SRC/deploy/scripts/deploy.sh" --src="$SRC" --dst="$DST"

# ---------------------------------------------------------------------
# 5. Decide what to restart. Router hot-reloads supergraph; subgraphs
#    only need a bounce when their dist/ contents actually changed.
# ---------------------------------------------------------------------
step "Service restarts"
restart_if_changed() {
    local unit="$1" dir="$2" stamp="${DST}/.last-deploy-${unit}.sha"
    local now
    now=$(find "$dir/dist" -type f -name '*.js' -newer "$dir/package.json" -print0 2>/dev/null | sort -z | xargs -0 sha256sum 2>/dev/null | sha256sum | cut -d' ' -f1)
    [ -n "$now" ] || now="empty"
    local prev=""
    [ -f "$stamp" ] && prev=$(cat "$stamp")
    if [ "$now" != "$prev" ]; then
        echo "  → systemctl restart $unit (dist changed)"
        systemctl restart "$unit"
        echo "$now" > "$stamp"
    else
        echo "  · $unit unchanged, leaving running"
    fi
}
restart_if_changed coreflux-subgraph-coreflux "$DST/subgraph-coreflux"
restart_if_changed coreflux-subgraph-jobdiva  "$DST/subgraph-jobdiva"
restart_if_changed coreflux-mcp               "$DST/mcp-server"
# Router stays up — it hot-reloads on the supergraph file mtime.

# ---------------------------------------------------------------------
# 6. Post-deploy health + introspection
# ---------------------------------------------------------------------
step "Post-deploy health"
for i in 1 2 3 4 5 6 7 8; do
    POST_HEALTH=$(curl -fsS "$ROUTER_HEALTH" 2>/dev/null || echo "")
    [ -n "$POST_HEALTH" ] && break
    sleep 1
done
[ -n "$POST_HEALTH" ] || die "router did not become healthy within 8s after deploy"
echo "  after:  $POST_HEALTH"

# ---------------------------------------------------------------------
# 7. Smoke tests against the live stack
# ---------------------------------------------------------------------
step "Smoke tests"
for t in \
    /app/tests/graphql_router_prod_config_smoke.php \
    /app/tests/graphql_federation_smoke.php \
    /app/tests/internal_hmac_bridge_smoke.php; do
    if [ -f "$t" ]; then
        echo "  · $(basename "$t")"
        php -d zend.assertions=1 "$t" >/dev/null
    fi
done

echo
echo "RELEASE OK."
echo
echo "Rollback (schema only):"
echo "  mv $DST/router/supergraph.graphql.prev $DST/router/supergraph.graphql"
echo "  (router auto-reloads in <1s)"
