#!/usr/bin/env bash
#
# scripts/sync_bundle.sh — single source of truth for "I rebuilt the Vite
# bundle, propagate the new hash everywhere".
#
# Wired as a `postbuild` hook in dashboard/package.json so `yarn build`
# automatically runs this. Can also be invoked directly:
#
#   bash scripts/sync_bundle.sh
#
# What it does (in order):
#   1. Reads the freshly-built dashboard/dist/index.html to discover the
#      new index-XXXXXXXX.js + index-XXXXXXXX.css hashes Vite just emitted.
#   2. Mirrors dashboard/dist/spa-assets/* into the top-level spa-assets/
#      directory so spa.php (which lives at /app/spa.php and serves the
#      SPA shell) can find them at /spa-assets/...
#   3. Patches the `expected_bundle:` block in /app/.deploy-version so the
#      sprint6b_dashboard_uis_smoke check sees the new hashes.
#
# All three are idempotent. Run it twice in a row and the second pass
# is a no-op. Errors abort with a non-zero exit code so CI fails fast.
#
# This script kills an entire class of "I forgot to update X" CI failures
# that have been recurring every few sessions.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DIST_INDEX="dashboard/dist/index.html"
DEPLOY_VER=".deploy-version"
DIST_ASSETS="dashboard/dist/spa-assets"
TOP_ASSETS="spa-assets"

if [ ! -f "$DIST_INDEX" ]; then
    echo "ERROR: $DIST_INDEX not found. Run 'yarn --cwd dashboard build' first." >&2
    exit 1
fi

# ── 1. Discover new hashes from the freshly-built index.html ─────────────
NEW_JS="$(grep -oE 'index-[A-Za-z0-9_-]+\.js' "$DIST_INDEX" | head -1 || true)"
NEW_CSS="$(grep -oE 'index-[A-Za-z0-9_-]+\.css' "$DIST_INDEX" | head -1 || true)"

if [ -z "$NEW_JS" ] || [ -z "$NEW_CSS" ]; then
    echo "ERROR: could not find index-*.js / index-*.css references in $DIST_INDEX" >&2
    exit 1
fi

echo "▶ Detected new bundle:"
echo "    JS : $NEW_JS"
echo "    CSS: $NEW_CSS"

# ── 2. Mirror dist/spa-assets → top-level spa-assets ─────────────────────
mkdir -p "$TOP_ASSETS"
if [ -d "$DIST_ASSETS" ]; then
    cp -f "$DIST_ASSETS"/*.js  "$TOP_ASSETS"/ 2>/dev/null || true
    cp -f "$DIST_ASSETS"/*.css "$TOP_ASSETS"/ 2>/dev/null || true
fi

# Sanity: the new files must exist at /spa-assets/
if [ ! -f "$TOP_ASSETS/$NEW_JS" ]; then
    echo "ERROR: $TOP_ASSETS/$NEW_JS missing after copy" >&2
    exit 1
fi
if [ ! -f "$TOP_ASSETS/$NEW_CSS" ]; then
    echo "ERROR: $TOP_ASSETS/$NEW_CSS missing after copy" >&2
    exit 1
fi

# ── 3. Patch .deploy-version expected_bundle: block ─────────────────────
if [ ! -f "$DEPLOY_VER" ]; then
    echo "ERROR: $DEPLOY_VER missing" >&2
    exit 1
fi

# Replace the two lines under "expected_bundle:" (the JS line and CSS
# line) with the new hashes. We rewrite ONLY those two lines, leaving
# every other line in .deploy-version untouched. Using awk so this works
# on any host without a PHP CLI.
awk -v js="$NEW_JS" -v css="$NEW_CSS" '
    BEGIN { inBlock = 0; jsDone = 0; cssDone = 0 }
    /^expected_bundle:/ { inBlock = 1; print; next }
    inBlock && /^- spa-assets\/index-.+\.js$/ && !jsDone {
        print "- spa-assets/" js; jsDone = 1; next
    }
    inBlock && /^- spa-assets\/index-.+\.css$/ && !cssDone {
        print "- spa-assets/" css; cssDone = 1; next
    }
    inBlock && $0 != "" && substr($0, 1, 2) != "- " { inBlock = 0 }
    { print }
    END {
        if (!jsDone || !cssDone) {
            print "ERROR: expected_bundle: block not found or malformed" > "/dev/stderr"
            exit 2
        }
    }
' "$DEPLOY_VER" > "$DEPLOY_VER.tmp" && mv "$DEPLOY_VER.tmp" "$DEPLOY_VER"

echo "✓ spa-assets/ synced"
echo "✓ .deploy-version expected_bundle: updated"
echo "✓ dashboard/dist/index.html already points at new hashes (Vite wrote them)"
echo ""
echo "All three sync points are now consistent. Commit and push."
