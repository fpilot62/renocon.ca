#!/bin/bash
# Move unfinalized Sandbox Kitchen shifts onto Harvey so the existing
# org-2 Auto break rule covers them. Do not run deploy20.
#   bash deploy21_sandbox_to_harvey.sh check
#   bash deploy21_sandbox_to_harvey.sh go
#   bash deploy21_sandbox_to_harvey.sh rollback
# Pin: download this file by commit SHA. It does not replace PHP.
set -euo pipefail
M=${1:-check}
D=/root/payroll-daily
DB=admin_payroll
HARVEY=23
HARVEY_NAME='6. popeyes harvey'

mysql_n() { mysql -N --batch "$DB" -e "$1"; }
mysql_t() { mysql --batch --table "$DB" -e "$1"; }

has_col() {
  local table="$1" col="$2"
  local n
  n=$(mysql_n "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${table}' AND COLUMN_NAME='${col}'")
  [ "$n" = 1 ]
}

echo "MODE=$M"

site_id_col=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME IN ('SId','Sid','id') ORDER BY FIELD(COLUMN_NAME,'SId','Sid','id') LIMIT 1")
[ -n "$site_id_col" ] || { echo "STOP: sites has no id column."; exit 1; }
name_cols=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME IN ('nick_name','name','site_name') ORDER BY FIELD(COLUMN_NAME,'nick_name','name','site_name')")
[ -n "$name_cols" ] || { echo "STOP: sites has no name column."; exit 1; }
org_sql="0"
if has_col sites org_id; then org_sql='org_id'; fi
name_where=""
while IFS= read -r col; do
  [ -n "$col" ] || continue
  piece="LOWER(TRIM(\`${col}\`))='sandbox kitchen'"
  if [ -z "$name_where" ]; then name_where="$piece"; else name_where="$name_where OR $piece"; fi
done <<< "$name_cols"

first_name=$(printf '%s\n' "$name_cols" | head -1)
sandbox_sql="SELECT \`${site_id_col}\` AS site_id, ${org_sql} AS org_id, \`${first_name}\` AS site_name FROM sites WHERE ${name_where} ORDER BY site_id"
echo "SANDBOX SITES:"
sandbox_rows=$(mysql_n "$sandbox_sql" || true)
if [ -z "$sandbox_rows" ]; then
  echo "SANDBOX none"
  sandbox_in=""
else
  echo "$sandbox_rows" | while IFS=$'\t' read -r sid org name; do
    echo "SANDBOX site_id=${sid} org_id=${org} name=${name}"
  done
  sandbox_in=$(echo "$sandbox_rows" | cut -f1 | paste -sd, -)
fi

harvey_name_col=$(echo "$name_cols" | head -1)
harvey=$(mysql_n "SELECT \`${site_id_col}\`, ${org_sql}, \`${harvey_name_col}\` FROM sites WHERE \`${site_id_col}\`=${HARVEY} LIMIT 1" || true)
if [ -z "$harvey" ]; then
  echo "HARVEY site_id=${HARVEY} MISSING"
  harvey_ok=no
else
  hv_id=$(echo "$harvey" | cut -f1)
  hv_org=$(echo "$harvey" | cut -f2)
  hv_name=$(echo "$harvey" | cut -f3)
  echo "HARVEY site_id=${hv_id} org_id=${hv_org} name=${hv_name}"
  hv_name_lc=$(printf '%s' "$hv_name" | tr '[:upper:]' '[:lower:]')
  if [ "$hv_org" = 2 ] && printf '%s' "$hv_name_lc" | grep -q 'harvey'; then
    harvey_ok=yes
  else
    harvey_ok=no
  fi
fi
echo "HARVEY_OK=$harvey_ok"

att_cols=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance'")
has_att() { printf '%s\n' "$att_cols" | grep -qx "$1"; }
fin_parts=()
if has_att is_finalized; then fin_parts+=("COALESCE(a.is_finalized,0)=1"); fi
if has_att payout_status; then fin_parts+=("COALESCE(a.payout_status,0)<>0"); fi
if has_att is_processed; then fin_parts+=("COALESCE(a.is_processed,0)=1"); fi
if mysql_n "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payroll_finalized'" | grep -qx 1; then
  fin_parts+=("EXISTS (SELECT 1 FROM payroll_finalized pf WHERE pf.attendance_id=a.id)")
fi
if [ "${#fin_parts[@]}" -eq 0 ]; then
  FIN="0"
else
  FIN="${fin_parts[0]}"
  for part in "${fin_parts[@]:1}"; do
    FIN="${FIN} OR ${part}"
  done
fi
UNFIN="NOT (${FIN})"

