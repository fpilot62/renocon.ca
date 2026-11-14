#!/bin/bash
# Move unfinalized shifts that are not on the six org-2 stores onto each
# employee's own assigned store. Site 99 (Sandbox Kitchen, org 1) is a
# non-store site and IS moved. Sites 0 and 999 are reported and left alone.
# Admin and Demo accounts are listed as TEST and are not moved.
#
#   bash deploy22_offsite_to_assigned.sh check
#   bash deploy22_offsite_to_assigned.sh go
#   bash deploy22_offsite_to_assigned.sh rollback
#   bash deploy22_offsite_to_assigned.sh selftest
#
# Assignment: this app has no user-site mapping table. Staff Audit stores the
# home restaurant on users.primary_site_id and users.primary_restaurant_id,
# and the punch list on users.assigned_site_ids (comma-separated). The
# candidate set is those values, plus a mapping table if one of the known
# names exists. Ids that are not one of the six stores are ignored. The
# target is the lowest remaining site id. No candidate means UNRESOLVED.
#
# FIN is always wrapped in parentheses. deploy21's finalized listing used an
# unparenthesized OR chain, so AND site_id IN (...) bound only to the first
# term and the rest matched every site.
#
# Pin: download this file by commit SHA. It does not replace PHP and it does
# not delete sites, finalized rows, or payroll_finalized.
set -euo pipefail
M=${1:-check}
D=/root/payroll-daily
DB=admin_payroll

is_uint() { printf '%s' "${1:-}" | grep -Eq '^[0-9]+$'; }

compact_name() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9'
}

# One store key, NONE, or AMBIGUOUS. Input is already compacted.
store_key_for() {
  local compact="$1" n=0 found=""
  if printf '%s' "$compact" | grep -q 'penticton'; then n=$((n + 1)); found=penticton; fi
  if printf '%s' "$compact" | grep -q 'westbank'; then n=$((n + 1)); found=westbank; fi
  if printf '%s' "$compact" | grep -q 'vernon'; then n=$((n + 1)); found=vernon; fi
  if printf '%s' "$compact" | grep -q 'merritt'; then n=$((n + 1)); found=merritt; fi
  if printf '%s' "$compact" | grep -q 'salmonarm'; then n=$((n + 1)); found=salmonarm; fi
  if printf '%s' "$compact" | grep -q 'harvey'; then n=$((n + 1)); found=harvey; fi
  if [ "$n" -eq 0 ]; then
    printf 'NONE'
  elif [ "$n" -eq 1 ]; then
    printf '%s' "$found"
  else
    printf 'AMBIGUOUS'
  fi
}

is_test_label() {
  local s
  s=$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
  [ -n "$s" ] || return 1
  case "$s" in
    admin|demo|administrator) return 0 ;;
    admin\ *|demo\ *|admin_*|demo_*|admin[0-9]*|demo[0-9]*) return 0 ;;
  esac
  return 1
}

is_test_user() {
  is_test_label "${1:-}" || is_test_label "${2:-}"
}

# Always parenthesized, including the single-term and empty cases.
build_fin() {
  local fin part
  if [ "$#" -eq 0 ]; then
    printf '(0)'
    return
  fi
  fin="($1"
  shift
  for part in "$@"; do
    fin="${fin} OR ${part}"
  done
  printf '%s)' "$fin"
}

# Parenthesized so it can be AND-ed with FIN/UNFIN.
build_move_where() {
  local exclude_in="$1"
  printf '(a.site_id REGEXP '\''^[0-9]+$'\'' AND CAST(a.site_id AS UNSIGNED) NOT IN (%s))' "$exclude_in"
}

