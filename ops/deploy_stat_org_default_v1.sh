#!/bin/bash
# Payroll screens follow the top-bar organization when no org_id is passed.
# Read-only until "go". File backup only.
#   bash deploy_stat_org_default_v1.sh check
#   bash deploy_stat_org_default_v1.sh go
#   bash deploy_stat_org_default_v1.sh rollback <ts>
set -euo pipefail
M=${1:-check}
export PAYROLL_APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
APP=$PAYROLL_APP
BK=${PAYROLL_BK:-/root/payroll-daily}
STAGE=${T4127_STAGE:-/root/t4127-stage}
OWN=${PAYROLL_OWN:-renocon:renocon}
REPO=${T4127_REPO:-}
PINNED=59d03ad99227c4151be4eee93eb4c779d3397282
PREV=76da1618bcb5b52ad995fff547eb566639cf7195
MAN='payroll_scope.php ef7ab4891c84df9641e5fd084164f262,aa12cc562b84d1a47f021c94f5a716b9 7b6c33397a837548d6b8628b8738274d
engine/payroll_payment_status_lib.php 3dbbc99ba2a7a104758a70d1fded1b5b c9219e88829e01a25a6851962ff1d08b
attendance_audit.php a9504ae64e789b2cc2c62e6e3a83cd51 240b0255284e9e2fffe76cc9f50840d3
balance_report.php 07c35201fd5d494bff2a5e58dba93575 55890d88bd674977b045567a58891438
bulk_allocation_desk.php 8b1030bba3c1367e203e5c269e66f131 d76ce3141ee8e37e5fb497f5a89f7386
direct_deposit_pending.php 35b897117ad1d4d3eea3500f10563d3f fe91c5897a0cd7b5ce26c66b6fd8d4e2
finalize_payroll.php 1d327573b86837dfe96ea9d2e952519e 319575cb1fb58db72c53bf4971101723
finalize_payroll1.php 1f36a25d950d12311da756035efbcb8b 869f760145879f24e2d0b38edae835e7
finalized_archive.php e16570be46f02be5400da1dfbe3d935d 9d5d6ae0d2d02b97b9dc0668926c74cf
generate_pay_documents.php 137d9f7ac8ec60e02883403ae0cec6c0,0f8281d8e4737fb4336f0ba6265655eb,88f43817563e848678a5544a1051f1b1 9bf2c0f2821dedf1d20d928c036a864d
payroll_dashboard.php f245c9f85a88d33ebd9a3b7a4fd0b8dd 87f57e3de076c19a0098d7a6d083869a
payroll_hours_report.php 2c90706183ffade24e4681aaa836d40d ddb35f8d44b2385caf9d2294262a75d9
payroll_hub.php ff4fb54ded5af4b89ba1b259b1bc5315 961c5b5fdebe7489d5fa5be54e0f2167
payroll_ledger_grouped.php a22c2f984fc22f08a3ca96ffcbd84aca 98c238dc844daa31f673b1d5f73b486f
payroll_master_ledger1.php e0c538c8f20227272d0db00c87fecb56,298757d67946f5e7a6178f346206d699,23d06f90bf727fdb5ff4a3ed5d27bfea b2b8d271be0d552bc55e4b87b0150ab9
payroll_settings.php 9e11ef6b8d2c95bfccf7d94ac74c46c9 2257040025523cf003fb11dcaa0ce7ab
payroll_statement.php afb4763575fadd6081e98bd2988c2d09 5b997ec252901e77550ab2d263c9e0bc
payroll_summary.php 18b0d5196cf244b32c788b0bec943b8f 994f3d3ed9bbcc630db63723388d3f8a'

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
  if [[ $liveMd == "$newMd" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
  local prev p
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    if [[ $liveMd == "$p" ]]; then echo "CLEAN $rel live $liveMd"; return 0; fi
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
  local ts=$1 src="$BK/pre-stat-orgdefault-$ts"
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
    DEST="$BK/pre-stat-orgdefault-$TS"
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
    echo "Installed the top-bar org default from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -d $BK/pre-stat-orgdefault-$TS ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    restore_files "$TS"
    ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 1
    ;;
esac
