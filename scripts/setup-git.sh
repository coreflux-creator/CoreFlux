#!/usr/bin/env bash
#
# scripts/setup-git.sh — one-time-per-clone git configuration.
#
# Registers the "ours" merge driver referenced by .gitattributes so that the
# committed, regenerated build artifacts auto-resolve to the CURRENT branch's
# copy during a local `git merge`, instead of producing merge conflicts.
#
# Files covered (see .gitattributes):
#   spa-assets/**, /index.html, .deploy-version,
#   dashboard/dist/**, app/assets/**, dashboard/assets/**
#
# How it works: a merge driver of `true` always succeeds and leaves the
# working-tree (ours / %A) version untouched, so git marks the path resolved
# with our copy. After merging a feature branch into `main`, rebuild the
# bundle once on `main` (`yarn --cwd dashboard build`) so the committed
# artifacts reflect the merged source.
#
# Run once after cloning:
#   bash scripts/setup-git.sh
#
# NOTE: merge drivers run only for LOCAL merges. GitHub's web "Resolve
# conflicts" / PR auto-merge does NOT use them — merge locally to benefit.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

git -C "$ROOT" config merge.ours.name "Keep our copy of generated build artifacts"
git -C "$ROOT" config merge.ours.driver true

echo "✓ 'ours' merge driver registered for this clone."
echo "  Generated bundle files (spa-assets/, index.html, .deploy-version, …)"
echo "  will now auto-resolve to the current branch on local merges."
