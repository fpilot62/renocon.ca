#!/bin/bash
# PR8 Auto break. Run: bash deploy9.sh check   then: nohup bash deploy9.sh go > /root/deploy9.log 2>&1 &
set -euo pipefail
M=${1:-check}; P=/home/renocon/public_html/payroll; S=/root/pr8-stage; D=/root/payroll-daily
R=https://raw.githubusercontent.com/fpilot62/renocon.ca/0b004f8155c4bf42508d01f95a105f63c7adb6a7
F="engine/auto_break_lib.php auto_break_backfill.php manual_attendance.php staff_audit.php migrations/20261111_break_source.sql"
SA=df1040857d3ce5995c6067c4e8449141
rm -rf $S; mkdir -p $S/engine $S/migrations
for f in $F; do curl -fsS $R/$f -o $S/$f; test -s $S/$f; done
for f in $F; do case $f in *.php) php -l $S/$f >/dev/null || { echo "LINT FAIL $f"; exit 1; };; esac; done
md5sum $P/staff_audit.php $P/manual_attendance.php $S/manual_attendance.php
SAOK=no; [ "$(md5sum < $P/staff_audit.php | cut -d' ' -f1)" = $SA ] && SAOK=yes; echo "STAFF_AUDIT_MATCHES_BASE=$SAOK"
for f in engine/payroll_db_config.php config/payroll.env engine/attendance_integrity_lib.php; do test -e $P/$f && echo "HAVE $f" || echo "MISSING $f"; done
test ! -e $P/engine/auto_break_lib.php || echo "NOTE auto_break_lib already live"
if grep -rnE --include='*.php' --exclude-dir=backups --exclude=auto_break_lib.php 'function +ab_[a-z_]+ *[(]' $P; then echo "NAME CLASH ab_"; exit 1; fi
echo "CLOCKOUT WRITERS:"; grep -rlE --include='*.php' --exclude-dir=backups 'UPDATE +attendance +SET[^{]*clock_out' $P || true
echo "SOURCE_COL=$(mysql -N admin_payroll -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='admin_payroll' AND TABLE_NAME='breaks' AND COLUMN_NAME='source'")"
if [ "$M" != go ]; then echo "CHECK ONLY. Nothing changed."; exit 0; fi
[ $SAOK = yes ] || { echo "STOP: live staff_audit.php differs from GitHub base"; exit 1; }
TS=$(date +%Y%m%d-%H%M%S); mkdir -p $D
tar czf $D/pre-pr8-code_$TS.tar.gz --exclude=./backups -C $P .
mysqldump --single-transaction --routines --triggers admin_payroll | gzip > $D/pre-pr8-db_$TS.sql.gz
gzip -t $D/pre-pr8-db_$TS.sql.gz; tar tzf $D/pre-pr8-code_$TS.tar.gz >/dev/null; ls -l $D/pre-pr8-*_$TS.*
RB="tar xzf $D/pre-pr8-code_$TS.tar.gz -C $P && rm -f $P/engine/auto_break_lib.php $P/auto_break_backfill.php"
trap 'echo "FAILED. ROLLBACK: $RB"' ERR
mysql admin_payroll < $S/migrations/20261111_break_source.sql
echo "SOURCE_COL_AFTER=$(mysql -N admin_payroll -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='admin_payroll' AND TABLE_NAME='breaks' AND COLUMN_NAME='source'")"
mkdir -p $P/migrations; cp $S/migrations/20261111_break_source.sql $P/migrations/
for f in engine/auto_break_lib.php auto_break_backfill.php; do cp $S/$f $P/$f; chown renocon:renocon $P/$f; chmod --reference=$P/db.php $P/$f; done
for f in manual_attendance.php staff_audit.php; do cat $S/$f > $P/$f; done
for f in engine/auto_break_lib.php auto_break_backfill.php manual_attendance.php staff_audit.php; do php -l $P/$f; done
for u in manual_attendance.php staff_audit.php auto_break_backfill.php; do echo "$u no-login -> $(curl -s -m 20 -o /dev/null -w '%{http_code} %{redirect_url}' https://payroll.renocon.ca/$u || true)"; done
cd $P && php auto_break_backfill.php --dry-run > $D/pr8-dryrun_$TS.txt 2>&1 || echo "DRYRUN EXIT $?"
wc -l $D/pr8-dryrun_$TS.txt; tail -3 $D/pr8-dryrun_$TS.txt
echo "DONE. ROLLBACK: $RB  (column source stays; harmless)"
