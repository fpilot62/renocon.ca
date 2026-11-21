#!/bin/bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
out=$(VERIFY_SQLITE=1 bash "$ROOT/ops/auto_break_off_verify_v1.sh")
echo "$out" | grep -q 'all passed' && ok 'fixture comparison passes' || { echo "$out"; bad 'fixture comparison failed'; }
echo "$out" | grep -q 'clock-span excess is flagged' && ok 'clock-span excess is flagged' || bad 'clock-span flag missing'
echo "$out" | grep -q 'dropped punched break is flagged' && ok 'dropped punched breaks are named' || bad 'punched-break cause missing'
echo "$out" | grep -q 'untouched sibling line is listed' && ok 'the untouched pending line is listed' || bad 'untouched line missing'
echo "$out" | grep -q 'exact match was flagged' && bad 'an exact match was flagged' || ok 'an exact match is not flagged'
if grep -n -E 'mysql[[:space:]].*admin_payroll([^_]|$)' "$ROOT/ops/auto_break_off_verify_v1.sh" | grep -v LIVE_DB | grep -v 'SELECT only' | grep -v 'not modified' >/dev/null; then
  bad 'the shell names the live database as a mysql target'
else
  ok 'the shell does not restore into the live database'
fi
if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
