#!/bin/bash
# Sheet Review after the outstanding v2 install (PIN 312b9313):
#   the pay summary starts collapsed
#   one line stays visible: clock-in payroll net minus the By Abdul sheet net
#   outstanding add-period and add-payment forms are one compact row each
#
# Live base is the files at 312b931378ea09f767bd90716b7fc0bdb4c82d14.
#   payroll_sheet_review.php         3b6af60009f0f2c067fa4a3446f02bcf
#   engine/sheet_summary_lib.php     cc0cab82bcd344a335d735308b370338
#   tests/sheet_pay_summary_test.php eb0a8f76dd1abdaa87fae192138f33fe
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_sheet_review_v5.sh check
#   bash deploy_sheet_review_v5.sh go
#   bash deploy_sheet_review_v5.sh rollback <ts>
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

# Files as installed by deploy_outstanding_summary_v2.sh (PIN 312b9313).
BASE=${SHEET_REVIEW_BASE:-312b931378ea09f767bd90716b7fc0bdb4c82d14}
# Feature commit: collapsed pay summary and the clock-in versus sheet line.
PIN=${SHEET_REVIEW_PIN:-05ad2830f86fd11f76aa1be1a9b641c7277e4555}

FILES='engine/sheet_summary_lib.php
payroll_sheet_review.php
tests/sheet_pay_summary_test.php'

if [[ ${SHEET_REVIEW_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

# Empty means the file is new and has no live base.
expect_base_md() {
  case $1 in
    payroll_sheet_review.php) printf '%s\n' '3b6af60009f0f2c067fa4a3446f02bcf' ;;
    engine/sheet_summary_lib.php) printf '%s\n' 'cc0cab82bcd344a335d735308b370338' ;;
    tests/sheet_pay_summary_test.php) printf '%s\n' 'eb0a8f76dd1abdaa87fae192138f33fe' ;;
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

restore_files() {
  local ts=$1
  local tgz=$BK/pre-sheet-v5-$ts-files.tgz
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
  if [[ -z $PIN || $PIN == 312b931378ea09f767bd90716b7fc0bdb4c82d14 ]]; then
    echo "PIN is not set. Set SHEET_REVIEW_PIN to the v5 feature commit."
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
    if ! php -l "$pin" >/dev/null 2>&1; then
      echo "PHP_L_FAIL pin $rel"
      bad=1
      continue
    fi
    local pin_md want_md
    pin_md=$(md "$pin")
    want_md=$(expect_base_md "$rel")
    if [[ -n $want_md ]]; then
      if ! fetch_sha "$BASE" "$rel" "$base"; then
        echo "FETCH_FAIL base $rel $BASE"
        bad=1
        continue
      fi
      local base_md
      base_md=$(md "$base")
      if [[ $base_md != "$want_md" ]]; then
        echo "BASE_HASH_FAIL $rel base=$base_md expected=$want_md sha=$BASE"
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
  echo "ROLLBACK DONE"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v5-$ts.sql.gz"
  echo "$BK/pre-sheet-v5-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-sheet-v5-$ts.sql.gz"
  : > "$BK/pre-sheet-v5-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-sheet-v5-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-sheet-v5-$ts-files.list ]]; then
    tar -czpf "$BK/pre-sheet-v5-$ts-files.tgz" -C "$APP" -T "$BK/pre-sheet-v5-$ts-files.list"
  else
    tar -czpf "$BK/pre-sheet-v5-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v5-$ts.sql.gz"
  echo "$BK/pre-sheet-v5-$ts-files.tgz"

  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    mkdir -p "$APP/$(dirname "$rel")"
    local spec
    spec=$(neighbour_spec "$APP/$rel")
    \cp -f "$WORK/pin/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if ! php -l "$APP/$rel"; then
      echo "php -l failed on $rel"
      restore_files "$ts"
      echo "ROLLBACK POINT=$ts"
      echo "$BK/pre-sheet-v5-$ts.sql.gz"
      echo "$BK/pre-sheet-v5-$ts-files.tgz"
      exit 1
    fi
  done <<< "$FILES"

  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-v5-$ts.sql.gz"
  echo "$BK/pre-sheet-v5-$ts-files.tgz"
  echo "rollback: bash /root/deploy_sheet_review_v5.sh rollback $ts"
}

case $MODE in
  check) check ;;
  go) go ;;
  rollback) rollback_cmd "$TSARG" ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>"
    exit 2
    ;;
esac
