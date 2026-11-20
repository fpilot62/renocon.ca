#!/bin/bash
# Preflight for the stat-approval install. The backfill runner is not a web file.
# Run: bash tests/deploy_stat_approval_check_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE=4d4c6549b57d9fa2cf548f18fce2ff1fb910247b
PIN=3901ad2b81dc356f818b0a03e8575bb400c7b87f
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }

seed_app() {
  local app=$1
  rm -rf "$app"
  mkdir -p "$app/payroll_engine" "$app/ops"
  git -C "$ROOT" show "$BASE:payroll_engine/stat_holidays.php" > "$app/payroll_engine/stat_holidays.php"
  git -C "$ROOT" show "$BASE:payroll_master_ledger.php" > "$app/payroll_master_ledger.php"
  git -C "$ROOT" show "$BASE:payroll_master_ledger1.php" > "$app/payroll_master_ledger1.php"
  git -C "$ROOT" show "$BASE:generate_stat_shifts.php" > "$app/generate_stat_shifts.php"
  git -C "$ROOT" show "$BASE:generate_pay_documents.php" > "$app/generate_pay_documents.php"
}

run_check() {
  local app=$1 stage=$2
  T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$app" T4127_STAGE="$stage" \
    bash "$ROOT/ops/deploy_stat_approval_v1.sh" check
}

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
STAGE=$WORK/stage

seed_app "$APP"
rm -rf "$STAGE"
mkdir -p "$STAGE"
if out=$(run_check "$APP" "$STAGE" 2>&1); then
  echo "$out" | grep -q 'CLEAN ops/t4127_stat_backfill.php new file' \
    && ok 'missing web backfill with no stage runner is a new file' \
    || bad 'missing web backfill was not reported as a new file'
else
  echo "$out"
  bad 'missing web backfill failed the preflight'
fi

git -C "$ROOT" show "$PIN:ops/t4127_stat_backfill.php" > "$STAGE/t4127_stat_backfill.php"
if out=$(run_check "$APP" "$STAGE" 2>&1); then
  echo "$out" | grep -q 'CLEAN stage runner' \
    && ok 'known stage runner is accepted' \
    || bad 'known stage runner was not accepted'
else
  echo "$out"
  bad 'known stage runner failed the preflight'
fi

echo 'not the backfill' > "$STAGE/t4127_stat_backfill.php"
if out=$(run_check "$APP" "$STAGE" 2>&1); then
  bad 'an unknown stage runner was accepted'
else
  echo "$out" | grep -q "DRIFT $STAGE/t4127_stat_backfill.php" \
    && ok 'an unknown stage runner is a conflict' \
    || bad 'an unknown stage runner did not report DRIFT'
fi

git -C "$ROOT" show "$PIN:ops/t4127_stat_backfill.php" > "$APP/ops/t4127_stat_backfill.php"
echo 'changed after install' > "$STAGE/t4127_stat_backfill.php"
if out=$(run_check "$APP" "$STAGE" 2>&1); then
  bad 'a drifted stage runner was accepted beside a good web copy'
else
  echo "$out" | grep -q "DRIFT $STAGE/t4127_stat_backfill.php" \
    && ok 'a drifted stage runner is still a conflict when the web copy matches' \
    || bad 'a drifted stage runner beside a good web copy did not report DRIFT'
fi

rm -f "$STAGE/t4127_stat_backfill.php" "$APP/ops/t4127_stat_backfill.php"
rm -f "$APP/payroll_engine/stat_holidays.php"
if out=$(run_check "$APP" "$STAGE" 2>&1); then
  bad 'a missing engine file was accepted'
else
  echo "$out" | grep -q 'MISSING .*/payroll_engine/stat_holidays.php' \
    && ok 'a missing engine file is still a conflict' \
    || bad 'a missing engine file was not reported as MISSING'
fi

if [[ $fail -gt 0 ]]; then
  echo "$fail failed"
  exit 1
fi
echo "all passed"
