#!/bin/bash
# The v3 deploy accepts the live Payment Status page and installs the merged
# copy. A missing or drifted optional file is skipped. A drifted ledger stops
# check. diff prints the live difference and does not write the app.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE=76da1618bcb5b52ad995fff547eb566639cf7195
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
seed() {
  local app=$1
  local rel
  while read -r rel; do
    [[ -n $rel ]] || continue
    mkdir -p "$app/$(dirname "$rel")"
    git -C "$ROOT" show "$BASE:$rel" > "$app/$rel"
  done << 'EOF'
payroll_scope.php
payroll_master_ledger.php
engine/payroll_payment_status_lib.php
payroll_payment_status.php
attendance_audit.php
balance_report.php
bulk_allocation_desk.php
direct_deposit_pending.php
finalize_payroll.php
finalize_payroll1.php
finalized_archive.php
generate_pay_documents.php
payroll_dashboard.php
payroll_hours_report.php
payroll_hub.php
payroll_ledger_grouped.php
payroll_master_ledger1.php
payroll_settings.php
payroll_statement.php
payroll_summary.php
EOF
}
run() {
  T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$1" T4127_STAGE="$2" PAYROLL_BK="$3" \
    bash "$ROOT/ops/deploy_stat_org_default_v3.sh" "$4" 2>&1
}

APP=$WORK/clean
seed "$APP"
if out=$(run "$APP" "$WORK/stage-clean" "$WORK/bk" check 2>&1); then
  echo "$out" | grep -q 'CLEAN payroll_scope.php' \
    && echo "$out" | grep -q 'CLEAN payroll_master_ledger.php' \
    && echo "$out" | grep -q 'CLEAN engine/payroll_payment_status_lib.php' \
    && echo "$out" | grep -q 'WILL_SKIP none' \
    && ok 'check accepts the live payroll files' || { echo "$out"; bad 'clean check output was incomplete'; }
else
  echo "$out"
  bad 'clean check failed'
fi

APP=$WORK/skip
seed "$APP"
rm -f "$APP/attendance_audit.php"
printf '\n/* local edit */\n' >> "$APP/bulk_allocation_desk.php"
bulk_before=$(md5sum "$APP/bulk_allocation_desk.php" | cut -c1-32)
if out=$(run "$APP" "$WORK/stage-skip" "$WORK/bk-skip" check 2>&1); then
  echo "$out" | grep -q 'SKIP attendance_audit.php MISSING' \
    && echo "$out" | grep -q 'SKIP bulk_allocation_desk.php DRIFT' \
    && echo "$out" | grep -q 'WILL_INSTALL' \
    && echo "$out" | grep -q 'payroll_scope.php' \
    && ok 'optional missing and drifted files are skipped' || { echo "$out"; bad 'skip check output was incomplete'; }
else
  echo "$out"
  bad 'skip check should pass'
fi
if out=$(run "$APP" "$WORK/stage-diff" "$WORK/bk-diff" diff 2>&1); then
  echo "$out" | grep -q 'bulk_allocation_desk.php' \
    && echo "$out" | grep -q 'local edit' \
    && echo "$out" | grep -q 'diff is read-only' \
    && ok 'diff prints the live bulk desk and changes nothing' || { echo "$out"; bad 'diff output was incomplete'; }
else
  echo "$out"
  bad 'diff of an optional file should stay read-only and succeed'
fi
bulk_after=$(md5sum "$APP/bulk_allocation_desk.php" | cut -c1-32)
[[ $bulk_before == "$bulk_after" ]] && ok 'diff does not rewrite the live file' || bad 'diff changed the live file'
[[ ! -f $APP/attendance_audit.php ]] && ok 'diff does not create the missing audit page' || bad 'diff created attendance_audit.php'

