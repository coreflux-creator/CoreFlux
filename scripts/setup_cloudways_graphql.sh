#!/usr/bin/env bash
#
# CoreFlux GraphQL — one-command Cloudways setup.
#
# This is the SINGLE script you copy to a fresh Cloudways box and run as
# root. It chains the previously-manual six SSH steps (Node 20 → Apollo
# Router → service user → /etc/coreflux secrets → systemd units →
# nginx include) into one idempotent invocation, and it also pulls the
# CoreFlux git repo down so /app/graphql/* is on disk before the
# bootstrap wires systemd/nginx.
#
# Typical usage (fresh server):
#
#     # SSH into the Cloudways application server, then:
#     curl -fsSL https://raw.githubusercontent.com/<org>/coreflux/main/scripts/setup_cloudways_graphql.sh \
#       | sudo REPO_URL=https://github.com/<org>/coreflux.git bash
#
#   or, if you already SCP'd this file:
#
#     sudo REPO_URL=https://github.com/<org>/coreflux.git \
#          bash /tmp/setup_cloudways_graphql.sh
#
# Flags / env:
#   REPO_URL=<https url or git@…>   (required unless /app/.git already exists)
#   REPO_BRANCH=<branch>            (default: main)
#   REPO_PATH=<dir>                 (default: /app — must be writable)
#   --dry-run                       Print what would happen, change nothing.
#   --skip-nginx                    Don't touch nginx (for Cloudways apps where
#                                   nginx is managed by another script).
#   --skip-git                      Don't clone/pull (use existing checkout).
#
# Re-runnable. Safe to invoke after any update: it will `git pull` the
# latest code and re-apply the bootstrap (which is itself idempotent).

set -euo pipefail

# ---------------------------------------------------------------------
# Arg parsing
# ---------------------------------------------------------------------
DRY_RUN=0
SKIP_NGINX=0
SKIP_GIT=0
for arg in "$@"; do
    case "$arg" in
        --dry-run)    DRY_RUN=1 ;;
        --skip-nginx) SKIP_NGINX=1 ;;
        --skip-git)   SKIP_GIT=1 ;;
        -h|--help)    sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)            echo "unknown arg: $arg" >&2; exit 2 ;;
    esac
done

REPO_URL="${REPO_URL:-}"
REPO_BRANCH="${REPO_BRANCH:-main}"
REPO_PATH="${REPO_PATH:-/app}"

# ---------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------
say()  { printf '\n=== %s ===\n' "$*"; }
run()  { if [ "$DRY_RUN" -eq 1 ]; then printf '  [dry-run] %s\n' "$*"; else printf '  + %s\n' "$*"; eval "$@"; fi; }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------
# Pre-flight: root + supported OS
# ---------------------------------------------------------------------
say "Pre-flight"
[ "$EUID" -eq 0 ] || die "must run as root (e.g. via sudo)"
command -v apt-get >/dev/null || die "this script targets Debian/Ubuntu (Cloudways default) — apt-get not found"
echo "  · running as root on $(. /etc/os-release && echo "$PRETTY_NAME")"

# ---------------------------------------------------------------------
# 0. Base packages (git, curl, openssl) — bootstrap.sh assumes they exist
# ---------------------------------------------------------------------
say "Base packages"
missing=()
for bin in git curl openssl rsync; do
    command -v "$bin" >/dev/null || missing+=("$bin")
done
if [ "${#missing[@]}" -gt 0 ]; then
    run "apt-get update -qq"
    run "apt-get install -y ${missing[*]}"
else
    echo "  · git, curl, openssl, rsync already installed"
fi

# ---------------------------------------------------------------------
# 1. Pull CoreFlux source into $REPO_PATH (default /app)
#    bootstrap.sh / release.sh expect the checkout to live at /app.
# ---------------------------------------------------------------------
if [ "$SKIP_GIT" -eq 1 ]; then
    say "Source checkout (--skip-git)"
    [ -d "$REPO_PATH/graphql" ] || die "--skip-git set but $REPO_PATH/graphql is missing"
    echo "  · using existing checkout at $REPO_PATH"
else
    say "Source checkout"
    if [ -d "$REPO_PATH/.git" ]; then
        echo "  · existing checkout at $REPO_PATH — fast-forwarding"
        run "git -C $REPO_PATH fetch --quiet origin $REPO_BRANCH"
        run "git -C $REPO_PATH checkout --quiet $REPO_BRANCH"
        run "git -C $REPO_PATH pull --ff-only --quiet origin $REPO_BRANCH"
    else
        [ -n "$REPO_URL" ] || die "REPO_URL env var is required for first-time clone (e.g. REPO_URL=https://github.com/<org>/coreflux.git)"
        # If $REPO_PATH already contains files (e.g. /app on Cloudways), refuse
        # rather than blowing them away.
        if [ -d "$REPO_PATH" ] && [ -n "$(ls -A "$REPO_PATH" 2>/dev/null || true)" ]; then
            die "$REPO_PATH already exists and is non-empty but isn't a git checkout — refusing to overwrite. Move it aside or set REPO_PATH to a different dir."
        fi
        run "install -d $REPO_PATH"
        run "git clone --branch $REPO_BRANCH --depth 1 $REPO_URL $REPO_PATH"
    fi
    [ -f "$REPO_PATH/graphql/deploy/scripts/bootstrap.sh" ] \
        || die "expected $REPO_PATH/graphql/deploy/scripts/bootstrap.sh after checkout — repo layout looks wrong"
fi

# ---------------------------------------------------------------------
# 2. Hand off to the existing idempotent bootstrap.
#    bootstrap.sh handles: Node 20, Apollo Router (latest stable),
#    coreflux user, /etc/coreflux/graphql.env (with auto-generated
#    INTERNAL_HMAC_SECRET), systemd units, nginx include, and the
#    /opt/coreflux/.bootstrap-complete stamp that the dashboard's
#    "Deploy Apollo Router" button checks for.
# ---------------------------------------------------------------------
say "Bootstrap (Node 20 / Apollo Router / systemd / nginx)"
BOOTSTRAP_ARGS=()
[ "$DRY_RUN"    -eq 1 ] && BOOTSTRAP_ARGS+=("--dry-run")
[ "$SKIP_NGINX" -eq 1 ] && BOOTSTRAP_ARGS+=("--skip-nginx")
run "bash $REPO_PATH/graphql/deploy/scripts/bootstrap.sh ${BOOTSTRAP_ARGS[*]:-}"

# ---------------------------------------------------------------------
# 3. Final summary + next-step prompts.
# ---------------------------------------------------------------------
say "Setup complete"
cat <<EOF

CoreFlux GraphQL layer is provisioned on this host.

Next steps (no SSH needed after this):

  1. Open the CoreFlux dashboard (corefluxapp.com) and sign in as an
     admin with the 'integrations.field_map.manage' permission.

  2. Settings → Integrations → "Deploy Apollo Router" — this calls
     /api/admin/router_deploy.php which runs release.sh server-side
     and rolls out the latest /app/graphql/* code to /opt/coreflux/graphql.

  3. Verify the live router responds:
       curl -fsS http://127.0.0.1:8088/health
       curl -sX POST http://127.0.0.1:4000/ \\
         -H 'Content-Type: application/json' \\
         -d '{"query":"{ __schema { queryType { name } } }"}'

If you ever want to re-run this setup script (e.g. to upgrade the
Apollo Router binary or pull new systemd unit changes), just run it
again — every step is idempotent.
EOF