# Lowest id in the candidate list that is one of the six stores.
pick_lowest_store() {
  local raw="$1" best="" tok id
  raw=$(printf '%s' "$raw" | tr '|;[:space:]' ',')
  IFS=',' read -ra toks <<< "$raw"
  for tok in "${toks[@]+"${toks[@]}"}"; do
    id=$(printf '%s' "$tok" | tr -cd '0-9')
    [ -n "$id" ] || continue
    id=$((10#$id))
    [ -n "${STORE_ORG[$id]+x}" ] || continue
    if [ -z "$best" ] || [ "$id" -lt "$best" ]; then
      best=$id
    fi
  done
  printf '%s' "$best"
}

build_update_sql() {
  local id="$1" old_site="$2" new_site="$3" new_org="$4" move_where="$5" unfin="$6" has_org="$7"
  local set_sql="a.site_id=${new_site}"
  if [ "$has_org" = yes ]; then
    set_sql="${set_sql}, a.org_id=${new_org}"
  fi
  printf 'UPDATE attendance a SET %s WHERE a.id=%s AND CAST(a.site_id AS UNSIGNED)=%s AND %s AND %s;' \
    "$set_sql" "$id" "$old_site" "$move_where" "$unfin"
}

# One transaction. Each UPDATE is followed by its own ROW_COUNT() capture.
# The final SET reads only the previous statement, so a single capture after
# the loop would count the last row and then refuse a correct multi-row move.
write_apply_sql() {
  local planned="$1" move_where="$2" unfin="$3" has_org="$4"
  local i id old_site old_org new_site new_org
  echo "START TRANSACTION;"
  echo "SET @moved = 0;"
  i=0
  while [ "$i" -lt "$planned" ]; do
    IFS=$'\t' read -r id old_site old_org new_site <<< "${plan_lines[$i]}"
    new_org=${plan_orgs[$i]}
    build_update_sql "$id" "$old_site" "$new_site" "$new_org" "$move_where" "$unfin" "$has_org"
    echo "SET @moved = @moved + ROW_COUNT();"
    i=$((i + 1))
  done
  echo "SET @planned = ${planned};"
  echo "SET @gate = IF(@moved = @planned, 1, (SELECT 1 UNION SELECT 2));"
  echo "COMMIT;"
  echo "SELECT @moved;"
}

comma_has() {
  local list="$1" needle="$2"
  case ",${list}," in
    *",${needle},"*) return 0 ;;
  esac
  return 1
}

if [ "$M" = selftest ]; then
  fail=0
  ok() { printf 'ok  %s\n' "$1"; }
  bad() { printf 'FAIL %s\n' "$1"; fail=$((fail + 1)); }

  FIN=$(build_fin 'COALESCE(a.is_finalized,0)=1' 'COALESCE(a.payout_status,0)<>0' 'COALESCE(a.is_processed,0)=1')
  UNFIN="NOT ${FIN}"
  case "$FIN" in
    \(*\)) ok "FIN is parenthesized" ;;
    *) bad "FIN is parenthesized" ;;
  esac
  case "$UNFIN" in
    "NOT ("*\)) ok "UNFIN is NOT (...)" ;;
    *) bad "UNFIN is NOT (...)" ;;
  esac
  if printf '%s' "$FIN" | grep -q ' OR '; then ok "FIN keeps every term"; else bad "FIN keeps every term"; fi
  [ "$(build_fin)" = "(0)" ] && ok "empty FIN is (0)" || bad "empty FIN is (0)"

  exclude_in="0,999,11,18,19,20,21,23"
  MOVE_WHERE=$(build_move_where "$exclude_in")
  case "$MOVE_WHERE" in
    \(*\)) ok "move where is parenthesized" ;;
    *) bad "move where is parenthesized" ;;
  esac
  comma_has "$exclude_in" 99 && bad "site 99 is not excluded" || ok "site 99 is not excluded"
  comma_has "$exclude_in" 0 && comma_has "$exclude_in" 999 && ok "sites 0 and 999 are excluded" || bad "sites 0 and 999 are excluded"

  where="WHERE ${MOVE_WHERE} AND ${FIN}"
  printf '%s' "$where" | grep -q 'AND (COALESCE' && ok "finalized filter cannot escape the site predicate" || bad "finalized filter cannot escape the site predicate"
  printf '%s' "$where" | grep -q 'AND COALESCE' && bad "unparenthesized AND COALESCE is absent" || ok "unparenthesized AND COALESCE is absent"
  unfin_where="WHERE ${MOVE_WHERE} AND ${UNFIN}"
  printf '%s' "$unfin_where" | grep -q 'AND NOT (COALESCE' && ok "unfinalized filter stays inside the site predicate" || bad "unfinalized filter stays inside the site predicate"

  upd=$(build_update_sql 5 99 23 2 "$MOVE_WHERE" "$UNFIN" yes)
  printf '%s' "$upd" | grep -q 'UPDATE attendance a SET a.site_id=23, a.org_id=2 WHERE a.id=5 AND CAST(a.site_id AS UNSIGNED)=99 AND (' \
    && ok "update pins id, old site, and move where" || bad "update pins id, old site, and move where"
  printf '%s' "$upd" | grep -q 'NOT IN (0,999,11,18,19,20,21,23)' \
    && ok "update cannot touch a store site, 0, or 999" || bad "update cannot touch a store site, 0, or 999"
  printf '%s' "$upd" | grep -q 'AND NOT (COALESCE' && ok "update keeps the parenthesized unfinalized test" || bad "update keeps the parenthesized unfinalized test"

  plan_lines=("5	99	1	23" "6	99	1	11")
  plan_orgs=("2" "2")
  apply=$(write_apply_sql 2 "$MOVE_WHERE" "$UNFIN" yes)
  updates=$(printf '%s\n' "$apply" | grep -c '^UPDATE ' || true)
  incs=$(printf '%s\n' "$apply" | grep -c 'SET @moved = @moved + ROW_COUNT()' || true)
  [ "$updates" = 2 ] && [ "$incs" = 2 ] && ok "each update adds its own row count" || bad "each update adds its own row count"
  printf '%s\n' "$apply" | grep -q 'a.id=5 AND CAST(a.site_id AS UNSIGNED)=99' && ok "apply keeps the sandbox row on its old site in the WHERE" || bad "apply keeps the sandbox row on its old site in the WHERE"
  printf '%s\n' "$apply" | grep -q 'NOT IN (0,999,11,18,19,20,21,23)' && ok "apply cannot touch a store, 0, or 999" || bad "apply cannot touch a store, 0, or 999"
  printf '%s\n' "$apply" | grep -q 'SET @gate = IF(@moved = @planned, 1, (SELECT 1 UNION SELECT 2));' && ok "go commits only when every planned row changes" || bad "go commits only when every planned row changes"

  declare -A STORE_ORG=([11]=2 [18]=2 [19]=2 [20]=2 [21]=2 [23]=2)
  [ "$(pick_lowest_store '23,11,99')" = 11 ] && ok "several stores pick the lowest store id" || bad "several stores pick the lowest store id"
  [ "$(pick_lowest_store '99,0,999')" = "" ] && ok "non-store assignments stay unresolved" || bad "non-store assignments stay unresolved"
  [ "$(pick_lowest_store '')" = "" ] && ok "no assignment stays unresolved" || bad "no assignment stays unresolved"
  [ "$(pick_lowest_store 'primary 23 / 18')" = 18 ] && ok "mixed text still yields the lowest store" || bad "mixed text still yields the lowest store"

  is_test_user 'Admin' 'jane' && ok "Admin is a test user" || bad "Admin is a test user"
  is_test_user 'Demo Test' '' && ok "Demo Test is a test user" || bad "Demo Test is a test user"
  is_test_user 'Jane Cook' 'demo' && ok "demo login is a test user" || bad "demo login is a test user"
  is_test_user 'Jane Cook' 'jcook' && bad "a store employee is not a test user" || ok "a store employee is not a test user"
  is_test_user 'Administrator' 'root' && ok "Administrator is a test user" || bad "Administrator is a test user"

  [ "$(store_key_for "$(compact_name '6. Popeyes harvey')")" = harvey ] && ok "Harvey name matches" || bad "Harvey name matches"
  [ "$(store_key_for "$(compact_name 'Sandbox Kitchen')")" = NONE ] && ok "Sandbox Kitchen is not a store name" || bad "Sandbox Kitchen is not a store name"
  [ "$(store_key_for "$(compact_name 'West Bank')")" = westbank ] && ok "West Bank matches" || bad "West Bank matches"
  [ "$(store_key_for "$(compact_name 'Salmon Arm')")" = salmonarm ] && ok "Salmon Arm matches" || bad "Salmon Arm matches"
  [ "$(store_key_for "$(compact_name 'Penticton')")" = penticton ] && ok "Penticton matches" || bad "Penticton matches"
  [ "$(store_key_for "$(compact_name 'Vernon')")" = vernon ] && ok "Vernon matches" || bad "Vernon matches"
  [ "$(store_key_for "$(compact_name 'Merritt')")" = merritt ] && ok "Merritt matches" || bad "Merritt matches"
  [ "$(store_key_for "$(compact_name 'Harvey Vernon')")" = AMBIGUOUS ] && ok "two store names are ambiguous" || bad "two store names are ambiguous"

  if [ "$fail" -eq 0 ]; then
    printf 'SELFTEST_OK\n'
    exit 0
  fi
  printf 'SELFTEST_FAIL %s\n' "$fail"
  exit 1
