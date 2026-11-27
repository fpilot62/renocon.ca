#!/bin/bash
# Sheet Review v7. Same install as v6, plus the employee active flag that
# is already on the live review library. v6 check stopped on that file.
#
# Live engine/payroll_sheet_review_lib.php is 74371f4a50c92c3eb4202081c8fd9576.
# That copy is not the blob at BASE: it has users.active and no CPP2.
# go saves that exact file. rollback puts that exact file back.
# The other live files still match BASE 312b9313:
#   payroll_sheet_review.php              3b6af60009f0f2c067fa4a3446f02bcf
#   payroll_sheet_sheet.php               d6fd70a5d0aacfee7fe0489917c3ad9b
#   engine/outstanding_summary_lib.php    cb8dafbc59dec1ea30955c97b892ba92
#   engine/sheet_summary_lib.php          cc0cab82bcd344a335d735308b370338
#   tests/sheet_pay_summary_test.php      eb0a8f76dd1abdaa87fae192138f33fe
#   ops/load_outstanding_summary.php      b24ee5a0b1de99a18d313f9ac0c1eafd
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_sheet_review_v7.sh check
#   bash deploy_sheet_review_v7.sh go
#   bash deploy_sheet_review_v7.sh rollback <ts>
#   bash deploy_sheet_review_v7.sh autolink-dry
#   bash deploy_sheet_review_v7.sh autolink
#   bash deploy_sheet_review_v7.sh autolink-rollback <ts>
#
# check is read-only and stops on DRIFT. go refuses unless check prints
# CHECK OK. Do not run go until Abdul says yes.

set -euo pipefail
_ops_dir=$(cd "$(dirname "$0")" && pwd)
if [[ -f $_ops_dir/deploy_target.inc.sh ]]; then
  # shellcheck disable=SC1091
  source "$_ops_dir/deploy_target.inc.sh"
fi
unset _ops_dir

MODE=${1:-}
TSARG=${2:-}
APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
DB=${PAYROLL_DB:-admin_payroll}
BK=${PAYROLL_BK:-/root/payroll-daily}
WORK=${SHEET_REVIEW_WORK:-/root/sheet-review-work}
RAW=${SHEET_REVIEW_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

# Files as installed on the live server (PIN 312b9313). v5 was not deployed.
BASE=${SHEET_REVIEW_BASE:-312b931378ea09f767bd90716b7fc0bdb4c82d14}
# Feature commit: v6 name links, CPP2, and the live employee active flag.
PIN=${SHEET_REVIEW_PIN:-86a07bc4663f6f4ca2e0ef988b4d534c723dd34a}
# Live review library. Not the blob at BASE.
LIVE_LIB_MD=74371f4a50c92c3eb4202081c8fd9576

FILES='engine/outstanding_summary_lib.php
engine/payroll_sheet_review_lib.php
engine/sheet_name_link_lib.php
engine/sheet_summary_lib.php
migrations/20261127_payroll_sheet_name_links.sql
ops/auto_link_sheet_names.php
ops/load_outstanding_summary.php
payroll_sheet_review.php
payroll_sheet_sheet.php
tests/sheet_name_link_test.php
tests/sheet_pay_summary_test.php'

TABLES='payroll_sheet_name_links payroll_sheet_name_link_audit payroll_sheet_rows payroll_sheet_edit_audit payroll_outstanding_summary payroll_outstanding_summary_audit'

if [[ ${SHEET_REVIEW_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

# Hash of the file at BASE. The live review library is a server edit, so its
# git blob stays 7378b4b9 while the copy on disk is LIVE_LIB_MD.
expect_git_md() {
  case $1 in
    engine/payroll_sheet_review_lib.php) printf '%s\n' '7378b4b9584082de0d884a34ca1fae04' ;;
    *) expect_base_md "$1" ;;
  esac
}

# Empty means the file is new and has no live base.
expect_base_md() {
  case $1 in
    payroll_sheet_review.php) printf '%s\n' '3b6af60009f0f2c067fa4a3446f02bcf' ;;
    payroll_sheet_sheet.php) printf '%s\n' 'd6fd70a5d0aacfee7fe0489917c3ad9b' ;;
    engine/payroll_sheet_review_lib.php) printf '%s\n' '74371f4a50c92c3eb4202081c8fd9576' ;;
    engine/outstanding_summary_lib.php) printf '%s\n' 'cb8dafbc59dec1ea30955c97b892ba92' ;;
    engine/sheet_summary_lib.php) printf '%s\n' 'cc0cab82bcd344a335d735308b370338' ;;
    tests/sheet_pay_summary_test.php) printf '%s\n' 'eb0a8f76dd1abdaa87fae192138f33fe' ;;
    ops/load_outstanding_summary.php) printf '%s\n' 'b24ee5a0b1de99a18d313f9ac0c1eafd' ;;
    *) printf '\n' ;;
  esac
}

