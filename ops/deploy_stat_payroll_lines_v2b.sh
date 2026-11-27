#!/bin/bash
# Calculated stat lines go into payroll and onto cheques and pay stubs
# with no Approve click. Pending and Ignore are optional and put the
# regular hours back. Nothing is marked paid. Already approved lines
# are left as posted.
#
# Variant (b): same stat lines, and restores the privacy gates and masked bank account on the pay stub and the ledger. See the PR note for who can lose access.
#
# Live bases, measured on prod and in git:
#   generate_pay_documents.php   cefb66f11a795d837e3d3f3e1d06c688
#     commit 30de1fd0e2fa28f06fbb6063300b459c34d1ae6d
#   payroll_master_ledger.php    411e42f1ce0402396e3b73e5d10bec60
#     same commit. Also accepted: 411e42f1ce9402396e3b73e5d10bec60
#   generate_stat_shifts.php     8e4d4e709bcac8fdc9fca7af83c3bde7
#   print_payrun.php             d01156c746cd54320f59fd98b9fb5f8b
#   payroll_master_ledger1.php   7dd268aeefb455cbec1aacff2e40eac5
#   payroll_engine/stat_holidays.php 74eb1c65f373077250f6de02fd3dc594
#   payroll_engine/payroll_engine.php 5e00951eb142c35d9ad846af03e92fab
#     those five are main at 13d253ed023671b71cc4d439bb143e1b1329fabb
#
#   bash deploy_stat_payroll_lines_v2b.sh check|dry|go|rollback <ts>
# dry lists org 4 Thanksgiving 2026-10-12 and does not write.

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
WORK=${STAT_LINES_WORK:-/root/stat-payroll-lines-v2b-work}
RAW=${STAT_LINES_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}
PIN=${STAT_LINES_PIN:-9fd69c3997105ca9f1b7d6d2d610c22109f7ef7f}
NOV23=30de1fd0e2fa28f06fbb6063300b459c34d1ae6d
MAIN73=13d253ed023671b71cc4d439bb143e1b1329fabb

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

base_sha() {
  case $1 in
    generate_pay_documents.php|payroll_master_ledger.php) printf '%s\n' "$NOV23" ;;
    *) printf '%s\n' "$MAIN73" ;;
  esac
}

expect_git_md() {
  case $1 in
    generate_pay_documents.php) printf '%s\n' 'cefb66f11a795d837e3d3f3e1d06c688' ;;
    payroll_master_ledger.php) printf '%s\n' '411e42f1ce0402396e3b73e5d10bec60' ;;
    generate_stat_shifts.php) printf '%s\n' '8e4d4e709bcac8fdc9fca7af83c3bde7' ;;
    print_payrun.php) printf '%s\n' 'd01156c746cd54320f59fd98b9fb5f8b' ;;
    payroll_master_ledger1.php) printf '%s\n' '7dd268aeefb455cbec1aacff2e40eac5' ;;
    payroll_engine/stat_holidays.php) printf '%s\n' '74eb1c65f373077250f6de02fd3dc594' ;;
    payroll_engine/payroll_engine.php) printf '%s\n' '5e00951eb142c35d9ad846af03e92fab' ;;
    *) printf '\n' ;;
  esac
}

live_ok() {
  case $1 in
    generate_pay_documents.php) [[ $2 == cefb66f11a795d837e3d3f3e1d06c688 ]] ;;
    payroll_master_ledger.php) [[ $2 == 411e42f1ce0402396e3b73e5d10bec60 || $2 == 411e42f1ce9402396e3b73e5d10bec60 ]] ;;
    generate_stat_shifts.php) [[ $2 == 8e4d4e709bcac8fdc9fca7af83c3bde7 ]] ;;
    print_payrun.php) [[ $2 == d01156c746cd54320f59fd98b9fb5f8b ]] ;;
    payroll_master_ledger1.php) [[ $2 == 7dd268aeefb455cbec1aacff2e40eac5 ]] ;;
    payroll_engine/stat_holidays.php) [[ $2 == 74eb1c65f373077250f6de02fd3dc594 ]] ;;
    payroll_engine/payroll_engine.php) [[ $2 == 5e00951eb142c35d9ad846af03e92fab ]] ;;
    *) return 1 ;;
  esac
}

pin_src() {
  case $1 in

    *) printf '%s\n' "$1" ;;
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
  local tgz=$BK/pre-stat-lines-v2b-$ts-files.tgz
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
  if [[ -z $PIN ]]; then
    echo "PIN is not set."
    return 1
  fi
  rm -rf "$WORK"
  mkdir -p "$WORK/base" "$WORK/pin" "$WORK/live"
  local bad=0 rel live pin base src sha
  echo "PIN=$PIN"
  echo "APP=$APP"
  echo "echo "variant b: restores privacy gates""
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    live=$APP/$rel
    pin=$WORK/pin/$rel
    base=$WORK/base/$rel
    src=$(pin_src "$rel")
    if ! fetch_sha "$PIN" "$src" "$pin"; then
      echo "FETCH_FAIL pin $src $PIN"
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
      sha=$(base_sha "$rel")
      if ! fetch_sha "$sha" "$rel" "$base"; then
        echo "FETCH_FAIL base $rel $sha"
        bad=1
        continue
      fi
      local base_md
      base_md=$(md "$base")
      if [[ $base_md != "$git_want" ]]; then
        echo "BASE_HASH_FAIL $rel base=$base_md expected=$git_want sha=$sha"
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
        diff -u "$base" "$live" | sed -n '1,40p' || true
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
  echo "$BK/pre-stat-lines-v2b-$ts.sql.gz"
  echo "$BK/pre-stat-lines-v2b-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-stat-lines-v2b-$ts.sql.gz"
  : > "$BK/pre-stat-lines-v2b-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-stat-lines-v2b-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-stat-lines-v2b-$ts-files.list ]]; then
    tar -czpf "$BK/pre-stat-lines-v2b-$ts-files.tgz" -C "$APP" -T "$BK/pre-stat-lines-v2b-$ts-files.list"
  else
    tar -czpf "$BK/pre-stat-lines-v2b-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
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
  echo "$BK/pre-stat-lines-v2b-$ts.sql.gz"
  echo "$BK/pre-stat-lines-v2b-$ts-files.tgz"
  echo "rollback: bash /root/deploy_stat_payroll_lines_v2b.sh rollback $ts"
}

dry() {
  rm -rf "$WORK"
  mkdir -p "$WORK/pin/ops" "$WORK/pin/payroll_engine"
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
