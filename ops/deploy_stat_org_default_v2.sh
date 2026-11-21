#!/bin/bash
# Payroll screens follow the top-bar organization when no org_id is passed.
# Optional files that are missing or different on the server are skipped.
# The ledger, Pay Employee Period, Payment Status, and payroll_scope.php are
# required: if one of those is missing or different, check stops and nothing
# is copied. Read-only until "go".
#   bash deploy_stat_org_default_v2.sh check
#   bash deploy_stat_org_default_v2.sh diff
#   bash deploy_stat_org_default_v2.sh go
#   bash deploy_stat_org_default_v2.sh rollback <ts>
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
# rel  accepted-live-md5s  pinned-md5  core|opt
# core: ledger (and Pay Employee Period, same file), payment status, payroll_scope.php
MAN='payroll_scope.php ef7ab4891c84df9641e5fd084164f262,aa12cc562b84d1a47f021c94f5a716b9 7b6c33397a837548d6b8628b8738274d core
payroll_master_ledger.php 3112be807ba0765ffc07f3f0e4a9da97,537de9c4d6b1cd15498ecac94dea47df,a0552472794688e74dfb92a7690c7bd4 3112be807ba0765ffc07f3f0e4a9da97 guard
engine/payroll_payment_status_lib.php 3dbbc99ba2a7a104758a70d1fded1b5b c9219e88829e01a25a6851962ff1d08b core
payroll_payment_status.php 5ee2086342c2ae020c9bc1e0cb495453 5ee2086342c2ae020c9bc1e0cb495453 guard
attendance_audit.php a9504ae64e789b2cc2c62e6e3a83cd51 240b0255284e9e2fffe76cc9f50840d3 opt
balance_report.php 07c35201fd5d494bff2a5e58dba93575 55890d88bd674977b045567a58891438 opt
bulk_allocation_desk.php 8b1030bba3c1367e203e5c269e66f131 d76ce3141ee8e37e5fb497f5a89f7386 opt
direct_deposit_pending.php 35b897117ad1d4d3eea3500f10563d3f fe91c5897a0cd7b5ce26c66b6fd8d4e2 opt
finalize_payroll.php 1d327573b86837dfe96ea9d2e952519e 319575cb1fb58db72c53bf4971101723 opt
finalize_payroll1.php 1f36a25d950d12311da756035efbcb8b 869f760145879f24e2d0b38edae835e7 opt
finalized_archive.php e16570be46f02be5400da1dfbe3d935d 9d5d6ae0d2d02b97b9dc0668926c74cf opt
generate_pay_documents.php 137d9f7ac8ec60e02883403ae0cec6c0,0f8281d8e4737fb4336f0ba6265655eb,88f43817563e848678a5544a1051f1b1 9bf2c0f2821dedf1d20d928c036a864d opt
payroll_dashboard.php f245c9f85a88d33ebd9a3b7a4fd0b8dd 87f57e3de076c19a0098d7a6d083869a opt
payroll_hours_report.php 2c90706183ffade24e4681aaa836d40d ddb35f8d44b2385caf9d2294262a75d9 opt
payroll_hub.php ff4fb54ded5af4b89ba1b259b1bc5315 961c5b5fdebe7489d5fa5be54e0f2167 opt
payroll_ledger_grouped.php a22c2f984fc22f08a3ca96ffcbd84aca 98c238dc844daa31f673b1d5f73b486f opt
payroll_master_ledger1.php e0c538c8f20227272d0db00c87fecb56,298757d67946f5e7a6178f346206d699,23d06f90bf727fdb5ff4a3ed5d27bfea b2b8d271be0d552bc55e4b87b0150ab9 opt
payroll_settings.php 9e11ef6b8d2c95bfccf7d94ac74c46c9 2257040025523cf003fb11dcaa0ce7ab opt
payroll_statement.php afb4763575fadd6081e98bd2988c2d09 5b997ec252901e77550ab2d263c9e0bc opt
payroll_summary.php 18b0d5196cf244b32c788b0bec943b8f 994f3d3ed9bbcc630db63723388d3f8a opt'

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
# Prints one word to stdout: install, skip, or fail. Details go to stderr via echo in the caller.
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
    if [[ $role == core || $role == guard ]]; then
      echo "CORE_MISSING $rel" >&2
      echo fail
    else
      echo "SKIP $rel MISSING" >&2
      echo skip
    fi
    return
  fi
  local liveMd
  liveMd=$(md "$live")
  local action=install
  if [[ $role == guard ]]; then action=keep; fi
  if [[ $liveMd == "$newMd" ]]; then
    echo "CLEAN $rel live $liveMd" >&2
    echo "$action"
    return
  fi
  local prev p
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    if [[ $liveMd == "$p" ]]; then
      echo "CLEAN $rel live $liveMd" >&2
      echo "$action"
      return
    fi
  done
  local snap base
  for snap in "$PREV" "$PINNED"; do
    base="$STAGE/snap/$snap/$rel"
    if ! fetch_sha "$snap" "$rel" "$base"; then continue; fi
    if only_osg "$base" "$live"; then
      echo "CLEAN $rel org-scope lines only" >&2
      echo "$action"
      return
    fi
  done
  if [[ $role == core || $role == guard ]]; then
    echo "CORE_DRIFT $rel live $liveMd" >&2
    echo fail
  else
    echo "SKIP $rel DRIFT live $liveMd" >&2
    echo skip
  fi
}
plan() {
  local rel prevs newMd role verdict
  mkdir -p "$STAGE"
  : > "$STAGE/install.list"
  : > "$STAGE/skip.list"
  : > "$STAGE/keep.list"
  local bad=0
  while read -r rel prevs newMd role; do
    [[ -n $rel ]] || continue
    verdict=$(class_of "$rel" "$prevs" "$newMd" "$role")
    if [[ $verdict == install ]]; then
      echo "$rel" >> "$STAGE/install.list"
    elif [[ $verdict == keep ]]; then
      echo "$rel" >> "$STAGE/keep.list"
    elif [[ $verdict == skip ]]; then
      echo "$rel" >> "$STAGE/skip.list"
    else
      bad=1
    fi
  done <<< "$MAN"
  echo "----"
  if [[ -s $STAGE/install.list ]]; then
    echo "WILL_INSTALL"
    sed 's/^/  /' "$STAGE/install.list"
  fi
  if [[ -s $STAGE/keep.list ]]; then
    echo "WILL_KEEP"
    sed 's/^/  /' "$STAGE/keep.list"
  fi
  if [[ -s $STAGE/skip.list ]]; then
    echo "WILL_SKIP"
    sed 's/^/  /' "$STAGE/skip.list"
  else
    echo "WILL_SKIP none"
  fi
  return "$bad"
}
restore_files() {
  local ts=$1 src="$BK/pre-stat-orgdefault2-$ts"
  [[ -d $src ]] || { echo "No file backup at $src"; return 1; }
  local f rel
  while IFS= read -r f; do
    [[ -n $f ]] || continue
    rel=${f#"$src/"}
    mkdir -p "$APP/$(dirname "$rel")"
    cp -f "$f" "$APP/$rel"
    chown "$OWN" "$APP/$rel" 2>/dev/null || true
    echo "Restored $rel"
  done < <(find "$src" -type f | sort)
}

case "$M" in
  check)
    plan
    ;;
  diff)
    bad=0
    while read -r rel prevs newMd role; do
      [[ -n $rel ]] || continue
      live="$APP/$rel"
      if [[ ! -f $live ]]; then
        echo "MISSING $rel"
        [[ $role == core || $role == guard ]] && bad=1
        continue
      fi
      liveMd=$(md "$live")
      matched=0
      if [[ $liveMd == "$newMd" ]]; then matched=1; fi
      IFS=',' read -ra prev <<< "$prevs"
      for p in "${prev[@]}"; do
        [[ $liveMd == "$p" ]] && matched=1
      done
      base="$STAGE/diffbase/$rel"
      fetch_sha "$PREV" "$rel" "$base" || true
      if [[ -f $base ]] && only_osg "$base" "$live"; then matched=1; fi
      if [[ $matched == 1 ]]; then
        continue
      fi
      echo "===== $rel live $liveMd role $role ====="
      if [[ -f $base ]]; then
        diff -u --label "expected $PREV $rel" --label "live $rel" "$base" "$live" || true
      else
        echo "NO_EXPECTED_BASE $rel"
      fi
      [[ $role == core || $role == guard ]] && bad=1
    done <<< "$MAN"
    if [[ $bad -eq 1 ]]; then
      echo "A required file is missing or different. check will fail and go will not copy anything."
      exit 1
    fi
    echo "diff is read-only. No file was changed."
    ;;
  go)
    plan
    TS=$(date +%Y%m%d-%H%M%S)
    DEST="$BK/pre-stat-orgdefault2-$TS"
    mkdir -p "$DEST"
    while IFS= read -r rel; do
      [[ -n $rel ]] || continue
      if [[ ! -f $APP/$rel ]]; then
        echo "Refusing to create $rel. It was not a clean existing file."
        exit 1
      fi
      mkdir -p "$DEST/$(dirname "$rel")"
      cp -f "$APP/$rel" "$DEST/$rel"
    done < "$STAGE/install.list"
    echo "Backup $DEST"
    while IFS= read -r rel; do
      [[ -n $rel ]] || continue
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring files"
        restore_files "$TS"
        exit 1
      fi
      echo "Installed $rel"
    done < "$STAGE/install.list"
    if [[ -s $STAGE/skip.list ]]; then
      echo "Left unchanged:"
      sed 's/^/  /' "$STAGE/skip.list"
    fi
    echo "Installed the top-bar org default from $PINNED. Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -d $BK/pre-stat-orgdefault2-$TS ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    restore_files "$TS"
    ;;
  *)
    echo "Usage: bash $0 check|diff|go|rollback <ts>"
    exit 1
    ;;
esac
