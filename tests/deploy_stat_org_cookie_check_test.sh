#!/bin/bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE=7d96978113379a13c96816f250c9c48c238318c2
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
mkdir -p "$APP/payroll_engine"
for f in payroll_scope.php payroll_engine/stat_holidays.php generate_stat_shifts.php payroll_master_ledger.php stat_holiday_review.php; do
  mkdir -p "$APP/$(dirname "$f")"
  git -C "$ROOT" show "$BASE:$f" > "$APP/$f"
done
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$WORK/stage" bash "$ROOT/ops/deploy_stat_org_cookie_v1.sh" check 2>&1); then
  echo "$out" | grep -q 'CLEAN payroll_scope.php' && echo "$out" | grep -q 'CLEAN stat_holiday_review.php' && ok 'check accepts the live files' || bad 'check output was incomplete'
else
  echo "$out"
  bad 'check failed'
fi
if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
