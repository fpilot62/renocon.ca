#!/bin/bash
# The four live files that stopped v1 check are an accepted base for v2.
# Run: bash tests/deploy_stat_approval_flow_v2_check_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
UP=$ROOT/tests/fixtures/stat_flow_live
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
STAGE=$WORK/stage
mkdir -p "$APP" "$STAGE"
PIN_BASE=5a4dab88672774b6b0408e7507615d622210b81c
files='engine/payroll_cheque_lib.php engine/paydocs_earn.php engine/ledger_matrix_lib.php payroll_payment_status.php payroll_payment_desk.php payroll_save_payment.php print_payrun.php generate_pay_documents.php payroll_engine/payroll_engine.php payroll_engine/stat_holidays.php payroll_master_ledger.php payroll_master_ledger1.php generate_stat_shifts.php ops/t4127_stat_backfill.php payroll_gen.php bulk_finalize_handler.php'
for rel in $files; do
  mkdir -p "$APP/$(dirname "$rel")"
  git -C "$ROOT" show "$PIN_BASE:$rel" > "$APP/$rel"
done
cp "$UP/engine/payroll_cheque_lib.php" "$APP/engine/payroll_cheque_lib.php"
cp "$UP/payroll_save_payment.php" "$APP/payroll_save_payment.php"
cp "$UP/bulk_finalize_handler.php" "$APP/bulk_finalize_handler.php"
cp "$UP/ops/t4127_stat_backfill.php" "$STAGE/t4127_stat_backfill.php"
bad=0
ok() { echo "ok  $1"; }
badf() { echo "FAIL $1"; bad=1; }
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$STAGE" PAYROLL_BK="$WORK/bk" bash "$ROOT/ops/deploy_stat_approval_flow_v2.sh" check 2>&1); then
  echo "$out" | grep -q 'check clean' && ok 'the four live files are an accepted base' || badf 'check was not clean'
  echo "$out" | grep -q 'DRIFT' && badf 'a live file was still reported as drift' || ok 'no drift on the live copies'
else
  echo "$out"
  badf 'check failed on the live copies'
fi
echo 'drift' >> "$APP/print_payrun.php"
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$STAGE" PAYROLL_BK="$WORK/bk" bash "$ROOT/ops/deploy_stat_approval_flow_v2.sh" check 2>&1); then
  badf 'a new drift was accepted'
else
  echo "$out" | grep -q 'DRIFT print_payrun.php' && ok 'a new drift is listed' || badf 'the new drift was not listed'
  echo "$out" | grep -q 'STOP. Drifted files were not installed' && ok 'check stops' || badf 'check did not stop'
fi
if [[ $bad -ne 0 ]]; then
  echo "$bad failed"
  exit 1
fi
echo "all passed"
