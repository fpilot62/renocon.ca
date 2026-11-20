#!/bin/bash
# T4127 source deductions deploy (PR #19 @ b29f973). Root, WHM terminal.
#   bash deploy_t4127.sh check | go | rollback <ts>
set -euo pipefail
M=${1:-check}; APP=/home/renocon/public_html/payroll; BK=/root/payroll-daily; DB=admin_payroll
SHA=b29f973bceadf42e9005c2d0ab386830cbf2b14b; R=https://raw.githubusercontent.com/fpilot62/renocon.ca/$SHA
S=/root/t4127-stage; OWN=renocon:renocon; URL=https://payroll.renocon.ca
# path | Nov19 live md5 (- = new file) | bundle md5
MAN='payroll_engine/payroll_engine.php ab4136aad3ab78759569aa82d89ea998 e4b27a545b24bd1ffe639a7ce2d70e3d
manual_pay_entry.php 8cb18b215d208933bed9be61f851e3ff cd86101cc128452c95e4b8d1e86241df
bulk_finalize_handler.php 40d4974818de7369b0c6330b4c3d01cc 62edd3e83ea3e15e310212842c12422d
bulk_finalize_autosplit.php e9180002f11725e56a96592fcb99e15e 1aef2324f3837b0cf40437203256f2bd
generate_stat_shifts.php 0ef627cdf7d73d33deaec23a9f39cf64 98cb9f33d88ecf0f7979afa6570b576b
process_audit.php 930a3969499f76969f1620c81396769f 5c0dd1232634d16a22c2800a70bc666a
process_payroll.php 300a7182019f5c0b895273655cea572f 4f791e50e76a570a3e7c4c21e19c3f28
payroll_master_ledger.php 563ff63b0c366b401c47a5ddd5f78ac9 602706a4729b27a050362f8cb2e20be6
generate_pay_documents.php d892d12c72e1e7e59b5574db6fa387f2 ba49bb0fcde80ccc6bbae07a25670275
finalize_shift.php 8953ce6206d11d3a35a0c434704b0c16 8c9385e7c246785d1a4405b9833c2b35
payroll_engine/t4127_bridge.php - 5b155bf158f7d1875e6a2e3e25512d0f
payroll_engine/source_deductions_lib.php - 3238b1680ec3b7687cec0c076f890f81
payroll_engine/rates/2026-01-01.php - 11ff45b559fcc31b03ae7e69b81e440b
payroll_engine/rates/2026-07-01.php - c1a23dc795060eb905417efdc46e815c
payroll_engine/seed_rates.php - 008915f21d39d58d02ee452ec985ba6b
payroll_engine/page_gate.php - 7723a481fbe922bb776c06cec0dd0e16
payroll_eht_report.php - 681b66d0c836d3a8f734c093522e601f
migrations/20261120_source_deductions.sql - 275ff2af5939d7bc998caa1260d407b5
docs/SOURCE_DEDUCTIONS.md - c2ac07e52be75b28e46c9001a06f2fd8'
[[ $EUID -eq 0 ]] || { echo "Run as root."; exit 1; }
q() { mysql -N -B $DB -e "$1"; }
md() { md5sum "$1" | cut -c1-32; }
stage() {
  rm -rf $S; mkdir -p $S; local f=0
  while read -r p b n; do mkdir -p "$S/$(dirname $p)"
    curl -fsS "$R/$p" -o "$S/$p" || { echo "FETCH_FAIL $p"; f=1; continue; }
    [[ $(md "$S/$p") == $n ]] || { echo "BUNDLE_MD5_BAD $p"; f=1; }
    if [[ $p == *.php ]] && ! php -l "$S/$p" >/dev/null 2>&1; then echo "LINT_FAIL stage $p"; f=1; fi
  done <<< "$MAN"; return $f; }
