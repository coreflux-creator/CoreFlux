#!/usr/bin/env bash
#
# CI: Sim scenario dry-runs (per-commit lightweight gate).
#
#   bash scripts/ci_sim_scenarios.sh
#
# Walks /app/sim/scenarios/*.json and runs each in --dry-run mode
# (no DB required). Asserts:
#   • Every scenario passes its dry-run assertions.
#   • Same seed → identical stdout (determinism).
# Exits non-zero on any failure.
#
# For full execution (against the sim tenant, with invariant checks)
# use scripts/ci_sim_full.sh — that's the nightly job.

set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php CLI not found." >&2
    exit 2
fi

PASS=0; FAIL=0; FAILED_LIST=""

for scenario_file in sim/scenarios/*.json; do
    name="$(basename "$scenario_file" .json)"
    # Dry-run pass 1
    out1=$(php sim/runner.php --scenario="$name" --dry-run 2>&1)
    rc1=$?
    # Dry-run pass 2 (same seed) — must produce identical normalized output
    out2=$(php sim/runner.php --scenario="$name" --dry-run 2>&1)
    rc2=$?

    # Strip the legitimately-varying parts (run_id + duration_ms).
    n1=$(echo "$out1" | sed -E 's/run_id=[^ ]+/run_id=X/g; s/in [0-9]+ms/in Xms/g')
    n2=$(echo "$out2" | sed -E 's/run_id=[^ ]+/run_id=X/g; s/in [0-9]+ms/in Xms/g')

    if [ $rc1 -eq 0 ] && [ $rc2 -eq 0 ] && [ "$n1" = "$n2" ]; then
        PASS=$((PASS+1))
        echo "  ok     $name"
    else
        FAIL=$((FAIL+1))
        FAILED_LIST="$FAILED_LIST\n  $name"
        echo "  FAIL   $name (rc1=$rc1 rc2=$rc2)"
        if [ "$n1" != "$n2" ]; then
            echo "         determinism mismatch — same seed should yield identical output"
        fi
    fi
done

echo ""
echo "==============================================="
echo "  Sim scenario dry-runs: $PASS passed, $FAIL failed"
echo "==============================================="
if [ $FAIL -gt 0 ]; then
    echo -e "Failed scenarios:$FAILED_LIST"
    exit 1
fi
exit 0
