#!/bin/bash
# Run an existing deploy script against the staging copy first.
# The default target is staging, so this entry point does not touch live.
#
#   bash ops/deploy_to_staging.sh check
#   bash ops/deploy_to_staging.sh ops/deploy_stat_approval_v1.sh check
#   bash ops/deploy_to_staging.sh check ops/deploy_stat_approval_v1.sh
#   TARGET=live bash ops/deploy_to_staging.sh ops/deploy_help_v1.sh check
#
# Scripts that read PAYROLL_APP and PAYROLL_DB pick up the target.
# A script that pins APP=/home/renocon/public_html/payroll is refused
# when the target is staging. Direct use is the same switch:
#   TARGET=staging bash ops/deploy_stat_approval_v1.sh check
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
# This entry point defaults to staging. Deploy scripts sourced on their own
# still default to live.
: "${TARGET:=staging}"
# shellcheck disable=SC1091
source "$HERE/deploy_target.inc.sh"

say_target() {
  printf 'TARGET=%s\n' "$TARGET"
  printf 'PAYROLL_APP=%s\n' "$PAYROLL_APP"
  printf 'PAYROLL_DB=%s\n' "$PAYROLL_DB"
  if [[ $TARGET == staging ]]; then
    printf 'PAYROLL_URL=%s\n' "$PAYROLL_URL"
    printf 'PAYROLL_ENV_FILE=%s\n' "$PAYROLL_ENV_FILE"
  fi
}

refuse_live_pin() {
  local script=$1
  if grep -E '^[[:space:]]*APP=/home/renocon/public_html/payroll([[:space:]]|$|;)' "$script" >/dev/null; then
    echo "Refusing $script: it pins the live docroot and ignores PAYROLL_APP." >&2
    exit 1
  fi
  if ! grep -q 'PAYROLL_APP' "$script"; then
    echo "Refusing $script: it does not read PAYROLL_APP, so it cannot be aimed at staging." >&2
    exit 1
  fi
  if [[ $TARGET == staging ]]; then
    refuse_live_pin_db "$script"
  fi
}

refuse_live_pin_db() {
  local script=$1
  if grep -E '^[[:space:]]*DB=admin_payroll([[:space:]]|$|;)' "$script" >/dev/null; then
    echo "Refusing $script: it pins the live database and ignores PAYROLL_DB." >&2
    exit 1
  fi
}

run_script() {
  local script=$1
  shift
  [[ -f $script ]] || { echo "No deploy script at $script" >&2; exit 2; }
  if [[ $TARGET == staging ]]; then
    refuse_live_pin "$script"
  fi
  say_target
  exec bash "$script" "$@"
}

usage() {
  echo "Usage: bash $0 check" >&2
  echo "       bash $0 <deploy-script> check|dry|go|rollback [args]" >&2
  echo "       TARGET=live bash $0 <deploy-script> check" >&2
  exit 2
}

first=${1:-}
second=${2:-}
if [[ $first == check && -z $second ]]; then
  say_target
  exit 0
fi
if [[ -n $first && -f $first ]]; then
  run_script "$@"
fi
if [[ -n $second && -f $second ]]; then
  mode=$first
  script=$second
  shift 2
  run_script "$script" "$mode" "$@"
fi
usage
