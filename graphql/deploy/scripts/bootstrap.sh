#!/usr/bin/env bash
#
# CoreFlux GraphQL — ONE-TIME bootstrap on a fresh Cloudways box.
#
# Run as root, ONCE per server (idempotent if re-run). After this is
# done, ALL subsequent deploys can go through the dashboard
# (/api/admin/router_deploy.php) — no SSH needed.
#
# Usage:
#   sudo bash /app/graphql/deploy/scripts/bootstrap.sh
#
# Flags:
#   --dry-run     Print what would happen, change nothing.
#   --skip-nginx  Don't touch nginx (for boxes where another team owns it).
#
# What it does (idempotent — re-running is safe):
#   1. Verifies prerequisites and installs missing system packages.
#   2. Installs Node 20 if absent.
#   3. Installs Apollo Router binary if absent.
#   4. Creates the `coreflux` service user if absent.
#   5. Creates /opt/coreflux/graphql and /etc/coreflux.
#   6. Seeds /etc/coreflux/graphql.env with auto-generated secrets
#      (only if the file doesn't exist — never overwrites).
#   7. Installs the four systemd unit files.
#   8. Adds the nginx include line (idempotent grep-then-append).
#   9. Reloads systemd; does NOT yet start services. The first release
#      via /api/admin/router_deploy.php will rsync the code and start.

set -euo pipefail

DRY_RUN=0
SKIP_NGINX=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)    DRY_RUN=1 ;;
        --skip-nginx) SKIP_NGINX=1 ;;
        -h|--help)    sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)            echo "unknown arg: $arg" >&2; exit 2 ;;
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

[ "$EUID" -eq 0 ] || { echo "must run as root"; exit 1; }

# ---------------------------------------------------------------------
# 1. Node 20
# ---------------------------------------------------------------------
step "Node 20"
if ! command -v node >/dev/null || [ "$(node --version | cut -d. -f1 | tr -d v)" -lt 20 ]; then
    run "curl -fsSL https://deb.nodesource.com/setup_20.x | bash -"
    run "apt-get install -y nodejs"
else
    echo "  · already installed ($(node --version))"
fi
command -v yarn >/dev/null || run "npm install -g yarn"

# ---------------------------------------------------------------------
# 2. Apollo Router binary (latest stable from Apollo's official installer)
# ---------------------------------------------------------------------
step "Apollo Router"
if ! command -v router >/dev/null; then
    # Apollo's official installer pulls the latest stable release.
    run "curl -sSL https://router.apollo.dev/download/nix/latest | sh"
    run "mv router /usr/local/bin/router"
    run "chmod +x /usr/local/bin/router"
else
    echo "  · already installed ($(router --version))"
fi

# ---------------------------------------------------------------------
# 3. coreflux service user
# ---------------------------------------------------------------------
step "Service user"
if ! id coreflux >/dev/null 2>&1; then
    run "useradd --system --shell /usr/sbin/nologin --create-home --home /opt/coreflux coreflux"
else
    echo "  · coreflux user exists"
fi

# ---------------------------------------------------------------------
# 4. Filesystem layout
# ---------------------------------------------------------------------
step "Filesystem layout"
run "install -d -o coreflux -g coreflux /opt/coreflux/graphql"
run "install -d -o root     -g coreflux -m 0750 /etc/coreflux"

# ---------------------------------------------------------------------
# 5. Secrets file
# ---------------------------------------------------------------------
step "Secrets"
ENV_FILE=/etc/coreflux/graphql.env
if [ -f "$ENV_FILE" ]; then
    echo "  · $ENV_FILE exists — leaving untouched"
else
    if [ "$DRY_RUN" -eq 0 ]; then
        HMAC=$(openssl rand -hex 32)
        # JWT secret: try to copy from PHP config so the dashboard and
        # router share the same key. Falls back to a generated secret
        # if the operator hasn't set one in config — they MUST replace
        # it manually before any dashboard login works.
        PHP_JWT=$(grep -hoE "JWT_SECRET=[^[:space:]\"']+" /app/.env /app/config/*.env 2>/dev/null | head -1 | cut -d= -f2- || echo "")
        if [ -z "$PHP_JWT" ]; then
            PHP_JWT="REPLACE_WITH_VALUE_FROM_PHP_JWT_SECRET"
            echo "  ⚠ could not auto-detect PHP JWT_SECRET — placeholder written"
        fi
        umask 027
        cat > "$ENV_FILE" <<EOF
JWT_SECRET=$PHP_JWT
INTERNAL_HMAC_SECRET=$HMAC
COREFLUX_API_BASE=http://127.0.0.1:8080
SUBGRAPH_COREFLUX_URL=http://127.0.0.1:4001/
SUBGRAPH_JOBDIVA_URL=http://127.0.0.1:4002/
ROUTER_LISTEN=127.0.0.1:4000
PORT=4001
EOF
        chown root:coreflux "$ENV_FILE"
        chmod 0640 "$ENV_FILE"
        echo "  + wrote $ENV_FILE (review COREFLUX_API_BASE and JWT_SECRET)"
    else
        echo "  [dry-run] would write $ENV_FILE with random INTERNAL_HMAC_SECRET"
    fi
fi

# ---------------------------------------------------------------------
# 6. systemd units
# ---------------------------------------------------------------------
step "systemd units"
SRC_UNITS=/app/graphql/deploy/systemd
for unit in coreflux-subgraph-coreflux coreflux-subgraph-jobdiva coreflux-router coreflux-mcp; do
    src="$SRC_UNITS/$unit.service"
    [ -f "$src" ] || { echo "  ! $src missing"; exit 1; }
    dst="/etc/systemd/system/$unit.service"
    if [ -f "$dst" ] && cmp -s "$src" "$dst"; then
        echo "  · $unit unchanged"
    else
        run "install -m 0644 -o root -g root $src $dst"
    fi
done
run "systemctl daemon-reload"

# ---------------------------------------------------------------------
# 7. nginx
# ---------------------------------------------------------------------
if [ "$SKIP_NGINX" -eq 0 ]; then
    step "nginx"
    SNIPPET=/app/graphql/deploy/nginx/coreflux-graphql.conf
    CONF=/etc/nginx/sites-available/corefluxapp.com
    if [ ! -f "$CONF" ]; then
        echo "  ⚠ $CONF not found — skipping nginx wiring (set --skip-nginx to silence)"
    elif grep -q "coreflux-graphql.conf" "$CONF"; then
        echo "  · include already present in $CONF"
    else
        if [ "$DRY_RUN" -eq 0 ]; then
            sed -i.coreflux-bak "/server {/a \\    include $SNIPPET;" "$CONF"
            nginx -t 2>&1 | tail -3
            systemctl reload nginx
            echo "  + nginx reloaded with new include"
        else
            echo "  [dry-run] would add 'include $SNIPPET;' inside server { }"
        fi
    fi
fi

# ---------------------------------------------------------------------
# 8. Stamp the box so /api/admin/router_deploy.php knows we're done.
# ---------------------------------------------------------------------
step "Stamp"
run "install -d -o coreflux -g coreflux /opt/coreflux"
if [ "$DRY_RUN" -eq 0 ]; then
    date -u +%Y-%m-%dT%H:%M:%SZ > /opt/coreflux/.bootstrap-complete
    chown coreflux:coreflux /opt/coreflux/.bootstrap-complete
fi

echo
echo "BOOTSTRAP OK."
echo
echo "Next step: open the dashboard → Settings → Integrations → 'Deploy Apollo Router'"
echo "to roll out the code. The deploy can be re-run any time from there."