check() {
  local f=0; stage || f=1
  while read -r p b n; do
    if [[ ! -e $APP/$p ]]; then [[ $b == - ]] && echo "ABSENT_OK $p" || { echo "MISSING_LIVE $p"; f=1; }
    else c=$(md "$APP/$p")
      if [[ $c == $n ]]; then echo "ALREADY_DEPLOYED $p"
      elif [[ $c == $b ]]; then echo "MATCHES_NOV19 $p"
      else echo "DRIFTED $p (live $c, Nov19 $b) - will NOT overwrite"; f=1; fi; fi
  done <<< "$MAN"
  for p in db.php payroll_scope.php engine/privacy_lib.php engine/attendance_integrity_lib.php engine/org_modules_lib.php engine/orgscope_guard.php; do
    [[ -f $APP/$p ]] && echo "DEP_OK $p" || echo "DEP_MISSING $p"; done
  echo "sites.province col: $(q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='sites' AND COLUMN_NAME='province'")"
  echo "payroll_finalized rows/maxid: $(q 'SELECT COUNT(*),MAX(id) FROM payroll_finalized')"
  return $f; }
sites() { mysql -t $DB -e "SELECT SId,nick_name,org_id,$1 FROM sites WHERE org_id=2 ORDER BY SId"; }
verify() {
  local f=0 since=$1
  for p in payroll_master_ledger.php manual_pay_entry.php generate_pay_documents.php payroll_eht_report.php finalize_shift.php process_payroll.php process_audit.php bulk_finalize_handler.php bulk_finalize_autosplit.php generate_stat_shifts.php index.php; do
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL/$p"); echo "HTTP $c $p"; [[ $c == 5* || $c == 000 ]] && f=1; done
  cd $APP; php -r '
    require "payroll_engine/payroll_engine.php"; require "db.php";
    $r=sd_calculate(["pay_date"=>"2026-01-15","periods"=>24,"province"=>"BC","periodic_gross"=>5000]);
    printf("SD   cpp=%.2f cpp2=%.2f ei=%.2f tax=%.2f net=%.2f %s\n",$r["cpp"],$r["cpp2"],$r["ei"],$r["income_tax"],5000-$r["cpp"]-$r["cpp2"]-$r["ei"]-$r["income_tax"],$r["edition"]);
    $l=pe_line(5000,0.0,pe_rates($pdo),["pdo"=>$pdo,"user_id"=>0,"site_id"=>6,"org_id"=>2,"work_date"=>"2026-01-15","pay_date"=>"2026-01-15","pay_periods"=>24,"force_vac_rate"=>0.0]);
    printf("LINE cpp=%.2f cpp2=%.2f ei=%.2f tax=%.2f wcb=%.2f edition=%s\n",$l["cpp"],$l["cpp2"]??0,$l["ei"],$l["income_tax"],$l["wcb"],$l["edition"]??"legacy");
    exit((abs($r["cpp"]-288.82)<.01&&abs($r["ei"]-81.50)<.01&&abs($r["income_tax"]-1040.58)<.01)?0:3);' || { echo "CALC_MISMATCH_OR_ERROR"; f=1; }
  echo "--- PHP fatals in error_log files changed since deploy:"
  local fat; fat=$(find $APP -maxdepth 3 -name error_log -newer $BK/pre-t4127-files_$since.list -print0 2>/dev/null | xargs -0 -r grep -h "Fatal" 2>/dev/null | grep -F -e "$(date +%d-%b-%Y)" | tail -20 || true)
  [[ -n $fat ]] && { echo "$fat"; f=1; } || echo "none"
  return $f; }
rollback() {
  local ts=$1
  tar -xzpf $BK/pre-t4127-files_$ts.tar.gz -C $APP && echo "restored replaced files"
  while read -r p b n; do if [[ $b == - ]] && grep -qx "$p" $BK/pre-t4127-files_$ts.created; then rm -f "$APP/$p"; fi; done <<< "$MAN"
  rmdir $APP/payroll_engine/rates 2>/dev/null || true
  if [[ -s $BK/pre-t4127-province_$ts.sql ]]; then mysql $DB < $BK/pre-t4127-province_$ts.sql && echo "province values restored"; fi
  echo "ROLLBACK DONE ts=$ts. Added columns/tables left (additive). Full DB restore if needed: zcat $BK/pre-t4127-db_$ts.sql.gz | mysql $DB"; }
