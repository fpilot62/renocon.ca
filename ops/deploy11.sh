#!/bin/bash
# Prorated Auto break. Live PR8 files must still match before go.
#   bash deploy11.sh check
#   nohup bash deploy11.sh go > /root/deploy11.log 2>&1 &
# go backs up, copies the new files, and dry-runs. It does not insert breaks
# and it does not install a cron entry.
#   bash deploy11.sh apply
#   bash deploy11.sh rollback
set -euo pipefail
M=${1:-check}
P=/home/renocon/public_html/payroll
S=/root/pr11-stage
D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/55c7be604b4b5080185fc80bafa57731165cf54d
F="engine/auto_break_lib.php auto_break_backfill.php auto_break_cron.php manual_attendance.php ops/pr8_live/staff_audit.php"
# md5 of the files deploy10 copied from 668963a40ec63782b086e3b8a259fea1aebe76b9
EXP_LIB=4dc7f93bdcb5bba80f31338f6e67c496
EXP_BACKFILL=221adfed026ba2272fd8253944379bdf
EXP_CRON=5a9dd7ae8c3d074d926ee57f71e01f7e
EXP_MANUAL=6319d393a74e4605bf6d664b4cc7f381
EXP_STAFF=892cf99ab176e8c66ef71e4189320889

md_of() { md5sum < "$1" | cut -d' ' -f1; }

echo "MODE=$M"
ok=yes
check_one() {
  local label="$1" path="$2" expect="$3"
  if [ ! -f "$path" ]; then
    echo "$label MISSING expected=$expect MATCH=no"
    ok=no
    return
  fi
  local got
  got=$(md_of "$path")
  local match=no
  [ "$got" = "$expect" ] && match=yes
  [ "$match" = yes ] || ok=no
  echo "$label md5=$got expected=$expect MATCH=$match"
}
check_one engine/auto_break_lib.php "$P/engine/auto_break_lib.php" "$EXP_LIB"
check_one auto_break_backfill.php "$P/auto_break_backfill.php" "$EXP_BACKFILL"
check_one auto_break_cron.php "$P/auto_break_cron.php" "$EXP_CRON"
check_one manual_attendance.php "$P/manual_attendance.php" "$EXP_MANUAL"
check_one staff_audit.php "$P/staff_audit.php" "$EXP_STAFF"
test -e "$P/db.php" && echo "HAVE db.php" || { echo "MISSING db.php"; ok=no; }
if grep -rnE --include='*.php' --exclude-dir=backups --exclude=auto_break_lib.php 'function +ab_[a-z_]+ *[(]' "$P"; then
  echo "NAME CLASH ab_"
  ok=no
else
  echo "NAME CLASH ab_: none"
fi
echo "CHECK_OK=$ok"
if [ "$M" = check ]; then
  [ "$ok" = yes ] && echo "CHECK ONLY. Nothing changed." && exit 0
  echo "CHECK FAILED. Nothing changed."
  exit 1
fi

if [ "$M" = rollback ]; then
  idfile=$D/prorate-inserted-latest.path
  [ -f "$idfile" ] || { echo "STOP: no apply id list. Nothing removed."; exit 1; }
  list=$(cat "$idfile")
  [ -f "$list" ] || { echo "STOP: id list $list is missing. Nothing removed."; exit 1; }
  ids=$(grep -E '^[0-9]+$' "$list" | paste -sd, - || true)
  if [ -z "${ids:-}" ]; then
    echo "ROLLBACK: the apply inserted no auto breaks. Nothing removed."
    exit 0
  fi
  before=$(mysql -N admin_payroll -e "SELECT COUNT(*) FROM breaks WHERE source='auto' AND id IN ($ids)")
  mysql admin_payroll -e "DELETE FROM breaks WHERE source='auto' AND id IN ($ids)"
  echo "ROLLBACK removed $before auto break(s) from ids in $list. Other breaks were left in place."
  exit 0
fi

