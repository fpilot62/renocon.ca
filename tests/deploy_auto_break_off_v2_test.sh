#!/bin/bash
# File check installs the switch when the libraries match, and stops if a
# required library has drifted. The database report is skipped here.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
BASE=f180c5e827bdb9dd1118163d4be01a220c77e7b3
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
seed() {
  local app=$1
  local rel
  for rel in engine/auto_break_lib.php engine/break_waiver_lib.php break_waiver_finalize_guard.php no_break_request.php staff_audit.php; do
    mkdir -p "$app/$(dirname "$rel")"
    git -C "$ROOT" show "$BASE:$rel" > "$app/$rel"
  done
}
run() {
  ABO_SKIP_DB=1 T4127_ALLOW_NONROOT=1 T4127_REPO="$ROOT" PAYROLL_APP="$1" T4127_STAGE="$2" PAYROLL_BK="$3" \
    bash "$ROOT/ops/auto_break_off_v2.sh" "$4" 2>&1
}
APP=$WORK/app
seed "$APP"
printf '\n/* local staff audit */\n' >> "$APP/staff_audit.php"
rm -f "$APP/no_break_request.php"
if out=$(run "$APP" "$WORK/stage" "$WORK/bk" check); then
  echo "$out" | grep -q 'CLEAN engine/auto_break_lib.php' \
    && echo "$out" | grep -q 'SKIP staff_audit.php DRIFT' \
    && echo "$out" | grep -q 'SKIP no_break_request.php MISSING' \
    && echo "$out" | grep -q 'engine/auto_break_switch.php' \
    && echo "$out" | grep -q 'DB report skipped' \
    && echo "$out" | grep -q 'SKIP does not fail check' \
    && echo "$out" | grep -q 'check passed' \
    && echo "$out" | grep -q 'INSERT INTO breaks' \
    && ok 'check installs the switch and skips drifted optional files' || { echo "$out"; bad 'check output was incomplete'; }
else
  echo "$out"
  bad 'check should pass'
fi
printf '\n/* unknown library */\n' >> "$APP/engine/auto_break_lib.php"
if out=$(run "$APP" "$WORK/stage-bad" "$WORK/bk" check); then
  bad 'a drifted auto-break library must fail check'
else
  echo "$out" | grep -q 'CORE_DRIFT engine/auto_break_lib.php' \
    && ok 'a drifted auto-break library fails check' || { echo "$out"; bad 'core drift was not reported'; }
fi
seed "$APP"
printf '\n/* local staff audit */\n' >> "$APP/staff_audit.php"
if out=$(run "$APP" "$WORK/stage-go" "$WORK/bk-go" go); then
  echo "$out" | grep -q 'Installed engine/auto_break_switch.php' \
    && echo "$out" | grep -q 'DB backup skipped' \
    && echo "$out" | grep -q 'DB report skipped' \
    && ok 'go installs the switch without the database' || { echo "$out"; bad 'go output was incomplete'; }
else
  echo "$out"
  bad 'go should install the clean files'
fi
val=$(php -r "echo (include '$APP/engine/auto_break_switch.php') ? 'on' : 'off';")
[[ $val == off ]] && ok 'the installed switch is off' || bad "the switch is $val"
grep -q 'local staff audit' "$APP/staff_audit.php" && ok 'go leaves a drifted staff audit alone' || bad 'go overwrote staff audit'
grep -q 'feature off' "$APP/engine/auto_break_lib.php" && ok 'go installed the feature gate' || bad 'the library was not updated'

if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
