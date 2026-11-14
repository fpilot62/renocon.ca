#!/bin/bash
# Auto break on Staff Audit load, and before finalize.
#   bash deploy18_autobreak_onload.sh check
#   bash deploy18_autobreak_onload.sh go
set -euo pipefail
M=${1:-check}
P=/home/renocon/public_html/payroll
D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/a8ae9e18f06fa0e23f7b8169e29cba6432134179
EXP_STAFF=bf68f270650daec682e9e089744a9e40
EXP_LIB=a14902b91586bf6c4832baf7696f6353

echo "MODE=$M"
ok=yes
check_one() {
  local label="$1" path="$2" expect="$3" got match=no
  if [ ! -f "$path" ]; then
    echo "$label MISSING expected=$expect MATCH=no"
    ok=no
    return
  fi
  got=$(md5sum < "$path" | cut -d' ' -f1)
  [ "$got" = "$expect" ] && match=yes || ok=no
  echo "$label md5=$got expected=$expect MATCH=$match"
}
check_one staff_audit.php "$P/staff_audit.php" "$EXP_STAFF"
check_one engine/auto_break_lib.php "$P/engine/auto_break_lib.php" "$EXP_LIB"
echo "CHECK_OK=$ok"
if [ "$M" = check ]; then
  if [ "$ok" = yes ]; then
    echo "CHECK ONLY. Nothing changed."
    exit 0
  fi
  echo "STOP: live files are not the current deploy. Nothing changed."
  exit 1
fi
[ "$M" = go ] || { echo "Usage: bash deploy18_autobreak_onload.sh check|go"; exit 2; }
[ "$ok" = yes ] || { echo "STOP: live files are not the current deploy. Nothing changed."; exit 1; }

TS=$(date +%Y%m%d-%H%M%S)
mkdir -p "$D" "$P/engine"
cp -a "$P/staff_audit.php" "$D/pre-pr18-staff_audit_$TS.php"
cp -a "$P/engine/auto_break_lib.php" "$D/pre-pr18-auto_break_lib_$TS.php"
test -s "$D/pre-pr18-staff_audit_$TS.php"
test -s "$D/pre-pr18-auto_break_lib_$TS.php"
stage=$D/pr18-stage-$TS
mkdir -p "$stage/engine" "$stage/ops/pr8_live"
curl -fsS "$R/ops/pr8_live/staff_audit.php" -o "$stage/ops/pr8_live/staff_audit.php"
curl -fsS "$R/engine/auto_break_lib.php" -o "$stage/engine/auto_break_lib.php"
curl -fsS "$R/auto_break_before_finalize.php" -o "$stage/auto_break_before_finalize.php"
php -l "$stage/ops/pr8_live/staff_audit.php"
php -l "$stage/engine/auto_break_lib.php"
php -l "$stage/auto_break_before_finalize.php"
cp -a "$stage/ops/pr8_live/staff_audit.php" "$P/staff_audit.php"
cp -a "$stage/engine/auto_break_lib.php" "$P/engine/auto_break_lib.php"
cp -a "$stage/auto_break_before_finalize.php" "$P/auto_break_before_finalize.php"
chown --reference="$D/pre-pr18-staff_audit_$TS.php" "$P/staff_audit.php" "$P/auto_break_before_finalize.php"
chown --reference="$D/pre-pr18-auto_break_lib_$TS.php" "$P/engine/auto_break_lib.php"
chmod --reference="$D/pre-pr18-staff_audit_$TS.php" "$P/staff_audit.php" "$P/auto_break_before_finalize.php"
chmod --reference="$D/pre-pr18-auto_break_lib_$TS.php" "$P/engine/auto_break_lib.php"
php -l "$P/staff_audit.php"
php -l "$P/engine/auto_break_lib.php"
php -l "$P/auto_break_before_finalize.php"
echo "ROLLBACK:"
echo "cp $D/pre-pr18-staff_audit_$TS.php $P/staff_audit.php"
echo "cp $D/pre-pr18-auto_break_lib_$TS.php $P/engine/auto_break_lib.php"
echo "rm -f $P/auto_break_before_finalize.php"
echo "DONE go. No cron was installed."