case $M in
 check) check && echo "CHECK OK. Nothing changed." || { echo "CHECK FAILED. Nothing changed."; exit 1; } ;;
 go)
  check || { echo "CHECK FAILED - aborting, nothing changed"; exit 1; }
  ts=$(date +%Y%m%d-%H%M%S); mkdir -p $BK; chmod 700 $BK
  mysqldump --single-transaction --no-tablespaces --routines --triggers $DB | gzip > $BK/pre-t4127-db_$ts.sql.gz
  gzip -t $BK/pre-t4127-db_$ts.sql.gz; zcat $BK/pre-t4127-db_$ts.sql.gz | tail -1 | grep -q "Dump completed" || { echo "DB DUMP INCOMPLETE - aborting"; exit 1; }
  echo "DB backup: $BK/pre-t4127-db_$ts.sql.gz $(stat -c %s $BK/pre-t4127-db_$ts.sql.gz) bytes"
  : > $BK/pre-t4127-files_$ts.list; : > $BK/pre-t4127-files_$ts.created
  while read -r p b n; do if [[ -e $APP/$p ]]; then echo "$p" >> $BK/pre-t4127-files_$ts.list; else echo "$p" >> $BK/pre-t4127-files_$ts.created; fi; done <<< "$MAN"
  tar -czpf $BK/pre-t4127-files_$ts.tar.gz -C $APP -T $BK/pre-t4127-files_$ts.list
  echo "File backup: $BK/pre-t4127-files_$ts.tar.gz $(stat -c %s $BK/pre-t4127-files_$ts.tar.gz) bytes, contents:"; tar -tzf $BK/pre-t4127-files_$ts.tar.gz
  [[ $(tar -tzf $BK/pre-t4127-files_$ts.tar.gz | wc -l) -eq $(wc -l < $BK/pre-t4127-files_$ts.list) ]] || { echo "FILE BACKUP INCOMPLETE - aborting"; exit 1; }
  echo "--- migration"; if mysql $DB < $S/migrations/20261120_source_deductions.sql; then echo "migration OK"; else echo "MIGRATION FAILED - no files installed yet; DB backup $BK/pre-t4127-db_$ts.sql.gz"; exit 1; fi
  echo "new cols: $(q "SELECT GROUP_CONCAT(CONCAT(TABLE_NAME,'.',COLUMN_NAME)) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND COLUMN_NAME IN ('td1_federal_total','eht_eligible','province','wsib_percent','cpp2','cpp2_er','eht','deduction_edition')")"
  echo "new tables: $(q "SHOW TABLES LIKE 'payroll_rate_packs'") $(q "SHOW TABLES LIKE 'payroll_deduction_openings'")"
  while read -r p b n; do mkdir -p "$APP/$(dirname $p)"; cp -f "$S/$p" "$APP/$p"; chown $OWN "$APP/$p"; chmod 644 "$APP/$p"; done <<< "$MAN"
  chown $OWN $APP/payroll_engine/rates; chmod 755 $APP/payroll_engine/rates
  lf=0; while read -r p b n; do if [[ $p == *.php ]]; then php -l "$APP/$p" || lf=1; fi; done <<< "$MAN"
  [[ $lf -eq 0 ]] || { echo "LINT FAILED - rolling back"; rollback $ts; exit 1; }
  echo "--- seed_rates"; (cd $APP && php payroll_engine/seed_rates.php 2>&1) || echo "seed_rates exit $? (engine reads payroll_engine/rates/*.php directly)"
  echo "--- province before"; sites "province"
  mysql -N -B $DB -e "SELECT CONCAT('UPDATE sites SET province=',IF(province IS NULL,'NULL',QUOTE(province)),' WHERE SId=',SId,';') FROM sites WHERE org_id=2 AND (province IS NULL OR TRIM(province)='')" > $BK/pre-t4127-province_$ts.sql
  q "UPDATE sites SET province='BC' WHERE org_id=2 AND (province IS NULL OR TRIM(province)='')"
  echo "--- province after (org 2 only)"; sites "province,wsib_percent"
  echo "wcb_rate setting: $(q 'SELECT wcb_rate FROM payroll_settings LIMIT 1' 2>&1)"
  echo "payroll_finalized rows/maxid: $(q 'SELECT COUNT(*),MAX(id) FROM payroll_finalized')"
  echo "--- verify"; if verify $ts; then echo "DEPLOY OK ts=$ts"; else echo "VERIFY FAILED - rolling back"; rollback $ts; exit 1; fi ;;
 rollback) rollback "${2:?ts}" ;;
 *) echo "Usage: bash $0 check|go|rollback <ts>"; exit 2 ;;
esac
