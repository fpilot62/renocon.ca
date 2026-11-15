#!/bin/bash
# Org 2 only. Move unfinalized attendance that is not on the six Popeyes
# Okanagan stores onto the employee's own store.
#
# The six stores, confirmed on prod, are site ids 6, 11, 19, 20, 21, 23
# (Penticton, Westbank, Vernon, Merritt, Salmon Arm, Harvey). Site 99
# (Sandbox Kitchen) and other non-store sites such as Temp 103 stay eligible.
# Sites 0 and 999 are reported and never moved. Other organizations are never
# read into the candidate set.
#
#   bash deploy23_offsite_to_assigned.sh check
#   bash deploy23_offsite_to_assigned.sh go
#   bash deploy23_offsite_to_assigned.sh rollback
#   bash deploy23_offsite_to_assigned.sh selftest
#
# deploy22 check scanned every org, built NOT IN (0,999,0,11,19,20,21,23)
# with Penticton 6 missing, and split employee fields so counts showed site
# lists. This script uses the fixed store ids, requires payout_status <> 0,
# and reads employee fields on a separator that cannot appear in the text.
#
# Assignment: users.assigned_site_ids when it contains any number; otherwise
# users.primary_site_id and users.primary_restaurant_id. A mapping table is
# used only when assigned_site_ids is empty. The chosen store is the lowest
# of those ids that is one of the six. None of them means UNRESOLVED.
#
# Pin this file by commit SHA. It does not replace PHP, delete sites, or
# change finalized rows or payroll_finalized.
set -euo pipefail
M=${1:-check}
D=/root/payroll-daily
DB=admin_payroll
ORG=2
# Prod ids. Names are checked before go; the list is not discovered by scan.
STORE_IDS=(6 11 19 20 21 23)

is_uint() { printf '%s' "${1:-}" | grep -Eq '^[0-9]+$'; }

compact_name() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9'
}

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

is_test_user() { is_test_label "${1:-}" || is_test_label "${2:-}"; }

has_digit() {
  case "${1:-}" in
    *[0-9]*) return 0 ;;
  esac
  return 1
}

comma_has() {
  local list="$1" needle="$2"
  case ",${list}," in
    *",${needle},"*) return 0 ;;
  esac
  return 1
}

count_token() {
  local list="$1" needle="$2" n=0 tok
  IFS=',' read -ra toks <<< "$list"
  for tok in "${toks[@]+"${toks[@]}"}"; do
    [ "$tok" = "$needle" ] && n=$((n + 1))
  done
  printf '%s' "$n"
}

# 0 and 999 once, then exactly the six store ids. Rejects a discovered 0.
build_exclude_list() {
  local id seen="0,999"
  [ "$#" -eq 6 ] || return 1
  for id in "$@"; do
    is_uint "$id" || return 1
    if [ "$id" -eq 0 ] || [ "$id" -eq 99 ] || [ "$id" -eq 999 ]; then
      return 1
    fi
    comma_has "$seen" "$id" && return 1
    seen="${seen},${id}"
  done
  for id in 6 11 19 20 21 23; do
    comma_has "$seen" "$id" || return 1
  done
  [ "$(count_token "$seen" 0)" = 1 ] || return 1
  [ "$(count_token "$seen" 6)" = 1 ] || return 1
  printf '%s' "$seen"
}

accept_store_ids() {
  local id got=" $* "
  for id in 6 11 19 20 21 23; do
    case "$got" in
      *" ${id} "*) ;;
      *) return 1 ;;
    esac
  done
  case "$got" in
    *" 0 "*|*" 99 "*|*" 999 "*) return 1 ;;
  esac
  build_exclude_list 6 11 19 20 21 23
}

# <> is built from octets so the predicate cannot become >=0.
payout_final_sql() {
  printf 'COALESCE(a.payout_status,0)%s0' "$(printf '\074\076')"
}

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

build_scope_sql() {
  local att_org="$1" user_org="$2" sql=""
  [ -n "$att_org" ] && sql="${att_org}=${ORG}"
  if [ -n "$user_org" ]; then
    if [ -n "$sql" ]; then
      sql="${sql} OR ${user_org}=${ORG}"
    else
      sql="${user_org}=${ORG}"
    fi
  fi
  [ -n "$sql" ] || return 1
  printf '(%s)' "$sql"
}

build_move_where() {
  local scope="$1" exclude_in="$2"
  printf '(%s AND a.site_id REGEXP '\''^[0-9]+$'\'' AND CAST(a.site_id AS UNSIGNED) NOT IN (%s))' \
    "$scope" "$exclude_in"
}

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

# Non-empty assigned_site_ids wins. Primary is not allowed to invent a store
# the assignment list does not contain (Inderpreet: assigned 121, primary 6).
choose_store() {
  local csv="$1" primary="$2" restaurant="$3" mapped="$4" pool source
  if has_digit "$csv"; then
    pool="$csv"
    source=assigned_site_ids
  elif has_digit "$mapped"; then
    pool="$mapped"
    source=mapping
  else
    pool="${primary},${restaurant}"
    source=primary_site_id
  fi
  printf '%s|%s\n' "$(pick_lowest_store "$pool")" "$source"
}

