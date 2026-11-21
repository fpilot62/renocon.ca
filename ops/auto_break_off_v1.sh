#!/bin/bash
# Turn automatic meal breaks off and remove every source='auto' row.
# Punched breaks are not deleted. Paid and cheque-printed lines are not changed.
# Read-only until "go".
#   bash auto_break_off_v1.sh check
#   bash auto_break_off_v1.sh dry
#   bash auto_break_off_v1.sh go
#   bash auto_break_off_v1.sh rollback <ts>
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
PINNED=a99ca03934d86124c2b7f1be601689fd87b1f52c
PREV=f180c5e827bdb9dd1118163d4be01a220c77e7b3
# rel  accepted-live-md5s  pinned-md5  core|opt|new
MAN='engine/auto_break_lib.php 62a6ddb7bcb2ced62a7c95d7ac3b42c2 005e7319bfb4c0c00265efd2e379f9e5 core
engine/break_waiver_lib.php 146d4c85c2f4f14c9ad26967c601d205 8a774c9608d1a9eb76e82e54584c3570 core
break_waiver_finalize_guard.php 2db8a8c23585c39ba2c7e6444855bf55 070da027676e40d10a09a1b0df96d104 core
engine/auto_break_switch.php - 611a25e0011191251370be4ba4e7a6e5 new
engine/auto_break_off_lib.php - 2b41286a39fa22615a266dc172b35c1f new
ops/auto_break_off.php - 44ab064dc2a8a9138f6457e16de7aabd new
no_break_request.php 45d792ab5460134d501d69e63f6b1561 a0f0dd5bdc68c6929a6121de341f8439 opt
staff_audit.php 3325ecb85b0bd6d1b30d98c29e47f2dc 4408aa25f1a005d7682e180606acacc9 opt'

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
class_of() {
  local rel=$1 prevs=$2 newMd=$3 role=$4
  local bundle="$STAGE/bundle/$rel" live="$APP/$rel"
  fetch_sha "$PINNED" "$rel" "$bundle"
  local got
  got=$(md "$bundle")
  echo "bundle $rel $got" >&2
  if [[ $got != "$newMd" ]]; then
    echo "BUNDLE_MD5_BAD $rel want $newMd" >&2
    echo fail
    return
  fi
  if [[ ! -f $live ]]; then
    if [[ $role == new ]]; then
      echo "CLEAN $rel new file" >&2
      echo install
    elif [[ $role == opt ]]; then
      echo "SKIP $rel MISSING" >&2
      echo skip
    else
      echo "CORE_MISSING $rel" >&2
      echo fail
    fi
    return
  fi
  local liveMd
  liveMd=$(md "$live")
  if [[ $liveMd == "$newMd" ]]; then
    echo "CLEAN $rel live $liveMd" >&2
    echo install
    return
  fi
  if [[ $prevs != "-" ]]; then
    local prev p
    IFS=',' read -ra prev <<< "$prevs"
    for p in "${prev[@]}"; do
      if [[ $liveMd == "$p" ]]; then
        echo "CLEAN $rel live $liveMd" >&2
        echo install
        return
      fi
    done
  fi
  if [[ $role != new && $prevs != "-" ]]; then
    local base="$STAGE/snap/$PREV/$rel"
    if fetch_sha "$PREV" "$rel" "$base" && only_osg "$base" "$live"; then
      echo "CLEAN $rel org-scope lines only" >&2
      echo install
      return
    fi
  fi
  if [[ $role == opt ]]; then
    echo "SKIP $rel DRIFT live $liveMd" >&2
    echo skip
  else
    echo "CORE_DRIFT $rel live $liveMd" >&2
    echo fail
  fi
}
plan() {
  mkdir -p "$STAGE"
  : > "$STAGE/install.list"
  : > "$STAGE/skip.list"
  local rel prevs newMd role verdict bad=0
  while read -r rel prevs newMd role; do
    [[ -n $rel ]] || continue
    verdict=$(class_of "$rel" "$prevs" "$newMd" "$role")
    if [[ $verdict == install ]]; then
      echo "$rel" >> "$STAGE/install.list"
    elif [[ $verdict == skip ]]; then
      echo "$rel" >> "$STAGE/skip.list"
    else
      bad=1
    fi
  done <<< "$MAN"
  echo "----"
  echo "WILL_INSTALL"
  sed 's/^/  /' "$STAGE/install.list"
  if [[ -s $STAGE/skip.list ]]; then
    echo "WILL_SKIP"
    sed 's/^/  /' "$STAGE/skip.list"
  else
    echo "WILL_SKIP none"
  fi
  return "$bad"
}
run_php() {
  local mode=$1
  if [[ ${ABO_SKIP_DB:-0} == 1 ]]; then
    echo "DB report skipped"
    return 0
  fi
  PAYROLL_APP="$APP" php "$STAGE/bundle/ops/auto_break_off.php" "$mode"
}
backup_db() {
  local dest=$1
  ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" breaks attendance payroll_finalized | gzip -c > "$dest"
  echo "Backup $dest"
}
restore_db() {
  local src=$1
  [[ -f $src ]] || { echo "No database backup at $src"; return 1; }
  gzip -dc "$src" | ${T4127_MYSQL:-mysql} "$DB"
  echo "Restored database from $src"
}
restore_files() {
  local ts=$1 src="$BK/pre-autobreak-off-$ts/files"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
  local f rel
  while IFS= read -r f; do
    [[ -n $f ]] || continue
    rel=${f#"$src/"}
    if [[ -f $f ]]; then
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$f" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      echo "Restored $rel"
    fi
  done < <(find "$src" -type f | sort)
}

case "$M" in
  check)
    set +e
    run_php check
    db_rc=$?
    set -e
    plan
    file_rc=$?
    [[ $db_rc -eq 0 && $file_rc -eq 0 ]]
    ;;
  dry)
    run_php dry
    ;;
  go)
    plan
    TS=$(date +%Y%m%d-%H%M%S)
    DEST="$BK/pre-autobreak-off-$TS"
    mkdir -p "$DEST/files"
    if [[ ${ABO_SKIP_DB:-0} != 1 ]]; then
      backup_db "$DEST/tables.sql.gz"
    else
      echo "DB backup skipped"
    fi
    while IFS= read -r rel; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/files/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/files/$rel"
      fi
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring files"
        restore_files "$TS" || true
        exit 1
      fi
      echo "Installed $rel"
    done < "$STAGE/install.list"
    if ! run_php go; then
      echo "Recalculation failed. Restoring the database and files."
      if [[ ${ABO_SKIP_DB:-0} != 1 ]]; then
        restore_db "$DEST/tables.sql.gz" || true
      fi
      restore_files "$TS" || true
      exit 1
    fi
    if [[ -s $STAGE/skip.list ]]; then
      echo "Left unchanged:"
      sed 's/^/  /' "$STAGE/skip.list"
    fi
    echo "Automatic breaks are off. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -d $BK/pre-autobreak-off-$TS ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    if [[ -f $BK/pre-autobreak-off-$TS/tables.sql.gz ]]; then
      restore_db "$BK/pre-autobreak-off-$TS/tables.sql.gz"
    fi
    restore_files "$TS"
    ;;
  *)
    echo "Usage: bash $0 check|dry|go|rollback <ts>"
    exit 1
    ;;
esac
