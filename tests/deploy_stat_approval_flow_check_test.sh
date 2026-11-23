#!/bin/bash
# Preflight for the stat approval flow install. Does not touch a server.
# Run: bash tests/deploy_stat_approval_flow_check_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
mkdir -p "$APP"
PINNED=30de1fd0e2fa28f06fbb6063300b459c34d1ae6d
BASE=dff7c465c437010a36588effc1222b5350ab1624
files='engine/payroll_cheque_lib.php engine/ledger_matrix_lib.php payroll_payment_status.php payroll_payment_desk.php payroll_save_payment.php print_payrun.php generate_pay_documents.php payroll_engine/payroll_engine.php payroll_engine/stat_holidays.php payroll_master_ledger.php payroll_master_ledger1.php generate_stat_shifts.php ops/t4127_stat_backfill.php payroll_gen.php bulk_finalize_handler.php'
for rel in $files; do
  mkdir -p "$APP/$(dirname "$rel")"
  git -C "$ROOT" show "$BASE:$rel" > "$APP/$rel"
done
bad=0
ok() { echo "ok  $1"; }
badf() { echo "FAIL $1"; bad=1; }
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$WORK/stage" PAYROLL_BK="$WORK/bk" bash "$ROOT/ops/deploy_stat_approval_flow_v1.sh" check 2>&1); then
  echo "$out" | grep -q 'check clean' && ok 'current main is an accepted base' || badf 'clean check did not say so'
  echo "$out" | grep -q 'CLEAN engine/paydocs_earn.php new file' && ok 'new earnings helper is reported' || badf 'new file was not reported'
else
  echo "$out"
  badf 'check failed on the current main copies'
fi
echo 'drift' >> "$APP/payroll_save_payment.php"
echo 'drift' >> "$APP/print_payrun.php"
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$WORK/stage2" PAYROLL_BK="$WORK/bk" bash "$ROOT/ops/deploy_stat_approval_flow_v1.sh" check 2>&1); then
  badf 'drifted files were accepted'
else
  echo "$out" | grep -q 'DRIFT payroll_save_payment.php' && ok 'save-payment drift is listed' || badf 'save-payment drift was not listed'
  echo "$out" | grep -q 'DRIFT print_payrun.php' && ok 'print-payrun drift is listed' || badf 'print-payrun drift was not listed'
  echo "$out" | grep -q 'STOP. Drifted files were not installed' && ok 'check stops and lists the drift' || badf 'check did not stop'
fi
if [[ $bad -ne 0 ]]; then
  echo "$bad failed"
  exit 1
fi
echo "all passed"
