#!/bin/bash
# Preflight for the Stat Holiday Pay page install.
# Run: bash tests/deploy_stat_preview_guard_check_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE=bda896ecdf801b5471964d091ccf080193b64c91
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
mkdir -p "$APP/payroll_engine" "$APP/ops"
git -C "$ROOT" show "$BASE:payroll_engine/stat_holidays.php" > "$APP/payroll_engine/stat_holidays.php"
git -C "$ROOT" show "$BASE:generate_stat_shifts.php" > "$APP/generate_stat_shifts.php"
git -C "$ROOT" show "$BASE:payroll_master_ledger.php" > "$APP/payroll_master_ledger.php"
git -C "$ROOT" show "$BASE:stat_holiday_review.php" > "$APP/stat_holiday_review.php"
git -C "$ROOT" show "$BASE:ops/t4127_stat_backfill.php" > "$APP/ops/t4127_stat_backfill.php"
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$WORK/stage" bash "$ROOT/ops/deploy_stat_preview_guard_v1.sh" check 2>&1); then
  echo "$out" | grep -q 'CLEAN payroll_engine/stat_holidays.php' \
    && echo "$out" | grep -q 'CLEAN migrations/20261122_stat_holiday_exclusions.sql new file' \
    && ok 'check accepts the live files and the new migration' \
    || bad 'check output was incomplete'
else
  echo "$out"
  bad 'check failed against the current live hashes'
fi
echo 'drift' >> "$APP/generate_stat_shifts.php"
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$WORK/stage2" bash "$ROOT/ops/deploy_stat_preview_guard_v1.sh" check 2>&1); then
  bad 'a changed preview page was accepted'
else
  echo "$out" | grep -q 'DRIFT generate_stat_shifts.php' && ok 'a changed preview page is a conflict' || bad 'drift was not reported'
fi
if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
