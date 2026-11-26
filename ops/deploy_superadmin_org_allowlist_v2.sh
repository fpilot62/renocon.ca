#!/bin/bash
# Deploy the platform-superadmin payroll org allowlist (PK BC Food id 2,
# AA Group id 4) on top of the live privacy files.
#
# v1's check failed because live engine/privacy_lib.php and
# tests/privacy_lib_test.php had moved past the old base. v2 accepts those
# live copies as its privacy base, then installs the allowlist commit.
# The outstanding-report files still accept the older base that live
# matched (d4264134). No database schema or payroll-data change. Nobody
# is marked paid. Does not touch Sheet Review.
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_superadmin_org_allowlist_v2.sh check
#   bash deploy_superadmin_org_allowlist_v2.sh go
#   bash deploy_superadmin_org_allowlist_v2.sh rollback <ts>
#
# check is read-only and stops on DRIFT. go refuses unless check prints
# CHECK OK, then backs up the database and the files it will replace and
# runs php -l. Do not run go until Abdul says yes.

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
WORK=${ALLOWLIST_WORK:-/root/allowlist-work}
URL=${PAYROLL_URL:-https://payroll.ecommi.com}
RAW=${ALLOWLIST_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

# Sync-with-live commit. Its privacy_lib.php and privacy_lib_test.php are
# the live bytes (md5 129fad976dbcb6d2718cd6f484e3a93b and
# 95d7e4d167f0b90955748cbf1bc49812).
PRIVACY_BASE=${ALLOWLIST_PRIVACY_BASE:-bff5d9e2699734d9864ec9fc00921edda7de5d28}
# Outstanding-report files. Live matched this commit, not later main.
OUTSTANDING_BASE=${ALLOWLIST_OUTSTANDING_BASE:-d4264134e5c220657f1c7e7051d81d3490893786}
# Feature commit that re-applies SUPERADMIN_PAYROLL_ORG_ALLOWLIST=[2,4].
PIN=${ALLOWLIST_PIN:-bf884f8349dc0c1c35082b4184cd5cc41bdbbb7f}

FILES='engine/privacy_lib.php
engine/outstanding_report_lib.php
outstanding_report.php
tests/privacy_lib_test.php
tests/outstanding_report_test.php'

if [[ ${ALLOWLIST_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

base_for() {
  case $1 in
    engine/privacy_lib.php|tests/privacy_lib_test.php) printf '%s\n' "$PRIVACY_BASE" ;;
    *) printf '%s\n' "$OUTSTANDING_BASE" ;;
  esac
}

# Live md5 each base blob must hash to. check fails if the fetched base
# is not these bytes, so a wrong BASE cannot look like a match.
expect_base_md() {
  case $1 in
    engine/privacy_lib.php) printf '%s\n' '129fad976dbcb6d2718cd6f484e3a93b' ;;
    tests/privacy_lib_test.php) printf '%s\n' '95d7e4d167f0b90955748cbf1bc49812' ;;
    engine/outstanding_report_lib.php) printf '%s\n' '052ca28e6773747308cc2fb3a3beb132' ;;
    outstanding_report.php) printf '%s\n' 'b9f614fa4ef7fcb5509b5a736cb66cd0' ;;
    tests/outstanding_report_test.php) printf '%s\n' 'd3d0be91ecc2c1d165d9db2ab607706e' ;;
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
  local tgz=$BK/pre-allowlist-$ts-files.tgz
  [[ -f $tgz ]] || { echo "Missing $tgz"; return 1; }
  tar -xzpf "$tgz" -C "$APP"
}

check() {
  if [[ $PIN == b102669ddf092363c06de2ed3aebd587fae9d970 || $PIN == 3e5c949c42fd0cbf7aacca17e1ec11b86f384431 || -z $PIN ]]; then
    echo "PIN is not set. Set ALLOWLIST_PIN to the v2 feature commit."
    return 1
  fi
  rm -rf "$WORK"
  mkdir -p "$WORK/base" "$WORK/pin" "$WORK/live"
  local bad=0 rel live pin base base_sha
  echo "PRIVACY_BASE=$PRIVACY_BASE"
  echo "OUTSTANDING_BASE=$OUTSTANDING_BASE"
  echo "PIN=$PIN"
  echo "APP=$APP"
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    live=$APP/$rel
    pin=$WORK/pin/$rel
    base=$WORK/base/$rel
    base_sha=$(base_for "$rel")
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
    if ! fetch_sha "$base_sha" "$rel" "$base"; then
      echo "FETCH_FAIL base $rel $base_sha"
      bad=1
      continue
    fi
    local live_md pin_md base_md want_md
    pin_md=$(md "$pin")
    base_md=$(md "$base")
    want_md=$(expect_base_md "$rel")
    if [[ -n $want_md && $base_md != "$want_md" ]]; then
      echo "BASE_HASH_FAIL $rel base=$base_md expected=$want_md sha=$base_sha"
      bad=1
      continue
    fi
    if [[ ! -e $live ]]; then
      echo "MISSING $rel (will be added on go)"
      continue
    fi
    mkdir -p "$(dirname "$WORK/live/$rel")"
    cp -f "$live" "$WORK/live/$rel"
    live_md=$(md "$live")
    if [[ $live_md == "$pin_md" ]]; then
      echo "MATCH_PIN $rel $live_md"
    elif [[ $live_md == "$base_md" ]]; then
      echo "MATCH_BASE $rel $live_md"
    else
      echo "DRIFT $rel live=$live_md pin=$pin_md base=$base_md"
      diff -u "$base" "$live" | sed -n '1,80p' || true
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
  echo "$BK/pre-allowlist-$ts.sql.gz"
  echo "$BK/pre-allowlist-$ts-files.tgz"
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-allowlist-$ts.sql.gz"
  : > "$BK/pre-allowlist-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-allowlist-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-allowlist-$ts-files.list ]]; then
    tar -czpf "$BK/pre-allowlist-$ts-files.tgz" -C "$APP" -T "$BK/pre-allowlist-$ts-files.list"
  else
    tar -czpf "$BK/pre-allowlist-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-allowlist-$ts.sql.gz"
  echo "$BK/pre-allowlist-$ts-files.tgz"

  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    mkdir -p "$APP/$(dirname "$rel")"
    local spec
    spec=$(neighbour_spec "$APP/$rel")
    # \cp avoids the interactive alias on the WHM shell.
    \cp -f "$WORK/pin/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if ! php -l "$APP/$rel"; then
      echo "php -l failed on $rel"
      restore_files "$ts"
      echo "ROLLBACK POINT=$ts"
      echo "$BK/pre-allowlist-$ts.sql.gz"
      echo "$BK/pre-allowlist-$ts-files.tgz"
      exit 1
    fi
  done <<< "$FILES"

  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-allowlist-$ts.sql.gz"
  echo "$BK/pre-allowlist-$ts-files.tgz"
  echo "rollback: bash /root/deploy_superadmin_org_allowlist_v2.sh rollback $ts"
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
