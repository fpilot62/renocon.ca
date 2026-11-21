#!/bin/bash
# Deploy the attendance audit log.
# Pins the feature commit. Does not deploy anything else.
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_audit_v1.sh check
#   bash deploy_audit_v1.sh go
#   bash deploy_audit_v1.sh rollback <ts>
#
# check is read-only. go refuses unless check prints CHECK OK.
# A live file that differs from the pinned base only by the one-line
# org-scope guard is CLEAN. Any other live drift is a CONFLICT.
set -euo pipefail
# TARGET=staging|live. Default is live. See ops/deploy_target.inc.sh.
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
MERGED=${AUDIT_MERGED:-/root/audit-merged}
WORK=${AUDIT_WORK:-/root/audit-work}
URL=${PAYROLL_URL:-https://payroll.renocon.ca}
RAW=${AUDIT_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

# main before this work, and the commit that contains the PHP and SQL.
BASE=9cf017e5702bd03dacfe0be3954ad487ef8522f3
HEAD=5c798d62b6f546533730304ee2ef760cbdfaf6d3

# rel|base_sha|head_sha|added
# added=1 means the file did not exist at the base.
FILES=$(cat <<EOF
aa_labor_hours_import.php|$BASE|$HEAD|0
api/punch.php|$BASE|$HEAD|0
app_clock.php|$BASE|$HEAD|0
app_punch.php|$BASE|$HEAD|0
app_upload_attendance.php|$BASE|$HEAD|0
approve_shift.php|$BASE|$HEAD|0
attendance_audit.php|$BASE|$HEAD|1
bulk_allocation_desk.php|$BASE|$HEAD|0
bulk_assign_site.php|$BASE|$HEAD|0
bulk_finalize.php|$BASE|$HEAD|0
bulk_finalize_autosplit.php|$BASE|$HEAD|0
bulk_finalize_handler.php|$BASE|$HEAD|0
clearview_import.php|$BASE|$HEAD|0
delete_attendance.php|$BASE|$HEAD|0
delete_shift.php|$BASE|$HEAD|0
edit_shift.php|$BASE|$HEAD|0
engine/aa_labor_hours_lib.php|$BASE|$HEAD|0
engine/attendance_audit_lib.php|$BASE|$HEAD|1
engine/attendance_integrity_lib.php|$BASE|$HEAD|0
engine/update_shift_time.php|$BASE|$HEAD|0
import_clearview.php|$BASE|$HEAD|0
importer_lib.php|$BASE|$HEAD|0
imports/import_commit.php|$BASE|$HEAD|0
manual_attendance.php|$BASE|$HEAD|0
migration_identity_fix.php|$BASE|$HEAD|0
migrations/20261120_attendance_audit.sql|$BASE|$HEAD|1
payroll_ledger.php|$BASE|$HEAD|0
payroll_master_ledger.php|$BASE|$HEAD|0
payroll_master_ledger1.php|$BASE|$HEAD|0
payroll_processor.php|$BASE|$HEAD|0
payroll_save_payment.php|$BASE|$HEAD|0
process_audit.php|$BASE|$HEAD|0
process_bulk_payroll.php|$BASE|$HEAD|0
process_finalize.php|$BASE|$HEAD|0
process_kiosk.php|$BASE|$HEAD|0
process_payroll.php|$BASE|$HEAD|0
process_punch.php|$BASE|$HEAD|0
process_punch1.php|$BASE|$HEAD|0
revert_payroll.php|$BASE|$HEAD|0
save_payroll.php|$BASE|$HEAD|0
save_split_payroll.php|$BASE|$HEAD|0
staff_audit.php|$BASE|$HEAD|0
staff_audit1.php|$BASE|$HEAD|0
transfer_engine.php|$BASE|$HEAD|0
unfinalize_action.php|$BASE|$HEAD|0
update_attendance.php|$BASE|$HEAD|0
update_pending_hrs.php|$BASE|$HEAD|0
EOF
)

if [[ ${AUDIT_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root."
  exit 1
fi

md() { md5sum "$1" | cut -c1-32; }

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

# True when every line that differs is the one-line org-scope guard.
only_org_scope() {
  local base=$1 live=$2
  local adds dels line body
  adds=$(diff -u "$base" "$live" | grep '^+' | grep -v '^+++' || true)
  dels=$(diff -u "$base" "$live" | grep '^-' | grep -v '^---' || true)
  [[ -z $dels && -n $adds ]] || return 1
  while IFS= read -r line; do
    [[ -z $line ]] && continue
    body=${line#+}
    [[ -z ${body//[[:space:]]/} ]] && continue
    [[ $body == *orgscope_guard.php* && $body == *osg_page* ]] || return 1
  done <<< "$adds"
  return 0
}

# PR file plus the exact guard lines from live, placed under the opening tag.
reinsert_guard() {
  local live=$1 pr=$2 out=$3
  local guards
  guards=$(grep 'orgscope_guard.php' "$live" || true)
  [[ -n $guards ]] || return 1
  {
    IFS= read -r first
    printf '%s\n' "$first"
    printf '%s\n' "$guards"
    cat
  } < "$pr" > "$out"
}

merge_one() {
  local rel=$1 base_sha=$2 head_sha=$3 added=$4
  local live=$APP/$rel
  local base=$WORK/base/$rel
  local pr=$WORK/pr/$rel
  local out=$MERGED/$rel
  mkdir -p "$(dirname "$base")" "$(dirname "$pr")" "$(dirname "$out")"
  if ! fetch_sha "$head_sha" "$rel" "$pr"; then
    echo "FETCH_FAIL $rel $head_sha"
    return 1
  fi
  if [[ $added == 1 ]]; then
    if [[ ! -e $live ]]; then
      cp -f "$pr" "$out"
      echo "SAFE $rel (new file, not on the server)"
      echo "SAFE $rel" >> "$MERGED/plan.txt"
      return 0
    fi
    echo "CONFLICT $rel (new in the PR, but a different file is already live)"
    : > "$base"
  else
    if ! fetch_sha "$base_sha" "$rel" "$base"; then
      echo "FETCH_FAIL base $rel $base_sha"
      return 1
    fi
    if [[ ! -e $live ]]; then
      echo "CONFLICT $rel (missing on the server)"
      live=$WORK/empty/$rel
      mkdir -p "$(dirname "$live")"
      : > "$live"
    fi
    local live_md5 base_md5
    live_md5=$(md "$live")
    base_md5=$(md "$base")
    if [[ $live_md5 == "$base_md5" ]]; then
      cp -f "$pr" "$out"
      echo "SAFE $rel"
      echo "SAFE $rel" >> "$MERGED/plan.txt"
      return 0
    fi
    echo "CONFLICT $rel live=$live_md5 base=$base_md5"
  fi

  local rc=0
  if command -v git >/dev/null 2>&1; then
    git merge-file -p -L live -L base -L pr "$live" "$base" "$pr" > "$out" || rc=$?
  elif command -v diff3 >/dev/null 2>&1; then
    diff3 -m -L live -L base -L pr "$live" "$base" "$pr" > "$out" || rc=$?
  else
    echo "CONFLICT $rel (need git merge-file or diff3)"
    echo "CONFLICT $rel" >> "$MERGED/plan.txt"
    return 1
  fi
  if [[ $rc -lt 0 ]]; then
    echo "CONFLICT $rel (merge tool failed)"
    echo "CONFLICT $rel" >> "$MERGED/plan.txt"
    return 1
  fi

  local live_guard=0 out_guard=0
  if [[ -s $live ]] && grep -q 'orgscope_guard.php' "$live"; then live_guard=1; fi
  if grep -q 'orgscope_guard.php' "$out"; then out_guard=1; fi
  local markers=0
  if grep -q '^<<<<<<<' "$out"; then markers=1; fi

  if [[ $markers -eq 0 && $rc -eq 0 && ( $live_guard -eq 0 || $out_guard -eq 1 ) ]]; then
    echo "CLEAN $rel"
    echo "CLEAN $rel" >> "$MERGED/plan.txt"
    return 0
  fi

  if [[ $added == 0 && -s $base && -s $live ]] && only_org_scope "$base" "$live"; then
    if reinsert_guard "$live" "$pr" "$out" && grep -q 'orgscope_guard.php' "$out"; then
      if [[ $rel != *.php ]] || php -l "$out" >/dev/null 2>&1; then
        echo "CLEAN $rel (org-scope guard kept)"
        echo "CLEAN $rel" >> "$MERGED/plan.txt"
        return 0
      fi
    fi
  fi

  echo "CONFLICT $rel"
  echo "CONFLICT $rel" >> "$MERGED/plan.txt"
  return 1
}

check() {
  rm -rf "$WORK" "$MERGED"
  mkdir -p "$WORK" "$MERGED"
  : > "$MERGED/plan.txt"
  local bad=0 rel base head added
  while IFS='|' read -r rel base head added; do
    [[ -z $rel ]] && continue
    merge_one "$rel" "$base" "$head" "$added" || bad=1
    if [[ -f $MERGED/$rel && $rel == *.php ]]; then
      if ! php -l "$MERGED/$rel" >/dev/null 2>&1; then
        echo "CONFLICT $rel (php -l failed on the merged file)"
        echo "CONFLICT $rel" >> "$MERGED/plan.txt"
        bad=1
      fi
    fi
  done <<< "$FILES"
  if [[ $bad -eq 0 ]] && ! grep -q '^CONFLICT ' "$MERGED/plan.txt"; then
    echo "CHECK OK"
    return 0
  fi
  echo "CHECK FAILED"
  return 1
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
  local tgz=$BK/pre-audit-$ts-files.tgz
  local added=$BK/pre-audit-$ts-added.txt
  [[ -f $tgz ]] || { echo "Missing $tgz"; return 1; }
  tar -xzpf "$tgz" -C "$APP"
  if [[ -f $added ]]; then
    local rel
    while IFS= read -r rel; do
      [[ -n $rel ]] && rm -f "$APP/$rel"
    done < "$added"
  fi
}

db_undo_this_run() {
  local ts=$1
  local state=$BK/pre-audit-$ts.state
  [[ -f $state ]] || return 0
  # shellcheck disable=SC1090
  source "$state"
  if [[ ${CREATED_ATTENDANCE_AUDIT:-0} == 1 ]]; then
    mysql "$DB" -e "DROP TABLE IF EXISTS attendance_audit"
  fi
  if [[ ${CREATED_CREATED_BY:-0} == 1 ]]; then
    mysql "$DB" -e "ALTER TABLE attendance DROP COLUMN created_by"
  fi
  if [[ ${CREATED_UPDATED_BY:-0} == 1 ]]; then
    mysql "$DB" -e "ALTER TABLE attendance DROP COLUMN updated_by"
  fi
}

rollback_cmd() {
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 rollback <ts>"; exit 2; }
  restore_files "$ts"
  db_undo_this_run "$ts"
  echo "ROLLBACK DONE"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-$ts.sql.gz"
  echo "$BK/pre-audit-$ts-files.tgz"
}

smoke() {
  local fail=0 rel code have col menu_before menu_after
  local pages=(
    staff_audit.php
    attendance_audit.php
    manual_attendance.php
    manual_pay_entry.php
    edit_shift.php
  )
  for rel in "${pages[@]}"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$URL/$rel" || echo 000)
    echo "HTTP $code $rel"
    case $code in
      200|301|302|303|401|403) ;;
      *) echo "SMOKE FAIL $rel"; fail=1 ;;
    esac
  done
  if grep -q 'manual_pay_entry.php?lock=1' "$APP/staff_audit.php"; then
    echo "SMOKE FAIL staff_audit still has the per-row Manual Pay link"
    fail=1
  fi
  if ! grep -q 'Manually added' "$APP/staff_audit.php"; then
    echo "SMOKE FAIL staff_audit is missing the manual badge"
    fail=1
  fi
  if ! grep -q 'Manual Pay / Adjustments' "$APP/payroll_master_ledger.php"; then
    echo "SMOKE FAIL the ledger Manual Pay entry is missing"
    fail=1
  fi
  have=$(mysql -N -B "$DB" -e "SHOW TABLES LIKE 'attendance_audit'")
  echo "attendance_audit=$have"
  if [[ $have != attendance_audit ]]; then
    echo "SMOKE FAIL attendance_audit table"
    fail=1
  fi
  for col in created_by updated_by; do
    have=$(mysql -N -B "$DB" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='attendance' AND COLUMN_NAME='$col'")
    echo "attendance.$col=$have"
    if [[ ${have:-0} != 1 ]]; then
      echo "SMOKE FAIL attendance.$col"
      fail=1
    fi
  done
  if [[ -f $BK/pre-audit-menu.count ]]; then
    menu_before=$(cat "$BK/pre-audit-menu.count")
    menu_after=$(mysql -N -B "$DB" -e "SELECT COUNT(*) FROM menu_manager WHERE LinkURL LIKE '%manual_pay_entry.php%'")
    echo "manual_pay_menu before=$menu_before after=$menu_after"
    if [[ ${menu_before:-0} -gt 0 && ${menu_after:-0} -lt ${menu_before:-0} ]]; then
      echo "SMOKE FAIL manual pay menu link"
      fail=1
    fi
  fi
  return $fail
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-audit-$ts.sql.gz"
  : > "$BK/pre-audit-$ts-files.list"
  : > "$BK/pre-audit-$ts-added.txt"
  local rel
  while IFS='|' read -r rel _base _head _added; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-audit-$ts-files.list"
    else
      echo "$rel" >> "$BK/pre-audit-$ts-added.txt"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-audit-$ts-files.list ]]; then
    tar -czpf "$BK/pre-audit-$ts-files.tgz" -C "$APP" -T "$BK/pre-audit-$ts-files.list"
  else
    tar -czpf "$BK/pre-audit-$ts-files.tgz" --files-from=/dev/null
  fi
  local created=0 created_by=0 updated_by=0
  if [[ $(mysql -N -B "$DB" -e "SHOW TABLES LIKE 'attendance_audit'") != attendance_audit ]]; then
    created=1
  fi
  created_by=$(mysql -N -B "$DB" -e "SELECT IF(COUNT(*)=0,1,0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='attendance' AND COLUMN_NAME='created_by'")
  updated_by=$(mysql -N -B "$DB" -e "SELECT IF(COUNT(*)=0,1,0) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='attendance' AND COLUMN_NAME='updated_by'")
  mysql -N -B "$DB" -e "SELECT COUNT(*) FROM menu_manager WHERE LinkURL LIKE '%manual_pay_entry.php%'" > "$BK/pre-audit-menu.count" || echo 0 > "$BK/pre-audit-menu.count"
  printf 'CREATED_ATTENDANCE_AUDIT=%s\nCREATED_CREATED_BY=%s\nCREATED_UPDATED_BY=%s\n' "$created" "$created_by" "$updated_by" > "$BK/pre-audit-$ts.state"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-$ts.sql.gz"
  echo "$BK/pre-audit-$ts-files.tgz"

  local spec
  while IFS='|' read -r rel _base _head _added; do
    [[ -z $rel ]] && continue
    mkdir -p "$APP/$(dirname "$rel")"
    spec=$(neighbour_spec "$APP/$rel")
    cp -f "$MERGED/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if [[ $rel == *.php ]]; then
      if ! php -l "$APP/$rel"; then
        echo "php -l failed on $rel"
        restore_files "$ts"
        echo "ROLLBACK POINT=$ts"
        echo "$BK/pre-audit-$ts.sql.gz"
        echo "$BK/pre-audit-$ts-files.tgz"
        exit 1
      fi
    fi
  done <<< "$FILES"

  if ! mysql "$DB" < "$APP/migrations/20261120_attendance_audit.sql"; then
    echo "attendance audit migration failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-audit-$ts.sql.gz"
    echo "$BK/pre-audit-$ts-files.tgz"
    exit 1
  fi
  if ! smoke; then
    echo "smoke test failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-audit-$ts.sql.gz"
    echo "$BK/pre-audit-$ts-files.tgz"
    exit 1
  fi
  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-audit-$ts.sql.gz"
  echo "$BK/pre-audit-$ts-files.tgz"
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
