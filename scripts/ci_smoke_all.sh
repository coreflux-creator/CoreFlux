#!/usr/bin/env bash
#
# CI: Full smoke suite (lightweight per-commit gate).
#
#   bash scripts/ci_smoke_all.sh                  # run every test
#   bash scripts/ci_smoke_all.sh --lane=harness   # run only one lane
#   bash scripts/ci_smoke_all.sh --lane=ui
#   bash scripts/ci_smoke_all.sh --lane=modules
#   bash scripts/ci_smoke_all.sh --lane=core
#
# Lanes are defined in scripts/ci_lane_classifier.sh. The GitHub
# Actions workflow runs 4 lanes in parallel (matrix strategy) for
# ~4× faster commit feedback.
#
# Runs each /app/tests/*_smoke.php with zend.assertions=1.
# Skips the 2 documented integration-only smokes that require live API
# keys: ai_platform_smoke + plaid_integration_smoke (handled by ci_sim_*).
# Exits non-zero on any other failure.
#
# Per the harness design doc §20: invariant violations block deploy.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

# ── Args ────────────────────────────────────────────────────────────
LANE_FILTER=""
for arg in "$@"; do
    case "$arg" in
        --lane=*) LANE_FILTER="${arg#--lane=}" ;;
        --help|-h)
            echo "Usage: $0 [--lane=harness|ui|modules|core]"
            exit 0 ;;
        *)
            echo "Unknown arg: $arg (use --help)" >&2; exit 2 ;;
    esac
done

if [ -n "$LANE_FILTER" ]; then
    case "$LANE_FILTER" in
        harness|ui|modules|core) ;;
        *) echo "ERROR: --lane must be harness|ui|modules|core (got: $LANE_FILTER)" >&2; exit 2 ;;
    esac
fi

# shellcheck disable=SC1091
. "$ROOT/scripts/ci_lane_classifier.sh"

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php CLI not found. Install php-cli + php-mysql before CI." >&2
    exit 2
fi

PASS=0; FAIL=0; SKIPPED_LANE=0
FAILED_LIST=""
SKIP_REGEX='ai_platform_smoke|plaid_integration_smoke'

[ -n "$LANE_FILTER" ] && echo "▶ Lane filter: $LANE_FILTER"

for f in tests/*_smoke.php; do
    name="$(basename "$f")"
    if echo "$name" | grep -Eq "$SKIP_REGEX"; then
        echo "  skip   $name (requires live API keys; see ci_sim_*)"
        continue
    fi
    if [ -n "$LANE_FILTER" ]; then
        lane="$(ci_classify_lane "$name")"
        if [ "$lane" != "$LANE_FILTER" ]; then
            SKIPPED_LANE=$((SKIPPED_LANE+1))
            continue
        fi
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
if [ -n "$LANE_FILTER" ]; then
    echo "  Lane $LANE_FILTER: $PASS passed, $FAIL failed, $SKIPPED_LANE not in this lane"
else
    echo "  Smoke suite: $PASS passed, $FAIL failed"
fi
echo "=========================================="
if [ $FAIL -gt 0 ]; then
    echo -e "Failed files:$FAILED_LIST"
    exit 1
fi
exit 0