fi

mysql_n() { mysql -N --batch "$DB" -e "$1"; }
has_col() {
  local table="$1" col="$2" n
  n=$(mysql_n "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${table}' AND COLUMN_NAME='${col}'")
  [ "$n" = 1 ]
}
has_table() {
  local table="$1" n
  n=$(mysql_n "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${table}'")
  [ "$n" = 1 ]
}

say() { printf '%s\n' "$1" | tee -a "$REPORT"; }

echo "MODE=$M"
[ "$M" = check ] || [ "$M" = go ] || [ "$M" = rollback ] || { echo "Usage: bash deploy22_offsite_to_assigned.sh check|go|rollback"; exit 2; }

mkdir -p "$D"
TS=$(date +%Y%m%d-%H%M%S)
if [ "$M" = check ]; then
  REPORT=$D/pr22-check_$TS.txt
else
  REPORT=$D/pr22-${M}_$TS.txt
fi
: > "$REPORT"
say "MODE=$M"
say "REPORT=$REPORT"

site_id_col=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME IN ('SId','Sid','id') ORDER BY FIELD(COLUMN_NAME,'SId','Sid','id') LIMIT 1")
[ -n "$site_id_col" ] || { say "STOP: sites has no id column."; exit 1; }
name_cols=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME IN ('nick_name','name','site_name') ORDER BY FIELD(COLUMN_NAME,'nick_name','name','site_name')")
[ -n "$name_cols" ] || { say "STOP: sites has no name column."; exit 1; }
has_col sites org_id || { say "STOP: sites.org_id is missing."; exit 1; }
has_col attendance site_id || { say "STOP: attendance.site_id is missing."; exit 1; }
has_col attendance user_id || { say "STOP: attendance.user_id is missing."; exit 1; }

