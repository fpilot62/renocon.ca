#!/bin/bash
# Include Sandbox Kitchen in Auto breaks.
#   bash deploy20_sandbox_breaks.sh check
#   bash deploy20_sandbox_breaks.sh go
set -euo pipefail
M=${1:-check}
P=/home/renocon/public_html/payroll
D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/75a8e882583e8bba547b82e086e2d37aea0a61d9
EXP_STAFF=1cb2023ccd0a24b968ef847f655d2b57
EXP_LIB=d30bbd418d3cd224900d3f6c9faf3214

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
[ "$ok" = yes ] || { echo "STOP: live files are not the deploy18 copies. Nothing changed."; exit 1; }

stage=$D/pr20-stage
rm -rf "$stage"
mkdir -p "$stage/engine"
curl -fsS "$R/engine/auto_break_lib.php" -o "$stage/engine/auto_break_lib.php"
cp "$P/engine/attendance_integrity_lib.php" "$stage/engine/attendance_integrity_lib.php"
php -l "$stage/engine/auto_break_lib.php" >/dev/null
php -r '
require $argv[1];
$pdo = ab_open_pdo($argv[2]);
$sites = ab_sandbox_kitchen_sites($pdo);
if (!$sites) {
    echo "SANDBOX none\nELIGIBLE 0\n";
    exit;
}
$ids = [];
foreach ($sites as $site) {
    echo "SANDBOX site_id=" . $site["site_id"] . " org_id=" . $site["org_id"] . "\n";
    $ids[] = (int)$site["site_id"];
}
echo "ELIGIBLE " . ab_sandbox_eligible_count($pdo, $ids) . "\n";
' "$stage/engine/auto_break_lib.php" "$P"

if [ "$M" = check ]; then
  echo "CHECK ONLY. Nothing changed."
  exit 0
fi
[ "$M" = go ] || { echo "Usage: bash deploy20_sandbox_breaks.sh check|go"; exit 2; }

TS=$(date +%Y%m%d-%H%M%S)
mkdir -p "$D"
cp -a "$P/engine/auto_break_lib.php" "$D/pre-pr20-auto_break_lib_$TS.php"
test -s "$D/pre-pr20-auto_break_lib_$TS.php"
cp -a "$stage/engine/auto_break_lib.php" "$P/engine/auto_break_lib.php"
chown --reference="$D/pre-pr20-auto_break_lib_$TS.php" "$P/engine/auto_break_lib.php"
chmod --reference="$D/pre-pr20-auto_break_lib_$TS.php" "$P/engine/auto_break_lib.php"
php -l "$P/engine/auto_break_lib.php"
echo "ROLLBACK: cp $D/pre-pr20-auto_break_lib_$TS.php $P/engine/auto_break_lib.php"
echo "DONE go."
