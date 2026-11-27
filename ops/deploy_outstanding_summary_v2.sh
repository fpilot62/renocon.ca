#!/bin/bash
# Outstanding pay table for Sheet Review, v2.
# Periods are rows on each employee, not columns. The summary line shows
# totals only. Dated payments stay manual reconciliation records.
#
# Live base is the Sheet Review page installed by deploy_sheet_review_v4.sh
# (PIN 5fe15d690baf592f39985db552e7cb32b6e53839):
#   payroll_sheet_review.php  6ed8997a6324778e2e4ae0bdce58ccad
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_outstanding_summary_v2.sh check
#   bash deploy_outstanding_summary_v2.sh go
#   bash deploy_outstanding_summary_v2.sh rollback <ts>
#   bash deploy_outstanding_summary_v2.sh load /root/outstanding_summary.csv
#   bash deploy_outstanding_summary_v2.sh load-rollback <ts>
#
# check is read-only and stops on DRIFT. go refuses unless check prints
# CHECK OK. Do not run go until Abdul says yes. Do not commit the CSV.

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
WORK=${OUTSTANDING_WORK:-/root/outstanding-work}
RAW=${OUTSTANDING_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

BASE=${OUTSTANDING_BASE:-5fe15d690baf592f39985db552e7cb32b6e53839}
PIN=${OUTSTANDING_PIN:-312b931378ea09f767bd90716b7fc0bdb4c82d14}

FILES='engine/outstanding_summary_lib.php
migrations/20261127_payroll_outstanding_period_rows.sql
ops/load_outstanding_summary.php
payroll_sheet_review.php
tests/outstanding_summary_test.php'

TABLES='payroll_outstanding_summary payroll_outstanding_summary_audit payroll_outstanding_period_rows payroll_outstanding_payments'

if [[ ${OUTSTANDING_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

expect_base_md() {
  case $1 in
    payroll_sheet_review.php) printf '%s\n' '6ed8997a6324778e2e4ae0bdce58ccad' ;;
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
  local tgz=$BK/pre-outstanding-v2-$ts-files.tgz
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
  if [[ -z $PIN || $PIN == 5fe15d690baf592f39985db552e7cb32b6e53839 || $PIN == e81768664a8a48e1e0d185a87bafd87c354c3256 ]]; then
    echo "PIN is not set. Set OUTSTANDING_PIN to the v2 feature commit."
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
  echo "$BK/pre-outstanding-v2-$ts.sql.gz"
  echo "$BK/pre-outstanding-v2-$ts-files.tgz"
  echo "The database dump is not restored automatically."
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-outstanding-v2-$ts.sql.gz"
  : > "$BK/pre-outstanding-v2-$ts-files.list"
  local rel
  while IFS= read -r rel; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-outstanding-v2-$ts-files.list"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-outstanding-v2-$ts-files.list ]]; then
    tar -czpf "$BK/pre-outstanding-v2-$ts-files.tgz" -C "$APP" -T "$BK/pre-outstanding-v2-$ts-files.list"
  else
    tar -czpf "$BK/pre-outstanding-v2-$ts-files.tgz" --files-from=/dev/null
  fi
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-outstanding-v2-$ts.sql.gz"
  echo "$BK/pre-outstanding-v2-$ts-files.tgz"

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
      echo "$BK/pre-outstanding-v2-$ts.sql.gz"
      echo "$BK/pre-outstanding-v2-$ts-files.tgz"
      exit 1
    fi
  done <<< "$FILES"

  if ! mysql "$DB" < "$APP/migrations/20261127_payroll_outstanding_period_rows.sql"; then
    echo "migration failed"
    restore_files "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-outstanding-v2-$ts.sql.gz"
    echo "$BK/pre-outstanding-v2-$ts-files.tgz"
    exit 1
  fi

  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-outstanding-v2-$ts.sql.gz"
  echo "$BK/pre-outstanding-v2-$ts-files.tgz"
  echo "rollback: bash /root/deploy_outstanding_summary_v2.sh rollback $ts"
}

load_cmd() {
  local csv=$1
  [[ -n $csv && -f $csv ]] || { echo "Usage: bash $0 load <csv>"; exit 2; }
  mysql -N -e "SHOW TABLES LIKE 'payroll_outstanding_summary'" "$DB" | grep -q . \
    || { echo "Run go first so the outstanding tables exist."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  # shellcheck disable=SC2086
  mysqldump --single-transaction "$DB" $TABLES | gzip > "$BK/pre-outstanding-v2-load-$ts.sql.gz"
  echo "LOAD ROLLBACK POINT=$ts"
  echo "$BK/pre-outstanding-v2-load-$ts.sql.gz"
  if ! php "$APP/ops/load_outstanding_summary.php" --dry-run --org=2 "$csv"; then
    echo "dry-run failed. Nothing was loaded."
    exit 1
  fi
  php "$APP/ops/load_outstanding_summary.php" --org=2 "$csv"
  echo "LOAD OK"
  echo "LOAD ROLLBACK POINT=$ts"
  echo "load-rollback: bash /root/deploy_outstanding_summary_v2.sh load-rollback $ts"
}

load_rollback_cmd() {
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 load-rollback <ts>"; exit 2; }
  local dump=$BK/pre-outstanding-v2-load-$ts.sql.gz
  [[ -f $dump ]] || { echo "Missing $dump"; exit 1; }
  gunzip -c "$dump" | mysql "$DB"
  echo "LOAD ROLLBACK DONE"
  echo "LOAD ROLLBACK POINT=$ts"
  echo "$dump"
}

case $MODE in
  check) check ;;
  go) go ;;
  rollback) rollback_cmd "$TSARG" ;;
  load) load_cmd "$TSARG" ;;
  load-rollback) load_rollback_cmd "$TSARG" ;;
  *)
    echo "Usage: bash $0 check|go|rollback <ts>|load <csv>|load-rollback <ts>"
    exit 2
    ;;
esac