fetch_sha() {
  local sha=$1 rel=$2 dest=$3
  mkdir -p "$(dirname "$dest")"
  local code
  code=$(curl -fsS -o "$dest" -w '%{http_code}' --retry 3 --max-time 60 \
    "$RAW/$sha/$rel" || true)
  if [[ ! -s $dest || $code != 200 ]]; then
    rm -f "$dest"
    return 1
  fi
  return 0
}

neighbour_spec() {
  local dest=$1
  local ref=$dest
  if [[ ! -e $ref ]]; then
    ref=$(find "$(dirname "$dest")" -maxdepth 1 -type f -print -quit || true)
  fi
  if [[ -z $ref || ! -e $ref ]]; then
    ref=$(dirname "$dest")
  fi
  stat -c '%u:%g:%a' "$ref"
}

apply_spec() {
  local dest=$1 spec=$2
  local u g mode
  IFS=: read -r u g mode <<< "$spec"
  chown "$u:$g" "$dest"
  chmod "$mode" "$dest"
}

live_lib_backup() {
  echo "$BK/pre-sheet-v7-$1-payroll_sheet_review_lib.php"
}

save_live_lib() {
  local ts=$1 dest
  dest=$(live_lib_backup "$ts")
  cp -a "$APP/engine/payroll_sheet_review_lib.php" "$dest"
  local got
  got=$(md "$dest")
  if [[ $got != "$LIVE_LIB_MD" ]]; then
    echo "Refusing to replace engine/payroll_sheet_review_lib.php. Live md5 $got is not $LIVE_LIB_MD."
    rm -f "$dest"
    return 1
  fi
  echo "SAVED LIVE engine/payroll_sheet_review_lib.php $got"
  echo "$dest"
}

restore_live_lib() {
  local ts=$1 dest got
  dest=$(live_lib_backup "$ts")
  [[ -f $dest ]] || { echo "Missing $dest"; return 1; }
  cp -a "$dest" "$APP/engine/payroll_sheet_review_lib.php"
  got=$(md "$APP/engine/payroll_sheet_review_lib.php")
  if [[ $got != "$LIVE_LIB_MD" ]]; then
    echo "ROLLBACK LIB MISMATCH got=$got expected=$LIVE_LIB_MD"
    return 1
  fi
  echo "RESTORED engine/payroll_sheet_review_lib.php $got"
}

restore_files() {
  local ts=$1
  local tgz=$BK/pre-sheet-v7-$ts-files.tgz
  [[ -f $tgz ]] || { echo "Missing $tgz"; return 1; }
  tar -xzpf "$tgz" -C "$APP"
  # New files were not in the tarball. Remove the copies this deploy added.
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if ! tar -tzf "$tgz" | grep -qx "$rel"; then
      rm -f "$APP/$rel"
    fi
  done <<< "$FILES"
}

check() {
  if [[ -z $PIN || $PIN == 312b931378ea09f767bd90716b7fc0bdb4c82d14 || $PIN == 05ad2830f86fd11f76aa1be1a9b641c7277e4555 || $PIN == 6b2f87a4771274297c462514f7072038ada07371 ]]; then
    echo "PIN is not set. Set SHEET_REVIEW_PIN to the v7 feature commit."
    return 1
  fi
  rm -rf "$WORK"
  mkdir -p "$WORK/base" "$WORK/pin" "$WORK/live"
  local bad=0 rel live pin base
  echo "BASE=$BASE"
  echo "PIN=$PIN"
  echo "APP=$APP"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    live=$APP/$rel
    pin=$WORK/pin/$rel
    base=$WORK/base/$rel
    if ! fetch_sha "$PIN" "$rel" "$pin"; then
      echo "FETCH_FAIL pin $rel $PIN"
      bad=1
      continue
    fi
    if [[ $rel == *.php ]] && ! php -l "$pin" >/dev/null 2>&1; then
      echo "PHP_L_FAIL pin $rel"
      bad=1
      continue
    fi
    local pin_md want_md git_want
    pin_md=$(md "$pin")
    want_md=$(expect_base_md "$rel")
    git_want=$(expect_git_md "$rel")
    if [[ -n $git_want ]]; then
      if ! fetch_sha "$BASE" "$rel" "$base"; then
        echo "FETCH_FAIL base $rel $BASE"
        bad=1
        continue
      fi
      local base_md
      base_md=$(md "$base")
      if [[ $base_md != "$git_want" ]]; then
        echo "BASE_HASH_FAIL $rel base=$base_md expected=$git_want sha=$BASE"
        bad=1
        continue
      fi
    fi
    if [[ ! -e $live ]]; then
      echo "MISSING $rel (will be added on go)"
      continue
    fi
    mkdir -p "$(dirname "$WORK/live/$rel")"
    cp -f "$live" "$WORK/live/$rel"
    local live_md
    live_md=$(md "$live")
    if [[ $live_md == "$pin_md" ]]; then
      echo "MATCH_PIN $rel $live_md"
    elif [[ -n $want_md && $live_md == "$want_md" ]]; then
      echo "MATCH_BASE $rel $live_md"
    else
      echo "DRIFT $rel live=$live_md pin=$pin_md base=${want_md:-new}"
      if [[ -f $base ]]; then
        diff -u "$base" "$live" | sed -n '1,80p' || true
      fi
      bad=1
    fi
  done <<< "$FILES"
  if [[ $bad -eq 0 ]]; then
    echo "CHECK OK"
    return 0
  fi
  echo "CHECK FAILED"
  return 1
}