if out=$(run "$APP" "$WORK/stage-go" "$WORK/bk-go" go 2>&1); then
  echo "$out" | grep -q 'Installed payroll_scope.php' \
    && echo "$out" | grep -q 'Installed engine/payroll_payment_status_lib.php' \
    && echo "$out" | grep -q 'Left unchanged:' \
    && echo "$out" | grep -q 'attendance_audit.php' \
    && echo "$out" | grep -q 'bulk_allocation_desk.php' \
    && ok 'go installs the required files and leaves the skipped ones' || { echo "$out"; bad 'go output was incomplete'; }
else
  echo "$out"
  bad 'go should install the clean files'
fi
[[ ! -f $APP/attendance_audit.php ]] && ok 'go does not create a missing optional file' || bad 'go created attendance_audit.php'
grep -q 'local edit' "$APP/bulk_allocation_desk.php" && ok 'go does not overwrite a drifted optional file' || bad 'go overwrote the drifted bulk desk'
grep -F -q '$top = ps_topbar_org();' "$APP/payroll_scope.php" && ok 'go installed the top-bar resolver' || bad 'payroll_scope was not updated'
scope_backup=$(find "$WORK/bk-go" -path '*/payroll_scope.php' | head -n 1)
[[ -n $scope_backup && -f $scope_backup ]] && ok 'go keeps a backup of the installed file' || bad 'backup of payroll_scope is missing'
find "$WORK/bk-go" -name 'bulk_allocation_desk.php' | grep -q . && bad 'backup includes a skipped file' || ok 'backup omits skipped files'

APP=$WORK/core
seed "$APP"
printf '\n/* unknown ledger edit */\n' >> "$APP/payroll_master_ledger.php"
if out=$(run "$APP" "$WORK/stage-core" "$WORK/bk-core" check 2>&1); then
  echo "$out"
  bad 'a drifted ledger must fail check'
else
  echo "$out" | grep -q 'CORE_DRIFT payroll_master_ledger.php' \
    && ok 'a drifted ledger fails check' || { echo "$out"; bad 'core drift was not reported'; }
fi
if out=$(run "$APP" "$WORK/stage-core-go" "$WORK/bk-core" go 2>&1); then
  bad 'go must refuse when the ledger is drifted'
else
  grep -q 'unknown ledger edit' "$APP/payroll_master_ledger.php" \
    && ! grep -F -q '$top = ps_topbar_org();' "$APP/payroll_scope.php" \
    && ok 'go copies nothing when a required file is drifted' || bad 'go changed files after a core failure'
fi

APP=$WORK/livepay
seed "$APP"
git -C "$ROOT" show 5a8a12a:payroll_payment_status.php > "$APP/payroll_payment_status.php"
live_md=$(md5sum "$APP/payroll_payment_status.php" | cut -c1-32)
[[ $live_md == f4c2e921bafd50753c72bc19900520d8 ]] && ok 'the fixture is the live payment page' || bad "fixture hash is $live_md"
if out=$(run "$APP" "$WORK/stage-livepay" "$WORK/bk-livepay" check 2>&1); then
  echo "$out" | grep -q 'CLEAN payroll_payment_status.php live f4c2e921bafd50753c72bc19900520d8' \
    && echo "$out" | grep -q '  payroll_payment_status.php' \
    && ok 'check accepts the live payment page and will install the merge' || { echo "$out"; bad 'live payment page was not accepted'; }
else
  echo "$out"
  bad 'the live payment page should not fail check'
fi
if out=$(run "$APP" "$WORK/stage-livepay-go" "$WORK/bk-livepay" go 2>&1); then
  grep -q 'pe_sql_employee_cpp' "$APP/payroll_payment_status.php" \
    && grep -q 'ps_selected_org' "$APP/payroll_payment_status.php" \
    && grep -q 'pstat_default_org_id' "$APP/payroll_payment_status.php" \
    && ok 'go installs the merged payment page and keeps its org calls' || bad 'the merged payment page is incomplete'
else
  echo "$out"
  bad 'go should install the merged payment page'
fi

if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
