#!/bin/bash
# Sheet Review after the v3 install (PIN 05fa5a24):
#   secondary panels start collapsed and remember open/closed
#   selecting a line opens the highlighted sheet-source cells
# Paid now and the draft edit still do not mark anyone paid.
#
# Live base is the files at 05fa5a24487e3b1d5b5896ab7576e5bf3e8337dd,
# which is what deploy_sheet_hide_admin_v3.sh installs.
#   payroll_sheet_review.php       b234de20f969fd7b78e2fb0c55b5f290
#   engine/sheet_summary_lib.php   316134c3e26e71fa31d74d85a93e2566
#   tests/sheet_pay_summary_test.php c85e5340349f29529e05661b7ec45bd0
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_sheet_review_v4.sh check
#   bash deploy_sheet_review_v4.sh go
#   bash deploy_sheet_review_v4.sh rollback <ts>
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

# Files as installed by deploy_sheet_hide_admin_v3.sh (PIN 05fa5a24).
BASE=${SHEET_REVIEW_BASE:-05fa5a24487e3b1d5b5896ab7576e5bf3e8337dd}
# Feature commit: collapsed panels and the sheet-source side panel.
PIN=${SHEET_REVIEW_PIN:-5fe15d690baf592f39985db552e7cb32b6e53839}

FILES='engine/sheet_summary_lib.php
payroll_sheet_review.php
tests/sheet_pay_summary_test.php
tests/sheet_source_panel_test.php'

if [[ ${SHEET_REVIEW_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

# Empty means the file is new and has no live base.
expect_base_md() {
  case $1 in
    payroll_sheet_review.php) printf '%s\n' 'b234de20f969fd7b78e2fb0c55b5f290' ;;
    engine/sheet_summary_lib.php) printf '%s\n' '316134c3e26e71fa31d74d85a93e2566' ;;
    tests/sheet_pay_summary_test.php) printf '%s\n' 'c85e5340349f29529e05661b7ec45bd0' ;;
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
  local tgz=$BK/pre-sheet-review-$ts-files.tgz
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
  if [[ -z $PIN || $PIN == 05fa5a24487e3b1d5b5896ab7576e5bf3e8337dd ]]; then
    echo "PIN is not set. Set SHEET_REVIEW_PIN to the v4 feature commit."
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
  echo "$BK/pre-sheet-review-$ts.sql.gz"
  echo "$BK/pre-sheet-review-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-sheet-review-$ts.sql.gz"
  : > "$BK/pre-sheet-review-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-sheet-review-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-sheet-review-$ts-files.list ]]; then
    tar -czpf "$BK/pre-sheet-review-$ts-files.tgz" -C "$APP" -T "$BK/pre-sheet-review-$ts-files.list"
  else
    tar -czpf "$BK/pre-sheet-review-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-review-$ts.sql.gz"
  echo "$BK/pre-sheet-review-$ts-files.tgz"

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
      echo "$BK/pre-sheet-review-$ts.sql.gz"
      echo "$BK/pre-sheet-review-$ts-files.tgz"
      exit 1
    fi
  done <<< "$FILES"

  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-sheet-review-$ts.sql.gz"
  echo "$BK/pre-sheet-review-$ts-files.tgz"
  echo "rollback: bash /root/deploy_sheet_review_v4.sh rollback $ts"
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
