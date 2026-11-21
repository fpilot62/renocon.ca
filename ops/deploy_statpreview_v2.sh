#!/bin/bash
# Stat Pay Preview deploy v2. Supersedes deploy_statpreview_v1.sh.
# Installs the posted-line preview fix and the yellow stat-pay highlighting.
# Root on the payroll server. Read-only until "go".
#   bash deploy_statpreview_v2.sh check
#   bash deploy_statpreview_v2.sh go
#   bash deploy_statpreview_v2.sh rollback <ts>
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
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=ddefd95064981750a174502bd7c8c2b03d121580
OLD=998e33239f9b2c8482c9af986b0722e11e0d7a40
MID=fcf7e1c3525275ee55b374142b145c324abee0a8
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
# path | earlier md5s, comma-separated | this deploy's md5
MAN='generate_stat_shifts.php 9730267ff0f7e4a4e5df90c762fffca5,c2e62f522227ca9cafd051f4da0a0966 b8c5122dae4f7ceed8e98f53c1cf1143
payroll_engine/stat_holidays.php 1cb3906b1f306b6fc2cc3c6c83c9ed9a,ac15130bce7281657f41650a6a67620c ac15130bce7281657f41650a6a67620c
payroll_master_ledger.php 602706a4729b27a050362f8cb2e20be6 1d1377f76a1baa4fa189f5c26b23f110
generate_pay_documents.php 490133db7d26a24bfd7dd09923499031 e0871e140bf263197cee45dd48425836'

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
  local prev
  IFS=',' read -ra prev <<< "$prevs"
  local p
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
restore_files() {
  local ts=$1 src="$BK/pre-statpreview-v2-$ts"
  [[ -d $src ]] || { echo "No backup at $src"; return 1; }
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
    DEST="$BK/pre-statpreview-v2-$TS"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      mkdir -p "$DEST/$(dirname "$rel")"
      cp -f "$APP/$rel" "$DEST/$rel"
    done <<< "$MAN"
    echo "Backup $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      keep_osg "$APP/$rel" "$STAGE/bundle/$rel"
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
    done <<< "$MAN"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring"
        restore_files "$TS"
        exit 1
      fi
    done <<< "$MAN"
    echo "Installed Stat Pay Preview v2 from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    restore_files "$TS"
    ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 1
    ;;
esac