# One record, CHAR(31)-separated, so a site list cannot land in a count.
parse_emp_line() {
  local line="$1" uid unfin fin sites csv primary restaurant mapped name login
  IFS=$'\x1f' read -r uid unfin fin sites csv primary restaurant mapped name login <<< "$line"
  is_uint "$uid" || return 1
  is_uint "$unfin" || return 1
  is_uint "$fin" || return 1
  printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
    "$uid" "$unfin" "$fin" "$sites" "$csv" "$primary" "$restaurant" "$mapped" "$name" "$login"
}

format_emp() {
  local status="$1" name="$2" uid="$3" unfin="$4" fin="$5" sites="$6" assigned="$7" chosen="$8" source="$9"
  is_uint "$unfin" || return 1
  is_uint "$fin" || return 1
  printf '%s name=%s user_id=%s sites=%s unfinalized=%s finalized=%s assigned=%s chosen=%s via=%s' \
    "$status" "$name" "$uid" "$sites" "$unfin" "$fin" "${assigned:--}" "${chosen:-none}" "$source"
}

build_update_sql() {
  local id="$1" old_site="$2" new_site="$3" new_org="$4" move_where="$5" unfin="$6" has_org="$7"
  local set_sql="a.site_id=${new_site}"
  if [ "$has_org" = yes ]; then
    set_sql="${set_sql}, a.org_id=${new_org}"
  fi
  printf 'UPDATE attendance a LEFT JOIN users u ON u.id=a.user_id SET %s WHERE a.id=%s AND CAST(a.site_id AS UNSIGNED)=%s AND %s AND %s;' \
    "$set_sql" "$id" "$old_site" "$move_where" "$unfin"
}

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

