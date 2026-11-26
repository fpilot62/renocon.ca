#!/bin/bash
# Sheet Review: sheet view, inline draft editing with an audit log, the source
# cells side panel, and the drilldown toggles.
# Read-only until "go". Does not approve payroll and does not mark anyone paid.
#   bash deploy_sheet_edit_v1.sh check
#   bash deploy_sheet_edit_v1.sh go
#   bash deploy_sheet_edit_v1.sh rollback <ts> [drop-tables]
#   bash deploy_sheet_edit_v1.sh cells-check <cells.csv>   (read-only)
#   bash deploy_sheet_edit_v1.sh cells-go <cells.csv>      (data load, own approval)
#   bash deploy_sheet_edit_v1.sh cells-rollback <ts>
#
# Source of the files: a bundle tarball (SE_BUNDLE, default
# /root/sheet_edit_bundle.tar.gz) or, when no bundle exists, the commit named in
# SE_PINNED fetched from GitHub (or SE_REPO, a local checkout).
#
# check: each live file must be a known previous hash, already the new file, or
# the same file plus org-scope lines. Anything else is DRIFT. check then stops
# and lists those files. Nothing is written.
# go: runs check, backs up the whole database and every file it replaces to
# /root/payroll-daily/pre-sheetedit-<ts>, installs, runs php -l on each PHP
# file (a failure restores the files), then creates the two new tables from the
# migration. The migration only adds payroll_sheet_cells and
# payroll_sheet_edit_audit. No existing table or row is changed.
# rollback restores the files and removes the new ones. The two new tables stay
# unless you add drop-tables, so the audit log is not lost by accident.
# cells-go loads the workbook cells into payroll_sheet_cells only. It is a
# separate step so the code can go live without any data load.
set -euo pipefail
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir
M=${1:-check}
ARG=${2:-}
OPT=${3:-}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
DB=${PAYROLL_DB:-admin_payroll}
OWN=${PAYROLL_OWN:-renocon:renocon}
ORG=${SHEET_ORG:-2}
BUNDLE=${SE_BUNDLE:-/root/sheet_edit_bundle.tar.gz}
PINNED=${SE_PINNED:-}
REPO=${SE_REPO:-}
BASEREF=${SE_BASE:-}
# rel  previous-live-md5s  new-md5  (- = new file)
MAN='payroll_sheet_review.php 1bf6d40aab7d98d82e8129f44df79417 cfa8e00475dc3d34dc6d74901d131a06
payroll_sheet_sheet.php - 5a3962e25ce3388b3fd808802144ab8e
engine/payroll_sheet_edit_lib.php - d7648faf7b7cf9dbbdf37a67d44e74fa
migrations/20261126_payroll_sheet_edit_source.sql - ebe078266937858d52894ba07be4e476
ops/load_sheet_cells.php - c8f18b270b9ddac98841a983c20f5ed9'
BASE_PAGE_MD5=1bf6d40aab7d98d82e8129f44df79417
NEEDED='engine/payroll_sheet_review_lib.php engine/payroll_sheet_grid_lib.php payroll_scope.php db.php'

