#!/bin/bash
# Org 2 Auto breaks, excluding placeholder sites 0, 99, and 999.
#   bash deploy12_apply.sh check
#   bash deploy12_apply.sh apply
#   bash deploy12_apply.sh rollback
# check dry-runs and does not replace files. apply dumps breaks, then writes
# only org 2. No cron is installed.
set -euo pipefail
M=${1:-check}
P=/home/renocon/public_html/payroll
S=/root/pr12-stage
D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/39655e8980e8a9a73159f5ca792e93d4bc81f170
# Files deploy11 copied from 55c7be604b4b5080185fc80bafa57731165cf54d.
EXP_LIB=0f262a6c64f433521a51a2c2657aae42
EXP_BACKFILL=fa7e38e9955b77ef43b0941c6a14e805
EXP_CRON=98db8ebc52d04eb8e161f1d32c818edd
EXP_MANUAL=b01c4eaacd670ede9321c095c73216b6
EXP_STAFF=892cf99ab176e8c66ef71e4189320889
ORG=2
EXCLUDE=0,99,999

md_of() { md5sum < "$1" | cut -d' ' -f1; }
echo "MODE=$M"
ok=yes
check_one() {
  local label="$1" path="$2" expect="$3" got match=no
  if [ ! -f "$path" ]; then
    echo "$label MISSING expected=$expect MATCH=no"
    ok=no
    return
  fi
  got=$(md_of "$path")
  [ "$got" = "$expect" ] && match=yes || ok=no
  echo "$label md5=$got expected=$expect MATCH=$match"
}
check_one engine/auto_break_lib.php "$P/engine/auto_break_lib.php" "$EXP_LIB"
check_one auto_break_backfill.php "$P/auto_break_backfill.php" "$EXP_BACKFILL"
check_one auto_break_cron.php "$P/auto_break_cron.php" "$EXP_CRON"
check_one manual_attendance.php "$P/manual_attendance.php" "$EXP_MANUAL"
check_one staff_audit.php "$P/staff_audit.php" "$EXP_STAFF"
test -e "$P/db.php" && echo "HAVE db.php" || { echo "MISSING db.php"; ok=no; }
echo "CHECK_OK=$ok"

run_backfill() {
  local mode="$1" out="$2"
  if [ "$mode" = apply ]; then
    php "$P/auto_break_backfill.php" --apply --org="$ORG" --exclude-sites="$EXCLUDE" > "$out" 2>&1 || echo "APPLY EXIT $?"
  else
    php "$S/auto_break_backfill.php" --dry-run --org="$ORG" --exclude-sites="$EXCLUDE" --root="$P" > "$out" 2>&1 || echo "DRYRUN EXIT $?"
  fi
}

show_result() {
  local out="$1"
  echo "FULL LIST $out"
  wc -l "$out"
  echo "BY SITE org $ORG (sites $EXCLUDE excluded):"
  awk '/^BY SITE org 2:/{p=1; next} p && /^org /{print; next} p{exit}' "$out" || true
  echo "MOVES:"
  grep ' move ' "$out" || echo "(none)"
  echo "SAMPLE 10:"
  awk '/ add /{print; c++; if (c==10) exit} END{if (!c) print "(no shifts to add)"}' "$out"
}

if [ "$M" = rollback ]; then
  idp=$D/pr12-inserted-latest.path
  mvp=$D/pr12-moved-latest.path
  [ -f "$idp" ] && [ -f "$mvp" ] || { echo "STOP: no apply log. Nothing changed."; exit 1; }
  idfile=$(cat "$idp")
  mvfile=$(cat "$mvp")
  [ -f "$idfile" ] && [ -f "$mvfile" ] || { echo "STOP: apply log files are missing. Nothing changed."; exit 1; }
  ids=$(grep -E '^[0-9]+$' "$idfile" | paste -sd, - || true)
  removed=0
  if [ -n "${ids:-}" ]; then
    removed=$(mysql -N admin_payroll -e "SELECT COUNT(*) FROM breaks WHERE source='auto' AND id IN ($ids)")
    mysql admin_payroll -e "DELETE FROM breaks WHERE source='auto' AND id IN ($ids)"
  fi
  restored=0
  while IFS=$'\t' read -r id start end; do
    [ -n "${id:-}" ] || continue
    printf '%s' "$id" | grep -Eq '^[0-9]+$' || continue
    printf '%s' "$start" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' || continue
    printf '%s' "$end" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$' || continue
    mysql admin_payroll -e "UPDATE breaks SET break_start='$start', break_end='$end' WHERE id=$id AND source='auto'"
    restored=$((restored + 1))
  done < "$mvfile"
  echo "ROLLBACK removed $removed inserted auto break(s) and restored $restored moved auto break(s). Other orgs and other breaks were not changed."
  exit 0