name_concat=""
while IFS= read -r col; do
  [ -n "$col" ] || continue
  if [ -z "$name_concat" ]; then
    name_concat="IFNULL(\`${col}\`,'')"
  else
    name_concat="${name_concat}, IFNULL(\`${col}\`,'')"
  fi
done <<< "$name_cols"
first_name=$(printf '%s\n' "$name_cols" | head -1)

declare -A STORE_ORG=()
declare -A STORE_NAME=()
declare -A KEY_SITE=()
store_ids=()
store_ok=yes
say "STORES:"
org2_rows=$(mysql_n "SELECT \`${site_id_col}\`, org_id, REPLACE(REPLACE(CONCAT_WS(' ', ${name_concat}), CHAR(9), ' '), CHAR(10), ' ') FROM sites WHERE org_id=2 ORDER BY \`${site_id_col}\`")
while IFS=$'\t' read -r sid org blob; do
  [ -n "${sid:-}" ] || continue
  is_uint "$sid" || continue
  compact=$(compact_name "$blob")
  key=$(store_key_for "$compact")
  if [ "$key" = NONE ]; then
    say "ORG2_NONSTORE site_id=${sid} org_id=${org} name=${blob}"
    continue
  fi
  if [ "$key" = AMBIGUOUS ]; then
    say "STORE_AMBIGUOUS site_id=${sid} org_id=${org} name=${blob}"
    store_ok=no
    continue
  fi
  if [ "$sid" = 0 ] || [ "$sid" = 99 ] || [ "$sid" = 999 ]; then
    say "STORE_REJECT site_id=${sid} key=${key} name=${blob}"
    store_ok=no
    continue
  fi
  if [ -n "${KEY_SITE[$key]:-}" ]; then
    say "STORE_DUPLICATE key=${key} site_id=${sid} other=${KEY_SITE[$key]} name=${blob}"
    store_ok=no
    continue
  fi
  KEY_SITE[$key]=$sid
  STORE_ORG[$sid]=$org
  STORE_NAME[$sid]=$blob
  store_ids+=("$sid")
  say "STORE key=${key} site_id=${sid} org_id=${org} name=${blob}"
done <<< "$org2_rows"

for key in penticton westbank vernon merritt salmonarm harvey; do
  if [ -z "${KEY_SITE[$key]:-}" ]; then
    say "STORE_MISSING key=${key}"
    store_ok=no
  fi
done
if [ "${#store_ids[@]}" -ne 6 ]; then
  store_ok=no
fi
say "STORE_OK=$store_ok"

exclude_in="0,999"
for sid in "${store_ids[@]+"${store_ids[@]}"}"; do
  exclude_in="${exclude_in},${sid}"
done
if comma_has "$exclude_in" 99; then
  say "STOP: site 99 was put on the exclude list. Nothing changed."
  exit 1
fi

att_cols=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance'")
has_att() { printf '%s\n' "$att_cols" | grep -qx "$1"; }
fin_parts=()
if has_att is_finalized; then fin_parts+=("COALESCE(a.is_finalized,0)=1"); fi
if has_att payout_status; then fin_parts+=("COALESCE(a.payout_status,0)<>0"); fi
if has_att is_processed; then fin_parts+=("COALESCE(a.is_processed,0)=1"); fi
if has_table payroll_finalized && has_col payroll_finalized attendance_id; then
  fin_parts+=("EXISTS (SELECT 1 FROM payroll_finalized pf WHERE pf.attendance_id=a.id)")
fi
if [ "${#fin_parts[@]}" -eq 0 ]; then
  FIN=$(build_fin)
else
  FIN=$(build_fin "${fin_parts[@]}")
fi
UNFIN="NOT ${FIN}"
case "$FIN" in
  \(*\)) ;;
  *) say "STOP: FIN is not parenthesized. Nothing changed."; exit 1 ;;
esac
case "$UNFIN" in
  "NOT ("*\)) ;;
  *) say "STOP: UNFIN is not parenthesized. Nothing changed."; exit 1 ;;
esac
MOVE_WHERE=$(build_move_where "$exclude_in")
say "MOVE_WHERE=${MOVE_WHERE}"
say "FIN=${FIN}"

has_org=no
has_att org_id && has_org=yes
org_select="0"
if [ "$has_org" = yes ]; then
  org_select="IFNULL(a.org_id,0)"