if [ "$M" = selftest ]; then
  fail=0
  ok() { printf 'ok  %s\n' "$1"; }
  bad() { printf 'FAIL %s\n' "$1"; fail=$((fail + 1)); }
  declare -A STORE_ORG=([6]=2 [11]=2 [19]=2 [20]=2 [21]=2 [23]=2)

  good=$(build_exclude_list 6 11 19 20 21 23) || good=""
  [ "$good" = "0,999,6,11,19,20,21,23" ] && ok "exclude list is 0,999 and the six stores" || bad "exclude list is 0,999 and the six stores"
  [ "$(count_token "$good" 0)" = 1 ] && ok "site 0 is excluded once" || bad "site 0 is excluded once"
  [ "$(count_token "$good" 6)" = 1 ] && ok "Penticton 6 is in the exclude list" || bad "Penticton 6 is in the exclude list"
  comma_has "$good" 99 && bad "site 99 is not excluded" || ok "site 99 is not excluded"
  if build_exclude_list 0 11 19 20 21 23 >/dev/null; then
    bad "a discovered 0 is rejected"
  else
    ok "a discovered 0 is rejected"
  fi
  if accept_store_ids 0 11 19 20 21 23 >/dev/null; then
    bad "Penticton cannot be replaced by 0"
  else
    ok "Penticton cannot be replaced by 0"
  fi
  [ "$(accept_store_ids 6 11 19 20 21 23)" = "$good" ] && ok "the prod store ids are accepted" || bad "the prod store ids are accepted"

  pred=$(payout_final_sql)
  [ "$pred" = 'COALESCE(a.payout_status,0)<>0' ] && ok "payout test is <>0" || bad "payout test is <>0"
  printf '%s' "$pred" | grep -q '>=' && bad "payout test is not >=" || ok "payout test is not >="
  FIN=$(build_fin 'COALESCE(a.is_finalized,0)=1' "$pred" 'COALESCE(a.is_processed,0)=1' 'EXISTS (SELECT 1 FROM payroll_finalized pf WHERE pf.attendance_id=a.id)')
  case "$FIN" in
    \(*'<>0'*\)) ok "FIN keeps <>0 inside parentheses" ;;
    *) bad "FIN keeps <>0 inside parentheses" ;;
  esac
  printf '%s' "$FIN" | grep -q '>=0' && bad "FIN does not contain >=0" || ok "FIN does not contain >=0"
  scope=$(build_scope_sql 'a.org_id' 'u.org_id')
  [ "$scope" = '(a.org_id=2 OR u.org_id=2)' ] && ok "scope is org 2 attendance or org 2 user" || bad "scope is org 2 attendance or org 2 user"
  MOVE_WHERE=$(build_move_where "$scope" "$good")
  printf '%s' "$MOVE_WHERE" | grep -q 'a.org_id=2 OR u.org_id=2' && ok "move where requires org 2" || bad "move where requires org 2"
  printf '%s' "$MOVE_WHERE" | grep -q 'NOT IN (0,999,6,11,19,20,21,23)' && ok "move where uses the fixed store list" || bad "move where uses the fixed store list"
  where="WHERE ${MOVE_WHERE} AND ${FIN}"
  printf '%s' "$where" | grep -q 'AND (COALESCE' && ok "finalized OR cannot escape the org and site filter" || bad "finalized OR cannot escape the org and site filter"

  IFS='|' read -r chosen source <<< "$(choose_store '121' '6' '' '')"
  [ -z "$chosen" ] && [ "$source" = assigned_site_ids ] && ok "assigned 121 does not become store 6" || bad "assigned 121 does not become store 6"
  IFS='|' read -r chosen source <<< "$(choose_store '23,11' '121' '' '')"
  [ "$chosen" = 11 ] && ok "several assigned stores pick the lowest of the six" || bad "several assigned stores pick the lowest of the six"
  IFS='|' read -r chosen source <<< "$(choose_store '' '23' '11' '')"
  [ "$chosen" = 11 ] && [ "$source" = primary_site_id ] && ok "empty assignment falls back to primary" || bad "empty assignment falls back to primary"
  IFS='|' read -r chosen source <<< "$(choose_store '' '121' '' '')"
  [ -z "$chosen" ] && ok "primary outside the six stays unresolved" || bad "primary outside the six stays unresolved"

  RS=$'\x1f'
  inder="41${RS}2${RS}4${RS}99,103${RS}121${RS}6${RS}${RS}${RS}Inderpreet${RS}inder"
  parsed=$(parse_emp_line "$inder") || parsed=""
  [ -n "$parsed" ] && ok "employee record parses" || bad "employee record parses"
  IFS=$'\t' read -r uid unfin fin sites csv primary restaurant mapped name login <<< "$parsed"
  [ "$unfin" = 2 ] && [ "$fin" = 4 ] && ok "unfinalized and finalized are counts" || bad "unfinalized and finalized are counts"
  [ "$sites" = "99,103" ] && [ "$csv" = 121 ] && [ "$primary" = 6 ] && ok "site list stays out of the count fields" || bad "site list stays out of the count fields"
  IFS='|' read -r chosen source <<< "$(choose_store "$csv" "$primary" "$restaurant" "$mapped")"
  [ -z "$chosen" ] && ok "Inderpreet stays unresolved" || bad "Inderpreet stays unresolved"
  line=$(format_emp UNRESOLVED "$name" "$uid" "$unfin" "$fin" "$sites" "$csv" "$chosen" "$source")
  printf '%s' "$line" | grep -q 'unfinalized=2 finalized=4' && ok "employee line prints counts" || bad "employee line prints counts"
  printf '%s' "$line" | grep -q 'chosen=none' && ok "employee line does not invent store 6" || bad "employee line does not invent store 6"
  if parse_emp_line "7${RS}99,103${RS}1${RS}99${RS}11${RS}${RS}${RS}${RS}Pat${RS}pat" >/dev/null; then
    bad "a site list in the count field is rejected"
  else
    ok "a site list in the count field is rejected"
  fi

  UNFIN="NOT ${FIN}"
  plan_lines=("5	99	1	11")
  plan_orgs=("2")
  apply=$(write_apply_sql 1 "$MOVE_WHERE" "$UNFIN" yes)
  printf '%s\n' "$apply" | grep -q 'LEFT JOIN users u' && ok "update is limited to the joined user" || bad "update is limited to the joined user"
  printf '%s\n' "$apply" | grep -q 'a.org_id=2 OR u.org_id=2' && ok "update cannot touch another org" || bad "update cannot touch another org"
  printf '%s\n' "$apply" | grep -q 'NOT IN (0,999,6,11,19,20,21,23)' && ok "update cannot touch a store, 0, or 999" || bad "update cannot touch a store, 0, or 999"
  printf '%s\n' "$apply" | grep -q 'CAST(a.site_id AS UNSIGNED)=99' && ok "update still requires the old non-store site" || bad "update still requires the old non-store site"
  printf '%s\n' "$apply" | grep -q '>=0' && bad "update SQL does not contain >=0" || ok "update SQL does not contain >=0"
  printf '%s\n' "$apply" | grep -q '<>0' && ok "update SQL keeps <>0" || bad "update SQL keeps <>0"

  if [ "$fail" -eq 0 ]; then
    printf 'SELFTEST_OK\n'
    exit 0
  fi
  printf 'SELFTEST_FAIL %s\n' "$fail"
  exit 1
fi

mysql_n() { mysql -N --batch "$DB" -e "$1"; }
has_col() {
  local n
  n=$(mysql_n "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${1}' AND COLUMN_NAME='${2}'")
  [ "$n" = 1 ]
}
has_table() {
  local n
  n=$(mysql_n "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='${1}'")
  [ "$n" = 1 ]
}

screen_n=0
show() {
  printf '%s\n' "$1" | tee -a "$REPORT"
  screen_n=$((screen_n + 1))
}
file() { printf '%s\n' "$1" >> "$REPORT"; }