if [ -n "$sandbox_in" ]; then
  echo "ATTENDANCE BY EMPLOYEE:"
  mysql_t "SELECT COALESCE(u.Name, CONCAT('user ', a.user_id)) AS employee,
      SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END) AS unfinalized,
      SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END) AS finalized,
      MIN(a.clock_in) AS first_in, MAX(a.clock_in) AS last_in
    FROM attendance a
    LEFT JOIN users u ON u.id=a.user_id
    WHERE a.site_id IN (${sandbox_in})
    GROUP BY a.user_id, u.Name
    ORDER BY employee"
  echo "ATTENDANCE RANGE:"
  mysql_t "SELECT COUNT(*) AS rows_total,
      SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END) AS unfinalized,
      SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END) AS finalized,
      MIN(clock_in) AS first_in, MAX(clock_in) AS last_in
    FROM attendance a WHERE a.site_id IN (${sandbox_in})"
  echo "FINALIZED ROWS (not moved):"
  mysql_t "SELECT a.id, COALESCE(u.Name, CONCAT('user ', a.user_id)) AS employee, a.clock_in, a.clock_out
    FROM attendance a
    LEFT JOIN users u ON u.id=a.user_id
    WHERE a.site_id IN (${sandbox_in}) AND ${FIN}
    ORDER BY a.clock_in, a.id"
  if has_col payroll_finalized site_id; then
    pf_site=$(mysql_n "SELECT COUNT(*) FROM payroll_finalized WHERE site_id IN (${sandbox_in})")
    echo "PAYROLL_FINALIZED_ON_SITE=${pf_site}"
  else
    echo "PAYROLL_FINALIZED_ON_SITE=no-site-column"
  fi
  if has_col payroll_finalized attendance_id; then
    pf_att=$(mysql_n "SELECT COUNT(*) FROM payroll_finalized pf INNER JOIN attendance a ON a.id=pf.attendance_id WHERE a.site_id IN (${sandbox_in})")
    echo "PAYROLL_FINALIZED_FOR_THOSE_SHIFTS=${pf_att}"
  fi
else
  echo "ATTENDANCE none"
  echo "PAYROLL_FINALIZED_ON_SITE=0"
fi

if has_col breaks site_id; then
  echo "BREAKS store site_id"
else
  echo "BREAKS have no site column; they follow shift_id and are not updated"
fi

if [ "$M" = check ]; then
  echo "CHECK ONLY. Nothing changed."
  exit 0
fi

if [ "$M" = rollback ]; then
  pointer=$D/pr21-sandbox-latest.ids
  [ -f "$pointer" ] || { echo "STOP: no move log. Nothing changed."; exit 1; }
  idfile=$(cat "$pointer")
  [ -f "$idfile" ] || { echo "STOP: id file $idfile is missing. Nothing changed."; exit 1; }
  restored=0
  has_org=no
  has_att org_id && has_org=yes
  while IFS=$'\t' read -r id old_site old_org; do
    [ -n "${id:-}" ] || continue
    printf '%s' "$id" | grep -Eq '^[0-9]+$' || continue
    printf '%s' "$old_site" | grep -Eq '^[0-9]+$' || continue
    if [ "$has_org" = yes ]; then
      printf '%s' "$old_org" | grep -Eq '^[0-9]+$' || continue
      mysql "$DB" -e "UPDATE attendance SET site_id=${old_site}, org_id=${old_org} WHERE id=${id} AND site_id=${HARVEY}"
    else
      mysql "$DB" -e "UPDATE attendance SET site_id=${old_site} WHERE id=${id} AND site_id=${HARVEY}"
    fi
    restored=$((restored + 1))
  done < "$idfile"
  echo "ROLLBACK restored ${restored} attendance row(s) from $idfile. Finalized rows and payroll_finalized were not changed. The Sandbox Kitchen site was not deleted."
  exit 0
fi

[ "$M" = go ] || { echo "Usage: bash deploy21_sandbox_to_harvey.sh check|go|rollback"; exit 2; }
[ "$harvey_ok" = yes ] || { echo "STOP: site ${HARVEY} is not Harvey in org 2. Nothing changed."; exit 1; }
[ -n "$sandbox_in" ] || { echo "MOVED 0. No Sandbox Kitchen site."; exit 0; }

org_select="0"
org_set=""
if has_att org_id; then
  org_select="a.org_id"
  org_set=", org_id=2"
fi
move_where="a.site_id IN (${sandbox_in}) AND ${UNFIN}"
preview=$(mysql_n "SELECT a.id, a.site_id, ${org_select} FROM attendance a WHERE ${move_where} ORDER BY a.id")
if [ -z "$preview" ]; then
  echo "MOVED 0. No unfinalized Sandbox Kitchen shifts."
  exit 0
fi

TS=$(date +%Y%m%d-%H%M%S)
mkdir -p "$D"
idfile=$D/pre-pr21-sandbox_$TS.ids
printf '%s\n' "$preview" > "$idfile"
echo "$idfile" > "$D/pr21-sandbox-latest.ids"
id_list=$(echo "$preview" | cut -f1 | paste -sd, -)
dump=$D/pre-pr21-sandbox_$TS.sql
mysqldump --single-transaction --no-create-info --complete-insert --skip-extended-insert "$DB" attendance --where="id IN (${id_list})" > "$dump"
if has_col breaks site_id; then
  mysqldump --single-transaction --no-create-info --complete-insert --skip-extended-insert "$DB" breaks --where="site_id IN (${sandbox_in})" >> "$dump"
fi
gzip -f "$dump"
gzip -t "$dump.gz"
ls -l "$dump.gz" "$idfile"
mysql "$DB" -e "UPDATE attendance a SET a.site_id=${HARVEY}${org_set} WHERE ${move_where}"
moved=$(mysql_n "SELECT COUNT(*) FROM attendance WHERE id IN (${id_list}) AND site_id=${HARVEY}")
echo "MOVED ${moved}"
echo "DUMP $dump.gz"
echo "IDS $idfile"
echo "FINALIZED rows were listed above and were not moved. payroll_finalized was not changed. Sandbox Kitchen was not renamed or deleted."
echo "ROLLBACK: bash $0 rollback"
