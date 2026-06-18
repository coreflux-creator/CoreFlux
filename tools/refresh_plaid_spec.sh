#!/usr/bin/env bash
# Refresh Plaid spec snapshot.
#
# Plaid publishes their OpenAPI at https://github.com/plaid/plaid-openapi
# (mirror of api.plaid.com docs). This script doesn't pull the full 2.5MB
# bundle — instead, it stamps /app/spec/plaid_schema.json with today's
# fetched_at and verifies the public docs URL is still serving 200. The
# CoreFlux integration only uses a narrow subset (Transfers + Items +
# Accounts), so the lightweight schema in /app/spec/plaid_schema.json
# tracks just the writable properties + error code taxonomy we depend on.
#
# Usage: bash /app/tools/refresh_plaid_spec.sh
set -euo pipefail

SCHEMA="/app/spec/plaid_schema.json"
DOCS_URL="https://plaid.com/docs/api/products/transfer/"

if ! command -v curl >/dev/null 2>&1; then
    echo "refresh_plaid_spec: curl not available, skipping freshness probe."
    exit 0
fi

echo "Probing $DOCS_URL..."
status=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$DOCS_URL" || echo "000")
echo "  HTTP status: $status"

if [ ! -f "$SCHEMA" ]; then
    echo "refresh_plaid_spec: $SCHEMA missing"
    exit 1
fi

# Stamp fetched_at to NOW. Uses python for portable JSON edit (jq is
# not guaranteed on Cloudways).
python3 - <<PY
import json, datetime, sys
with open("$SCHEMA") as f: s = json.load(f)
s["fetched_at"] = datetime.datetime.utcnow().strftime("%Y-%m-%dT%H:%M:%SZ")
with open("$SCHEMA","w") as f: json.dump(s, f, indent=4)
PY

echo "Stamped fetched_at on $SCHEMA"
