#!/bin/bash
# Fill stat_flag on statutory lines that are already waiting for approval.
# Read-only until "go". Writes stat_flag only. Does not change amounts,
# status, approval fields, or delete a row.
#   bash deploy_stat_flag_existing_v1.sh check
#   bash deploy_stat_flag_existing_v1.sh dry
#   bash deploy_stat_flag_existing_v1.sh go
#   bash deploy_stat_flag_existing_v1.sh rollback <ts>
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
REPO=${T4127_REPO:-}
PINNED=c707ea750ce2b229a007b6c96b19b2eab5a6fd6f
ENGINE_SHA=e295ecd13ca7423fabc04dea19d83e247fee5370
PHP_MD5=25806f09bdaed3ac3aa725be873ffbb6
ENGINE_MD5=f59f69597dfd1163a240a3e5d8c21e9c
RUNNER=ops/stat_flag_existing.php
ENGINE=payroll_engine/stat_holidays.php

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
fetch_runner() {
  fetch_sha "$PINNED" "$RUNNER" "$STAGE/stat_flag_existing.php"
  local got
  got=$(md "$STAGE/stat_flag_existing.php")
  echo "bundle $RUNNER $got"
  [[ $got == "$PHP_MD5" ]] || { echo "BUNDLE_MD5_BAD $RUNNER want $PHP_MD5"; return 1; }
}
check_engine() {
  local live="$APP/$ENGINE"
  [[ -f $live ]] || { echo "MISSING $live"; return 1; }
  local liveMd
  liveMd=$(md "$live")
  if [[ $liveMd == "$ENGINE_MD5" ]]; then
    echo "CLEAN $ENGINE live $liveMd"
    return 0
  fi
  local base="$STAGE/snap/$ENGINE_SHA/$ENGINE"
  fetch_sha "$ENGINE_SHA" "$ENGINE" "$base"
  if only_osg "$base" "$live"; then
    echo "CLEAN $ENGINE org-scope lines only"
    return 0
  fi
  echo "DRIFT $ENGINE live $liveMd"
  return 1
}

case "$M" in
  check)
    fetch_runner
    check_engine
    ;;
  dry)
    fetch_runner
    check_engine
    PAYROLL_APP="$APP" php "$STAGE/stat_flag_existing.php" dry
    ;;
  go)
    bash "$0" check
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$BK/pre-stat-flag-$TS.sql.gz"
    [[ -s $BK/pre-stat-flag-$TS.sql.gz ]]
    TSV="$BK/pre-stat-flag-$TS.tsv"
    echo "Backup $BK/pre-stat-flag-$TS.sql.gz"
    if ! PAYROLL_APP="$APP" php "$STAGE/stat_flag_existing.php" apply "$TSV"; then
      echo "================================================================"
      echo "FLAG UPDATE FAILED. The database transaction was rolled back."
      echo "Full restore, if you still want the backup:"
      echo "gzip -dc $BK/pre-stat-flag-$TS.sql.gz | ${T4127_MYSQL:-mysql} $DB"
      echo "================================================================"
      exit 1
    fi
    echo "Flag backup $TSV"
    echo "Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    TSV="$BK/pre-stat-flag-$TS.tsv"
    [[ -n $TS && -f $TSV ]] || { echo "Usage: bash $0 rollback <ts>"; echo "Missing $TSV"; exit 1; }
    fetch_runner
    PAYROLL_APP="$APP" php "$STAGE/stat_flag_existing.php" rollback "$TSV"
    echo "Restored stat_flag from $TSV"
    echo "The full database backup is still at $BK/pre-stat-flag-$TS.sql.gz and was not restored."
    ;;
  *)
    echo "Usage: bash $0 check|dry|go|rollback <ts>"
    exit 1
    ;;
esac
