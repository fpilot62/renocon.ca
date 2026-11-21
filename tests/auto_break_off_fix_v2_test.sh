#!/bin/bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
out=$(FIX_SQLITE=1 FIX_ALLOW_NONROOT=1 bash "$ROOT/ops/auto_break_off_fix_v2.sh" check)
echo "$out" | grep -q 'all passed' && ok 'the fixture fix matches auto-break hours' || { echo "$out"; bad 'fixture fix failed'; }
echo "$out" | grep -q 'edited after 03:34 is skipped' && ok 'edited lines are skipped' || bad 'edited line was not skipped'
if grep -n 'CREATE DATABASE' "$ROOT/ops/auto_break_off_fix_v2.sh" | grep -q 'admin_payroll`'; then
  bad 'the temporary database is the live database'
else
  ok 'the temporary database is not the live database'
fi
if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
