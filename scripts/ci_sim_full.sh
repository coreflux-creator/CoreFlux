#!/usr/bin/env bash
#
# CI: Full sim execution against a flagged sim tenant (nightly job).
#
#   SIM_TENANT_ID=999 bash scripts/ci_sim_full.sh
#
# Runs every scenario against the sim tenant — this exercises the real
# posting engine + accounting_events tables, then asserts every
# invariant. Failing invariants exit non-zero (per harness spec §20:
# block deploy on invariant breach).
#
# Required env:
#   SIM_TENANT_ID  — id of a tenant with tenants.is_simulation=1
#
# Optional env:
#   SIM_MODE=1     — enable mock layer (Plaid/OpenAI/Resend) for the run

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

: "${SIM_TENANT_ID:?SIM_TENANT_ID env var is required}"
export SIM_MODE="${SIM_MODE:-1}"

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php CLI not found." >&2
    exit 2
fi

PASS=0; FAIL=0; FAILED_LIST=""

for scenario_file in sim/scenarios/*.json; do
    name="$(basename "$scenario_file" .json)"
    echo ""
    echo "▶ $name (tenant=$SIM_TENANT_ID)"
    php sim/runner.php --scenario="$name" --tenant="$SIM_TENANT_ID"
    rc=$?
    if [ $rc -eq 0 ]; then
        PASS=$((PASS+1))
    else
        FAIL=$((FAIL+1))
        FAILED_LIST="$FAILED_LIST\n  $name"
    fi
done

echo ""
echo "================================================="
echo "  Full sim execution: $PASS passed, $FAIL failed"
echo "================================================="
if [ $FAIL -gt 0 ]; then
    echo -e "Failed scenarios:$FAILED_LIST"
    exit 1
fi
exit 0
