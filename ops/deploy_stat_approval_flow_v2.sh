#!/bin/bash
# Stat holiday pay stays pending until an admin approves it.
# Pending lines are left out of payment, cheques, stubs, and period deductions.
# Read-only until "go". Does not mark anything paid.
#   bash deploy_stat_approval_flow_v2.sh check
#   bash deploy_stat_approval_flow_v2.sh go
#   bash deploy_stat_approval_flow_v2.sh rollback <ts>
#
# v2 accepts the four live files that stopped v1 check:
#   engine/payroll_cheque_lib.php 46fd636c3c802f5ab9470000a366c78c
#   payroll_save_payment.php a3292c3604f34d43658302d537d4fdc3
#   bulk_finalize_handler.php 7c7c952038fde012d899b935e8cb376a
#   /root/t4127-stage/t4127_stat_backfill.php 85881f9183edd90999d43393eb7b1236
#
# check compares each live file with the pinned bundle. A live file that is not
# the pinned file, a known previous hash, or the same file plus org-scope lines
# is DRIFT. check then stops and lists those files. Nothing is written.
# go backs up the database and the files to /root/payroll-daily/pre-statflow-<ts>
# before it installs. php -l runs on each installed PHP file. A failure restores
# the files. There is no schema change.
set -euo pipefail
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
PINNED=e976d60777f2a990d6410a9b218ebcaf5f88a916
BASE=5a4dab88672774b6b0408e7507615d622210b81c
MAN='engine/payroll_cheque_lib.php 46fd636c3c802f5ab9470000a366c78c,3beeeb9967329c604ae5cbcb75672cae 8df9dbd8362c66e5be95cc092f555bde
engine/paydocs_earn.php - 4ba6848f3f128d597947190e4b12f6fe
engine/ledger_matrix_lib.php 591de1a59d65618d846de74a2d240752 a770836bf20b6ed2c6a0886302082180
payroll_payment_status.php f4c2e921bafd50753c72bc19900520d8,5ee2086342c2ae020c9bc1e0cb495453 6cdb39996fbe3544eb537a5a5fee72c8
payroll_payment_desk.php 6590cb0faaa160ee1cae97e593557f24 839d55d3dc9f3f651208b75869e61a93
payroll_save_payment.php a3292c3604f34d43658302d537d4fdc3,3b7df89c735fd497ba019c1f443b7d33,ac8cdc93e296ed9fd986276cd2186e42 fe1334afa69cfae4b4a439b46073b74f
print_payrun.php 1da566cbd6e82469789d66d28622617b ca75195658f8118bd41e274afef8de4e
generate_pay_documents.php 9bf2c0f2821dedf1d20d928c036a864d cefb66f11a795d837e3d3f3e1d06c688
payroll_engine/payroll_engine.php 9eabae66752770e5daca5d64cfbbedf6 5e00951eb142c35d9ad846af03e92fab
payroll_engine/stat_holidays.php bfd38c928b5d33b4f5c088004d001ee1 db78d955bf14710c4cdff634578c383d
payroll_master_ledger.php 3112be807ba0765ffc07f3f0e4a9da97 411e42f1ce0402396e3b73e5d10bec60
payroll_master_ledger1.php b2b8d271be0d552bc55e4b87b0150ab9 7dd268aeefb455cbec1aacff2e40eac5
generate_stat_shifts.php 6dc31c3c0b190e5d5e22f48fc5b6ae20 e3d130a70f2196f8e89ba84016f362c6
ops/t4127_stat_backfill.php -,85881f9183edd90999d43393eb7b1236,b1a8c9eb86ad5cf78674bf69e6078933 4b5e11e6f2c73d8b4ac7e0efd76e5afa
payroll_gen.php b25410b1709bb77c5082ef352459dbe0 a615098c51be00ec66aa5fc96b231c6e
bulk_finalize_handler.php 7c7c952038fde012d899b935e8cb376a,de099e901fa7d63ad22c45e42acead4e,e5a3908f8ecbfb1411e02f45808b6f60 e5e46fb0d43adc1f7b043b0f5efb7e45'

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
  [[ -f $live ]] || return 0
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
known_hash() {
  local prevs=$1 want=$2 newMd=$3 p
  [[ $want == "$newMd" ]] && return 0
  local prev
  IFS=',' read -ra prev <<< "$prevs"
  for p in "${prev[@]}"; do
    [[ $p != '-' && $want == "$p" ]] && return 0
  done
  return 1
}
stage_runner() { echo "$STAGE/t4127_stat_backfill.php"; }
check_stage_runner() {
  local prevs=$1 newMd=$2 runner runnerMd
  runner=$(stage_runner)
  [[ -f $runner ]] || return 0
  runnerMd=$(md "$runner")
  if known_hash "$prevs" "$runnerMd" "$newMd"; then
    echo "CLEAN stage runner $runner $runnerMd"
    return 0
  fi
  echo "DRIFT $runner live $runnerMd"
  return 1
}
check_one() {
  local rel=$1 prevs=$2 newMd=$3
  local bundle="$STAGE/bundle/$rel" live="$APP/$rel"
  fetch_sha "$PINNED" "$rel" "$bundle"
  local got
  got=$(md "$bundle")
  echo "bundle $rel $got"
  [[ $got == "$newMd" ]] || { echo "BUNDLE_MD5_BAD $rel want $newMd"; return 1; }
  if [[ $rel == *.php ]] && ! php -l "$bundle" >/dev/null; then
    echo "PHP_LINT $rel"
    return 1
  fi
  if [[ ! -f $live ]]; then
    case ",$prevs," in
      *,-,*)
        if [[ $rel == ops/t4127_stat_backfill.php ]]; then
          check_stage_runner "$prevs" "$newMd" || return 1
        fi
        echo "CLEAN $rel new file"
        return 0
        ;;
    esac
    echo "DRIFT $rel MISSING $live"
    return 1
  fi
  local liveMd
  liveMd=$(md "$live")
  if known_hash "$prevs" "$liveMd" "$newMd"; then
    if [[ $rel == ops/t4127_stat_backfill.php ]]; then check_stage_runner "$prevs" "$newMd" || return 1; fi
    echo "CLEAN $rel live $liveMd"
    return 0
  fi
  local snap
  for snap in "$BASE" "$PINNED"; do
    local base="$STAGE/snap/$snap/$rel"
    if ! fetch_sha "$snap" "$rel" "$base"; then continue; fi
    if only_osg "$base" "$live"; then
      if [[ $rel == ops/t4127_stat_backfill.php ]]; then check_stage_runner "$prevs" "$newMd" || return 1; fi
      echo "CLEAN $rel org-scope lines only"
      return 0
    fi
  done
  echo "DRIFT $rel live $liveMd"
  return 1
}
restore_files() {
  local ts=$1 src="$BK/pre-statflow-$ts"
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
  local runner
  runner=$(stage_runner)
  if [[ -f $src/stage/t4127_stat_backfill.php ]]; then
    mkdir -p "$(dirname "$runner")"
    cp -f "$src/stage/t4127_stat_backfill.php" "$runner"
    echo "Restored $runner"
  elif [[ -f $src/stage/was-absent ]]; then
    rm -f "$runner"
    echo "Removed new $runner"
  fi
}

