#!/bin/bash
# Deploy ONLY PR #22 (ROE help, tutorial, help_qa panel and seed) and
# PR #23 (Admin Tutorial link, site_module_manager redirect).
# Does not deploy PR #24, and does not deploy T4127 or quick-entry.
# FAQ_T4127_LIVE and FAQ_QUICK_ENTRY_LIVE stay false; this script never sets them.
#
# Run as root from the WHM terminal. The server has no git checkout.
#   bash deploy_help_v1.sh check
#   bash deploy_help_v1.sh go
#   bash deploy_help_v1.sh rollback <ts>
#
# check is read-only. go refuses unless check prints CHECK OK.
set -euo pipefail

MODE=${1:-}
TSARG=${2:-}
APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}
DB=${PAYROLL_DB:-admin_payroll}
BK=${PAYROLL_BK:-/root/payroll-daily}
MERGED=${HELP_MERGED:-/root/help-merged}
WORK=${HELP_WORK:-/root/help-work}
URL=${PAYROLL_URL:-https://payroll.renocon.ca}
RAW=${HELP_RAW:-https://raw.githubusercontent.com/fpilot62/renocon.ca}

# PR #22 merged as e988cbf. Its base (main before that merge) is 3e2c434.
# PR #23 merged as 24da23c. Its base is e988cbf. PR #24 is later and is not used.
BASE22=3e2c43493bb0daeb514f8107f77cadabd1bc37ed
HEAD22=e988cbfb1b915e013f023aace822c00e9b3880db
BASE23=e988cbfb1b915e013f023aace822c00e9b3880db
HEAD23=24da23c1441b054b3bc44533d9e57e41e2a851ac

# rel|base_sha|head_sha|added
# added=1 means the file did not exist at the PR base.
FILES=$(cat <<EOF
docs/FAQ_PAYROLL.md|$BASE22|$HEAD22|1
engine/help_qa_lib.php|$BASE22|$HEAD22|1
engine/help_qa_panel.php|$BASE22|$HEAD22|1
engine/help_qa_seed.php|$BASE22|$HEAD22|1
engine/menu_generator.php|$BASE22|$HEAD22|0
help_center.php|$BASE22|$HEAD22|0
help_qa.php|$BASE22|$HEAD22|1
help_qa_action.php|$BASE22|$HEAD22|1
migrations/20261120_help_qa.sql|$BASE22|$HEAD22|1
payroll_help.php|$BASE22|$HEAD22|0
roe.php|$BASE22|$HEAD22|0
roe_help.php|$BASE22|$HEAD22|1
migrations/20261120_admin_tutorial_link.sql|$BASE23|$HEAD23|1
site_module_manager.php|$BASE23|$HEAD23|0
EOF
)

if [[ ${HELP_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
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
  # curl -f exits non-zero on 404 and may leave no usable body.
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

  # The live copy's only extra line is the org-scope guard. Keep that line
  # on the PR file. A full rewrite (site_module_manager.php) otherwise conflicts.
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
  local tgz=$BK/pre-help-$ts-files.tgz
  local added=$BK/pre-help-$ts-added.txt
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
  local state=$BK/pre-help-$ts.state
  [[ -f $state ]] || return 0
  # shellcheck disable=SC1090
  source "$state"
  if [[ ${CREATED_HELP_QA:-0} == 1 ]]; then
    mysql "$DB" -e "DROP TABLE IF EXISTS help_qa"
  fi
  if [[ -f $BK/pre-help-$ts-menu.sql ]]; then
    mysql "$DB" < "$BK/pre-help-$ts-menu.sql"
  fi
}

rollback_cmd() {
  local ts=$1
  [[ -n $ts ]] || { echo "Usage: bash $0 rollback <ts>"; exit 2; }
  restore_files "$ts"
  mysql "$DB" -e "DROP TABLE IF EXISTS help_qa"
  mysql "$DB" -e "UPDATE menu_manager SET LinkURL='#' WHERE UPPER(TRIM(MenuTitle))='ADMIN TUTORIAL' AND TRIM(LinkURL)='help_center.php#manager'"
  echo "ROLLBACK DONE"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-help-$ts.sql.gz"
  echo "$BK/pre-help-$ts-files.tgz"
}

smoke() {
  local fail=0 rel code count hidden menu_ok still_hash
  local pages=(
    help_center.php
    help_qa.php
    help_qa_action.php
    payroll_help.php
    roe.php
    roe_help.php
    site_module_manager.php
    engine/menu_generator.php
    engine/help_qa_lib.php
    engine/help_qa_panel.php
    engine/help_qa_seed.php
  )
  for rel in "${pages[@]}"; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$URL/$rel" || echo 000)
    echo "HTTP $code $rel"
    case $code in
      200|301|302|303|401|403) ;;
      *) echo "SMOKE FAIL $rel"; fail=1 ;;
    esac
  done
  read -r count hidden < <(mysql -N -B "$DB" -e "SELECT COUNT(*), IFNULL(SUM(status='hidden'),0) FROM help_qa")
  echo "help_qa count=$count hidden=$hidden"
  if [[ $count != 54 || $hidden != 4 ]]; then
    echo "SMOKE FAIL help_qa counts (expected 54 and 4)"
    fail=1
  fi
  menu_ok=$(mysql -N -B "$DB" -e "SELECT COUNT(*) FROM menu_manager WHERE UPPER(TRIM(MenuTitle))='ADMIN TUTORIAL' AND TRIM(LinkURL)='help_center.php#manager'")
  still_hash=$(mysql -N -B "$DB" -e "SELECT COUNT(*) FROM menu_manager WHERE UPPER(TRIM(MenuTitle))='ADMIN TUTORIAL' AND TRIM(LinkURL)='#'")
  echo "admin_tutorial_linked=$menu_ok still_hash=$still_hash"
  if [[ ${menu_ok:-0} -lt 1 || ${still_hash:-1} != 0 ]]; then
    echo "SMOKE FAIL Admin Tutorial url"
    fail=1
  fi
  return $fail
}

go() {
  check || { echo "go refused: check did not pass. Nothing was changed."; exit 1; }
  local ts
  ts=$(date +%Y%m%d-%H%M%S)
  mkdir -p "$BK"
  chmod 700 "$BK" || true
  mysqldump --single-transaction "$DB" | gzip > "$BK/pre-help-$ts.sql.gz"
  : > "$BK/pre-help-$ts-files.list"
  : > "$BK/pre-help-$ts-added.txt"
  local rel
  while IFS='|' read -r rel _base _head _added; do
    [[ -z $rel ]] && continue
    if [[ -e $APP/$rel ]]; then
      echo "$rel" >> "$BK/pre-help-$ts-files.list"
    else
      echo "$rel" >> "$BK/pre-help-$ts-added.txt"
    fi
  done <<< "$FILES"
  if [[ -s $BK/pre-help-$ts-files.list ]]; then
    tar -czpf "$BK/pre-help-$ts-files.tgz" -C "$APP" -T "$BK/pre-help-$ts-files.list"
  else
    tar -czpf "$BK/pre-help-$ts-files.tgz" --files-from=/dev/null
  fi
  local created=0
  if [[ $(mysql -N -B "$DB" -e "SHOW TABLES LIKE 'help_qa'") != help_qa ]]; then
    created=1
  fi
  mysql -N -B "$DB" -e "SELECT CONCAT('UPDATE menu_manager SET LinkURL=', QUOTE(IFNULL(LinkURL,'')), ' WHERE MenuID=', MenuID, ';') FROM menu_manager WHERE UPPER(TRIM(MenuTitle))='ADMIN TUTORIAL'" \
    > "$BK/pre-help-$ts-menu.sql" || true
  printf 'CREATED_HELP_QA=%s\n' "$created" > "$BK/pre-help-$ts.state"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-help-$ts.sql.gz"
  echo "$BK/pre-help-$ts-files.tgz"

  local spec
  while IFS='|' read -r rel _base _head _added; do
    [[ -z $rel ]] && continue
    mkdir -p "$APP/$(dirname "$rel")"
    if [[ -e $APP/$rel ]]; then
      spec=$(neighbour_spec "$APP/$rel")
    else
      spec=$(neighbour_spec "$APP/$rel")
    fi
    cp -f "$MERGED/$rel" "$APP/$rel"
    apply_spec "$APP/$rel" "$spec"
    if [[ $rel == *.php ]]; then
      if ! php -l "$APP/$rel"; then
        echo "php -l failed on $rel"
        restore_files "$ts"
        echo "ROLLBACK POINT=$ts"
        echo "$BK/pre-help-$ts.sql.gz"
        echo "$BK/pre-help-$ts-files.tgz"
        exit 1
      fi
    fi
  done <<< "$FILES"

  if ! mysql "$DB" < "$APP/migrations/20261120_help_qa.sql"; then
    echo "help_qa migration failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-help-$ts.sql.gz"
    echo "$BK/pre-help-$ts-files.tgz"
    exit 1
  fi
  if ! mysql "$DB" < "$APP/migrations/20261120_admin_tutorial_link.sql"; then
    echo "admin tutorial migration failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-help-$ts.sql.gz"
    echo "$BK/pre-help-$ts-files.tgz"
    exit 1
  fi
  if ! smoke; then
    echo "smoke test failed"
    restore_files "$ts"
    db_undo_this_run "$ts"
    echo "ROLLBACK POINT=$ts"
    echo "$BK/pre-help-$ts.sql.gz"
    echo "$BK/pre-help-$ts-files.tgz"
    exit 1
  fi
  echo "DEPLOY OK"
  echo "ROLLBACK POINT=$ts"
  echo "$BK/pre-help-$ts.sql.gz"
  echo "$BK/pre-help-$ts-files.tgz"
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
