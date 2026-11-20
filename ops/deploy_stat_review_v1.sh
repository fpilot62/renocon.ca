#!/bin/bash
# Ontario holiday eligibility review. Read-only until "go".
#   bash deploy_stat_review_v1.sh check
#   bash deploy_stat_review_v1.sh go
#   bash deploy_stat_review_v1.sh rollback <ts>
set -euo pipefail
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=f58c334be906b4a4b1589ee8a0f207457a52e555
STAT=ddefd95064981750a174502bd7c8c2b03d121580
LEDGER2=6c4b8fae0abe38ad43796347e408bd10b388ee89
LEDGER3=2e332ec705943bd9f9c998a1f10d3feb81e91295
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
MAN='payroll_engine/stat_holidays.php 598937bab88de36fb7d8d73ad16769ad,ac15130bce7281657f41650a6a67620c 8aac92a6bc02130a08de81e2c067ff7e
payroll_master_ledger.php 0364d96da0960959067ae54df40e9f86,8a742c2b07ffd45caaab17e129990611,1d1377f76a1baa4fa189f5c26b23f110 d97a3af30d0c1879c6482c560defe330
generate_stat_shifts.php 45258fe978ae9b0a403b68bab27bb680,b8c5122dae4f7ceed8e98f53c1cf1143 3385210d69e3e1a99f14dd2fab0b9bee
ops/t4127_stat_backfill.php 85881f9183edd90999d43393eb7b1236 7a152d9946968c7ab4d5b2acdec0ef6a
stat_holiday_review.php - 962a5f17e30d97cdadcddfb830a4dabe
migrations/20261121_stat_holiday_review.sql - 11965c4fac8296b2e4b72295265b912d'

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
    if [[ $prevs == '-' ]]; then echo "CLEAN $rel new file"; return 0; fi
    echo "MISSING $live"; return 1
  fi
  local liveMd
  liveMd=$(md "$live")
  if [[ $liveMd == "$newMd" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
  if [[ $prevs != '-' ]]; then
    local prev p
    IFS=',' read -ra prev <<< "$prevs"
    for p in "${prev[@]}"; do
      if [[ $liveMd == "$p" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
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
  local src="$BK/pre-stat-review-$ts"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
  local rel
  while read -r rel _ _; do
    [[ -n $rel ]] || continue
    if [[ -f $src/$rel ]]; then
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$src/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      echo "Restored $rel"
    elif [[ -f $APP/$rel && $rel != payroll_engine/* ]]; then
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

case "$M" in
  check)
    bad=0
    while read -r rel prevs newMd; do
      [[ -n $rel ]] || continue
      check_one "$rel" "$prevs" "$newMd" || bad=1
    done <<< "$MAN"
    [[ $bad -eq 0 ]]
    ;;
  go)
    bash "$0" check
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$BK/pre-stat-review-$TS.sql.gz"
    [[ -s $BK/pre-stat-review-$TS.sql.gz ]]
    DEST="$BK/pre-stat-review-$TS"
    mkdir -p "$DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/$rel"
      fi
    done <<< "$MAN"
    echo "Backup $BK/pre-stat-review-$TS.sql.gz and $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then keep_osg "$APP/$rel" "$STAGE/bundle/$rel"; fi
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring files"
        restore_files "$TS"
        exit 1
      fi
    done <<< "$MAN"
    if ! ${T4127_MYSQL:-mysql} "$DB" < "$APP/migrations/20261121_stat_holiday_review.sql"; then
      echo "migration failed, restoring files. Database restore: bash $0 rollback $TS"
      restore_files "$TS"
      exit 1
    fi
    smoke https://payroll.ecommi.com/ L || echo "SMOKE_WARN ecommi"
    smoke https://payroll.renocon.ca/ plain || echo "SMOKE_WARN renocon (308 without -L is expected)"
    echo "Installed holiday review from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -f $BK/pre-stat-review-$TS.sql.gz ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    gzip -dc "$BK/pre-stat-review-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
    echo "Restored database from pre-stat-review-$TS.sql.gz"
    if [[ -d $BK/pre-stat-review-$TS ]]; then restore_files "$TS"; fi
    ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 1
    ;;
esac
