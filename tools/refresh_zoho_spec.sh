#!/usr/bin/env bash
# tools/refresh_zoho_spec.sh — snapshot Zoho Books API HTML docs.
# Zoho doesn't publish OpenAPI. Workflow: pull → diff → hand-edit
# spec/zoho_schema.json → re-run tests/zoho_payload_contract_smoke.php.
set -euo pipefail
DEST="$(cd "$(dirname "$0")/.." && pwd)/spec/zoho_docs"
mkdir -p "$DEST"
BASE='https://www.zoho.com/books/api/v3'
PAGES=(bills invoices journals)
for p in "${PAGES[@]}"; do
    echo "→ fetching ${p}"
    curl -sSfL "${BASE}/${p}/" -o "${DEST}/${p}.html" \
        -H 'User-Agent: Mozilla/5.0 CoreFlux zoho-spec-refresh' \
        || { echo "✗ fetch failed for ${p}"; exit 1; }
done
date -u +'%Y-%m-%dT%H:%M:%SZ' > "${DEST}/.fetched_at"
echo "✓ wrote ${DEST} ($(ls "${DEST}" | wc -l) files)"
echo "Next: diff, hand-edit spec/zoho_schema.json, re-run contract smoke."