fi

user_name_expr="CONCAT('user ', a.user_id)"
fin_name_expr="CONCAT('user ', a.user_id)"
user_login_expr="''"
if has_col users Name; then
  user_name_expr="COALESCE(MAX(u.\`Name\`), CONCAT('user ', a.user_id))"
  fin_name_expr="COALESCE(u.\`Name\`, CONCAT('user ', a.user_id))"
elif has_col users name; then
  user_name_expr="COALESCE(MAX(u.\`name\`), CONCAT('user ', a.user_id))"
  fin_name_expr="COALESCE(u.\`name\`, CONCAT('user ', a.user_id))"
fi
login_col=""
for cand in user username login; do
  if has_col users "$cand"; then
    login_col=$cand
    break
  fi
done
if [ -n "$login_col" ]; then
  user_login_expr="COALESCE(MAX(u.\`${login_col}\`), '')"
fi

assign_cols=()
primary_expr="''"
restaurant_expr="''"
csv_expr="''"
if has_col users primary_site_id; then
  primary_expr="IFNULL(MAX(u.primary_site_id), '')"
  assign_cols+=("users.primary_site_id")
fi
if has_col users primary_restaurant_id; then
  restaurant_expr="IFNULL(MAX(u.primary_restaurant_id), '')"
  assign_cols+=("users.primary_restaurant_id")
fi
if has_col users assigned_site_ids; then
  csv_expr="IFNULL(MAX(u.assigned_site_ids), '')"
  assign_cols+=("users.assigned_site_ids")
fi

map_table=""
map_join=""
map_expr="''"
map_note="none"
for cand in user_sites site_users user_site users_sites employee_sites assigned_sites user_site_map user_assigned_sites; do
  if has_table "$cand" && has_col "$cand" user_id && has_col "$cand" site_id; then
    if [ -n "$map_table" ]; then
      map_note="ambiguous:${map_table},${cand}"
      map_table=""
      map_join=""
      map_expr="''"
      break
    fi
    map_table=$cand
    map_note=$cand
    map_join="LEFT JOIN (SELECT user_id, GROUP_CONCAT(DISTINCT site_id ORDER BY site_id SEPARATOR ',') AS mapped_sites FROM \`${cand}\` GROUP BY user_id) umap ON umap.user_id=a.user_id"
    map_expr="IFNULL(MAX(umap.mapped_sites), '')"
  fi
done
if [ -n "$map_table" ]; then
  assign_cols+=("mapping:${map_table}")
fi
if [ "${#assign_cols[@]}" -eq 0 ]; then
  say "ASSIGNMENT_SOURCE=none"
  say "STOP: no user site assignment column or mapping table. Nothing changed."
  exit 1
else
  assignment_source=$(IFS=','; printf '%s' "${assign_cols[*]}")
  say "ASSIGNMENT_SOURCE=${assignment_source}"
fi
say "MAPPING_TABLE=${map_note}"
say "ASSIGNMENT_RULE=lowest site id among the employee's ids that are one of the six stores. Empty set is UNRESOLVED and is not moved."
say "TEST_RULE=name or login is Admin, Demo, or Administrator, or starts with Admin/Demo. Not moved."
say "EXCLUDED_SITES=0,999. Site 99 Sandbox Kitchen stays in the move set."

say "EXCLUDED:"
excl_rows=$(mysql_n "SELECT CAST(a.site_id AS UNSIGNED) AS site_id, COUNT(*),
    SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END),
    SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END)
  FROM attendance a
  WHERE a.site_id REGEXP '^[0-9]+$' AND CAST(a.site_id AS UNSIGNED) IN (0, 999)
  GROUP BY CAST(a.site_id AS UNSIGNED)
  ORDER BY site_id")
if [ -z "$excl_rows" ]; then
  say "EXCLUDED none"
else
  while IFS=$'\t' read -r sid rows unfin fin; do
    [ -n "${sid:-}" ] || continue
    say "EXCLUDED site_id=${sid} rows=${rows} unfinalized=${unfin} finalized=${fin}"
  done <<< "$excl_rows"
fi
blank_n=$(mysql_n "SELECT COUNT(*) FROM attendance a WHERE a.site_id IS NULL OR a.site_id='' OR LOWER(TRIM(a.site_id))='all' OR a.site_id NOT REGEXP '^[0-9]+$'")
say "EXCLUDED_BLANK=${blank_n}"