fi

[ "$ok" = yes ] || { echo "STOP: live files are not the deploy11 copies. Nothing changed."; exit 1; }

mkdir -p "$S/engine" "$D"
curl -fsS "$R/engine/auto_break_lib.php" -o "$S/engine/auto_break_lib.php"
curl -fsS "$R/auto_break_backfill.php" -o "$S/auto_break_backfill.php"
cp "$P/engine/attendance_integrity_lib.php" "$S/engine/attendance_integrity_lib.php"
php -l "$S/engine/auto_break_lib.php" >/dev/null
php -l "$S/auto_break_backfill.php" >/dev/null

if [ "$M" = check ]; then
  TS=$(date +%Y%m%d-%H%M%S)
  run_backfill dry "$D/pr12-check_$TS.txt"
  echo "DRY RUN ONLY for org $ORG excluding sites $EXCLUDE. No files were replaced. No breaks were written. Cron was not installed."
  show_result "$D/pr12-check_$TS.txt"
  exit 0
fi

[ "$M" = apply ] || { echo "Usage: bash deploy12_apply.sh check|apply|rollback"; exit 2; }

TS=$(date +%Y%m%d-%H%M%S)
mysqldump --single-transaction --routines --triggers admin_payroll breaks | gzip > "$D/pre-apply-breaks_$TS.sql.gz"
gzip -t "$D/pre-apply-breaks_$TS.sql.gz"
ls -l "$D/pre-apply-breaks_$TS.sql.gz"
cp "$S/engine/auto_break_lib.php" "$P/engine/auto_break_lib.php"
cp "$S/auto_break_backfill.php" "$P/auto_break_backfill.php"
chown renocon:renocon "$P/engine/auto_break_lib.php" "$P/auto_break_backfill.php"
chmod --reference="$P/db.php" "$P/engine/auto_break_lib.php" "$P/auto_break_backfill.php"
php -l "$P/engine/auto_break_lib.php"
php -l "$P/auto_break_backfill.php"
run_backfill apply "$D/pr12-apply_$TS.txt"
grep -oE 'inserted_id=[0-9]+' "$D/pr12-apply_$TS.txt" | cut -d= -f2 > "$D/pr12-inserted_$TS.ids" || true
php -r '
$in = file_get_contents($argv[1]);
preg_match_all("/moved_id=(\d+) old_start=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) old_end=(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/", $in, $m, PREG_SET_ORDER);
foreach ($m as $row) {
    echo $row[1] . "\t" . $row[2] . "\t" . $row[3] . "\n";
}
' "$D/pr12-apply_$TS.txt" > "$D/pr12-moved_$TS.tsv"
echo "$D/pr12-inserted_$TS.ids" > "$D/pr12-inserted-latest.path"
echo "$D/pr12-moved_$TS.tsv" > "$D/pr12-moved-latest.path"
echo "APPLY org $ORG excluding sites $EXCLUDE. Other orgs were not changed. Cron was not installed."
echo "INSERTED $(wc -l < "$D/pr12-inserted_$TS.ids") ids in $D/pr12-inserted_$TS.ids"
echo "MOVED $(wc -l < "$D/pr12-moved_$TS.tsv") rows in $D/pr12-moved_$TS.tsv"
show_result "$D/pr12-apply_$TS.txt"
echo "ROLLBACK: bash $0 rollback"
echo "DUMP: gzip -dc $D/pre-apply-breaks_$TS.sql.gz | mysql admin_payroll"