rollback_cmd() {
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 rollback <ts>"; exit 2; }
  restore_files "$ts"
  restore_live_lib "$ts"
  echo "ROLLBACK DONE"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v7-$ts.sql.gz"
  echo "$BK/pre-sheet-v7-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-sheet-v7-$ts.sql.gz"
  : > "$BK/pre-sheet-v7-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-sheet-v7-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-sheet-v7-$ts-files.list ]]; then
    tar -czpf "$BK/pre-sheet-v7-$ts-files.tgz" -C "$APP" -T "$BK/pre-sheet-v7-$ts-files.list"
  else
    tar -czpf "$BK/pre-sheet-v7-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v7-$ts.sql.gz"
  echo "$BK/pre-sheet-v7-$ts-files.tgz"
  save_live_lib "$ts"

  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    mkdir -p "$APP/$(dirname "$rel")"
    local spec
    spec=$(neighbour_spec "$APP/$rel")
    \cp -f "$WORK/pin/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if [[ $rel == *.php ]] && ! php -l "$APP/$rel"; then
      echo "php -l failed on $rel"
      restore_files "$ts"
      restore_live_lib "$ts"
      echo "ROLLBACK POINT=$ts"
      echo "$BK/pre-sheet-v7-$ts.sql.gz"
      echo "$BK/pre-sheet-v7-$ts-files.tgz"
      exit 1
    fi
  done <<< "$FILES"

  if ! mysql "$DB" < "$APP/migrations/20261127_payroll_sheet_name_links.sql"; then
    echo "migration failed"
    restore_files "$ts"
    restore_live_lib "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-sheet-v7-$ts.sql.gz"
    echo "$BK/pre-sheet-v7-$ts-files.tgz"
    exit 1
  fi

  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v7-$ts.sql.gz"
  echo "$BK/pre-sheet-v7-$ts-files.tgz"
  echo "rollback: bash /root/deploy_sheet_review_v7.sh rollback $ts"
}

autolink_cmd() {
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  # shellcheck disable=SC2086
  mysqldump --single-transaction "$DB" $TABLES | gzip > "$BK/pre-sheet-v7-autolink-$ts.sql.gz"
  echo "AUTOLINK ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v7-autolink-$ts.sql.gz"
  echo "autolink-rollback $ts"
  php "$APP/ops/auto_link_sheet_names.php" --org=2
  echo "AUTOLINK OK"
  echo "autolink-rollback $ts"
}

autolink_rollback_cmd() {
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 autolink-rollback <ts>"; exit 2; }
  local dump=$BK/pre-sheet-v7-autolink-$ts.sql.gz
  [[ -f $dump ]] || { echo "Missing $dump"; exit 1; }
  gunzip -c "$dump" | mysql "$DB"
  echo "AUTOLINK ROLLBACK DONE"
  echo "autolink-rollback $ts"
  echo "$dump"
}

case $MODE in
  check) check ;;
  go) go ;;
  rollback) rollback_cmd "$TSARG" ;;
  autolink-dry) php "$APP/ops/auto_link_sheet_names.php" --dry-run --org=2 ;;
  autolink) autolink_cmd ;;
  autolink-rollback) autolink_rollback_cmd "$TSARG" ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>|autolink-dry|autolink|autolink-rollback <ts>"
    exit 2
    ;;
esac
