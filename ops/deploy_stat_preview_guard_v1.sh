#!/bin/bash
# One Stat Holiday Pay page, plus exclusions so a removed line is not offered again.
# Read-only until "go".
#   bash deploy_stat_preview_guard_v1.sh check
#   bash deploy_stat_preview_guard_v1.sh dry
#   bash deploy_stat_preview_guard_v1.sh go
#   bash deploy_stat_preview_guard_v1.sh rollback <ts>
set -euo pipefail
# TARGET=staging|live. Default is live. See ops/deploy_target.inc.sh.
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=b6107efa723c86ddd263000558e1b10bf7cdb81c
PREV=e295ecd13ca7423fabc04dea19d83e247fee5370
MAN='payroll_engine/stat_holidays.php f59f69597dfd1163a240a3e5d8c21e9c,7ac1cb14961182ae397a4044200b1d15,8aac92a6bc02130a08de81e2c067ff7e,ac15130bce7281657f41650a6a67620c f1d7b3c8e7641637857ff5496d43cd5c
generate_stat_shifts.php 2552eca2bc7415edd8352d3257b4c517,9937224cc569b2875c24bd1c48c05b46,3385210d69e3e1a99f14dd2fab0b9bee 0fc18412303524d6e6f3110a475e5d3a
payroll_master_ledger.php 9f87c79b7d8cf863a4bf56dac246c88f,92a45eeb6e7a97ce69091a03bdc0c643,d97a3af30d0c1879c6482c560defe330,0364d96da0960959067ae54df40e9f86 a0552472794688e74dfb92a7690c7bd4
stat_holiday_review.php a8c29507028637d3178a2171a053ef23,bb2ffd0a3b0101aeff1fff1be6d50b6f,- 1415a9d31ee4bfddae2d649d21e6ba47
ops/t4127_stat_backfill.php 85881f9183edd90999d43393eb7b1236,7a152d9946968c7ab4d5b2acdec0ef6a,- b1a8c9eb86ad5cf78674bf69e6078933
migrations/20261122_stat_holiday_exclusions.sql - 2d3e6d5f949750b28366cced0f86dde5'

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
  local prev p
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    if [[ $p != '-' && $liveMd == "$p" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
  done
  local snap
  for snap in "$PREV" "$PINNED"; do
    local base="$STAGE/snap/$snap/$rel"
    if ! fetch_sha "$snap" "$rel" "$base"; then continue; fi
    if only_osg "$base" "$live"; then echo "CLEAN $rel org-scope lines only"; return 0; fi
  done
  echo "DRIFT $rel live $liveMd"
  return 1
}
restore_files() {
  local ts=$1 src="$BK/pre-stat-preview-$ts"
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
dry_report() {
  echo "Thanksgiving exclusion already stored (1 means the seed is present):"
  ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stat_holiday_exclusions'" | grep -q '^1$' \
    && ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM stat_holiday_exclusions WHERE org_id=4 AND user_id=10766 AND site_id=121 AND holiday_date='2026-10-12'" \
    || echo "0 (table not created yet; go will insert the seed)"
  echo "Statutory lines still stored for that employee and holiday:"
  ${T4127_MYSQL:-mysql} -N "$DB" -e "SELECT COUNT(*) FROM payroll_finalized WHERE org_id=4 AND user_id=10766 AND site_id=121 AND work_date='2026-10-12' AND category='Statutory Holiday'" || echo "SCHEMA_WARN"
}
smoke() {
  local url=$1 follow=$2 code
  if [[ $follow == L ]]; then code=$(curl -sS -L -o /dev/null -w '%{http_code}' --max-time 25 "$url" || echo 000)
  else code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "$url" || echo 000); fi
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
  dry)
    dry_report
    ;;
  go)
    bash "$0" check
    dry_report
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$BK/pre-stat-preview-$TS.sql.gz"
    [[ -s $BK/pre-stat-preview-$TS.sql.gz ]]
    DEST="$BK/pre-stat-preview-$TS"
    mkdir -p "$DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/$rel"
      fi
    done <<< "$MAN"
    echo "Backup $BK/pre-stat-preview-$TS.sql.gz and $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
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
    if ! ${T4127_MYSQL:-mysql} "$DB" < "$APP/migrations/20261122_stat_holiday_exclusions.sql"; then
      echo "================================================================"
      echo "MIGRATION FAILED. Restoring the database from the backup just taken."
      echo "gzip -dc $BK/pre-stat-preview-$TS.sql.gz | ${T4127_MYSQL:-mysql} $DB"
      echo "bash $0 rollback $TS"
      echo "================================================================"
      gzip -dc "$BK/pre-stat-preview-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB" || echo "DATABASE RESTORE FAILED"
      restore_files "$TS"
      exit 1
    fi
    smoke "${PAYROLL_URL:-https://payroll.ecommi.com}/" L || echo "SMOKE_WARN primary"
    if [[ ${TARGET:-live} == live ]]; then
      smoke https://payroll.renocon.ca/ plain || echo "SMOKE_WARN renocon (308 without -L is expected)"
    fi
    echo "Installed Stat Holiday Pay page from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -f $BK/pre-stat-preview-$TS.sql.gz ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    gzip -dc "$BK/pre-stat-preview-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
    echo "Restored database from pre-stat-preview-$TS.sql.gz"
    if [[ -d $BK/pre-stat-preview-$TS ]]; then restore_files "$TS"; fi
    ;;
  *)
    echo "Usage: bash $0 check|dry|go|rollback <ts>"
    exit 1
    ;;
esac
