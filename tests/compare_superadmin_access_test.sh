#!/bin/bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }
LIVE=$(mktemp -d)
mkdir -p "$LIVE/engine"
git -C "$ROOT" show origin/main:engine/privacy_lib.php > "$LIVE/engine/privacy_lib.php"
git -C "$ROOT" show origin/main:payroll_eht_report.php > "$LIVE/payroll_eht_report.php"
out=$(COMPARE_REPO="$ROOT" PAYROLL_APP="$LIVE" bash "$ROOT/ops/compare_superadmin_access_v1.sh")
echo "$out" | grep -q 'repo engine/privacy_lib.php' && echo "$out" | grep -q 'live engine/privacy_lib.php' && ok 'prints both md5 lines' || { echo "$out"; bad 'md5 lines missing'; }
echo "$out" | grep -q 'platform_full_access' && ok 'prints the privacy diff' || bad 'diff missing'
echo "$out" | grep -q 'no git repo' && ok 'skips git show when the live tree is not a repo' || bad 'git show was required'
rm -rf "$LIVE"
if [[ $fail -gt 0 ]]; then echo "$fail failed"; exit 1; fi
echo "all passed"