if [[ ${T4127_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then echo "Run as root."; exit 1; fi
command -v php >/dev/null || { echo "php must be on PATH."; exit 1; }
md() { md5sum "$1" | cut -c1-32; }
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/src"

fetch_src() {
  local rel=$1 dest="$STAGE/src/$1"
  mkdir -p "$(dirname "$dest")"
  [[ -f $dest ]] && return 0
  if [[ -f $BUNDLE ]]; then
    [[ -d $STAGE/untar ]] || { mkdir -p "$STAGE/untar"; tar -xzf "$BUNDLE" -C "$STAGE/untar"; }
    cp -f "$STAGE/untar/$rel" "$dest"
  elif [[ -n $PINNED ]]; then
    local ref=$PINNED path=$rel
    if [[ $rel == base/* ]]; then
      [[ -n $BASEREF ]] || { echo "SE_BASE (the commit holding the live snapshot of the page) is not set." >&2; return 1; }
      ref=$BASEREF
      path=${rel#base/}
    fi
    if [[ -n $REPO && -e $REPO/.git ]]; then
      git -C "$REPO" show "$ref:$path" > "$dest"
    else
      curl -fsS "https://raw.githubusercontent.com/fpilot62/renocon.ca/$ref/$path" -o "$dest"
    fi
  else
    echo "No bundle at $BUNDLE and SE_PINNED is not set." >&2
    return 1
  fi
}
keep_osg() {
  local live=$1 dest=$2 extra
  [[ -f $live ]] || return 0
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
for line in difflib.ndiff(base, live):
    if line.startswith('  ') or line.startswith('? '):
        continue
    if line.startswith('- '):
        sys.exit(1)
    text = line[2:]
    if 'orgscope_guard' in text or 'osg_page(' in text or 'osg_cli_only(' in text or text.strip() == '':
        continue
    sys.exit(1)
sys.exit(0)
PY
}
known_hash() {
  local prevs=$1 want=$2 newMd=$3 p prev
  [[ $want == "$newMd" ]] && return 0
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    [[ $p != '-' && $want == "$p" ]] && return 0
  done
  return 1
}
check_one() {
  local rel=$1 prevs=$2 newMd=$3
  fetch_src "$rel" || return 1
  local src="$STAGE/src/$rel" live="$APP/$rel" got
  got=$(md "$src")
  echo "source $rel $got"
  [[ $got == "$newMd" ]] || { echo "SOURCE_MD5_BAD $rel want $newMd"; return 1; }
  if [[ $rel == *.php ]] && ! php -l "$src" >/dev/null; then echo "PHP_LINT $rel"; return 1; fi
  if [[ ! -f $live ]]; then
    case ",$prevs," in
      *,-,*) echo "CLEAN $rel new file"; return 0 ;;
    esac
    echo "DRIFT $rel MISSING $live"
    return 1
  fi
  local liveMd
  liveMd=$(md "$live")
  if known_hash "$prevs" "$liveMd" "$newMd"; then
    echo "CLEAN $rel live $liveMd"
    return 0
  fi
  if [[ $rel == payroll_sheet_review.php ]]; then
    fetch_src base/payroll_sheet_review.php || return 1
    local baseMd
    baseMd=$(md "$STAGE/src/base/payroll_sheet_review.php")
    [[ $baseMd == "$BASE_PAGE_MD5" ]] || { echo "BASE_MD5_BAD base page $baseMd"; return 1; }
    if only_osg "$STAGE/src/base/payroll_sheet_review.php" "$live"; then
      echo "CLEAN $rel org-scope lines only"
      return 0
    fi
  fi
  echo "DRIFT $rel live $liveMd"
  return 1
}
check_all() {
  local bad=0 rel prevs newMd need
  for need in $NEEDED; do
    [[ -f $APP/$need ]] || { echo "MISSING_DEPENDENCY $need"; bad=1; }
  done
  while read -r rel prevs newMd; do
    [[ -n $rel ]] || continue
    check_one "$rel" "$prevs" "$newMd" || bad=1
  done <<< "$MAN"
  if [[ $bad -ne 0 ]]; then
    echo "STOP. Nothing was installed. Review each DRIFT or MISSING line above."
    echo "A drifted file needs its live lines merged into the new file before go."
    return 1
  fi
  echo "check clean."
}
restore_files() {
  local ts=$1 rel src
  src="$BK/pre-sheetedit-$ts"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
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
dump() { ${SE_MYSQLDUMP:-mysqldump} --single-transaction "$DB" "$@"; }
sql() { ${SE_MYSQL:-mysql} "$DB" "$@"; }

case "$M" in
  check)
    check_all
    ;;
  go)
    check_all
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    dump | gzip -c > "$BK/pre-sheetedit-$TS.sql.gz"
    [[ -s $BK/pre-sheetedit-$TS.sql.gz ]]
    DEST="$BK/pre-sheetedit-$TS"
    mkdir -p "$DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/$rel"
      fi
    done <<< "$MAN"
    echo "Backup $BK/pre-sheetedit-$TS.sql.gz and $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ $rel == payroll_sheet_review.php && -f $APP/$rel ]]; then keep_osg "$APP/$rel" "$STAGE/src/$rel"; fi
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/src/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if [[ $rel == *.php ]] && ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring files"
        restore_files "$TS"
        exit 1
      fi
    done <<< "$MAN"
    if ! sql < "$APP/migrations/20261126_payroll_sheet_edit_source.sql"; then
      echo "Migration failed, restoring files. The database backup is $BK/pre-sheetedit-$TS.sql.gz"
      restore_files "$TS"
      exit 1
    fi
    sql -e "SELECT 'payroll_sheet_cells' t, COUNT(*) n FROM payroll_sheet_cells UNION ALL SELECT 'payroll_sheet_edit_audit', COUNT(*) FROM payroll_sheet_edit_audit"
    echo "Installed. Open payroll_sheet_review.php and payroll_sheet_sheet.php as an admin. Nothing was approved or marked paid."
    echo "Next, optional data step: bash $0 cells-check <cells.csv>"
    echo "Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    [[ -n $ARG ]] || { echo "Usage: $0 rollback <ts> [drop-tables]"; exit 1; }
    restore_files "$ARG"
    if [[ $OPT == drop-tables ]]; then
      sql -e "DROP TABLE IF EXISTS payroll_sheet_edit_audit, payroll_sheet_cells"
      echo "Dropped payroll_sheet_edit_audit and payroll_sheet_cells."
    else
      echo "Tables payroll_sheet_edit_audit and payroll_sheet_cells were kept. Add drop-tables to remove them."
    fi
    ;;
  cells-check)
    [[ -f $ARG ]] || { echo "Usage: $0 cells-check <cells.csv>"; exit 1; }
    fetch_src ops/load_sheet_cells.php
    php "$STAGE/src/ops/load_sheet_cells.php" dry "$ORG" "$ARG"
    ;;
  cells-go)
    [[ -f $ARG ]] || { echo "Usage: $0 cells-go <cells.csv>"; exit 1; }
    fetch_src ops/load_sheet_cells.php
    php "$STAGE/src/ops/load_sheet_cells.php" dry "$ORG" "$ARG"
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    dump payroll_sheet_cells | gzip -c > "$BK/pre-sheetcells-$TS.sql.gz"
    [[ -s $BK/pre-sheetcells-$TS.sql.gz ]]
    echo "Backup $BK/pre-sheetcells-$TS.sql.gz"
    php "$STAGE/src/ops/load_sheet_cells.php" apply "$ORG" "$ARG"
    sql -e "SELECT sheet_name, COUNT(*) cells, MIN(row_num) first_row, MAX(row_num) last_row FROM payroll_sheet_cells WHERE org_id = $ORG GROUP BY sheet_name"
    echo "Rollback: bash $0 cells-rollback $TS"
    ;;
  cells-rollback)
    [[ -n $ARG && -s $BK/pre-sheetcells-$ARG.sql.gz ]] || { echo "No backup $BK/pre-sheetcells-$ARG.sql.gz"; exit 1; }
    gunzip -c "$BK/pre-sheetcells-$ARG.sql.gz" | sql
    echo "Restored payroll_sheet_cells from $BK/pre-sheetcells-$ARG.sql.gz"
    ;;
  *)
    echo "Usage: $0 check|go|rollback <ts> [drop-tables]|cells-check <csv>|cells-go <csv>|cells-rollback <ts>"
    exit 1
    ;;
esac
