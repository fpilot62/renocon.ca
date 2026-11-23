#!/bin/bash
# The six live files that stopped v1 or v2 are an accepted base for v3.
# Run: bash tests/deploy_stat_approval_flow_v3_check_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
UP=$ROOT/tests/fixtures/stat_flow_live
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
STAGE=$WORK/stage
mkdir -p "$APP" "$STAGE"
PIN_BASE=ffe662e3c621995edac01752701fe21ed5ae3b72
files='engine/payroll_cheque_lib.php engine/paydocs_earn.php engine/ledger_matrix_lib.php payroll_payment_status.php payroll_payment_desk.php payroll_save_payment.php print_payrun.php generate_pay_documents.php payroll_engine/payroll_engine.php payroll_engine/stat_holidays.php payroll_master_ledger.php payroll_master_ledger1.php generate_stat_shifts.php ops/t4127_stat_backfill.php payroll_gen.php bulk_finalize_handler.php'
for rel in $files; do
  mkdir -p "$APP/$(dirname "$rel")"
  git -C "$ROOT" show "$PIN_BASE:$rel" > "$APP/$rel"
done
cp "$UP/engine/payroll_cheque_lib.php" "$APP/engine/payroll_cheque_lib.php"
cp "$UP/payroll_save_payment.php" "$APP/payroll_save_payment.php"
cp "$UP/bulk_finalize_handler.php" "$APP/bulk_finalize_handler.php"
cp "$UP/payroll_payment_desk.php" "$APP/payroll_payment_desk.php"
cp "$UP/print_payrun.php" "$APP/print_payrun.php"
cp "$UP/ops/t4127_stat_backfill.php" "$STAGE/t4127_stat_backfill.php"
bad=0
ok() { echo "ok  $1"; }
badf() { echo "FAIL $1"; bad=1; }
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$STAGE" PAYROLL_BK="$WORK/bk" bash "$ROOT/ops/deploy_stat_approval_flow_v3.sh" check 2>&1); then
  echo "$out" | grep -q 'check clean' && ok 'the six live files are an accepted base' || badf 'check was not clean'
  echo "$out" | grep -q 'DRIFT' && badf 'a live file was still reported as drift' || ok 'no drift on the live copies'
  echo "$out" | grep -q 'CLEAN print_payrun.php' && ok 'live print_payrun is accepted' || badf 'print_payrun was not accepted'
  echo "$out" | grep -q 'CLEAN payroll_payment_desk.php' && ok 'live payment desk is accepted' || badf 'payment desk was not accepted'
  echo "$out" | grep -q 'CLEAN generate_stat_shifts.php' && ok 'the v2 holiday page is an accepted base' || badf 'holiday page was not accepted'
else
  echo "$out"
  badf 'check failed on the live copies'
fi
echo 'drift' >> "$APP/payroll_master_ledger.php"
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$STAGE" PAYROLL_BK="$WORK/bk" bash "$ROOT/ops/deploy_stat_approval_flow_v3.sh" check 2>&1); then
  badf 'a new drift was accepted'
else
  echo "$out" | grep -q 'DRIFT payroll_master_ledger.php' && ok 'a new drift is listed' || badf 'the new drift was not listed'
  echo "$out" | grep -q 'STOP. Drifted files were not installed' && ok 'check stops' || badf 'check did not stop'
fi
if [[ $bad -ne 0 ]]; then
  echo "$bad failed"
  exit 1
fi
echo "all passed"