case "$M" in
  check)
    bad=0
    while read -r rel prevs newMd; do
      [[ -n $rel ]] || continue
      check_one "$rel" "$prevs" "$newMd" || bad=1
    done <<< "$MAN"
    if [[ $bad -ne 0 ]]; then
      echo "STOP. Drifted files were not installed:"
      echo "Review each DRIFT line above. go will not run until check is clean."
      exit 1
    fi
    echo "check clean. Pinned $PINNED"
    ;;
  go)
    bash "$0" check
    TS=$(date +%Y%m%d-%H%M%S)
    mkdir -p "$BK"
    ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$DB" | gzip -c > "$BK/pre-statflow-$TS.sql.gz"
    [[ -s $BK/pre-statflow-$TS.sql.gz ]]
    DEST="$BK/pre-statflow-$TS"
    mkdir -p "$DEST/stage"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then
        mkdir -p "$DEST/$(dirname "$rel")"
        cp -f "$APP/$rel" "$DEST/$rel"
      fi
    done <<< "$MAN"
    runner=$(stage_runner)
    if [[ -f $runner ]]; then
      cp -f "$runner" "$DEST/stage/t4127_stat_backfill.php"
    else
      : > "$DEST/stage/was-absent"
    fi
    echo "Backup $BK/pre-statflow-$TS.sql.gz and $DEST"
    while read -r rel _ _; do
      [[ -n $rel ]] || continue
      if [[ -f $APP/$rel ]]; then keep_osg "$APP/$rel" "$STAGE/bundle/$rel"; fi
      mkdir -p "$APP/$(dirname "$rel")"
      cp -f "$STAGE/bundle/$rel" "$APP/$rel"
      chown "$OWN" "$APP/$rel" 2>/dev/null || true
      if [[ $rel == *.php ]] && ! php -l "$APP/$rel"; then
        echo "php -l failed, restoring files"
        restore_files "$TS"
        exit 1
      fi
    done <<< "$MAN"
    mkdir -p "$(dirname "$runner")"
    cp -f "$STAGE/bundle/ops/t4127_stat_backfill.php" "$runner"
    if ! php -l "$runner"; then
      echo "php -l failed on the stage backfill runner, restoring files"
      restore_files "$TS"
      exit 1
    fi
    echo "Installed stat approval flow from $PINNED. Nothing was marked paid."
    echo "Rollback: bash $0 rollback $TS"
    ;;
  rollback)
    TS=${2:-}
    [[ -n $TS && -f $BK/pre-statflow-$TS.sql.gz ]] || { echo "Usage: bash $0 rollback <ts>"; exit 1; }
    gzip -dc "$BK/pre-statflow-$TS.sql.gz" | ${T4127_MYSQL:-mysql} "$DB"
    echo "Restored database from pre-statflow-$TS.sql.gz"
    if [[ -d $BK/pre-statflow-$TS ]]; then restore_files "$TS"; fi
    ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 1
    ;;
esac
