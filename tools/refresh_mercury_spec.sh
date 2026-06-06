#!/usr/bin/env bash
# tools/refresh_mercury_spec.sh — snapshot Mercury API HTML reference.
# Same pattern as the Zoho refresh: pull → diff → hand-edit
# spec/mercury_schema.json → re-run tests/mercury_payload_contract_smoke.php.
set -euo pipefail
DEST="$(cd "$(dirname "$0")/.." && pwd)/spec/mercury_docs"
mkdir -p "$DEST"
BASE='https://docs.mercury.com/reference'
PAGES=("create-payment-1" "list-recipients" "create-recipient")
for p in "${PAGES[@]}"; do
    echo "→ fetching ${p}"
    curl -sSfL "${BASE}/${p}" -o "${DEST}/${p}.html" \
        -H 'User-Agent: Mozilla/5.0 CoreFlux mercury-spec-refresh' \
        || { echo "✗ fetch failed for ${p}"; exit 1; }
done
date -u +'%Y-%m-%dT%H:%M:%SZ' > "${DEST}/.fetched_at"
echo "✓ wrote ${DEST} ($(ls "${DEST}" | wc -l) files)"
echo "Next: diff, hand-edit spec/mercury_schema.json, re-run contract smoke."
