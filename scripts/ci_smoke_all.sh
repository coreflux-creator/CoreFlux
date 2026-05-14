#!/usr/bin/env bash
#
# CI: Full smoke suite (lightweight per-commit gate).
#
#   bash scripts/ci_smoke_all.sh
#
# Runs every /app/tests/*_smoke.php with zend.assertions=1.
# Skips the 2 documented integration-only smokes that require live API
# keys: ai_platform_smoke + plaid_integration_smoke (handled by ci_sim_*).
# Exits non-zero on any other failure.
#
# Per the harness design doc §20: invariant violations block deploy.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php CLI not found. Install php-cli + php-mysql before CI." >&2
    exit 2
fi

PASS=0; FAIL=0
FAILED_LIST=""
SKIP_REGEX='ai_platform_smoke|plaid_integration_smoke'

for f in tests/*_smoke.php; do
    name="$(basename "$f")"
    if echo "$name" | grep -Eq "$SKIP_REGEX"; then
        echo "  skip   $name (requires live API keys; see ci_sim_*)"
        continue
    fi
    out=$(php -d zend.assertions=1 "$f" 2>&1)
    rc=$?
    if [ $rc -eq 0 ]; then
        PASS=$((PASS+1))
    else
        FAIL=$((FAIL+1))
        FAILED_LIST="$FAILED_LIST\n  $name"
        echo "  FAIL   $name (exit=$rc)"
        echo "$out" | tail -10 | sed 's/^/         /'
    fi
done

echo ""
echo "=========================================="
echo "  Smoke suite: $PASS passed, $FAIL failed"
echo "=========================================="
if [ $FAIL -gt 0 ]; then
    echo -e "Failed files:$FAILED_LIST"
    exit 1
fi
exit 0
