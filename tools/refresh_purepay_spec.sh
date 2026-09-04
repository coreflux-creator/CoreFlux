#!/usr/bin/env bash
# Snapshot Pure//Pay's public developer SPA and verify the documented surface
# still exists. The curated wire contract remains spec/purepay_schema.json.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/spec/purepay_docs"
mkdir -p "$DEST"
HTML="$DEST/developers.html"
curl -sSfL 'https://purepay.online/developers' -o "$HTML" \
  -H 'User-Agent: Mozilla/5.0 CoreFlux purepay-spec-refresh'
BUNDLE="$(grep -oE '/static/js/main\.[a-f0-9]+\.js' "$HTML" | head -1)"
test -n "$BUNDLE" || { echo 'Pure//Pay main bundle not found'; exit 1; }
curl -sSfL "https://purepay.online${BUNDLE}" -o "$DEST/main.js" \
  -H 'User-Agent: Mozilla/5.0 CoreFlux purepay-spec-refresh'
for needle in 'https://purepay.online/api/v1' 'bill.created' 'payment.settled' 'payment.failed' 'X-Webhook-Signature'; do
  grep -q "$needle" "$DEST/main.js" || { echo "Published contract marker missing: $needle"; exit 1; }
done
date -u +'%Y-%m-%dT%H:%M:%SZ' > "$DEST/.fetched_at"
echo "Pure//Pay developer snapshot refreshed in $DEST"
echo 'Review the diff, then update spec/purepay_schema.json if the published contract changed.'

