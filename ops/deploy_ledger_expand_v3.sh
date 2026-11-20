#!/bin/bash
# Ledger expand v3. The pay ledger loads when the attendance audit library is not installed.
# Live may be the stat-preview v2 files or the ledger expand v2 files, plus an org-scope line.
#   bash deploy_ledger_expand_v3.sh check
#   bash deploy_ledger_expand_v3.sh go
#   bash deploy_ledger_expand_v3.sh rollback <ts>
set -euo pipefail
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=2e332ec705943bd9f9c998a1f10d3feb81e91295
STAT=ddefd95064981750a174502bd7c8c2b03d121580
EXPAND2=6c4b8fae0abe38ad43796347e408bd10b388ee89
RAW=${T4127_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca/$PINNED}
# path | earlier md5s, or - when the file is new | this deploy's md5
MAN='payroll_master_ledger.php 8a742c2b07ffd45caaab17e129990611,1d1377f76a1baa4fa189f5c26b23f110,602706a4729b27a050362f8cb2e20be6,cd047535101b385e165ff12b91d3fa82,b1c676d66118298acb4f4bc02516c4ed 0364d96da0960959067ae54df40e9f86
payroll_master_ledger1.php 8163a859e9d5e46ca179ff9a33db8790,742e3cd8369734bdb58c9ef83ef3b87f,0688677ee41046e0a5cb2055b6c69f3f,614e8a3895693d1c08ecc69e0371b1f6 23d06f90bf727fdb5ff4a3ed5d27bfea
generate_stat_shifts.php b8c5122dae4f7ceed8e98f53c1cf1143,c2e62f522227ca9cafd051f4da0a0966,9730267ff0f7e4a4e5df90c762fffca5,45258fe978ae9b0a403b68bab27bb680 45258fe978ae9b0a403b68bab27bb680
generate_pay_documents.php e0871e140bf263197cee45dd48425836,490133db7d26a24bfd7dd09923499031,88f43817563e848678a5544a1051f1b1 88f43817563e848678a5544a1051f1b1
engine/ledger_matrix_lib.php 591de1a59d65618d846de74a2d240752 591de1a59d65618d846de74a2d240752'

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
  if [[ ! -f $live ]]; then
    if [[ $rel == engine/ledger_matrix_lib.php ]]; then echo "CLEAN $rel new file"; return 0; fi
    echo "MISSING $live"; return 1
  fi
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
  for snap in "$STAT" "$EXPAND2" "$PINNED"; do
    local base="$STAGE/snap/$snap/$rel"
    if ! fetch_sha "$snap" "$rel" "$base"; then
      continue
    fi
    if only_osg "$base" "$live"; then
      echo "CLEAN $rel org-scope lines only"
      return 0
    fi
  done
  echo "DRIFT $rel live $liveMd"
  return 1
}
restore_files() {
  local ts=$1
  local src="$BK/pre-ledger-expand-$ts"
  [[ -d $src ]] || { echo "No backup at $src"; return 1; }
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
    DEST="$BK/pre-ledger-expand-$TS"
    mkdir -p "$DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/$rel"
      fi
    done <<< "$MAN"
    echo "Backup $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        keep_osg "$APP/$rel" "$STAGE/bundle/$rel"
      fi
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring"
        restore_files "$TS"
        exit 1
      fi
    done <<< "$MAN"
    echo "Installed ledger expand v3 from $PINNED. Rollback: bash $0 rollback $TS"
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
