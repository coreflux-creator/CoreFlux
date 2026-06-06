#!/usr/bin/env bash
#
# tools/refresh_qbo_spec.sh
#
# Intuit does NOT publish a single OpenAPI doc for QBO — the schema
# lives across per-entity HTML pages at developer.intuit.com. This
# script just refreshes our local snapshot of those pages so a future
# operator can diff them against what we hand-rolled into
# `spec/qbo_schema.json`.
#
# Workflow when Intuit bumps a minor version:
#   1) bash tools/refresh_qbo_spec.sh           # pulls HTML, writes spec/qbo_docs/*.html
#   2) git diff spec/qbo_docs/                  # eyeball field/required changes
#   3) hand-edit spec/qbo_schema.json to match  # tighten required[] / writableProperties[]
#   4) php tests/qbo_payload_contract_smoke.php # confirms mappers still conform
#
# Once Intuit eventually ships an OpenAPI doc (or a community mirror
# becomes trustworthy), swap this for a JSON download analogous to
# refresh_jaz_spec.sh.
#
set -euo pipefail

DEST_DIR="$(cd "$(dirname "$0")/.." && pwd)/spec/qbo_docs"
mkdir -p "$DEST_DIR"

BASE='https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities'
PAGES=(
    "journalentry"
    "bill"
    "invoice"
)

for page in "${PAGES[@]}"; do
    echo "→ fetching ${page}"
    curl -sSfL "${BASE}/${page}" -o "${DEST_DIR}/${page}.html" \
        -H 'User-Agent: Mozilla/5.0 CoreFlux qbo-spec-refresh' \
        || { echo "✗ fetch failed for ${page}"; exit 1; }
done

# Note the minor_version we tested against — re-run the smoke
# afterwards so it sees the timestamp drift.
date -u +'%Y-%m-%dT%H:%M:%SZ' > "${DEST_DIR}/.fetched_at"
echo ""
echo "✓ wrote ${DEST_DIR}/ ($(ls "${DEST_DIR}" | wc -l) files)"
echo ""
echo "Next:"
echo "  1) git diff spec/qbo_docs/                  # eyeball any required-field changes"
echo "  2) edit spec/qbo_schema.json to match       # bump _meta.minor_version if Intuit did"
echo "  3) php tests/qbo_payload_contract_smoke.php # locks the new schema against the mappers"
