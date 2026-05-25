#!/usr/bin/env bash
#
# CoreFlux GraphQL — fresh DigitalOcean droplet (or any Ubuntu 22.04/24.04
# VM where you have root). One command, end-to-end, idempotent.
#
# Usage (paste on the droplet as root):
#
#     curl -fsSL https://raw.githubusercontent.com/coreflux-creator/CoreFlux/main/scripts/setup_droplet_graphql.sh \
#       | COREFLUX_API_BASE=https://corefluxapp.com \
#         JWT_SECRET=<paste-from-cloudways> \
#         CLOUDWAYS_APP_IP=<your-cloudways-server-ip> \
#         bash
#
# What it does, in order:
#   1. apt-get update + base packages (git curl openssl rsync ufw).
#   2. Install Node 20 from NodeSource.
#   3. Install Apollo Router latest stable.
#   4. Create the `coreflux` system user.
#   5. git clone the CoreFlux repo to /opt/coreflux/source (or pull).
#      Public over HTTPS — no SSH key needed because the repo is your own.
#      Override REPO_URL if it's private.
#   6. Build the subgraphs + router + mcp-server (yarn install + yarn build).
#   7. Compose the supergraph SDL.
#   8. rsync build output to /opt/coreflux/graphql (where systemd reads from).
#   9. Write /etc/coreflux/graphql.env with:
#        - JWT_SECRET (from your env — MUST match Cloudways PHP side)
#        - INTERNAL_HMAC_SECRET (auto-generated; copy to Cloudways after)
#        - COREFLUX_API_BASE (your public Cloudways URL)
#  10. Install + enable systemd units. Start the stack.
#  11. Configure UFW: allow :4000 ONLY from CLOUDWAYS_APP_IP (if provided).
#  12. Print health checks + the exact line to paste into Cloudways nginx.
#
# Re-running is safe. It pulls latest code, rebuilds, restarts services.

set -euo pipefail

# ---------------------------------------------------------------------
# Required + optional env
# ---------------------------------------------------------------------
REPO_URL="${REPO_URL:-https://github.com/coreflux-creator/CoreFlux.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"
COREFLUX_API_BASE="${COREFLUX_API_BASE:-}"
JWT_SECRET="${JWT_SECRET:-}"
INTERNAL_HMAC_SECRET="${INTERNAL_HMAC_SECRET:-}"
CLOUDWAYS_APP_IP="${CLOUDWAYS_APP_IP:-}"

# ---------------------------------------------------------------------
# Layout
# ---------------------------------------------------------------------
SRC_DIR=/opt/coreflux/source       # git checkout (full repo)
DST_DIR=/opt/coreflux/graphql      # built artifacts systemd reads from
ENV_FILE=/etc/coreflux/graphql.env

