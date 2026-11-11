#!/bin/bash
# PR8 Auto break, live Staff Audit. Run: bash deploy10.sh check
# then: nohup bash deploy10.sh go > /root/deploy10.log 2>&1 &
# Does not install a crontab entry. Prints the line at the end of go.
set -euo pipefail
M=${1:-check}; P=/home/renocon/public_html/payroll; S=/root/pr8-stage; D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/668963a40ec63782b086e3b8a259fea1aebe76b9
F="engine/auto_break_lib.php auto_break_backfill.php auto_break_cron.php manual_attendance.php ops/pr8_live/staff_audit.php migrations/20261111_break_source.sql"
SA=d30d5e4ddf2ee0eb9438ef2b8dbcac5b
MA=f3c63ca4d8f86698432319b0d9a7ef93
rm -rf $S; mkdir -p $S/engine $S/migrations $S/ops/pr8_live
for f in $F; do curl -fsS $R/$f -o $S/$f; test -s $S/$f; done
for f in $F; do case $f in *.php) php -l $S/$f >/dev/null || { echo "LINT FAIL $f"; exit 1; };; esac; done
echo "LINT staged php: ok"
SA_LIVE=$(md5sum < $P/staff_audit.php | cut -d' ' -f1)
MA_LIVE=$(md5sum < $P/manual_attendance.php | cut -d' ' -f1)
SAOK=no; [ "$SA_LIVE" = "$SA" ] && SAOK=yes
MAOK=no; [ "$MA_LIVE" = "$MA" ] && MAOK=yes
echo "LIVE staff_audit.php md5=$SA_LIVE"
echo "EXPECTED staff_audit.php md5=$SA"
echo "STAFF_AUDIT_MATCHES_LIVE=$SAOK"
echo "LIVE manual_attendance.php md5=$MA_LIVE"
echo "EXPECTED manual_attendance.php md5=$MA"
echo "MANUAL_ATTENDANCE_MATCHES_PR7=$MAOK"
test -e $P/db.php && echo "HAVE db.php" || echo "MISSING db.php"
test -e $P/engine/payroll_db_config.php && echo "HAVE engine/payroll_db_config.php" || echo "MISSING engine/payroll_db_config.php (db.php is the connector)"
test ! -e $P/engine/auto_break_lib.php || echo "NOTE auto_break_lib already live"
if grep -rnE --include='*.php' --exclude-dir=backups --exclude=auto_break_lib.php 'function +ab_[a-z_]+ *[(]' $P; then echo "NAME CLASH ab_"; exit 1; fi
echo "NAME CLASH ab_: none"
echo "SOURCE_COL=$(mysql -N admin_payroll -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='admin_payroll' AND TABLE_NAME='breaks' AND COLUMN_NAME='source'")"
if [ "$M" != go ]; then echo "CHECK ONLY. Nothing changed."; exit 0; fi
[ "$SAOK" = yes ] || { echo "STOP: live staff_audit.php md5 is not $SA"; exit 1; }
[ "$MAOK" = yes ] || { echo "STOP: live manual_attendance.php md5 is not the PR7 file $MA"; exit 1; }
TS=$(date +%Y%m%d-%H%M%S); mkdir -p $D
tar czf $D/pre-pr8-code_$TS.tar.gz --exclude=./backups -C $P .
mysqldump --single-transaction --routines --triggers admin_payroll | gzip > $D/pre-pr8-db_$TS.sql.gz
gzip -t $D/pre-pr8-db_$TS.sql.gz; tar tzf $D/pre-pr8-code_$TS.tar.gz >/dev/null; ls -l $D/pre-pr8-*_$TS.*
RB="tar xzf $D/pre-pr8-code_$TS.tar.gz -C $P && rm -f $P/engine/auto_break_lib.php $P/auto_break_backfill.php $P/auto_break_cron.php"
trap 'echo "FAILED. ROLLBACK: $RB"' ERR
mysql admin_payroll < $S/migrations/20261111_break_source.sql
echo "SOURCE_COL_AFTER=$(mysql -N admin_payroll -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='admin_payroll' AND TABLE_NAME='breaks' AND COLUMN_NAME='source'")"
mkdir -p $P/migrations $P/engine
cp $S/migrations/20261111_break_source.sql $P/migrations/
for f in engine/auto_break_lib.php auto_break_backfill.php auto_break_cron.php; do
  cp $S/$f $P/$f
  chown renocon:renocon $P/$f
  chmod --reference=$P/db.php $P/$f
done
cat $S/manual_attendance.php > $P/manual_attendance.php
cat $S/ops/pr8_live/staff_audit.php > $P/staff_audit.php
for f in engine/auto_break_lib.php auto_break_backfill.php auto_break_cron.php manual_attendance.php staff_audit.php; do php -l $P/$f; done
for u in manual_attendance.php staff_audit.php auto_break_backfill.php auto_break_cron.php; do echo "$u no-login -> $(curl -s -m 20 -o /dev/null -w '%{http_code} %{redirect_url}' https://payroll.renocon.ca/$u || true)"; done
cd $P && php auto_break_backfill.php --dry-run > $D/pr8-dryrun_$TS.txt 2>&1 || echo "DRYRUN EXIT $?"
echo "DRYRUN $D/pr8-dryrun_$TS.txt"
wc -l $D/pr8-dryrun_$TS.txt
echo "PER ORG:"
grep -E '^org ' $D/pr8-dryrun_$TS.txt || echo "(no org rows)"
tail -5 $D/pr8-dryrun_$TS.txt
echo "CRON (not installed):"
echo "*/10 * * * * cd /home/renocon/public_html/payroll && php auto_break_cron.php >> /root/payroll-daily/auto_break_cron.log 2>&1"
echo "DONE. ROLLBACK: $RB  (column source stays; harmless)"
