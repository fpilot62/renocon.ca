#!/bin/bash
# Staff Audit filters stay put when the period changes.
#   bash deploy13_filters.sh check
#   bash deploy13_filters.sh go
set -euo pipefail
M=${1:-check}
P=/home/renocon/public_html/payroll
D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/9e7d631f2f0591c4657c91550dcba0cb6e43e55d
EXP=892cf99ab176e8c66ef71e4189320889
LIVE=$P/staff_audit.php

echo "MODE=$M"
if [ ! -f "$LIVE" ]; then
  echo "staff_audit.php MISSING expected=$EXP MATCH=no"
  echo "CHECK_OK=no"
  exit 1
fi
got=$(md5sum < "$LIVE" | cut -d' ' -f1)
match=no
[ "$got" = "$EXP" ] && match=yes
echo "staff_audit.php md5=$got expected=$EXP MATCH=$match"
if [ "$match" != yes ]; then
  echo "CHECK_OK=no"
  echo "STOP: live staff_audit.php is not the deployed PR8 copy. Nothing changed."
  exit 1
fi
echo "CHECK_OK=yes"
if [ "$M" = check ]; then
  echo "CHECK ONLY. Nothing changed."
  exit 0
fi
[ "$M" = go ] || { echo "Usage: bash deploy13_filters.sh check|go"; exit 2; }

TS=$(date +%Y%m%d-%H%M%S)
mkdir -p "$D"
cp -a "$LIVE" "$D/pre-pr13-filters_$TS.php"
test -s "$D/pre-pr13-filters_$TS.php"
curl -fsS "$R/ops/pr8_live/staff_audit.php" -o "$D/pr13-filters_$TS.php"
php -l "$D/pr13-filters_$TS.php"
cp -a "$D/pr13-filters_$TS.php" "$LIVE"
chown --reference="$D/pre-pr13-filters_$TS.php" "$LIVE"
chmod --reference="$D/pre-pr13-filters_$TS.php" "$LIVE"
php -l "$LIVE"
echo "ROLLED IN. ROLLBACK: cp $D/pre-pr13-filters_$TS.php $LIVE"
echo "DONE go."
