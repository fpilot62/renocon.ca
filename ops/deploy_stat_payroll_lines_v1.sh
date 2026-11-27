#!/bin/bash
# Approved stat rows become payroll lines and pay-stub lines.
# Public holiday pay, holiday-date hours at 1.5x, and those hours taken
# off the worked shift. Pending and Ignore stay out of pay and put the
# hours back. Nothing is marked paid.
#
# Live base is main at 13d253ed023671b71cc4d439bb143e1b1329fabb
# (PR #73, the holiday-hour split). Check stops on any other bytes.
#   generate_stat_shifts.php                 8e4d4e709bcac8fdc9fca7af83c3bde7
#   generate_pay_documents.php               b8baf2da397c5d18c9b06ef29f51effc
#   print_payrun.php                         d01156c746cd54320f59fd98b9fb5f8b
#   payroll_master_ledger.php                c473148713da6db912a4903222b2b645
#   payroll_master_ledger1.php               7dd268aeefb455cbec1aacff2e40eac5
#   payroll_engine/stat_holidays.php         74eb1c65f373077250f6de02fd3dc594
#   payroll_engine/payroll_engine.php        5e00951eb142c35d9ad846af03e92fab
#
#   bash deploy_stat_payroll_lines_v1.sh check
#   bash deploy_stat_payroll_lines_v1.sh dry
#   bash deploy_stat_payroll_lines_v1.sh go
#   bash deploy_stat_payroll_lines_v1.sh rollback <ts>
#
# dry only prints org 4 Thanksgiving 2026-10-12 rows, old total beside
# the total Approve would post. It does not write.

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
WORK=${STAT_LINES_WORK:-/root/stat-payroll-lines-work}
RAW=${STAT_LINES_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

BASE=${STAT_LINES_BASE:-13d253ed023671b71cc4d439bb143e1b1329fabb}
PIN=${STAT_LINES_PIN:-b6e6f9e83668afe15a2e58bdcce924714d5f5661}

FILES='generate_stat_shifts.php
generate_pay_documents.php
print_payrun.php
payroll_master_ledger.php
payroll_master_ledger1.php
payroll_engine/stat_holidays.php
payroll_engine/payroll_engine.php
ops/stat_payroll_lines_dry.php'

if [[ ${STAT_LINES_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

expect_git_md() {
  case $1 in
    generate_stat_shifts.php) printf '%s\n' '8e4d4e709bcac8fdc9fca7af83c3bde7' ;;
    generate_pay_documents.php) printf '%s\n' 'b8baf2da397c5d18c9b06ef29f51effc' ;;
    print_payrun.php) printf '%s\n' 'd01156c746cd54320f59fd98b9fb5f8b' ;;
    payroll_master_ledger.php) printf '%s\n' 'c473148713da6db912a4903222b2b645' ;;
    payroll_master_ledger1.php) printf '%s\n' '7dd268aeefb455cbec1aacff2e40eac5' ;;
    payroll_engine/stat_holidays.php) printf '%s\n' '74eb1c65f373077250f6de02fd3dc594' ;;
    payroll_engine/payroll_engine.php) printf '%s\n' '5e00951eb142c35d9ad846af03e92fab' ;;
    *) printf '\n' ;;
  esac
}

live_ok() {
  case $1 in
    generate_stat_shifts.php) [[ $2 == 8e4d4e709bcac8fdc9fca7af83c3bde7 ]] ;;
    generate_pay_documents.php) [[ $2 == b8baf2da397c5d18c9b06ef29f51effc ]] ;;
    print_payrun.php) [[ $2 == d01156c746cd54320f59fd98b9fb5f8b ]] ;;
    payroll_master_ledger.php) [[ $2 == c473148713da6db912a4903222b2b645 ]] ;;
    payroll_master_ledger1.php) [[ $2 == 7dd268aeefb455cbec1aacff2e40eac5 ]] ;;
    payroll_engine/stat_holidays.php) [[ $2 == 74eb1c65f373077250f6de02fd3dc594 ]] ;;
    payroll_engine/payroll_engine.php) [[ $2 == 5e00951eb142c35d9ad846af03e92fab ]] ;;
    *) return 1 ;;
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
  local tgz=$BK/pre-stat-lines-$ts-files.tgz
  [[ -f $tgz ]] || { echo "Missing $tgz"; return 1; }
  tar -xzpf "$tgz" -C "$APP"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if ! tar -tzf "$tgz" | grep -qx "$rel"; then
      rm -f "$APP/$rel"
    fi
  done <<< "$FILES"
}

check() {
  if [[ -z $PIN || $PIN == "$BASE" ]]; then
    echo "PIN is not set. Set STAT_LINES_PIN to the stat-lines commit."
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
    local pin_md git_want
    pin_md=$(md "$pin")
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
    elif live_ok "$rel" "$live_md"; then
      echo "MATCH_BASE $rel $live_md"
    else
      echo "DRIFT $rel live=$live_md pin=$pin_md"
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
  echo "$BK/pre-stat-lines-$ts.sql.gz"
  echo "$BK/pre-stat-lines-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-stat-lines-$ts.sql.gz"
  : > "$BK/pre-stat-lines-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-stat-lines-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-stat-lines-$ts-files.list ]]; then
    tar -czpf "$BK/pre-stat-lines-$ts-files.tgz" -C "$APP" -T "$BK/pre-stat-lines-$ts-files.list"
  else
    tar -czpf "$BK/pre-stat-lines-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-stat-lines-$ts.sql.gz"
  echo "$BK/pre-stat-lines-$ts-files.tgz"

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
      echo "ROLLBACK POINT=$ts"
      exit 1
    fi
  done <<< "$FILES"

  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-stat-lines-$ts.sql.gz"
  echo "$BK/pre-stat-lines-$ts-files.tgz"
  echo "rollback: bash /root/deploy_stat_payroll_lines_v1.sh rollback $ts"
}

dry() {
  rm -rf "$WORK"
  mkdir -p "$WORK/pin"
  local rel
  for rel in payroll_engine/stat_holidays.php payroll_engine/payroll_engine.php ops/stat_payroll_lines_dry.php; do
    if ! fetch_sha "$PIN" "$rel" "$WORK/pin/$rel"; then
      echo "FETCH_FAIL pin $rel $PIN"
      exit 1
    fi
  done
  if ! php -l "$WORK/pin/ops/stat_payroll_lines_dry.php" >/dev/null; then
    echo "PHP_L_FAIL dry script"
    exit 1
  fi
  STAT_SPLIT_LIB="$WORK/pin" PAYROLL_APP="$APP" php "$WORK/pin/ops/stat_payroll_lines_dry.php" --org=4 --date=2026-10-12
}

case $MODE in
  check) check ;;
  go) go ;;
  rollback) rollback_cmd "$TSARG" ;;
  dry) dry ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>|dry"
    exit 2
    ;;
esac