echo "MODE=$M"
[ "$M" = check ] || [ "$M" = go ] || [ "$M" = rollback ] || {
  echo "Usage: bash deploy23_offsite_to_assigned.sh check|go|rollback"
  exit 2
}

mkdir -p "$D"
TS=$(date +%Y%m%d-%H%M%S)
if [ "$M" = check ]; then
  REPORT=$D/pr23-check_$TS.txt
else
  REPORT=$D/pr23-${M}_$TS.txt
fi
: > "$REPORT"
show "MODE=$M"
show "REPORT=$REPORT"

exclude_in=$(build_exclude_list "${STORE_IDS[@]}") || {
  show "STOP: store id list failed its own check. Nothing changed."
  exit 1
}
[ "$(count_token "$exclude_in" 0)" = 1 ] || { show "STOP: site 0 duplicated. Nothing changed."; exit 1; }
comma_has "$exclude_in" 6 || { show "STOP: Penticton 6 missing. Nothing changed."; exit 1; }

pred=$(payout_final_sql)
printf '%s' "$pred" | grep -q '>=' && { show "STOP: payout predicate must be <>0. Nothing changed."; exit 1; }
[ "$pred" = 'COALESCE(a.payout_status,0)<>0' ] || { show "STOP: payout predicate drifted. Nothing changed."; exit 1; }

site_id_col=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME IN ('SId','Sid','id') ORDER BY FIELD(COLUMN_NAME,'SId','Sid','id') LIMIT 1")
[ -n "$site_id_col" ] || { show "STOP: sites has no id column. Nothing changed."; exit 1; }
name_cols=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sites' AND COLUMN_NAME IN ('nick_name','name','site_name') ORDER BY FIELD(COLUMN_NAME,'nick_name','name','site_name')")
[ -n "$name_cols" ] || { show "STOP: sites has no name column. Nothing changed."; exit 1; }
has_col sites org_id || { show "STOP: sites.org_id is missing. Nothing changed."; exit 1; }
has_col attendance site_id || { show "STOP: attendance.site_id is missing. Nothing changed."; exit 1; }
has_col attendance user_id || { show "STOP: attendance.user_id is missing. Nothing changed."; exit 1; }
has_table users || { show "STOP: users table is missing. Nothing changed."; exit 1; }

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

user_org_col=""
for cand in org_id organization_id org; do
  if has_col users "$cand"; then
    user_org_col=$cand
    break
  fi
done
att_org_sql=""
user_org_sql=""
has_col attendance org_id && att_org_sql="a.org_id"
[ -n "$user_org_col" ] && user_org_sql="u.\`${user_org_col}\`"
scope=$(build_scope_sql "$att_org_sql" "$user_org_sql") || {
  show "STOP: neither attendance nor users has an org column. Nothing changed."
  exit 1
}
show "SCOPE=${scope}"
show "USER_ORG_COLUMN=${user_org_col:-missing}"
show "ATTENDANCE_ORG_COLUMN=$( [ -n "$att_org_sql" ] && printf 'org_id' || printf 'missing')"
file "SCOPE uses users.${user_org_col:-<none>} and attendance.org_id. Other orgs are not candidates."

declare -A STORE_ORG=()
declare -A STORE_NAME=()
declare -A KEY_SITE=()
store_ok=yes
show "STORES=6,11,19,20,21,23"
id_in=$(printf '%s\n' "${STORE_IDS[@]}" | paste -sd, -)
store_rows=$(mysql_n "SELECT \`${site_id_col}\`, org_id, REPLACE(REPLACE(CONCAT_WS(' ', ${name_concat}), CHAR(9), ' '), CHAR(10), ' ') FROM sites WHERE \`${site_id_col}\` IN (${id_in}) ORDER BY \`${site_id_col}\`")
seen_ids=" "
while IFS=$'\t' read -r sid org blob; do
  [ -n "${sid:-}" ] || continue
  is_uint "$sid" || { file "STORE_BAD id=${sid}"; store_ok=no; continue; }
  seen_ids="${seen_ids}${sid} "
  key=$(store_key_for "$(compact_name "$blob")")
  if [ "$org" != "$ORG" ]; then
    file "STORE_WRONG_ORG site_id=${sid} org_id=${org} name=${blob}"
    store_ok=no
    continue
  fi
  if [ "$key" = NONE ] || [ "$key" = AMBIGUOUS ]; then
    file "STORE_NAME site_id=${sid} org_id=${org} key=${key} name=${blob}"
    store_ok=no
    continue
  fi
  if [ -n "${KEY_SITE[$key]:-}" ]; then
    file "STORE_DUPLICATE key=${key} site_id=${sid} other=${KEY_SITE[$key]}"
    store_ok=no
    continue
  fi
  KEY_SITE[$key]=$sid
  STORE_ORG[$sid]=$org
  STORE_NAME[$sid]=$blob
  show "STORE ${sid} ${key} org=${org} name=${blob}"
