#!/usr/bin/env bash
#
# apply-layerfi.sh — surgically bring the LayerFi integration from the Emergent
# branch into your CoreFlux repo, WITHOUT a full (destructive) merge.
#
# Run this on YOUR machine, from the root of your CoreFlux clone:
#
#     bash scripts/apply-layerfi.sh [branch]
#
# `branch` defaults to origin/conflict_040626_2242 — pass your real branch name
# if it differs, e.g.  bash scripts/apply-layerfi.sh origin/conflict_XXXXXX_XXXX
#
# What it does:
#   1. Pre-flight safety checks (git repo, clean tree, branch exists).
#   2. Aborts any stuck merge, checks out main, fetches origin.
#   3. Copies ONLY the new LayerFi files from the branch (additive — cannot
#      overwrite your people module or other code).
#   4. Prints the exact hand-edits + env/migration/build steps.
#
# It does NOT commit, push, build, or edit any of your existing files. You stay
# in control of every change.

set -uo pipefail

BRANCH="${1:-origin/conflict_040626_2242}"

c_red()  { printf '\033[31m%s\033[0m\n' "$*"; }
c_grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
c_ylw()  { printf '\033[33m%s\033[0m\n' "$*"; }
c_cyn()  { printf '\033[36m%s\033[0m\n' "$*"; }
hr()     { printf '%s\n' "------------------------------------------------------------"; }

# New, additive LayerFi files (safe — none of these exist in your repo yet).
NEW_PATHS=(
  "core/integrations/layer"
  "modules/accounting/api/layer_smoke_test.php"
  "modules/accounting/api/layer_setup_tenant.php"
  "modules/accounting/api/layer_business_token.php"
  "modules/accounting/api/layer_status.php"
  "modules/accounting/api/layer_client_error.php"
  "modules/accounting/api/layer_audit_log.php"
  "modules/accounting/api/layer_tenant_enablement.php"
  "modules/accounting/ui/layer"
  "modules/accounting/migrations/022_layer_sandbox.sql"
  "modules/accounting/migrations/023_layer_tenant_enablement.sql"
)

# ── 1. Pre-flight ────────────────────────────────────────────────────────────
hr; c_cyn "LayerFi → CoreFlux surgical import"; hr

if [ ! -d .git ]; then
  c_red "ERROR: run this from the ROOT of your CoreFlux git clone (.git not found)."
  exit 1
fi

if [ -n "$(git status --porcelain)" ] && [ ! -f .git/MERGE_HEAD ]; then
  c_ylw "WARNING: you have uncommitted changes. Commit or stash them first so this"
  c_ylw "         import is easy to review/undo. Aborting to be safe."
  git status --short
  exit 1
fi

# ── 2. Unstick + sync ────────────────────────────────────────────────────────
if [ -f .git/MERGE_HEAD ]; then
  c_ylw "• A merge is in progress — aborting it (returns to a clean state)…"
  git merge --abort || { c_red "git merge --abort failed"; exit 1; }
fi

c_cyn "• Checking out main and fetching origin…"
git checkout main || { c_red "Could not checkout main"; exit 1; }
git fetch origin  || { c_red "git fetch failed"; exit 1; }

if ! git rev-parse --verify -q "$BRANCH" >/dev/null; then
  c_red "ERROR: branch '$BRANCH' not found."
  c_ylw "Available conflict/Emergent branches:"
  git branch -a | grep -iE "conflict|emergent" || echo "  (none matched — run: git branch -a)"
  c_ylw "Re-run with the right name:  bash scripts/apply-layerfi.sh <branch>"
  exit 1
fi
c_grn "• Using source branch: $BRANCH"

# ── 3. Pull only the additive LayerFi files ──────────────────────────────────
hr; c_cyn "Importing new LayerFi files (additive only)…"; hr
imported=0; missing=0
for p in "${NEW_PATHS[@]}"; do
  if git cat-file -e "$BRANCH:$p" 2>/dev/null || git ls-tree -r --name-only "$BRANCH" -- "$p" | grep -q .; then
    if git checkout "$BRANCH" -- "$p" 2>/dev/null; then
      c_grn "  ✓ $p"; imported=$((imported+1))
    else
      c_red "  ✗ failed to import $p"; missing=$((missing+1))
    fi
  else
    c_ylw "  ? not found on $BRANCH: $p (skipped)"; missing=$((missing+1))
  fi
