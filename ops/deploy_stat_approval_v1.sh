#!/bin/bash
# Stat holiday lines are created already calculated, then wait for approval.
# Replaces the undeployed review-list design. Read-only until "go".
#   bash deploy_stat_approval_v1.sh check
#   bash deploy_stat_approval_v1.sh dry
#   bash deploy_stat_approval_v1.sh go
#   bash deploy_stat_approval_v1.sh rollback <ts>
#
# Dry-run (also printed by go, before anything is changed):
#   unpaid statutory lines that will become 'needs approval'
#   already-paid statutory lines that are left paid
# The review table from the undeployed holiday-review change is not used.
# Approval columns on payroll_finalized are the schema this install needs.
set -euo pipefail
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=3901ad2b81dc356f818b0a03e8575bb400c7b87f
STAT=4d4c6549b57d9fa2cf548f18fce2ff1fb910247b
LEDGER2=6c4b8fae0abe38ad43796347e408bd10b388ee89
LEDGER3=2e332ec705943bd9f9c998a1f10d3feb81e91295
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
MAN='payroll_engine/stat_holidays.php 8aac92a6bc02130a08de81e2c067ff7e,598937bab88de36fb7d8d73ad16769ad,ac15130bce7281657f41650a6a67620c 7ac1cb14961182ae397a4044200b1d15
payroll_master_ledger.php d97a3af30d0c1879c6482c560defe330,0364d96da0960959067ae54df40e9f86,8a742c2b07ffd45caaab17e129990611,1d1377f76a1baa4fa189f5c26b23f110,b1c676d66118298acb4f4bc02516c4ed,cd047535101b385e165ff12b91d3fa82,602706a4729b27a050362f8cb2e20be6 92a45eeb6e7a97ce69091a03bdc0c643
payroll_master_ledger1.php 23d06f90bf727fdb5ff4a3ed5d27bfea,8163a859e9d5e46ca179ff9a33db8790,614e8a3895693d1c08ecc69e0371b1f6,0688677ee41046e0a5cb2055b6c69f3f,742e3cd8369734bdb58c9ef83ef3b87f 298757d67946f5e7a6178f346206d699
generate_stat_shifts.php 3385210d69e3e1a99f14dd2fab0b9bee,45258fe978ae9b0a403b68bab27bb680,b8c5122dae4f7ceed8e98f53c1cf1143 9937224cc569b2875c24bd1c48c05b46
generate_pay_documents.php 88f43817563e848678a5544a1051f1b1,e0871e140bf263197cee45dd48425836 0f8281d8e4737fb4336f0ba6265655eb
ops/t4127_stat_backfill.php 7a152d9946968c7ab4d5b2acdec0ef6a,85881f9183edd90999d43393eb7b1236 85881f9183edd90999d43393eb7b1236
stat_holiday_review.php -,962a5f17e30d97cdadcddfb830a4dabe bb2ffd0a3b0101aeff1fff1be6d50b6f
migrations/20261121_stat_approval.sql - a62605dba3685a1f508cc3d4a9343ed1'

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
      *,-,*) echo "CLEAN $rel new file"; return 0 ;;
    esac
    echo "MISSING $live"; return 1
  fi
  local liveMd
  liveMd=$(md "$live")
  if [[ $liveMd == "$newMd" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
  if [[ $prevs != '-' ]]; then
    local prev p
    IFS=',' read -ra prev <<< "$prevs"
    for p in "${prev[@]}"; do
      if [[ $p != '-' && $liveMd == "$p" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
    done
  fi
  local snap
  for snap in "$STAT" "$LEDGER2" "$LEDGER3" "$PINNED"; do
    local base="$STAGE/snap/$snap/$rel"
    if ! fetch_sha "$snap" "$rel" "$base"; then continue; fi
    if only_osg "$base" "$live"; then echo "CLEAN $rel org-scope lines only"; return 0; fi
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
dry_counts() {
  echo "Dry-run unpaid stat lines that will wait for approval:"
  ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM payroll_finalized WHERE category='Statutory Holiday' AND COALESCE(paid_amount,0)<=0.02 AND LOWER(COALESCE(status,'')) NOT IN ('paid','remitted','needs approval')"
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
    if ! ${T4127_MYSQL:-mysql} "$DB" < "$APP/migrations/20261121_stat_approval.sql"; then
      echo "migration failed, restoring files. Database restore: bash $0 rollback $TS"
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
