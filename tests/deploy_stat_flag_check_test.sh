#!/bin/bash
# Preflight for the one-off stat flag script.
# Run: bash tests/deploy_stat_flag_check_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
STAGE=$WORK/stage
mkdir -p "$APP/payroll_engine" "$STAGE"
cp "$ROOT/payroll_engine/stat_holidays.php" "$APP/payroll_engine/stat_holidays.php"
run_check() {
  T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$STAGE" \
    bash "$ROOT/ops/deploy_stat_flag_existing_v1.sh" check
}
if out=$(run_check 2>&1); then
  echo "$out" | grep -q 'CLEAN payroll_engine/stat_holidays.php' \
    && echo "$out" | grep -q 'bundle ops/stat_flag_existing.php' \
    && ok 'check accepts the live engine and the runner' \
    || bad 'check did not report the engine and the runner'
else
  echo "$out"
  bad 'check failed on the known engine'
fi
echo 'changed' >> "$APP/payroll_engine/stat_holidays.php"
if out=$(run_check 2>&1); then
  bad 'a changed engine was accepted'
else
  echo "$out" | grep -q 'DRIFT payroll_engine/stat_holidays.php' \
    && ok 'a changed engine is a conflict' \
    || bad 'a changed engine was not reported as DRIFT'
fi
rm -f "$APP/payroll_engine/stat_holidays.php"
if out=$(run_check 2>&1); then
  bad 'a missing engine was accepted'
else
  echo "$out" | grep -q 'MISSING' \
    && ok 'a missing engine is a conflict' \
    || bad 'a missing engine was not reported'
fi
grep -q 'SET stat_flag' "$ROOT/ops/stat_flag_existing.php" \
  && ! grep -q 'DELETE FROM payroll_finalized' "$ROOT/ops/stat_flag_existing.php" \
  && ok 'the runner does not delete rows' \
  || bad 'the runner deletes rows'
if [[ $fail -gt 0 ]]; then
  echo "$fail failed"
  exit 1
fi
echo "all passed"