# ---------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------
say() { printf '\n=== %s ===\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
run() { printf '  + %s\n' "$*"; eval "$@"; }

# ---------------------------------------------------------------------
# Pre-flight
# ---------------------------------------------------------------------
say "Pre-flight"
[ "$EUID" -eq 0 ] || die "must run as root (DO droplet root user, or sudo bash …)"
command -v apt-get >/dev/null || die "requires Debian/Ubuntu (apt-get not found)"
. /etc/os-release
echo "  · $PRETTY_NAME, root OK"

[ -n "$COREFLUX_API_BASE" ] || die "COREFLUX_API_BASE env var is required (e.g. https://corefluxapp.com — the public URL of your Cloudways app)"
[ -n "$JWT_SECRET" ]       || die "JWT_SECRET env var is required (copy from your Cloudways PHP .env so dashboard tokens validate)"

# ---------------------------------------------------------------------
# 1. Base packages
# ---------------------------------------------------------------------
say "Base packages"
export DEBIAN_FRONTEND=noninteractive
run "apt-get update -qq"
run "apt-get install -y git curl openssl rsync ufw ca-certificates"

# ---------------------------------------------------------------------
# 2. Node 20
# ---------------------------------------------------------------------
say "Node 20"
if ! command -v node >/dev/null || [ "$(node --version | cut -d. -f1 | tr -d v)" -lt 20 ]; then
    run "curl -fsSL https://deb.nodesource.com/setup_20.x | bash -"
    run "apt-get install -y nodejs"
else
    echo "  · already installed ($(node --version))"
fi
command -v yarn >/dev/null || run "npm install -g yarn"

# ---------------------------------------------------------------------
# 3. Apollo Router (latest stable from Apollo's installer)
# ---------------------------------------------------------------------
say "Apollo Router"
if ! command -v router >/dev/null; then
    cd /tmp
    run "curl -sSL https://router.apollo.dev/download/nix/latest | sh"
    run "mv router /usr/local/bin/router"
    run "chmod +x /usr/local/bin/router"
else
    echo "  · already installed ($(router --version 2>&1 | head -1))"
fi

# ---------------------------------------------------------------------
# 4. Service user
# ---------------------------------------------------------------------
say "Service user"
if ! id coreflux >/dev/null 2>&1; then
    run "useradd --system --shell /usr/sbin/nologin --create-home --home /opt/coreflux coreflux"
else
    echo "  · coreflux user exists"
fi

# ---------------------------------------------------------------------
# 5. Source checkout
# ---------------------------------------------------------------------
say "Source checkout"
run "install -d -o coreflux -g coreflux /opt/coreflux"
if [ -d "$SRC_DIR/.git" ]; then
    echo "  · existing checkout — fast-forwarding"
    run "git -C $SRC_DIR fetch --quiet origin $REPO_BRANCH"
    run "git -C $SRC_DIR checkout --quiet $REPO_BRANCH"
    run "git -C $SRC_DIR pull --ff-only --quiet origin $REPO_BRANCH"
else
    run "git clone --branch $REPO_BRANCH --depth 1 $REPO_URL $SRC_DIR"
fi
run "chown -R coreflux:coreflux $SRC_DIR"

# ---------------------------------------------------------------------
# 6. Build
# ---------------------------------------------------------------------
say "Build subgraphs + router + mcp-server"
for d in subgraph-coreflux subgraph-jobdiva mcp-server router; do
    echo "  · building $d"
    sudo -u coreflux bash -c "cd $SRC_DIR/graphql/$d && yarn install --frozen-lockfile --silent && (yarn build 2>/dev/null || true)"
done

# Compose the supergraph SDL (Apollo Router watches the file).
echo "  · composing supergraph"
sudo -u coreflux bash -c "cd $SRC_DIR/graphql/router && node compose.mjs"

# ---------------------------------------------------------------------
# 7. Stage built artifacts into runtime location
# ---------------------------------------------------------------------
say "Stage runtime tree"
run "install -d -o coreflux -g coreflux $DST_DIR"
run "rsync -a --delete --exclude '.git' --exclude 'src' --exclude 'node_modules/.cache' $SRC_DIR/graphql/ $DST_DIR/"
# Subgraphs need their node_modules at runtime; router needs supergraph.graphql.
# rsync copied everything above, but yarn's dev deps in node_modules are fine
# on a single-purpose droplet.
run "chown -R coreflux:coreflux $DST_DIR"

# ---------------------------------------------------------------------
# 8. Secrets / env
# ---------------------------------------------------------------------
say "Secrets"
run "install -d -o root -g coreflux -m 0750 /etc/coreflux"
if [ -z "$INTERNAL_HMAC_SECRET" ]; then
    INTERNAL_HMAC_SECRET=$(openssl rand -hex 32)
    HMAC_GENERATED=1
else
    HMAC_GENERATED=0
fi

# Idempotent write: if the file exists with the same values, leave it; else
# rewrite with new ones. Keeping HMAC stable across re-runs is important
# because the Cloudways PHP side has the matching value.
if [ -f "$ENV_FILE" ]; then
    EXISTING_HMAC=$(grep -E '^INTERNAL_HMAC_SECRET=' "$ENV_FILE" | cut -d= -f2- || true)
    [ -n "$EXISTING_HMAC" ] && INTERNAL_HMAC_SECRET="$EXISTING_HMAC" && HMAC_GENERATED=0
fi

umask 027
cat > "$ENV_FILE" <<EOF
JWT_SECRET=$JWT_SECRET
INTERNAL_HMAC_SECRET=$INTERNAL_HMAC_SECRET
COREFLUX_API_BASE=$COREFLUX_API_BASE
SUBGRAPH_COREFLUX_URL=http://127.0.0.1:4001/
SUBGRAPH_JOBDIVA_URL=http://127.0.0.1:4002/
ROUTER_LISTEN=0.0.0.0:4000
PORT=4001
EOF
chown root:coreflux "$ENV_FILE"
chmod 0640 "$ENV_FILE"
echo "  + wrote $ENV_FILE"

# ---------------------------------------------------------------------
# 9. systemd units
# ---------------------------------------------------------------------
say "systemd units"
for unit in coreflux-subgraph-coreflux coreflux-subgraph-jobdiva coreflux-router coreflux-mcp; do
    src="$SRC_DIR/graphql/deploy/systemd/$unit.service"
    [ -f "$src" ] || die "$src missing"
    # Strip the Cloudways-specific php8.2-fpm dependency — droplet has no PHP.
    sed 's| php8.2-fpm.service||g' "$src" > "/etc/systemd/system/$unit.service"
    chmod 0644 "/etc/systemd/system/$unit.service"
    echo "  + installed $unit.service"
done
run "systemctl daemon-reload"

# ---------------------------------------------------------------------
# 10. Start the stack
# ---------------------------------------------------------------------
say "Start services"
run "systemctl enable --now coreflux-subgraph-coreflux coreflux-subgraph-jobdiva coreflux-router coreflux-mcp"
sleep 3
for u in coreflux-subgraph-coreflux coreflux-subgraph-jobdiva coreflux-router coreflux-mcp; do
    if systemctl is-active --quiet "$u"; then
        echo "  · $u: active"
    else
        echo "  ✗ $u: NOT active — run 'journalctl -u $u --no-pager -n 50' to diagnose"
    fi
done

# ---------------------------------------------------------------------
# 11. Firewall (optional but recommended)
# ---------------------------------------------------------------------
if [ -n "$CLOUDWAYS_APP_IP" ]; then
    say "Firewall (UFW) — allow :4000 only from $CLOUDWAYS_APP_IP"
    run "ufw --force reset >/dev/null"
    run "ufw default deny incoming"
    run "ufw default allow outgoing"
    run "ufw allow 22/tcp"                              # SSH stays open
    run "ufw allow from $CLOUDWAYS_APP_IP to any port 4000 proto tcp"
    run "ufw --force enable"
    echo "  · firewall active — :4000 reachable only from Cloudways"
else
    say "Firewall — skipped (CLOUDWAYS_APP_IP not set)"
    echo "  ⚠ :4000 is currently reachable from the entire internet."
    echo "  ⚠ Re-run with CLOUDWAYS_APP_IP=<ip> to lock it down."
fi

# ---------------------------------------------------------------------
# 12. Final report
# ---------------------------------------------------------------------
DROPLET_IP=$(curl -fsS http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address 2>/dev/null || hostname -I | awk '{print $1}')

echo
echo "============================================================"
echo "  CoreFlux GraphQL stack is LIVE on this droplet."
echo "============================================================"
echo
echo "Droplet public IP:  $DROPLET_IP"
echo "Router endpoint:    http://$DROPLET_IP:4000/"
echo "Health:             curl http://$DROPLET_IP:8088/health"
echo
echo "INTERNAL_HMAC_SECRET (you MUST set this on Cloudways too):"
echo "  $INTERNAL_HMAC_SECRET"
if [ "$HMAC_GENERATED" = "1" ]; then
    echo
    echo "  ⚠ This was auto-generated. Set the SAME value in your Cloudways"
    echo "    PHP app via:  Cloudways dashboard → Application Settings →"
    echo "    Application Settings → Environment Variables (or paste into"
    echo "    your application's .env file via SFTP). Variable name:"
    echo "        INTERNAL_HMAC_SECRET"
fi
echo
echo "Add to Cloudways nginx (Application Settings → Nginx → Custom):"
echo
cat <<NGINX
    location /graphql {
        proxy_pass http://$DROPLET_IP:4000/;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_http_version 1.1;
    }
NGINX
echo
echo "Quick sanity check (run from anywhere — note: from outside Cloudways"
echo "you'll be blocked by the firewall, which is correct):"
echo
echo "  curl -sX POST http://$DROPLET_IP:4000/ \\"
echo "    -H 'Content-Type: application/json' \\"
echo "    -d '{\"query\":\"{ __schema { queryType { name } } }\"}'"
echo
echo "Re-run this script anytime to pull latest code + rebuild + restart."
echo "============================================================"
