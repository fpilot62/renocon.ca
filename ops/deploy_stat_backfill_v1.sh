#!/bin/bash
# Statutory holiday backfill for org 4 (pass another org id to override).
# Read-only until "apply". Does not mark anything paid.
#   bash deploy_stat_backfill_v1.sh check
#   bash deploy_stat_backfill_v1.sh dry [org]
#   bash deploy_stat_backfill_v1.sh apply [org]
#   bash deploy_stat_backfill_v1.sh rollback <ts>
set -euo pipefail
# TARGET=staging|live. Default is live. See ops/deploy_target.inc.sh.
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir
M=${1:-check}
ORG=${2:-4}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=79c0e2d48e74b5490c9767000a56fd1c6a47851e
OLD=998e33239f9b2c8482c9af986b0722e11e0d7a40
MID=90c73068bfe09df25b00f1526ea414d4786c9add
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
PHP_MD5=85881f9183edd90999d43393eb7b1236
# path | earlier md5s | this deploy's md5
MAN='payroll_engine/stat_holidays.php 1cb3906b1f306b6fc2cc3c6c83c9ed9a,ac15130bce7281657f41650a6a67620c 598937bab88de36fb7d8d73ad16769ad
payroll_engine/payroll_engine.php 4ee54c1c2e75f3791cc5b1a369d4861f 9eabae66752770e5daca5d64cfbbedf6'

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
  local got liveMd
  got=$(md "$bundle")
  echo "bundle $rel $got"
  [[ $got == "$newMd" ]] || { echo "BUNDLE_MD5_BAD $rel want $newMd"; return 1; }
  [[ -f $live ]] || { echo "MISSING $live"; return 1; }
  liveMd=$(md "$live")
  if [[ $liveMd == "$newMd" ]]; then
    echo "CLEAN $rel live $liveMd"
    return 0
  fi
  local prev p
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    if [[ $liveMd == "$p" ]]; then
      echo "CLEAN $rel live $liveMd"
      return 0
    fi
  done
  local snap
  for snap in "$OLD" "$MID" "$PINNED"; do
    local base="$STAGE/snap/$snap/$rel"
    fetch_sha "$snap" "$rel" "$base"
    if only_osg "$base" "$live"; then
      echo "CLEAN $rel org-scope lines only"
      return 0
    fi
  done
  echo "DRIFT $rel live $liveMd"
  diff -u "$STAGE/snap/$OLD/$rel" "$live" | head -20 || true
  return 1
}
run_check() {
  local bad=0 rel prevs newMd
  while read -r rel prevs newMd; do
    [[ -n $rel ]] || continue
    check_one "$rel" "$prevs" "$newMd" || bad=1
  done <<< "$MAN"
  [[ $bad -eq 0 ]]
}
restore_files() {
  local ts=$1
  local src="$BK/pre-stat-backfill-$ts"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
  local rel
  while read -r rel _ _; do
    [[ -n $rel ]] || continue
    [[ -f $src/$rel ]] || { echo "Backup missing $rel"; return 1; }
    mkdir -p "$APP/$(dirname "$rel")"
    cp -f "$src/$rel" "$APP/$rel"
    chown "$OWN" "$APP/$rel" 2>/dev/null || true
    echo "Restored $rel"
  done <<< "$MAN"
}
fetch_runner() {
  fetch_sha "$PINNED" ops/t4127_stat_backfill.php "$STAGE/t4127_stat_backfill.php"
  local got
  got=$(md "$STAGE/t4127_stat_backfill.php")
  [[ $got == "$PHP_MD5" ]] || { echo "BUNDLE_MD5_BAD t4127_stat_backfill.php want $PHP_MD5 got $got"; return 1; }
}

case "$M" in
  check)
    run_check
    ;;
  dry)
    fetch_runner
    PAYROLL_APP="$APP" php "$STAGE/t4127_stat_backfill.php" dry "$ORG"
    ;;
  apply)
    run_check
    fetch_runner
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$BK/pre-stat-backfill-$TS.sql.gz"
    [[ -s $BK/pre-stat-backfill-$TS.sql.gz ]]
    DEST="$BK/pre-stat-backfill-$TS"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      mkdir -p "$DEST/$(dirname "$rel")"
      cp -f "$APP/$rel" "$DEST/$rel"
    done <<< "$MAN"
    echo "Backup $BK/pre-stat-backfill-$TS.sql.gz"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      keep_osg "$APP/$rel" "$STAGE/bundle/$rel"
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      php -l "$APP/$rel"
    done <<< "$MAN"
    if ! PAYROLL_APP="$APP" php "$STAGE/t4127_stat_backfill.php" apply "$ORG"; then
      echo "apply failed, restoring files. Database transaction was rolled back."
      echo "Full restore: bash $0 rollback $TS"
      restore_files "$TS"
      exit 1
    fi
    echo "Stat backfill applied for org $ORG. Restore: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -f $BK/pre-stat-backfill-$TS.sql.gz ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    gzip -dc "$BK/pre-stat-backfill-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
    echo "Restored database from pre-stat-backfill-$TS.sql.gz"
    if [[ -d $BK/pre-stat-backfill-$TS ]]; then
      restore_files "$TS"
    fi
    ;;
  *)
    echo "Usage: bash $0 check|dry [org]|apply [org]|rollback <ts>"
    exit 1
    ;;
esac
