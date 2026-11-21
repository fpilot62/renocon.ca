#!/bin/bash
# Read the top-bar org cookie instead of the login home org.
# Read-only until "go". File backup only.
#   bash deploy_stat_org_cookie_v1.sh check
#   bash deploy_stat_org_cookie_v1.sh go
#   bash deploy_stat_org_cookie_v1.sh rollback <ts>
set -euo pipefail
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=6c2d925288ccb1485ce5a6b84b8dadd1b0ccfadb
PREV=74411269f4a559c80db61855dbf608705999c545
MAN='payroll_scope.php aa12cc562b84d1a47f021c94f5a716b9 ef7ab4891c84df9641e5fd084164f262
payroll_engine/stat_holidays.php 8f3347d6397080bc8b2defb9e654b6d8 bfd38c928b5d33b4f5c088004d001ee1
generate_stat_shifts.php 3bc6fded6a5687cbe821593d8e25aecc 6dc31c3c0b190e5d5e22f48fc5b6ae20
payroll_master_ledger.php 537de9c4d6b1cd15498ecac94dea47df 3112be807ba0765ffc07f3f0e4a9da97
stat_holiday_review.php b1ac0ff4b26f1b6edac0782c23f06c20 c35f121dd32ab03a03f1c9cd35ac11dd'

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
  [[ -f $live ]] || { echo "MISSING $live"; return 1; }
  local liveMd
  liveMd=$(md "$live")
  if [[ $liveMd == "$newMd" || $liveMd == "$prevs" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
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
  local ts=$1 src="$BK/pre-stat-org-$ts"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
  local rel
  while read -r rel _ _; do
    [[ -n $rel ]] || continue
    if [[ -f $src/$rel ]]; then
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$src/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      echo "Restored $rel"
    fi
  done <<< "$MAN"
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
    DEST="$BK/pre-stat-org-$TS"
    mkdir -p "$DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      mkdir -p "$DEST/$(dirname "$rel")"
      cp -f "$APP/$rel" "$DEST/$rel"
    done <<< "$MAN"
    echo "Backup $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring files"
        restore_files "$TS"
        exit 1
      fi
    done <<< "$MAN"
    echo "Installed the top-bar org fix from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -d $BK/pre-stat-org-$TS ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    restore_files "$TS"
    ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 1
    ;;
esac
