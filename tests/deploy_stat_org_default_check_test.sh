#!/bin/bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE=76da1618bcb5b52ad995fff547eb566639cf7195
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
APP=$WORK/app
while read -r rel _ _; do
  [[ -n $rel ]] || continue
  mkdir -p "$APP/$(dirname "$rel")"
  git -C "$ROOT" show "$BASE:$rel" > "$APP/$rel"
done << 'EOF'
payroll_scope.php
engine/payroll_payment_status_lib.php
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
if out=$(T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$APP" T4127_STAGE="$WORK/stage" bash "$ROOT/ops/deploy_stat_org_default_v1.sh" check 2>&1); then
  echo "$out" | grep -q 'CLEAN payroll_scope.php' && echo "$out" | grep -q 'CLEAN payroll_master_ledger1.php' && ok 'check accepts the live payroll files' || bad 'check output was incomplete'
else
  echo "$out"
  bad 'check failed'
fi
if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
