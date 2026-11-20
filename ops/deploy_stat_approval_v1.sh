#!/bin/bash
# Stat holiday lines are created already calculated, then wait for approval.
# Replaces the undeployed review-list design. Read-only until "go".
#   bash deploy_stat_approval_v1.sh check
#   bash deploy_stat_approval_v1.sh dry
#   bash deploy_stat_approval_v1.sh go
#   bash deploy_stat_approval_v1.sh rollback <ts>
#
# Dry-run (also printed by go, before anything is changed):
#   unpaid statutory lines that will wait for approval
#   already-paid statutory lines that are left paid
# Approval is a separate column. payroll_finalized.status is not rewritten.
# The review table from the undeployed holiday-review change is not used.
# Approval columns on payroll_finalized are the schema this install needs.
# ops/t4127_stat_backfill.php is not a web page. deploy_stat_backfill_v1.sh
# downloads it to $STAGE/t4127_stat_backfill.php (default /root/t4127-stage)
# and runs it from there, so the web root often has no copy. A missing web
# copy is a new file. The stage copy, when it is there, must still match a
# known hash. go updates that copy, and rollback puts it back.
set -euo pipefail
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=e295ecd13ca7423fabc04dea19d83e247fee5370
STAT=4d4c6549b57d9fa2cf548f18fce2ff1fb910247b
LEDGER2=6c4b8fae0abe38ad43796347e408bd10b388ee89
LEDGER3=2e332ec705943bd9f9c998a1f10d3feb81e91295
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
MAN='payroll_engine/stat_holidays.php 7ac1cb14961182ae397a4044200b1d15,8aac92a6bc02130a08de81e2c067ff7e,598937bab88de36fb7d8d73ad16769ad,ac15130bce7281657f41650a6a67620c f59f69597dfd1163a240a3e5d8c21e9c
payroll_master_ledger.php 92a45eeb6e7a97ce69091a03bdc0c643,d97a3af30d0c1879c6482c560defe330,0364d96da0960959067ae54df40e9f86,8a742c2b07ffd45caaab17e129990611,1d1377f76a1baa4fa189f5c26b23f110,b1c676d66118298acb4f4bc02516c4ed,cd047535101b385e165ff12b91d3fa82,602706a4729b27a050362f8cb2e20be6 9f87c79b7d8cf863a4bf56dac246c88f
payroll_master_ledger1.php 298757d67946f5e7a6178f346206d699,23d06f90bf727fdb5ff4a3ed5d27bfea,8163a859e9d5e46ca179ff9a33db8790,614e8a3895693d1c08ecc69e0371b1f6,0688677ee41046e0a5cb2055b6c69f3f,742e3cd8369734bdb58c9ef83ef3b87f e0c538c8f20227272d0db00c87fecb56
generate_stat_shifts.php 9937224cc569b2875c24bd1c48c05b46,3385210d69e3e1a99f14dd2fab0b9bee,45258fe978ae9b0a403b68bab27bb680,b8c5122dae4f7ceed8e98f53c1cf1143 2552eca2bc7415edd8352d3257b4c517
generate_pay_documents.php 0f8281d8e4737fb4336f0ba6265655eb,88f43817563e848678a5544a1051f1b1,e0871e140bf263197cee45dd48425836 137d9f7ac8ec60e02883403ae0cec6c0
ops/t4127_stat_backfill.php -,7a152d9946968c7ab4d5b2acdec0ef6a,85881f9183edd90999d43393eb7b1236 85881f9183edd90999d43393eb7b1236
stat_holiday_review.php -,bb2ffd0a3b0101aeff1fff1be6d50b6f,962a5f17e30d97cdadcddfb830a4dabe a8c29507028637d3178a2171a053ef23
migrations/20261121_stat_approval.sql -,a62605dba3685a1f508cc3d4a9343ed1 6389e4e7e9e29fb491174791209fb52d'