done <<< "$store_rows"
for sid in "${STORE_IDS[@]}"; do
  case "$seen_ids" in
    *" ${sid} "*) ;;
    *) file "STORE_MISSING site_id=${sid}"; store_ok=no ;;
  esac
done
for key in penticton westbank vernon merritt salmonarm harvey; do
  [ -n "${KEY_SITE[$key]:-}" ] || { file "STORE_KEY_MISSING ${key}"; store_ok=no; }
done
show "STORE_OK=${store_ok}"
file "EXCLUDE=${exclude_in}"

att_cols=$(mysql_n "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance'")
has_att() { printf '%s\n' "$att_cols" | grep -qx "$1"; }
fin_parts=()
if has_att is_finalized; then fin_parts+=("COALESCE(a.is_finalized,0)=1"); fi
if has_att payout_status; then fin_parts+=("$pred"); fi
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
  *) show "STOP: FIN is not parenthesized. Nothing changed."; exit 1 ;;
esac
if has_att payout_status; then
  printf '%s' "$FIN" | grep -q '<>0' || { show "STOP: FIN lost <>0. Nothing changed."; exit 1; }
  printf '%s' "$FIN" | grep -q '>=0' && { show "STOP: FIN contains >=0. Nothing changed."; exit 1; }
fi
MOVE_WHERE=$(build_move_where "$scope" "$exclude_in")
file "MOVE_WHERE=${MOVE_WHERE}"
file "FIN=${FIN}"
file "PAYOUT_PREDICATE=${pred}"

has_org=no
has_att org_id && has_org=yes
org_select="0"
[ "$has_org" = yes ] && org_select="IFNULL(a.org_id,0)"
from_sql="FROM attendance a LEFT JOIN users u ON u.id=a.user_id"

user_name_sql="CONCAT('user ', a.user_id)"
user_name_grp="CONCAT('user ', a.user_id)"
if has_col users Name; then
  user_name_sql="COALESCE(u.\`Name\`, CONCAT('user ', a.user_id))"
  user_name_grp="COALESCE(MAX(u.\`Name\`), CONCAT('user ', a.user_id))"
elif has_col users name; then
  user_name_sql="COALESCE(u.\`name\`, CONCAT('user ', a.user_id))"
  user_name_grp="COALESCE(MAX(u.\`name\`), CONCAT('user ', a.user_id))"
fi
login_sql="''"
login_grp="''"
login_col=""
for cand in user username login; do
  if has_col users "$cand"; then
    login_col=$cand
    login_sql="COALESCE(u.\`${cand}\`, '')"
    login_grp="COALESCE(MAX(u.\`${cand}\`), '')"
    break
  fi
done
clean() { printf "REPLACE(REPLACE(REPLACE(%s, CHAR(9), ' '), CHAR(10), ' '), CHAR(31), ' ')" "$1"; }

primary_sql="''"
restaurant_sql="''"
csv_sql="''"
assign_cols=()
if has_col users primary_site_id; then
  primary_sql="IFNULL(MAX(u.primary_site_id), '')"
  assign_cols+=("users.primary_site_id")
fi
if has_col users primary_restaurant_id; then
  restaurant_sql="IFNULL(MAX(u.primary_restaurant_id), '')"
  assign_cols+=("users.primary_restaurant_id")
fi
if has_col users assigned_site_ids; then
  csv_sql="IFNULL(MAX(u.assigned_site_ids), '')"
  assign_cols+=("users.assigned_site_ids")
fi
map_join=""
map_sql="''"
map_note=none
for cand in user_sites site_users user_site users_sites employee_sites assigned_sites user_site_map user_assigned_sites; do
  if has_table "$cand" && has_col "$cand" user_id && has_col "$cand" site_id; then
    if [ "$map_note" != none ]; then
      map_note="ambiguous"
      map_join=""
      map_sql="''"
      break
    fi
    map_note=$cand
    map_join="LEFT JOIN (SELECT user_id, GROUP_CONCAT(DISTINCT site_id ORDER BY site_id SEPARATOR ',') AS mapped_sites FROM \`${cand}\` GROUP BY user_id) umap ON umap.user_id=a.user_id"
    map_sql="IFNULL(MAX(umap.mapped_sites), '')"
    assign_cols+=("mapping:${cand}")
  fi
done
if [ "${#assign_cols[@]}" -eq 0 ]; then
  show "STOP: no assignment column. Nothing changed."
  exit 1
fi
show "ASSIGNMENT=$(IFS=','; printf '%s' "${assign_cols[*]}")"
show "MAPPING_TABLE=${map_note}"
file "ASSIGNMENT_RULE=assigned_site_ids when it has a number; else primary_site_id and primary_restaurant_id. Lowest id that is one of 6,11,19,20,21,23. Otherwise UNRESOLVED."

file "EXCLUDED:"
excl_rows=$(mysql_n "SELECT CONCAT(CAST(a.site_id AS UNSIGNED), CHAR(31), COUNT(*), CHAR(31),
    SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END), CHAR(31),
    SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END))
  ${from_sql}
  WHERE ${scope} AND a.site_id REGEXP '^[0-9]+$' AND CAST(a.site_id AS UNSIGNED) IN (0, 999)
  GROUP BY CAST(a.site_id AS UNSIGNED)
  ORDER BY CAST(a.site_id AS UNSIGNED)")
