#!/bin/bash
# Read-only. Restores the cleanup backup into a temporary database, compares
# auto-break hours removed with the payroll hours added, then drops that database.
# Writes nothing to admin_payroll.
#   bash auto_break_off_verify_v1.sh
#   bash auto_break_off_verify_v1.sh /root/payroll-daily/pre-autobreak-off2-20261121-033415/tables.sql.gz
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
PHP_BIN=${PHP_BIN:-php}
ANALYZER="$HERE/auto_break_off_verify.php"
LIVE_DB=${PAYROLL_DB:-admin_payroll}
BACKUP=${1:-/root/payroll-daily/pre-autobreak-off2-20261121-033415/tables.sql.gz}

if [[ ! -f $ANALYZER ]]; then
  echo "Missing $ANALYZER. Keep it in the same directory as this script."
  exit 1
fi

if [[ ${VERIFY_SQLITE:-0} == 1 ]]; then
  VERIFY_SQLITE=1 "$PHP_BIN" "$ANALYZER"
  exit $?
fi

if [[ ! -f $BACKUP ]]; then
  echo "Backup not found: $BACKUP"
  exit 1
fi
stamp=$(date +%Y%m%d_%H%M%S)
VERIFY_DB=${VERIFY_DB:-admin_payroll_verify_$stamp}
if [[ $VERIFY_DB == "$LIVE_DB" || $VERIFY_DB == admin_payroll ]]; then
  echo "Refusing to use $LIVE_DB as the temporary database."
  exit 1
fi
MYSQL=${T4127_MYSQL:-mysql}
cleanup() {
  "$MYSQL" --batch -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`" >/dev/null 2>&1 || \
    "$MYSQL" --batch -e "DROP DATABASE IF EXISTS \`$VERIFY_DB\`" || true
  echo "Dropped temporary database $VERIFY_DB"
}
trap cleanup EXIT
echo "Temporary database: $VERIFY_DB"
echo "Live database (SELECT only): $LIVE_DB"
echo "Backup: $BACKUP"
"$MYSQL" --batch -e "CREATE DATABASE \`$VERIFY_DB\`"
gzip -dc "$BACKUP" | sed -E '/^USE `/d; /^CREATE DATABASE/d; /^DROP DATABASE/d' | "$MYSQL" --batch "$VERIFY_DB"
echo "Restored the backup into $VERIFY_DB. Nothing was written to $LIVE_DB."
VERIFY_DB="$VERIFY_DB" LIVE_DB="$LIVE_DB" "$PHP_BIN" "$ANALYZER"
echo "Verification finished. $LIVE_DB was not modified."
