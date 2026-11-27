#!/bin/bash
# Holiday premium counts only the minutes on the holiday date, in the
# site's local time. Public holiday pay stays. Already approved lines
# are not rewritten.
#
# Live base is main at 6de94918bb649e9aa942f83d2ed3dedc2d6201e5, which is
# also what deploy_stat_approval_flow_v3.sh installs. Older live copies
# recorded by that script are accepted too. Anything else is DRIFT.
#   payroll_engine/stat_holidays.php
#     db78d955bf14710c4cdff634578c383d
#     bfd38c928b5d33b4f5c088004d001ee1
#   generate_stat_shifts.php
#     25169c2356945e43219f092cff10d76c
#     e3d130a70f2196f8e89ba84016f362c6
#     6dc31c3c0b190e5d5e22f48fc5b6ae20
#
#   bash deploy_stat_worked_split_v1.sh check
#   bash deploy_stat_worked_split_v1.sh go
#   bash deploy_stat_worked_split_v1.sh rollback <ts>
#   bash deploy_stat_worked_split_v1.sh dry
#
# dry only prints org 4 Thanksgiving 2026-10-12 rows whose worked-holiday
# hours or premium would change. It does not write.

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
WORK=${STAT_SPLIT_WORK:-/root/stat-worked-split-work}
RAW=${STAT_SPLIT_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

BASE=${STAT_SPLIT_BASE:-6de94918bb649e9aa942f83d2ed3dedc2d6201e5}
PIN=${STAT_SPLIT_PIN:-15902ae54ddaec4d7ceae9d293fa1bd419a52ee0}

FILES='generate_stat_shifts.php
ops/stat_worked_split_dry.php
payroll_engine/stat_holidays.php
tests/stat_worked_split_test.php'

if [[ ${STAT_SPLIT_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

expect_git_md() {
  case $1 in
    payroll_engine/stat_holidays.php) printf '%s\n' 'db78d955bf14710c4cdff634578c383d' ;;
    generate_stat_shifts.php) printf '%s\n' '25169c2356945e43219f092cff10d76c' ;;
    *) printf '\n' ;;
  esac
}

live_ok() {
  case $1 in
    payroll_engine/stat_holidays.php)
      case $2 in
        db78d955bf14710c4cdff634578c383d|bfd38c928b5d33b4f5c088004d001ee1) return 0 ;;
      esac
      ;;
    generate_stat_shifts.php)
      case $2 in
        25169c2356945e43219f092cff10d76c|e3d130a70f2196f8e89ba84016f362c6|6dc31c3c0b190e5d5e22f48fc5b6ae20) return 0 ;;
      esac
      ;;
  esac
  return 1
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
  local tgz=$BK/pre-stat-split-$ts-files.tgz
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
    echo "PIN is not set. Set STAT_SPLIT_PIN to the holiday-split commit."
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
  echo "$BK/pre-stat-split-$ts.sql.gz"
  echo "$BK/pre-stat-split-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-stat-split-$ts.sql.gz"
  : > "$BK/pre-stat-split-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-stat-split-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-stat-split-$ts-files.list ]]; then
    tar -czpf "$BK/pre-stat-split-$ts-files.tgz" -C "$APP" -T "$BK/pre-stat-split-$ts-files.list"
  else
    tar -czpf "$BK/pre-stat-split-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-stat-split-$ts.sql.gz"
  echo "$BK/pre-stat-split-$ts-files.tgz"

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
  echo "$BK/pre-stat-split-$ts.sql.gz"
  echo "$BK/pre-stat-split-$ts-files.tgz"
  echo "rollback: bash /root/deploy_stat_worked_split_v1.sh rollback $ts"
}

dry() {
  rm -rf "$WORK"
  mkdir -p "$WORK/pin"
  local rel
  for rel in payroll_engine/stat_holidays.php ops/stat_worked_split_dry.php; do
    if ! fetch_sha "$PIN" "$rel" "$WORK/pin/$rel"; then
      echo "FETCH_FAIL pin $rel $PIN"
      exit 1
    fi
  done
  if ! php -l "$WORK/pin/ops/stat_worked_split_dry.php" >/dev/null; then
    echo "PHP_L_FAIL dry script"
    exit 1
  fi
  STAT_SPLIT_LIB="$WORK/pin" PAYROLL_APP="$APP" php "$WORK/pin/ops/stat_worked_split_dry.php" --org=4 --date=2026-10-12
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