if [ -z "$excl_rows" ]; then
  show "EXCLUDED 0 and 999: none in org ${ORG}"
else
  while IFS= read -r row; do
    IFS=$'\x1f' read -r sid rows unfin fin <<< "$row"
    is_uint "${unfin:-}" || unfin=0
    is_uint "${fin:-}" || fin=0
    show "EXCLUDED site=${sid} rows=${rows} unfinalized=${unfin} finalized=${fin}"
  done <<< "$excl_rows"
fi
blank_n=$(mysql_n "SELECT COUNT(*) ${from_sql} WHERE ${scope} AND (a.site_id IS NULL OR a.site_id='' OR LOWER(TRIM(a.site_id))='all' OR a.site_id NOT REGEXP '^[0-9]+$')")
show "EXCLUDED_BLANK=${blank_n}"

file "SITES:"
site_rows=$(mysql_n "SELECT CONCAT(CAST(a.site_id AS UNSIGNED), CHAR(31),
    COALESCE(MAX(s.org_id), 0), CHAR(31),
    $(clean "COALESCE(MAX(s.\`${first_name}\`), '')"), CHAR(31),
    COUNT(*), CHAR(31),
    SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END), CHAR(31),
    SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END))
  ${from_sql}
  LEFT JOIN sites s ON s.\`${site_id_col}\` = CAST(a.site_id AS UNSIGNED)
  WHERE ${MOVE_WHERE}
  GROUP BY CAST(a.site_id AS UNSIGNED)
  ORDER BY CAST(a.site_id AS UNSIGNED)")
site_n=0
site_shown=0
if [ -z "$site_rows" ]; then
  show "SITES none"
else
  while IFS= read -r row; do
    IFS=$'\x1f' read -r sid org name rows unfin fin <<< "$row"
    is_uint "${sid:-}" || continue
    is_uint "${unfin:-}" || { file "SITE_PARSE_BAD ${row}"; continue; }
    is_uint "${fin:-}" || { file "SITE_PARSE_BAD ${row}"; continue; }
    line="SITE site=${sid} org=${org} name=${name} rows=${rows} unfinalized=${unfin} finalized=${fin}"
    file "$line"
    site_n=$((site_n + 1))
    if [ "$site_shown" -lt 8 ]; then
      show "$line"
      site_shown=$((site_shown + 1))
    fi
  done <<< "$site_rows"
fi
if [ "$site_n" -gt 8 ]; then
  show "SITES=${site_n} see report"
fi

declare -A USER_STATUS=()
declare -A USER_CHOSEN=()
move_people=0
move_rows=0
test_people=0
test_rows=0
bad_people=0
bad_rows=0
parse_bad=0

emp_sql="SET SESSION group_concat_max_len=100000;
SELECT CONCAT(
  a.user_id, CHAR(31),
  SUM(CASE WHEN ${UNFIN} THEN 1 ELSE 0 END), CHAR(31),
  SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END), CHAR(31),
  IFNULL(GROUP_CONCAT(DISTINCT CAST(a.site_id AS UNSIGNED) ORDER BY CAST(a.site_id AS UNSIGNED) SEPARATOR ','), ''), CHAR(31),
  $(clean "$csv_sql"), CHAR(31),
  $(clean "$primary_sql"), CHAR(31),
  $(clean "$restaurant_sql"), CHAR(31),
  $(clean "$map_sql"), CHAR(31),
  $(clean "$user_name_grp"), CHAR(31),
  $(clean "$login_grp")
)
${from_sql}
${map_join}
WHERE ${MOVE_WHERE}
GROUP BY a.user_id
ORDER BY MIN(${user_name_sql}), a.user_id"
emp_rows=$(mysql_n "$emp_sql")
while IFS= read -r row; do
  [ -n "$row" ] || continue
  parsed=$(parse_emp_line "$row") || { parse_bad=$((parse_bad + 1)); file "EMP_PARSE_BAD ${row}"; continue; }
  IFS=$'\t' read -r uid unfin fin sites csv primary restaurant mapped name login <<< "$parsed"
  IFS='|' read -r chosen source <<< "$(choose_store "$csv" "$primary" "$restaurant" "$mapped")"
  if is_test_user "$name" "$login"; then
    status=TEST
    USER_STATUS[$uid]=test
    test_people=$((test_people + 1))
    test_rows=$((test_rows + unfin))
  elif [ -z "$chosen" ]; then
    status=UNRESOLVED
    USER_STATUS[$uid]=unresolved
    bad_people=$((bad_people + 1))
    bad_rows=$((bad_rows + unfin))
  else
    status=EMP
    USER_STATUS[$uid]=move
    USER_CHOSEN[$uid]=$chosen
    move_people=$((move_people + 1))
    move_rows=$((move_rows + unfin))
  fi
  file "$(format_emp "$status" "$name" "$uid" "$unfin" "$fin" "$sites" "$csv" "$chosen" "$source")"
done <<< "$emp_rows"

show "MOVE_EMPLOYEES=${move_people} MOVE_UNFINALIZED=${move_rows}"
show "UNRESOLVED_EMPLOYEES=${bad_people} UNRESOLVED_UNFINALIZED=${bad_rows}"
show "TEST_EMPLOYEES=${test_people} TEST_UNFINALIZED=${test_rows}"
show "EMP_PARSE_BAD=${parse_bad}"

fin_where_n=$(mysql_n "SELECT COUNT(*) ${from_sql} ${map_join} WHERE ${MOVE_WHERE} AND ${FIN}")
fin_group_n=$(mysql_n "SELECT COALESCE(SUM(n),0) FROM (
    SELECT SUM(CASE WHEN ${FIN} THEN 1 ELSE 0 END) AS n
    ${from_sql} ${map_join}
    WHERE ${MOVE_WHERE}
    GROUP BY CAST(a.site_id AS UNSIGNED)
  ) fin_groups")
show "FINALIZED_ROWS=${fin_where_n}"
file "FINALIZED_GROUP_SUM=${fin_group_n}"
fin_match=yes
if [ "$fin_where_n" != "$fin_group_n" ]; then
  fin_match=no
  show "FINALIZED_MISMATCH where=${fin_where_n} by_site=${fin_group_n}"
fi
if is_uint "$fin_where_n" && [ "$fin_where_n" -lt 30 ]; then
  fin_rows=$(mysql_n "SELECT CONCAT(a.id, CHAR(31), $(clean "$user_name_sql"), CHAR(31), CAST(a.site_id AS UNSIGNED), CHAR(31), IFNULL(a.clock_in,''), CHAR(31), IFNULL(a.clock_out,''))
    ${from_sql}
    WHERE ${MOVE_WHERE} AND ${FIN}
    ORDER BY a.clock_in, a.id")
  while IFS= read -r row; do
    [ -n "$row" ] || continue
    IFS=$'\x1f' read -r id name sid cin cout <<< "$row"
    file "FINALIZED id=${id} name=${name} site=${sid} clock_in=${cin} clock_out=${cout}"
  done <<< "$fin_rows"
else
  file "FINALIZED list omitted because FINALIZED_ROWS is not under 30"
fi

if has_table payroll_finalized && has_col payroll_finalized attendance_id; then
  pf_n=$(mysql_n "SELECT COUNT(*) FROM payroll_finalized pf INNER JOIN attendance a ON a.id=pf.attendance_id LEFT JOIN users u ON u.id=a.user_id WHERE ${MOVE_WHERE}")
  show "PAYROLL_FINALIZED_FOR_OFFSITE_SHIFTS=${pf_n}"
else
  show "PAYROLL_FINALIZED_FOR_OFFSITE_SHIFTS=no-attendance-link"
fi
if has_col breaks site_id; then
  file "BREAKS store site_id. go dumps breaks for the sites it moves."
else
  file "BREAKS have no site column. They follow shift_id and are not updated."
fi

go_ok=yes
[ "$store_ok" = yes ] || go_ok=no
[ "$fin_match" = yes ] || go_ok=no
[ "$parse_bad" -eq 0 ] || go_ok=no
show "GO_ALLOWED=${go_ok}"
file "SCREEN_LINES=${screen_n}"

if [ "$M" = check ]; then
  show "CHECK ONLY. Database unchanged."
  if [ "$screen_n" -gt 40 ]; then
    echo "SCREEN_OVER_BUDGET=${screen_n}" >> "$REPORT"
  fi
  exit 0
fi

if [ "$M" = rollback ]; then
  [ "$store_ok" = yes ] || { show "STOP: the six stores did not verify. Nothing changed."; exit 1; }
  pointer=$D/pr23-offsite-latest.ids
  [ -f "$pointer" ] || { show "STOP: no move log. Nothing changed."; exit 1; }
  idfile=$(cat "$pointer")
  [ -f "$idfile" ] || { show "STOP: id file ${idfile} is missing. Nothing changed."; exit 1; }
  restored=0
  skipped=0
  while IFS=$'\t' read -r id old_site old_org new_site; do
    [ -n "${id:-}" ] || continue
    is_uint "$id" || { skipped=$((skipped + 1)); continue; }
    is_uint "$old_site" || { skipped=$((skipped + 1)); continue; }
    is_uint "$old_org" || { skipped=$((skipped + 1)); continue; }
    is_uint "$new_site" || { skipped=$((skipped + 1)); continue; }
    [ -n "${STORE_ORG[$new_site]+x}" ] || { file "SKIP id=${id} reason=new-site-not-a-store"; skipped=$((skipped + 1)); continue; }
    if [ "$has_org" = yes ]; then
      sql="UPDATE attendance a SET a.site_id=${old_site}, a.org_id=${old_org} WHERE a.id=${id} AND CAST(a.site_id AS UNSIGNED)=${new_site} AND ${UNFIN}"
    else
      sql="UPDATE attendance a SET a.site_id=${old_site} WHERE a.id=${id} AND CAST(a.site_id AS UNSIGNED)=${new_site} AND ${UNFIN}"
    fi
    changed=$(mysql_n "${sql}; SELECT ROW_COUNT();" | tail -1 | tr -cd '0-9')
    if [ "${changed:-0}" = 1 ]; then
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
      file "SKIP id=${id} reason=${why}"
      skipped=$((skipped + 1))
    fi
  done < "$idfile"
  show "ROLLBACK restored ${restored}. Skipped ${skipped}. Finalized rows were not changed."
  exit 0
fi

[ "$go_ok" = yes ] || { show "STOP: check would not allow go. Nothing changed."; exit 1; }

plan=$(mysql_n "SELECT CONCAT(a.id, CHAR(31), CAST(a.site_id AS UNSIGNED), CHAR(31), ${org_select}, CHAR(31), a.user_id)
  ${from_sql}
  WHERE ${MOVE_WHERE} AND ${UNFIN}
  ORDER BY a.id")
plan_lines=()
plan_ids=()
plan_sites=()
plan_orgs=()
while IFS= read -r row; do
  [ -n "$row" ] || continue
  IFS=$'\x1f' read -r id old_site old_org uid <<< "$row"
  is_uint "$id" || { show "STOP: bad attendance id. Nothing changed."; exit 1; }
  is_uint "$old_site" || { show "STOP: bad site on id=${id}. Nothing changed."; exit 1; }
  is_uint "$old_org" || { show "STOP: bad org on id=${id}. Nothing changed."; exit 1; }
  is_uint "$uid" || { show "STOP: bad user on id=${id}. Nothing changed."; exit 1; }
  comma_has "$exclude_in" "$old_site" && { show "STOP: id=${id} is on an excluded site. Nothing changed."; exit 1; }
  [ "${USER_STATUS[$uid]:-unresolved}" = move ] || continue
  new_site=${USER_CHOSEN[$uid]:-}
  is_uint "$new_site" || { show "STOP: no target for user ${uid}. Nothing changed."; exit 1; }
  new_org=${STORE_ORG[$new_site]:-}
  [ "$new_org" = "$ORG" ] || { show "STOP: target ${new_site} is not org ${ORG}. Nothing changed."; exit 1; }
  plan_lines+=("${id}	${old_site}	${old_org}	${new_site}")
  plan_ids+=("$id")
  plan_sites+=("$old_site")
  plan_orgs+=("$new_org")
done <<< "$plan"

if [ "${#plan_lines[@]}" -eq 0 ]; then
  show "MOVED 0"
  exit 0
fi

planned=${#plan_lines[@]}
idfile=$D/pre-pr23-offsite_$TS.ids
printf '%s\n' "${plan_lines[@]}" > "$idfile"
id_list=$(printf '%s\n' "${plan_ids[@]}" | paste -sd, -)
site_list=$(printf '%s\n' "${plan_sites[@]}" | sort -n | uniq | paste -sd, -)
dump=$D/pre-pr23-offsite_$TS.sql
mysqldump --single-transaction --no-create-info --complete-insert --skip-extended-insert "$DB" attendance --where="id IN (${id_list})" > "$dump"
if has_col breaks site_id; then
  mysqldump --single-transaction --no-create-info --complete-insert --skip-extended-insert "$DB" breaks --where="site_id IN (${site_list})" >> "$dump"
fi
gzip -f "$dump"
gzip -t "$dump.gz"
echo "$idfile" > "$D/pr23-offsite-latest.ids"
file "DUMP ${dump}.gz"
file "IDS ${idfile}"

sqlfile=$D/pre-pr23-offsite_$TS.apply.sql
write_apply_sql "$planned" "$MOVE_WHERE" "$UNFIN" "$has_org" > "$sqlfile"
if grep -q '>=0' "$sqlfile"; then
  rm -f "$D/pr23-offsite-latest.ids"
  show "STOP: apply SQL contains >=0. Nothing changed."
  exit 1
fi
set +e
apply_out=$(mysql -N --batch "$DB" < "$sqlfile" 2>&1)
apply_rc=$?
set -e
printf '%s\n' "$apply_out" >> "$REPORT"
if [ "$apply_rc" -ne 0 ]; then
  rm -f "$D/pr23-offsite-latest.ids"
  show "ABORTED. The transaction was not committed. Database unchanged."
  exit 1
fi
moved=$(printf '%s\n' "$apply_out" | grep -E '^[0-9]+$' | tail -1 || true)
if [ "$moved" != "$planned" ]; then
  show "STOP: committed count ${moved} does not match planned ${planned}. Run rollback."
  exit 1
fi
show "MOVED ${moved}"
show "PLANNED ${planned}"
show "ROLLBACK: bash $0 rollback"
