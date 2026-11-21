#!/bin/bash
# Correct the auto-break cleanup. Restore each changed line, then add only
# that shift's removed auto-break hours. Does not restore the break rows and
# does not change the off switch.
# An update that stores the values already on the row is a success.
# A line that is paid or cheque-printed is skipped instead of aborting the run.
#   bash auto_break_off_fix_v3.sh check
#   bash auto_break_off_fix_v3.sh dry
#   bash auto_break_off_fix_v3.sh why 9384
#   bash auto_break_off_fix_v3.sh go
#   bash auto_break_off_fix_v3.sh rollback <ts>
set -euo pipefail
if [[ ${FIX_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then echo "Run as root."; exit 1; fi
HERE=$(cd "$(dirname "$0")" && pwd)
PHP_BIN=${PHP_BIN:-php}
FIXER="$HERE/auto_break_off_fix.php"
MODE=${1:-check}
WHY_LINE=${2:-}
LIVE_DB=${PAYROLL_DB:-admin_payroll}
BACKUP=${FIX_BACKUP:-/root/payroll-daily/pre-autobreak-off2-20261121-033415/tables.sql.gz}
BK=${PAYROLL_BK:-/root/payroll-daily}
MYSQL=${T4127_MYSQL:-mysql}
stamp=$(date +%Y%m%d_%H%M%S)
VERIFY_DB=${VERIFY_DB:-admin_payroll_fixverify_$stamp}

if [[ ! -f $FIXER ]]; then
  echo "Missing $FIXER. Keep it in the same directory as this script."
  exit 1
fi
if [[ ${FIX_SQLITE:-0} == 1 ]]; then
  FIX_SQLITE=1 "$PHP_BIN" "$FIXER"
  exit $?
fi
if [[ $VERIFY_DB == "$LIVE_DB" || $VERIFY_DB == admin_payroll ]]; then
  echo "Refusing to use $LIVE_DB as the temporary database."
  exit 1
fi
if [[ $MODE == why && ! $WHY_LINE =~ ^[0-9]+$ ]]; then
  echo "Usage: bash $0 why <line id>"
  exit 1
fi
if [[ $MODE != rollback && ! -f $BACKUP ]]; then
  echo "Backup not found: $BACKUP"
  exit 1
fi

drop_verify() {
  if [[ -n ${VERIFY_DB:-} && $VERIFY_DB != "$LIVE_DB" && $VERIFY_DB != admin_payroll ]]; then
    "$MYSQL" --batch -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`" || true
    echo "Dropped temporary database $VERIFY_DB"
  fi
}

restore_lines() {
  local dump=$1
  echo "Restoring payroll_finalized from $dump"
  gzip -dc "$dump" | sed -E '/^USE `/d; /^CREATE DATABASE/d; /^DROP DATABASE/d' | "$MYSQL" --batch "$LIVE_DB"
}

case "$MODE" in
  check|dry|go|why)
    trap drop_verify EXIT
    echo "Temporary database: $VERIFY_DB"
    echo "Live database: $LIVE_DB"
    echo "Backup: $BACKUP"
    "$MYSQL" --batch -e "CREATE DATABASE \`$VERIFY_DB\`"
    gzip -dc "$BACKUP" | sed -E '/^USE `/d; /^CREATE DATABASE/d; /^DROP DATABASE/d' | "$MYSQL" --batch "$VERIFY_DB"
    echo "Restored the cleanup backup into $VERIFY_DB."
    GO_DUMP=""
    if [[ $MODE == go ]]; then
      TS=$(date +%Y%m%d-%H%M%S)
      GO_DUMP="$BK/pre-autobreak-fix-$TS.sql.gz"
      mkdir -p "$BK"
      ${T4127_MYSQLDUMP:-mysqldump} --single-transaction "$LIVE_DB" payroll_finalized | gzip -c > "$GO_DUMP"
      echo "Backed up current payroll_finalized to $GO_DUMP"
    fi
    set +e
    FIX_MODE="$MODE" FIX_LINE="$WHY_LINE" VERIFY_DB="$VERIFY_DB" LIVE_DB="$LIVE_DB" PAYROLL_APP="${PAYROLL_APP:-/home/renocon/public_html/payroll}" \
      "$PHP_BIN" "$FIXER"
    rc=$?
    set -e
    if [[ $rc -ne 0 ]]; then
      echo "$MODE failed: the fixer exited $rc."
      if [[ $MODE == go && -n $GO_DUMP ]]; then
        restore_lines "$GO_DUMP"
        echo "Restored the current payroll lines. Nothing from this fix stayed committed."
      fi
      exit "$rc"
    fi
    if [[ $MODE == go ]]; then
      echo "Fix committed. Rollback: bash $0 rollback $TS"
    fi
    ;;
  rollback)
    TS=${2:-}
    DUMP="$BK/pre-autobreak-fix-$TS.sql.gz"
    if [[ -z $TS || ! -f $DUMP ]]; then
      echo "Usage: bash $0 rollback <ts>"
      exit 1
    fi
    restore_lines "$DUMP"
    echo "Rolled payroll_finalized back to $DUMP. Auto breaks were not restored."
    ;;
  *)
    echo "Usage: bash $0 check|dry|why <line id>|go|rollback <ts>"
    exit 1
    ;;
esac
