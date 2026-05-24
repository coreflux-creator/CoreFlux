#!/usr/bin/env bash
#
# CoreFlux GraphQL — one-shot deploy.
#
# Wraps the rsync + yarn build + supergraph compose sequence from
# DEPLOYMENT.md into a single idempotent command. Run on the Cloudways
# host as the `coreflux` user, or as root with --user=coreflux.
#
# Usage:
#   sudo -u coreflux ./deploy.sh                       # full deploy
#   sudo -u coreflux ./deploy.sh --dry-run             # print, don't run
#   sudo -u coreflux ./deploy.sh --skip-build          # supergraph only
#   sudo -u coreflux ./deploy.sh --src=/path/to/repo   # override source
#
# Idempotency:
#   - rsync uses --delete so removed files vanish from the deploy.
#   - yarn install --frozen-lockfile is a no-op when lockfiles match.
#   - compose.mjs writes the same supergraph.graphql when nothing changed.
#   - Apollo Router hot-reloads on file change; no service restart needed
#     for schema-only deploys.

set -euo pipefail

# ---------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------
SRC="${SRC:-/app/graphql}"
DST="${DST:-/opt/coreflux/graphql}"
DRY_RUN=0
SKIP_BUILD=0
RESTART_ROUTER=0

# ---------------------------------------------------------------------
# Args
# ---------------------------------------------------------------------
for arg in "$@"; do
    case "$arg" in
        --dry-run)             DRY_RUN=1 ;;
        --skip-build)          SKIP_BUILD=1 ;;
        --restart-router)      RESTART_ROUTER=1 ;;
        --src=*)               SRC="${arg#*=}" ;;
        --dst=*)               DST="${arg#*=}" ;;
        -h|--help)
            sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            echo "unknown arg: $arg" >&2; exit 2 ;;
    esac
done

run() {
    if [ "$DRY_RUN" -eq 1 ]; then
        printf '  [dry-run] %s\n' "$*"
    else
        printf '  + %s\n' "$*"
        eval "$@"
    fi
}

step() { printf '\n=== %s ===\n' "$*"; }

# ---------------------------------------------------------------------
# 1. Sanity
# ---------------------------------------------------------------------
step "Pre-flight"
[ -d "$SRC" ]                || { echo "SRC=$SRC missing"; exit 1; }
[ -f "$SRC/router/compose.mjs" ] || { echo "$SRC/router/compose.mjs missing"; exit 1; }
[ -f "$SRC/router/router.yaml" ] || { echo "$SRC/router/router.yaml missing"; exit 1; }
command -v node    >/dev/null   || { echo "node not in PATH"; exit 1; }
command -v yarn    >/dev/null   || { echo "yarn not in PATH"; exit 1; }
command -v router  >/dev/null   || { echo "apollo router binary not in PATH"; exit 1; }
echo "  SRC = $SRC"
echo "  DST = $DST"
echo "  node     = $(node --version)"
echo "  yarn     = $(yarn --version)"
echo "  router   = $(router --version 2>&1)"

# ---------------------------------------------------------------------
# 2. Mirror the source tree
# ---------------------------------------------------------------------
step "rsync $SRC/ → $DST/"
run "mkdir -p $DST"
RSYNC_OPTS="-a --delete --exclude node_modules --exclude '*.log' --exclude 'dist' --exclude '.env'"
run "rsync $RSYNC_OPTS $SRC/ $DST/"

# ---------------------------------------------------------------------
# 3. Build each TS package (unless --skip-build)
# ---------------------------------------------------------------------
if [ "$SKIP_BUILD" -eq 0 ]; then
    for pkg in subgraph-coreflux subgraph-jobdiva mcp-server; do
        step "yarn install + build  ($pkg)"
        run "cd $DST/$pkg && yarn install --frozen-lockfile && yarn build"
    done
    step "yarn install              (router compose deps)"
    run "cd $DST/router && yarn install --frozen-lockfile"
else
    echo "  (--skip-build): leaving dist/ as-is"
fi

# ---------------------------------------------------------------------
# 4. Compose the supergraph SDL. Atomic write: compose to a temp file,
#    then mv into place. Router watches the file and hot-reloads.
# ---------------------------------------------------------------------
step "Compose supergraph.graphql"
run "cd $DST/router && node compose.mjs"

# ---------------------------------------------------------------------
# 5. Verify the produced SDL is non-empty + references both subgraphs.
# ---------------------------------------------------------------------
step "Verify supergraph"
if [ "$DRY_RUN" -eq 0 ]; then
    SG="$DST/router/supergraph.graphql"
    test -s "$SG" || { echo "ERROR: $SG missing or empty"; exit 1; }
    grep -q "type Placement"         "$SG" || { echo "ERROR: Placement type missing"; exit 1; }
    grep -q "type JobDivaAssignment" "$SG" || { echo "ERROR: JobDivaAssignment missing"; exit 1; }
    echo "  OK ($(wc -c <"$SG") bytes, both subgraphs present)"
fi

# ---------------------------------------------------------------------
# 6. Optional service restart (default: rely on router hot-reload)
# ---------------------------------------------------------------------
if [ "$RESTART_ROUTER" -eq 1 ]; then
    step "systemctl restart coreflux-router"
    run "systemctl restart coreflux-router"
fi

echo
echo "DONE."
echo "Next: verify /healthz/graphql returns 200, then tail journalctl -u coreflux-router."