if [[ ${T4127_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then echo "Run as root."; exit 1; fi
md() { md5sum "$1" | cut -c1-32; }
fetch_sha() {
  local sha=$1 rel=$2 dest=$3
  mkdir -p "$(dirname "$dest")"
  if [[ -n $REPO && -e $REPO/.git ]]; then
    git -C "$REPO" show "$sha:$rel" > "$dest"
    return
  fi
  curl -fsS "https://raw.githubusercontent.com/fpilot62/renocon.ca/$sha/$rel" -o "$dest"
}
keep_osg() {
  local live=$1 dest=$2
  local extra
  extra=$(grep -E 'orgscope_guard|osg_page\(|osg_cli_only\(' "$live" || true)
  if [[ -n $extra ]] && ! grep -q 'orgscope_guard' "$dest"; then
    { head -n 1 "$dest"; printf '%s\n' "$extra"; tail -n +2 "$dest"; } > "$dest.osg"
    mv "$dest.osg" "$dest"
  fi
}
only_osg() {
  python3 - "$1" "$2" << 'PY'
import sys, difflib
base = open(sys.argv[1]).read().splitlines()
live = open(sys.argv[2]).read().splitlines()
ok = True
for line in difflib.ndiff(base, live):
    if line.startswith('  ') or line.startswith('? '):
        continue
    if line.startswith('- '):
        ok = False
        break
    text = line[2:]
    if 'orgscope_guard' in text or 'osg_page(' in text or 'osg_cli_only(' in text or text.strip() == '':
        continue
    ok = False
    break
sys.exit(0 if ok else 1)
PY
}
# deploy_stat_backfill_v1.sh runs this from the stage dir, not the web root.
stage_runner() { echo "$STAGE/t4127_stat_backfill.php"; }
known_hash() {
  local prevs=$1 want=$2 newMd=$3 p
  [[ $want == "$newMd" ]] && return 0
  local prev
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    [[ $p != '-' && $want == "$p" ]] && return 0
  done
  return 1
}
check_stage_runner() {
  local prevs=$1 newMd=$2 runner runnerMd
  runner=$(stage_runner)
  [[ -f $runner ]] || return 0
  runnerMd=$(md "$runner")
  if known_hash "$prevs" "$runnerMd" "$newMd"; then
    echo "CLEAN stage runner $runner $runnerMd"
    return 0
  fi
  echo "DRIFT $runner live $runnerMd"
  return 1
}
check_one() {
  local rel=$1 prevs=$2 newMd=$3
  local bundle="$STAGE/bundle/$rel" live="$APP/$rel"
  fetch_sha "$PINNED" "$rel" "$bundle"
  local got
  got=$(md "$bundle")
  echo "bundle $rel $got"
  [[ $got == "$newMd" ]] || { echo "BUNDLE_MD5_BAD $rel want $newMd"; return 1; }
  if [[ ! -f $live ]]; then
    case ",$prevs," in
      *,-,*)
        if [[ $rel == ops/t4127_stat_backfill.php ]]; then
          check_stage_runner "$prevs" "$newMd" || return 1
        fi
        echo "CLEAN $rel new file"
        return 0
        ;;
    esac
    echo "MISSING $live"; return 1
  fi
  local liveMd
  liveMd=$(md "$live")
  if [[ $liveMd == "$newMd" ]]; then
    if [[ $rel == ops/t4127_stat_backfill.php ]]; then check_stage_runner "$prevs" "$newMd" || return 1; fi
    echo "CLEAN $rel live $liveMd"; return 0
  fi
  if [[ $prevs != '-' ]]; then
    local prev p
    IFS=',' read -ra prev <<< "$prevs"
    for p in "${prev[@]}"; do
      if [[ $p != '-' && $liveMd == "$p" ]]; then
        if [[ $rel == ops/t4127_stat_backfill.php ]]; then check_stage_runner "$prevs" "$newMd" || return 1; fi
        echo "CLEAN $rel live $liveMd"; return 0
      fi
    done
  fi
  local snap
  for snap in "$STAT" "$LEDGER2" "$LEDGER3" "$PINNED"; do
    local base="$STAGE/snap/$snap/$rel"
    if ! fetch_sha "$snap" "$rel" "$base"; then continue; fi
    if only_osg "$base" "$live"; then
      if [[ $rel == ops/t4127_stat_backfill.php ]]; then check_stage_runner "$prevs" "$newMd" || return 1; fi
      echo "CLEAN $rel org-scope lines only"; return 0
    fi
  done
  echo "DRIFT $rel live $liveMd"
  return 1
}
restore_files() {
  local ts=$1
  local src="$BK/pre-stat-approval-$ts"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
  local rel
  while read -r rel _ _; do
    [[ -n $rel ]] || continue
    if [[ -f $src/$rel ]]; then
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$src/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      echo "Restored $rel"
    elif [[ -f $APP/$rel ]]; then
      rm -f "$APP/$rel"
      echo "Removed new $rel"
    fi
  done <<< "$MAN"
  local runner
  runner=$(stage_runner)
  if [[ -f $src/stage/t4127_stat_backfill.php ]]; then
    cp -f "$src/stage/t4127_stat_backfill.php" "$runner"
    echo "Restored $runner"
  elif [[ -f $src/stage/was-absent ]]; then
    rm -f "$runner"
    echo "Removed new $runner"
  fi
}
smoke() {
  local url=$1 follow=$2 code
  if [[ $follow == L ]]; then
    code=$(curl -sS -L -o /dev/null -w '%{http_code}' --max-time 25 "$url" || echo 000)
  else
    code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "$url" || echo 000)
  fi
  echo "smoke $url $code"
  case $code in
    200|301|302|303|307|308|401|403) return 0 ;;
    *) return 1 ;;
  esac
}
schema_report() {
  echo "payroll_finalized.status:"
  ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT CONCAT(DATA_TYPE, ' ', COLUMN_TYPE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='status'" \
    || echo "SCHEMA_WARN could not read payroll_finalized.status"
  echo "approval columns already present:"
  ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME IN ('stat_calculated_amount','stat_approved_amount','stat_approved_by','stat_approved_at','stat_edited_by','stat_edit_reason','stat_flag','stat_approval_status') ORDER BY COLUMN_NAME" \
    || echo "SCHEMA_WARN could not list approval columns"
}
dry_counts() {
  local has
  has=$(${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_finalized' AND COLUMN_NAME='stat_approval_status'" || echo 0)
  echo "Dry-run unpaid stat lines that will wait for approval:"
  if [[ $has == 1 ]]; then
    ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM payroll_finalized WHERE category='Statutory Holiday' AND COALESCE(paid_amount,0)<=0.02 AND LOWER(COALESCE(status,'')) NOT IN ('paid','remitted') AND (stat_approval_status IS NULL OR stat_approval_status='')"
  else
    ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM payroll_finalized WHERE category='Statutory Holiday' AND COALESCE(paid_amount,0)<=0.02 AND LOWER(COALESCE(status,'')) NOT IN ('paid','remitted')"
  fi
  echo "Already-paid stat lines left unchanged:"
  ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM payroll_finalized WHERE category='Statutory Holiday' AND (COALESCE(paid_amount,0)>0.02 OR LOWER(COALESCE(status,'')) IN ('paid','remitted'))"
}

case "$M" in
  check)
    bad=0
    while read -r rel prevs newMd; do
      [[ -n $rel ]] || continue
      check_one "$rel" "$prevs" "$newMd" || bad=1
    done <<< "$MAN"
    [[ $bad -eq 0 ]]
    schema_report
    ;;
  dry)
    dry_counts
    ;;
  go)
    bash "$0" check
    dry_counts
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$BK/pre-stat-approval-$TS.sql.gz"
    [[ -s $BK/pre-stat-approval-$TS.sql.gz ]]
    DEST="$BK/pre-stat-approval-$TS"
    mkdir -p "$DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/$rel"
      fi
    done <<< "$MAN"
    echo "Backup $BK/pre-stat-approval-$TS.sql.gz and $DEST"
    runner=$(stage_runner)
    mkdir -p "$DEST/stage"
    if [[ -f $runner ]]; then
      cp -f "$runner" "$DEST/stage/t4127_stat_backfill.php"
    else
      : > "$DEST/stage/was-absent"
    fi
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then keep_osg "$APP/$rel" "$STAGE/bundle/$rel"; fi
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if [[ $rel == *.php ]]; then
        if ! php -l "$APP/$rel"; then
          echo "php -l failed, restoring files"
          restore_files "$TS"
          exit 1
        fi
      fi
    done <<< "$MAN"
    cp -f "$STAGE/bundle/ops/t4127_stat_backfill.php" "$runner"
    if ! php -l "$runner"; then
      echo "php -l failed on the stage backfill runner, restoring files"
      restore_files "$TS"
      exit 1
    fi
    if ! ${T4127_MYSQL:-mysql} "$DB" < "$APP/migrations/20261121_stat_approval.sql"; then
      echo "================================================================"
      echo "MIGRATION FAILED. Restoring the database from the backup just taken."
      echo "If that restore fails, run these two commands:"
      echo "gzip -dc $BK/pre-stat-approval-$TS.sql.gz | ${T4127_MYSQL:-mysql} $DB"
      echo "bash $0 rollback $TS"
      echo "================================================================"
      if gzip -dc "$BK/pre-stat-approval-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"; then
        echo "Database restored from pre-stat-approval-$TS.sql.gz"
      else
        echo "DATABASE RESTORE FAILED. Run this yourself:"
        echo "gzip -dc $BK/pre-stat-approval-$TS.sql.gz | ${T4127_MYSQL:-mysql} $DB"
      fi
      restore_files "$TS"
      exit 1
    fi
    smoke https://payroll.ecommi.com/ L || echo "SMOKE_WARN ecommi"
    smoke https://payroll.renocon.ca/ plain || echo "SMOKE_WARN renocon (308 without -L is expected)"
    echo "Installed stat holiday approval from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -f $BK/pre-stat-approval-$TS.sql.gz ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    gzip -dc "$BK/pre-stat-approval-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
    echo "Restored database from pre-stat-approval-$TS.sql.gz"
    if [[ -d $BK/pre-stat-approval-$TS ]]; then restore_files "$TS"; fi
    ;;
  *)
    echo "Usage: bash $0 check|dry|go|rollback <ts>"
    exit 1
    ;;
esac