if [ "$M" = apply ]; then
  grep -q 'function ab_break_minutes' "$P/engine/auto_break_lib.php" || { echo "STOP: live helper is still the fixed 30-minute rule. Run go first."; exit 1; }
  TS=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$D"
  mysqldump --single-transaction --routines --triggers admin_payroll breaks | gzip > "$D/pre-apply-breaks_$TS.sql.gz"
  gzip -t "$D/pre-apply-breaks_$TS.sql.gz"
  ls -l "$D/pre-apply-breaks_$TS.sql.gz"
  cd "$P"
  php auto_break_backfill.php --apply > "$D/prorate-apply_$TS.txt" 2>&1 || echo "APPLY EXIT $?"
  grep -oE 'inserted_id=[0-9]+' "$D/prorate-apply_$TS.txt" | cut -d= -f2 > "$D/prorate-inserted_$TS.ids" || true
  echo "$D/prorate-inserted_$TS.ids" > "$D/prorate-inserted-latest.path"
  echo "APPLY LIST $D/prorate-apply_$TS.txt"
  echo "INSERTED IDS $D/prorate-inserted_$TS.ids ($(wc -l < "$D/prorate-inserted_$TS.ids") rows)"
  echo "BY ORG:"
  awk '/^BY ORG:/{p=1; next} p && /^org /{print; next} p{exit}' "$D/prorate-apply_$TS.txt" || true
  echo "BY SITE org 2:"
  awk '/^BY SITE org 2:/{p=1; next} p && /^org /{print; next} p{exit}' "$D/prorate-apply_$TS.txt" || true
  echo "ROLLBACK: bash $0 rollback"
  echo "DUMP: gzip -dc $D/pre-apply-breaks_$TS.sql.gz | mysql admin_payroll"
  exit 0
fi

[ "$ok" = yes ] || { echo "STOP: live files are not the PR8 deploy. Nothing changed."; exit 1; }
[ "$M" = go ] || { echo "Usage: bash deploy11.sh check|go|apply|rollback"; exit 2; }

TS=$(date +%Y%m%d-%H%M%S)
mkdir -p "$D" "$S/engine" "$S/ops/pr8_live"
tar czf "$D/pre-prorate-code_$TS.tar.gz" --exclude=./backups -C "$P" .
mysqldump --single-transaction --routines --triggers admin_payroll breaks | gzip > "$D/pre-prorate-breaks_$TS.sql.gz"
gzip -t "$D/pre-prorate-breaks_$TS.sql.gz"
tar tzf "$D/pre-prorate-code_$TS.tar.gz" >/dev/null
ls -l "$D/pre-prorate-code_$TS.tar.gz" "$D/pre-prorate-breaks_$TS.sql.gz"
RB="tar xzf $D/pre-prorate-code_$TS.tar.gz -C $P"
trap 'echo "FAILED. CODE ROLLBACK: $RB"' ERR
for f in $F; do curl -fsS "$R/$f" -o "$S/$f"; test -s "$S/$f"; done
for f in $F; do case $f in *.php) php -l "$S/$f" >/dev/null || { echo "LINT FAIL $f"; exit 1; };; esac; done
cp "$S/engine/auto_break_lib.php" "$P/engine/auto_break_lib.php"
cp "$S/auto_break_backfill.php" "$P/auto_break_backfill.php"
cp "$S/auto_break_cron.php" "$P/auto_break_cron.php"
cat "$S/manual_attendance.php" > "$P/manual_attendance.php"
cat "$S/ops/pr8_live/staff_audit.php" > "$P/staff_audit.php"
for f in engine/auto_break_lib.php auto_break_backfill.php auto_break_cron.php; do
  chown renocon:renocon "$P/$f"
  chmod --reference="$P/db.php" "$P/$f"
done
for f in engine/auto_break_lib.php auto_break_backfill.php auto_break_cron.php manual_attendance.php staff_audit.php; do php -l "$P/$f"; done
cd "$P"
php auto_break_backfill.php --dry-run > "$D/prorate-dryrun_$TS.txt" 2>&1 || echo "DRYRUN EXIT $?"
echo "DRY RUN ONLY. No breaks were inserted. Cron was not installed."
echo "FULL LIST $D/prorate-dryrun_$TS.txt"
wc -l "$D/prorate-dryrun_$TS.txt"
echo "BY ORG:"
awk '/^BY ORG:/{p=1; next} p && /^org /{print; next} p{exit}' "$D/prorate-dryrun_$TS.txt" || true
echo "BY SITE org 2:"
awk '/^BY SITE org 2:/{p=1; next} p && /^org /{print; next} p{exit}' "$D/prorate-dryrun_$TS.txt" || true
echo "SAMPLE 10:"
awk '/ add /{print; c++; if (c==10) exit} END{if (!c) print "(no shifts to add)"}' "$D/prorate-dryrun_$TS.txt"
echo "APPLY (separate command, makes its own breaks backup): bash $0 apply"
echo "ROLLBACK after that apply: bash $0 rollback"
echo "CODE ROLLBACK: $RB"
echo "CRON (not installed):"
echo "*/10 * * * * cd /home/renocon/public_html/payroll && php auto_break_cron.php >> /root/payroll-daily/auto_break_cron.log 2>&1"
trap - ERR
echo "DONE go. Dry run only."