done

# Optional: provider adapter — only if you don't already have one.
if git cat-file -e "HEAD:core/accounting/accounting_provider.php" 2>/dev/null; then
  c_ylw "  ⚠ core/accounting/accounting_provider.php already exists on main —"
  c_ylw "    NOT overwriting. Diff it manually if LayerFi needs changes:"
  c_ylw "      git diff HEAD $BRANCH -- core/accounting/accounting_provider.php"
else
  if git cat-file -e "$BRANCH:core/accounting/accounting_provider.php" 2>/dev/null; then
    git checkout "$BRANCH" -- core/accounting/accounting_provider.php \
      && c_grn "  ✓ core/accounting/accounting_provider.php (new)"
  fi
fi

hr; c_grn "Imported $imported path(s)."; [ "$missing" -gt 0 ] && c_ylw "$missing path(s) skipped/failed — review above."; hr

# Migration collision guard
if ls modules/accounting/migrations/022_*.sql >/dev/null 2>&1; then
  others=$(ls modules/accounting/migrations/022_*.sql 2>/dev/null | grep -v layer_sandbox || true)
  [ -n "$others" ] && c_red "⚠ Migration number 022 collides with: $others
   → rename 022_layer_sandbox.sql / 023_layer_tenant_enablement.sql to the next free numbers."
fi

# ── 4. Print the remaining hand-edits ────────────────────────────────────────
cat <<'EOF'

============================================================
NEXT: 3 small hand-edits (the script will NOT touch these files)
============================================================

[A] dashboard/src/App.jsx
    • Add near the top (after the imports):

        const LAYER_SANDBOX_ENABLED =
          typeof import.meta !== 'undefined' &&
          String(import.meta.env?.VITE_ENABLE_LAYER_SANDBOX) === 'true';

    • In the `accounting` module's `actions` array, right after the
      `Audit Log` entry, add:

        ...(LAYER_SANDBOX_ENABLED ? [
          { name: 'Layer Sandbox (Embed)', route: 'layer-sandbox' },
          { name: 'Layer Integration',    route: 'layer-integration' },
        ] : []),

[B] modules/accounting/ui/AccountingModule.jsx
    • Add the import (with the other imports):

        import LayerSandboxModule from './layer/LayerSandboxModule';

    • Add the same flag:

        const LAYER_SANDBOX_ENABLED =
          typeof import.meta !== 'undefined' &&
          String(import.meta.env?.VITE_ENABLE_LAYER_SANDBOX) === 'true';

    • Inside the <Routes> block, add:

        {LAYER_SANDBOX_ENABLED && (
          <Route path="layer-sandbox" element={<LayerSandboxModule session={session} view="sandbox" />} />
        )}
        {LAYER_SANDBOX_ENABLED && (
          <Route path="layer-integration" element={<LayerSandboxModule session={session} view="settings" />} />
        )}

[C] dashboard/vite.config.js
        yarn --cwd dashboard add @layerfi/components
      then add inside resolve.alias:
        '@layerfi/components': path.resolve(__dirname, 'node_modules/@layerfi/components'),

============================================================
THEN: backend env + migrate + build + commit
============================================================
  # backend host env:
  ENABLE_LAYER_SANDBOX=true
  LAYER_ENV=sandbox
  LAYER_CLIENT_ID=<your sandbox client id>
  LAYER_CLIENT_SECRET=<your sandbox client secret>

  # RBAC: ensure these perms exist on the right roles:
  #   accounting.view, accounting.manage_integrations, coreflux.internal_sandbox

  php deploy/run_migrations.php
  VITE_ENABLE_LAYER_SANDBOX=true yarn --cwd dashboard build
  git add -A
  git commit -m "feat(accounting): embed LayerFi sandbox (flagged off by default)"
  git push origin main

Full reference: docs/LAYERFI_INTEGRATION.md
============================================================
EOF
