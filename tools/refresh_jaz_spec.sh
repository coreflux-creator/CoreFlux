#!/usr/bin/env bash
#
# tools/refresh_jaz_spec.sh
#
# Pulls the latest Jaz OpenAPI from upstream (the same spec used by
# Jaz's own MCP server / Claude plugin / CLI) and writes it to
# spec/jaz_openapi.json. Side-effect free — just downloads and atomic-
# moves into place. After running, eyeball `git diff spec/jaz_openapi.json`
# and re-run `tests/jaz_payload_contract_smoke.php` to surface any
# fields Jaz added that our mappers need to follow.
#
# Usage:
#   bash tools/refresh_jaz_spec.sh             # download + replace
#   bash tools/refresh_jaz_spec.sh --diff      # download + show diff only
#   bash tools/refresh_jaz_spec.sh --check     # download to /tmp, no replace
#
set -euo pipefail

UPSTREAM_URL="${JAZ_SPEC_URL:-https://raw.githubusercontent.com/teamtinvio/jaz-ai/main/spec/openapi.yaml}"
DEST="$(cd "$(dirname "$0")/.." && pwd)/spec/jaz_openapi.json"
TMP="$(mktemp -t jaz-openapi-XXXXXX.json)"
trap 'rm -f "$TMP"' EXIT

MODE="replace"
case "${1:-}" in
  --diff)  MODE="diff"  ;;
  --check) MODE="check" ;;
  '')      ;;
  *) echo "usage: $0 [--diff|--check]"; exit 2 ;;
esac

echo "→ fetching $UPSTREAM_URL"
if ! curl -sSfL "$UPSTREAM_URL" -o "$TMP"; then
  echo "✗ download failed — check JAZ_SPEC_URL or your network"
  exit 1
fi

# Verify it parses as JSON (despite the upstream `.yaml` extension —
# Jaz emits JSON-shaped content under that filename).
if ! python3 -c "import json,sys; json.load(open('$TMP'))" 2>/dev/null; then
  echo "✗ downloaded file is not valid JSON — Jaz may have switched format"
  exit 1
fi

if [ "$MODE" = "check" ]; then
  echo "✓ downloaded to $TMP (check mode — no replace)"
  trap - EXIT
  echo "$TMP"
  exit 0
fi

if [ "$MODE" = "diff" ]; then
  if [ -f "$DEST" ]; then
    diff -u "$DEST" "$TMP" || true
  else
    echo "(no current spec at $DEST — would create new)"
  fi
  exit 0
fi

# replace mode — atomic move so partial writes can't corrupt the spec.
mkdir -p "$(dirname "$DEST")"
mv "$TMP" "$DEST"
trap - EXIT
SIZE=$(wc -c < "$DEST")
echo "✓ wrote $DEST ($SIZE bytes)"
echo ""
echo "Next:"
echo "  1) git diff spec/jaz_openapi.json   # eyeball any required-field changes"
echo "  2) php tests/jaz_payload_contract_smoke.php   # fails loud if a mapper drifted"