say "SITES:"
site_fin_total=0
site_rows=$(mysql_n "SELECT CAST(a.site_id AS UNSIGNED) AS site_id,
    COALESCE(MAX(s.org_id), 0),
    REPLACE(REPLACE(COALESCE(MAX(s.\`${first_name}\`), ''), CHAR(9), ' '), CHAR(10), ' '),
    COUNT(*),
    SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END),
    SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END)
  FROM attendance a
  LEFT JOIN sites s ON s.\`${site_id_col}\` = CAST(a.site_id AS UNSIGNED)
  WHERE ${MOVE_WHERE}
  GROUP BY CAST(a.site_id AS UNSIGNED)
  ORDER BY site_id")
if [ -z "$site_rows" ]; then
  say "SITES none"
else
  while IFS=$'\t' read -r sid org name rows unfin fin; do
    [ -n "${sid:-}" ] || continue
    say "SITE site_id=${sid} org_id=${org} name=${name} rows=${rows} unfinalized=${unfin} finalized=${fin}"
    site_fin_total=$((site_fin_total + ${fin:-0}))
  done <<< "$site_rows"
fi

declare -A USER_STATUS=()
declare -A USER_CHOSEN=()
declare -A USER_NAME=()
move_people=0
move_rows=0
test_people=0
test_rows=0
bad_people=0
bad_rows=0

say "EMPLOYEES:"
emp_sql="SET SESSION group_concat_max_len=100000;
SELECT a.user_id,
  REPLACE(REPLACE(${user_name_expr}, CHAR(9), ' '), CHAR(10), ' '),
  REPLACE(REPLACE(${user_login_expr}, CHAR(9), ' '), CHAR(10), ' '),
  REPLACE(REPLACE(${primary_expr}, CHAR(9), ' '), CHAR(10), ' '),
  REPLACE(REPLACE(${restaurant_expr}, CHAR(9), ' '), CHAR(10), ' '),
  REPLACE(REPLACE(${csv_expr}, CHAR(9), ' '), CHAR(10), ' '),
  REPLACE(REPLACE(${map_expr}, CHAR(9), ' '), CHAR(10), ' '),
  SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END),
  SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END),
  GROUP_CONCAT(DISTINCT CAST(a.site_id AS UNSIGNED) ORDER BY CAST(a.site_id AS UNSIGNED) SEPARATOR ',')
FROM attendance a
LEFT JOIN users u ON u.id=a.user_id
${map_join}
WHERE ${MOVE_WHERE}
GROUP BY a.user_id
ORDER BY 2, a.user_id"
emp_rows=$(mysql_n "$emp_sql")
if [ -z "$emp_rows" ]; then
  say "EMPLOYEES none"
fi
while IFS=$'\t' read -r uid name login primary restaurant csv mapped unfin fin sites; do
  [ -n "${uid:-}" ] || continue
  chosen=$(pick_lowest_store "${primary},${restaurant},${csv},${mapped}")
  chosen_name="-"
  if [ -n "$chosen" ]; then
    chosen_name=${STORE_NAME[$chosen]:-}
  fi
  primary_show=${primary:--}
  restaurant_show=${restaurant:--}
  csv_show=${csv:--}
  [ -n "$primary_show" ] || primary_show="-"
  [ -n "$restaurant_show" ] || restaurant_show="-"
  [ -n "$csv_show" ] || csv_show="-"
  line="name=${name} user_id=${uid} login=${login} sites=${sites} unfinalized=${unfin} finalized=${fin} primary=${primary_show} restaurant=${restaurant_show} assigned_site_ids=${csv_show} chosen=${chosen:-none} chosen_name=${chosen_name}"
  if is_test_user "$name" "$login"; then
    USER_STATUS[$uid]=test
    say "TEST ${line}"
    test_people=$((test_people + 1))
    test_rows=$((test_rows + unfin))
  elif [ -z "$chosen" ]; then
    USER_STATUS[$uid]=unresolved
    say "UNRESOLVED ${line}"
    bad_people=$((bad_people + 1))
    bad_rows=$((bad_rows + unfin))
  else
    USER_STATUS[$uid]=move
    USER_CHOSEN[$uid]=$chosen
    USER_NAME[$uid]=$name
    say "EMP ${line}"
    move_people=$((move_people + 1))
    move_rows=$((move_rows + unfin))
  fi
done <<< "$emp_rows"

say "MOVE_EMPLOYEES=${move_people}"
say "MOVE_UNFINALIZED=${move_rows}"
say "TEST_EMPLOYEES=${test_people}"
say "TEST_UNFINALIZED=${test_rows}"
say "UNRESOLVED_EMPLOYEES=${bad_people}"
say "UNRESOLVED_UNFINALIZED=${bad_rows}"

fin_count=$(mysql_n "SELECT COUNT(*) FROM attendance a WHERE ${MOVE_WHERE} AND ${FIN}")
say "FINALIZED_ROWS=${fin_count}"
if ! is_uint "$fin_count" || [ "$fin_count" != "$site_fin_total" ]; then
  say "STOP: FINALIZED_ROWS=${fin_count} but the per-site finalized total is ${site_fin_total}. The finalized predicate leaked outside the site filter. Nothing changed."
  exit 1
fi
if [ "$fin_count" -lt 30 ]; then
  fin_rows=$(mysql_n "SELECT a.id,
      REPLACE(REPLACE(${fin_name_expr}, CHAR(9), ' '), CHAR(10), ' '),
      CAST(a.site_id AS UNSIGNED),
      a.clock_in,
      a.clock_out
    FROM attendance a
    LEFT JOIN users u ON u.id=a.user_id
    WHERE ${MOVE_WHERE} AND ${FIN}
    ORDER BY a.clock_in, a.id")
  if [ -z "$fin_rows" ]; then
    say "FINALIZED none"
  else
    while IFS=$'\t' read -r id name sid cin cout; do
      [ -n "${id:-}" ] || continue
      say "FINALIZED id=${id} name=${name} site_id=${sid} clock_in=${cin} clock_out=${cout}"
    done <<< "$fin_rows"
  fi
else
  say "FINALIZED list omitted because FINALIZED_ROWS is not under 30"
fi

if has_table payroll_finalized && has_col payroll_finalized attendance_id; then
  pf_n=$(mysql_n "SELECT COUNT(*) FROM payroll_finalized pf INNER JOIN attendance a ON a.id=pf.attendance_id WHERE ${MOVE_WHERE}")
  say "PAYROLL_FINALIZED_FOR_OFFSITE_SHIFTS=${pf_n}"
  pf_by=$(mysql_n "SELECT CAST(a.site_id AS UNSIGNED), COUNT(*)
    FROM payroll_finalized pf
    INNER JOIN attendance a ON a.id=pf.attendance_id
    WHERE ${MOVE_WHERE}
    GROUP BY CAST(a.site_id AS UNSIGNED)
    ORDER BY 1")
  if [ -n "$pf_by" ]; then
    while IFS=$'\t' read -r sid n; do
      [ -n "${sid:-}" ] || continue
      say "PAYROLL_FINALIZED_SITE site_id=${sid} rows=${n}"
    done <<< "$pf_by"
  fi
else
  say "PAYROLL_FINALIZED_FOR_OFFSITE_SHIFTS=no-attendance-link"
fi
if has_col breaks site_id; then
  say "BREAKS store site_id. go dumps breaks for the sites it moves."
else
  say "BREAKS have no site column. They follow shift_id and are not updated."
fi

if [ "$M" = check ]; then
  if [ "$store_ok" = yes ]; then
    say "GO_ALLOWED=yes"
  else
    say "GO_ALLOWED=no"
  fi
  say "CHECK ONLY. Database unchanged."
  exit 0
fi

if [ "$M" = rollback ]; then
  [ "$store_ok" = yes ] || { say "STOP: the six stores did not resolve. Nothing changed."; exit 1; }
  pointer=$D/pr22-offsite-latest.ids
  [ -f "$pointer" ] || { say "STOP: no move log. Nothing changed."; exit 1; }
  idfile=$(cat "$pointer")
  [ -f "$idfile" ] || { say "STOP: id file ${idfile} is missing. Nothing changed."; exit 1; }
  restored=0
  skipped=0
  while IFS=$'\t' read -r id old_site old_org new_site; do
    [ -n "${id:-}" ] || continue
    is_uint "$id" || { skipped=$((skipped + 1)); continue; }
    is_uint "$old_site" || { skipped=$((skipped + 1)); continue; }
    is_uint "$old_org" || { skipped=$((skipped + 1)); continue; }
    is_uint "$new_site" || { skipped=$((skipped + 1)); continue; }
    if [ -z "${STORE_ORG[$new_site]+x}" ]; then
      say "SKIP id=${id} reason=new-site-not-a-store"
      skipped=$((skipped + 1))
      continue
    fi
    if [ "$has_org" = yes ]; then
      sql="UPDATE attendance a SET a.site_id=${old_site}, a.org_id=${old_org} WHERE a.id=${id} AND CAST(a.site_id AS UNSIGNED)=${new_site} AND ${UNFIN}"
    else
      sql="UPDATE attendance a SET a.site_id=${old_site} WHERE a.id=${id} AND CAST(a.site_id AS UNSIGNED)=${new_site} AND ${UNFIN}"
    fi
    changed=$(mysql_n "${sql}; SELECT ROW_COUNT();" | tail -1 | tr -cd '0-9')
    if [ "$changed" = 1 ]; then
      restored=$((restored + 1))
    else
      why=$(mysql_n "SELECT CASE
          WHEN a.id IS NULL THEN 'missing'
          WHEN CAST(a.site_id AS UNSIGNED)<>${new_site} THEN 'site-changed'
          WHEN ${UNFIN} THEN 'not-updated'
          ELSE 'finalized'
        END
        FROM (SELECT ${id} AS want_id) w
        LEFT JOIN attendance a ON a.id=w.want_id")
      say "SKIP id=${id} reason=${why}"
      skipped=$((skipped + 1))
    fi
  done < "$idfile"
  say "ROLLBACK restored ${restored} attendance row(s) from ${idfile}. Skipped ${skipped}. Finalized rows and payroll_finalized were not changed. No site was deleted."
  exit 0
fi

[ "$store_ok" = yes ] || { say "STOP: the six stores did not resolve. Nothing changed."; exit 1; }

plan=$(mysql_n "SELECT a.id, CAST(a.site_id AS UNSIGNED), ${org_select}, a.user_id
  FROM attendance a
  WHERE ${MOVE_WHERE} AND ${UNFIN}
  ORDER BY a.id")

plan_lines=()
plan_ids=()
plan_sites=()
plan_orgs=()
while IFS=$'\t' read -r id old_site old_org uid; do
  [ -n "${id:-}" ] || continue
  is_uint "$id" || { say "STOP: bad attendance id. Nothing changed."; exit 1; }
  is_uint "$old_site" || { say "STOP: bad site on id=${id}. Nothing changed."; exit 1; }
  is_uint "$old_org" || { say "STOP: bad org on id=${id}. Nothing changed."; exit 1; }
  is_uint "$uid" || { say "STOP: bad user on id=${id}. Nothing changed."; exit 1; }
  if comma_has "$exclude_in" "$old_site"; then
    say "STOP: id=${id} is on excluded site ${old_site}. Nothing changed."
    exit 1
  fi
  status=${USER_STATUS[$uid]:-unresolved}
  [ "$status" = move ] || continue
  new_site=${USER_CHOSEN[$uid]:-}
  is_uint "$new_site" || { say "STOP: no target for user ${uid}. Nothing changed."; exit 1; }
  new_org=${STORE_ORG[$new_site]:-}
  is_uint "$new_org" || { say "STOP: target ${new_site} has no org. Nothing changed."; exit 1; }
  [ "$new_org" = 2 ] || { say "STOP: target ${new_site} is not org 2. Nothing changed."; exit 1; }
  [ -n "${STORE_ORG[$new_site]+x}" ] || { say "STOP: target ${new_site} is not one of the six stores. Nothing changed."; exit 1; }
  plan_lines+=("${id}	${old_site}	${old_org}	${new_site}")
  plan_ids+=("$id")
  plan_sites+=("$old_site")
  plan_orgs+=("$new_org")
done <<< "$plan"

if [ "${#plan_lines[@]}" -eq 0 ]; then
  say "MOVED 0"
  say "No resolved unfinalized non-store rows. Test users, unresolved employees, finalized rows, site 0, and site 999 were not moved."
  exit 0
fi

planned=${#plan_lines[@]}
idfile=$D/pre-pr22-offsite_$TS.ids
printf '%s\n' "${plan_lines[@]}" > "$idfile"
id_list=$(printf '%s\n' "${plan_ids[@]}" | paste -sd, -)
site_list=$(printf '%s\n' "${plan_sites[@]}" | sort -n | uniq | paste -sd, -)

dump=$D/pre-pr22-offsite_$TS.sql
mysqldump --single-transaction --no-create-info --complete-insert --skip-extended-insert "$DB" attendance --where="id IN (${id_list})" > "$dump"
if has_col breaks site_id; then
  mysqldump --single-transaction --no-create-info --complete-insert --skip-extended-insert "$DB" breaks --where="site_id IN (${site_list})" >> "$dump"
fi
gzip -f "$dump"
gzip -t "$dump.gz"
echo "$idfile" > "$D/pr22-offsite-latest.ids"
ls -l "$dump.gz" "$idfile" | tee -a "$REPORT"

sqlfile=$D/pre-pr22-offsite_$TS.apply.sql
write_apply_sql "$planned" "$MOVE_WHERE" "$UNFIN" "$has_org" > "$sqlfile"

set +e
apply_out=$(mysql -N --batch "$DB" < "$sqlfile" 2>&1)
apply_rc=$?
set -e
printf '%s\n' "$apply_out" | tee -a "$REPORT"
if [ "$apply_rc" -ne 0 ]; then
  rm -f "$D/pr22-offsite-latest.ids"
  say "ABORTED. The transaction was not committed. Database unchanged. Dump kept at ${dump}.gz. Ids file kept at ${idfile}."
  exit 1
fi
moved=$(printf '%s\n' "$apply_out" | grep -E '^[0-9]+$' | tail -1 || true)
if [ "$moved" != "$planned" ]; then
  say "STOP: committed count ${moved} does not match planned ${planned}. Run rollback."
  exit 1
fi
say "MOVED ${moved}"
say "PLANNED ${planned}"
say "DUMP ${dump}.gz"
say "IDS ${idfile}"
say "Finalized rows, payroll_finalized, test users, unresolved employees, site 0, and site 999 were not changed. No site was deleted."
say "ROLLBACK: bash $0 rollback"
