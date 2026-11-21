#!/bin/bash
# Staging copy of the live payroll app. Read-only until "go".
# Live code: /home/renocon/public_html/payroll
# Live database: admin_payroll
# Staging docroot: /home/renocon/staging_payroll
# Staging database: admin_payroll_staging
# Public name: staging.payroll.ecommi.com
#
#   bash ops/setup_staging_v1.sh check
#   bash ops/setup_staging_v1.sh go
#   bash ops/setup_staging_v1.sh refresh
#   bash ops/setup_staging_v1.sh remove
#   bash ops/setup_staging_v1.sh rollback
#
# check only reads. go creates the copy. refresh recopies live code and
# reloads a scrubbed database. remove and rollback delete the staging
# subdomain, docroot, and database. They never drop admin_payroll and
# never delete public_html/payroll.
#
# Do not run this under bash -x. The database password and the basic-auth
# password are written to root-only files and are not printed.
#
# The server clock is UTC. Backups from other deploy scripts stay in
# /root/payroll-daily. This script does not write a second unsanitized dump.
set -euo pipefail
case $- in
  *x*) echo "Refusing to run under xtrace." >&2; exit 1 ;;
esac

MODE=${1:-check}
HERE=$(cd "$(dirname "$0")" && pwd)
TOOL=$HERE/staging_tool.php
OVERLAY=$(cd "$HERE/.." && pwd)
LIVE_APP=${LIVE_APP:-/home/renocon/public_html/payroll}
STAGING_APP=${STAGING_APP:-/home/renocon/staging_payroll}
SECRET_DIR=${STAGING_SECRET_DIR:-/root/payroll-staging}
ENV_FILE=${STAGING_ENV_FILE:-/home/renocon/private/payroll-staging.env}
HTPASS=${STAGING_HTPASS:-/home/renocon/.htpasswds/staging_payroll/passwd}
MYSQL=${STAGING_MYSQL:-mysql}
MYSQLDUMP=${STAGING_MYSQLDUMP:-mysqldump}
UAPI=${STAGING_UAPI:-uapi}
USERDATA=${STAGING_USERDATA:-/var/cpanel/userdata/renocon/staging.payroll.ecommi.com}
SERVER_IP=${STAGING_SERVER_IP:-159.89.125.184}
CPANEL_USER=renocon
FQDN=staging.payroll.ecommi.com
SUB=staging.payroll
ROOTDOMAIN=ecommi.com
LIVE_DB=admin_payroll
STAGING_DB=admin_payroll_staging

if [[ ${STAGING_ALLOW_NONROOT:-0} != 1 && ${EUID:-1} -ne 0 ]]; then
  echo "Run as root." >&2
  exit 1
fi
if [[ ! -f $TOOL ]]; then
  echo "Missing $TOOL" >&2
  exit 1
fi
if [[ $LIVE_DB != admin_payroll || $STAGING_DB != admin_payroll_staging ]]; then
  echo "Refusing unexpected database names." >&2
  exit 1
fi
if [[ $FQDN != staging.payroll.ecommi.com || $SUB != staging.payroll || $ROOTDOMAIN != ecommi.com ]]; then
  echo "Refusing unexpected host names." >&2
  exit 1
fi
if [[ $CPANEL_USER != renocon ]]; then
  echo "Refusing unexpected cPanel user." >&2
  exit 1
fi

say() { printf '%s\n' "$*"; }
die() {
  echo "ERROR: $*" >&2
  if [[ ${PARTIAL:-} == go ]]; then
    PARTIAL=
    discard_partial || true
  fi
  exit 1
}
trap 'code=$?; trap - ERR; if [[ ${PARTIAL:-} == go ]]; then PARTIAL=; discard_partial || true; echo "Staging setup stopped. Live was not modified." >&2; fi; exit $code' ERR

norm_path() {
  realpath -m "$1"
}

# True only for the staging docroot, or a /tmp path while testing off the server.
staging_tree() {
  local path live stage
  path=$(norm_path "$1")
  live=$(norm_path "$LIVE_APP")
  stage=$(norm_path "$STAGING_APP")
  [[ $path != "$live" && $path != "$live"/* ]] || return 1
  [[ $path != / && $path != /home && $path != /home/renocon ]] || return 1
  [[ $path != /home/renocon/public_html && $path != /home/renocon/public_html/payroll ]] || return 1
  [[ $path != /home/renocon/public_html/payroll/* ]] || return 1
  [[ $path == "$stage" || $path == "$stage"/* ]] || return 1
  if [[ $stage == /home/renocon/staging_payroll ]]; then
    return 0
  fi
  if [[ ${STAGING_ALLOW_NONROOT:-0} == 1 && $stage == /tmp/* ]]; then
    return 0
  fi
  return 1
}

mysql_ro() {
  "$MYSQL" --batch --skip-column-names --execute "$1"
}

disk_free_kb() {
  local target=$1
  if [[ ! -e $target ]]; then
    target=$(dirname "$target")
  fi
  df -Pk "$target" 2>/dev/null | awk 'NR==2 {print $4}'
}

dns_lines() {
  local name=$1 kind=$2
  if [[ -n ${STAGING_DIG:-} ]]; then
    "$STAGING_DIG" +short "$kind" "$name" 2>/dev/null || true
  elif command -v dig >/dev/null 2>&1; then
    dig +short "$kind" "$name" 2>/dev/null || true
  elif [[ $kind == A ]]; then
    getent ahostsv4 "$name" 2>/dev/null | awk '{print $1}' | sort -u || true
  fi
}

server_ips() {
  hostname -I 2>/dev/null | tr ' ' '\n' || true
  printf '%s\n' "$SERVER_IP"
}

dns_matches() {
  local ip have
  while IFS= read -r ip; do
    [[ $ip =~ ^[0-9.]+$ ]] || continue
    while IFS= read -r have; do
      [[ $ip == "$have" ]] && return 0
    done < <(server_ips)
  done
  return 1
}

cmd_exists() {
  local bin=$1
  [[ -f $bin || -x $bin ]] && return 0
  command -v "$bin" >/dev/null 2>&1
}

report_dns() {
  local ips ns zone=no match=no
  ips=$(dns_lines "$FQDN" A | awk '/^[0-9.]+$/')
  ns=$(dns_lines "$ROOTDOMAIN" NS | tr '[:upper:]' '[:lower:]')
  if [[ -f /var/named/ecommi.com.db ]]; then
    zone=yes
  fi
  say "dns_name=$FQDN"
  if [[ -n $ips ]]; then
    say "dns_ips=$(echo "$ips" | tr '\n' ' ' | sed 's/ *$//')"
  else
    say "dns_ips="
  fi
  say "dns_expected=$SERVER_IP"
  if [[ -n $ips ]] && printf '%s\n' "$ips" | dns_matches; then
    match=yes
  fi
  say "dns_matches=$match"
  say "dns_zone_on_server=$zone"
  if [[ $match == yes ]]; then
    say "dns_record_needed="
    return 0
  fi
  say "dns_record_needed=${FQDN}. A ${SERVER_IP}"
  if [[ $zone == yes ]]; then
    say "dns_note=ecommi.com zone is on this server. Add the A record there, then re-run check."
  elif printf '%s\n' "$ns" | grep -q 'digitalocean.com'; then
    say "dns_note=ecommi.com DNS is at DigitalOcean, not on this server. Abdul adds: ${FQDN}. A ${SERVER_IP}"
  else
    say "dns_note=ecommi.com DNS is not on this server. Abdul adds an A record: ${FQDN}. A ${SERVER_IP}"
  fi
  return 1
}

overlay_guards() {
  local rel
  [[ -f $OVERLAY/engine/staging.php ]] || die "Missing $OVERLAY/engine/staging.php. Run this from a checkout of the staging commit."
  [[ -f $OVERLAY/engine/staging_prepend.php ]] || die "Missing the staging prepend file."
  local files=(
    engine/staging.php
    engine/staging_prepend.php
    engine/privacy_lib.php
    mailer.php
    contact_lib.php
    support_chat/lib.php
    support_chat/api.php
    alarm/index.php
    alarm/api/test_email.php
    alarm/api/alarm_invite_lib.php
    alarm/api/cron_notify.php
    direct_deposit_file.php
    direct_deposit_download.php
    payroll_compliance_desk.php
  )
  for rel in "${files[@]}"; do
    [[ -f $OVERLAY/$rel ]] || continue
    mkdir -p "$STAGING_APP/$(dirname "$rel")"
    cp -f "$OVERLAY/$rel" "$STAGING_APP/$rel"
  done
  say "overlaid staging guards from $OVERLAY"
}

copy_code() {
  staging_tree "$STAGING_APP" || die "Staging path is not allowed."
  [[ $LIVE_APP != "$STAGING_APP" ]] || die "Staging path and live path are the same."
  [[ -d $LIVE_APP ]] || die "Live app is missing: $LIVE_APP"
  mkdir -p "$STAGING_APP"
  rsync -a --delete "$LIVE_APP"/ "$STAGING_APP"/
  if staging_tree "$STAGING_APP/private_uploads"; then
    rm -rf "$STAGING_APP/private_uploads"
  fi
  say "copied live code to $STAGING_APP"
}

own_staging_files() {
  if id renocon >/dev/null 2>&1; then
    chown -R renocon:renocon "$STAGING_APP"
    chown renocon:renocon "$ENV_FILE" 2>/dev/null || true
    chown renocon:renocon "$HTPASS" 2>/dev/null || true
    chmod 600 "$ENV_FILE" 2>/dev/null || true
  fi
}

dump_into_staging() {
  local args=(--single-transaction --quick --default-character-set=utf8mb4)
  local help
  help=$("$MYSQLDUMP" --help 2>/dev/null || true)
  if printf '%s\n' "$help" | grep -q 'column-statistics'; then
    args+=(--column-statistics=0)
  fi
  if printf '%s\n' "$help" | grep -q 'no-tablespaces'; then
    args+=(--no-tablespaces)
  fi
  php "$TOOL" recreate-db
  php "$TOOL" ensure-db "$ENV_FILE"
  import_once() {
    {
      echo "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0;"
      "$MYSQLDUMP" "${args[@]}" "$@" "$LIVE_DB"
      echo "SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;"
    } | "$MYSQL" --batch "$STAGING_DB"
  }
  if import_once --routines --triggers; then
    say "imported $LIVE_DB into $STAGING_DB with --single-transaction"
    return 0
  fi
  say "import with routines failed; retrying without routines"
  php "$TOOL" recreate-db
  php "$TOOL" ensure-db "$ENV_FILE"
  import_once
  say "imported $LIVE_DB into $STAGING_DB with --single-transaction"
}

scrub_db() {
  php "$TOOL" scrub "$ENV_FILE"
  if staging_tree "$STAGING_APP/private_uploads"; then
    rm -rf "$STAGING_APP/private_uploads"
  fi
}

uapi_json() {
  local dest=$1
  shift
  : > "$dest"
  if ! "$UAPI" --user="$CPANEL_USER" --output=json "$@" > "$dest" 2>"$dest.err"; then
    return 1
  fi
  php "$TOOL" uapi-ok "$dest"
}

read_docroot() {
  if [[ ! -f $USERDATA ]]; then
    return 0
  fi
  awk '/^documentroot:/ {print $2; exit}' "$USERDATA"
}

delete_subdomain() {
  [[ $FQDN == staging.payroll.ecommi.com ]] || die "Refusing to delete $FQDN"
  local tmp
  tmp=$(mktemp)
  if uapi_json "$tmp" SubDomain delsubdomain domain="$FQDN"; then
    say "removed subdomain $FQDN"
    rm -f "$tmp" "$tmp.err"
    return 0
  fi
  if uapi_json "$tmp" SubDomain delsubdomain domain="$SUB" rootdomain="$ROOTDOMAIN"; then
    say "removed subdomain $SUB.$ROOTDOMAIN"
    rm -f "$tmp" "$tmp.err"
    return 0
  fi
  say "subdomain remove did not succeed; see $tmp"
  return 1
}

add_subdomain() {
  cmd_exists "$UAPI" || die "cPanel uapi is not available."
  local tmp dir doc
  tmp=$(mktemp)
  dir=/staging_payroll
  if ! uapi_json "$tmp" SubDomain addsubdomain domain="$SUB" rootdomain="$ROOTDOMAIN" dir="$dir" disallowdot=0; then
    say "addsubdomain did not accept dir=$dir"
  fi
  doc=$(read_docroot || true)
  if [[ -n $doc && $doc != "$STAGING_APP" ]]; then
    say "document root was $doc"
    delete_subdomain || true
    if ! uapi_json "$tmp" SubDomain addsubdomain domain="$SUB" rootdomain="$ROOTDOMAIN" dir="$STAGING_APP" disallowdot=0; then
      rm -f "$tmp" "$tmp.err"
      die "cPanel did not add $FQDN. If document roots are restricted to public_html, turn that off in WHM Tweak Settings and run remove, then go."
    fi
    doc=$(read_docroot || true)
  fi
  if [[ -n $doc && $doc != "$STAGING_APP" ]]; then
    delete_subdomain || true
    rm -f "$tmp" "$tmp.err"
    die "Subdomain document root is $doc, not $STAGING_APP. It was removed. Turn off Restrict document roots to public_html in WHM, then run go again."
  fi
  if [[ -z $doc ]]; then
    say "subdomain added; confirm the document root in WHM is $STAGING_APP"
  else
    say "subdomain $FQDN documentroot=$doc"
  fi
  rm -f "$tmp" "$tmp.err"
  local ssl
  ssl=$(mktemp)
  if uapi_json "$ssl" SSL start_autossl_check; then
    say "requested AutoSSL for $CPANEL_USER"
  else
    say "AutoSSL request did not succeed. Run it again after DNS points at this server."
  fi
  rm -f "$ssl" "$ssl.err"
}

discard_partial() {
  say "Removing the partial staging copy. Live was not changed."
  delete_subdomain || true
  php "$TOOL" drop-db "$ENV_FILE" || true
  if [[ -d $STAGING_APP ]] && staging_tree "$STAGING_APP"; then
    rm -rf "$STAGING_APP"
  fi
}

prepare_tree() {
  local free
  free=$(disk_free_kb "$LIVE_APP")
  say "disk_free_kb=${free:-unknown}"
  if [[ ${STAGING_SKIP_DISK_CHECK:-0} != 1 ]]; then
    [[ -n ${free:-} && $free -ge 1048576 ]] || die "Less than 1 GiB free. Free space and run go again."
  fi
  [[ -d $LIVE_APP ]] || die "Live app is missing: $LIVE_APP"
  php "$TOOL" secrets "$SECRET_DIR" "$ENV_FILE" "$HTPASS"
  chmod 700 "$SECRET_DIR" || true
  PARTIAL=go
  copy_code
  overlay_guards
  php "$TOOL" retarget "$STAGING_APP" "$ENV_FILE" || die "Could not retarget the staging database settings."
  php "$TOOL" protect "$STAGING_APP" "$HTPASS" "$ENV_FILE"
  own_staging_files
  if [[ -f $STAGING_APP/db.php ]]; then
    php -l "$STAGING_APP/db.php" >/dev/null
  fi
}

load_database() {
  dump_into_staging
  scrub_db
}

lock_setup() {
  local lock=${STAGING_LOCK:-/tmp/payroll-staging-setup.lock}
  exec 9>"$lock"
  flock -n 9 || die "Another setup_staging_v1.sh is running."
}

check_cmd() {
  local live_db stage_db free
  say "mode=check"
  if [[ -d $LIVE_APP ]]; then
    say "live_app=$LIVE_APP exists=yes"
  else
    say "live_app=$LIVE_APP exists=no"
  fi
  if [[ -d $STAGING_APP ]]; then
    say "staging_app=$STAGING_APP exists=yes"
  else
    say "staging_app=$STAGING_APP exists=no"
  fi
  if [[ -f $STAGING_APP/.staging ]]; then
    say "staging_marker=yes"
  else
    say "staging_marker=no"
  fi
  free=$(disk_free_kb "$LIVE_APP")
  say "disk_free_kb=${free:-unknown}"
  say "disk_need_kb=1048576"
  live_db=$(mysql_ro "SHOW DATABASES LIKE 'admin_payroll'" 2>/dev/null || true)
  stage_db=$(mysql_ro "SHOW DATABASES LIKE 'admin_payroll_staging'" 2>/dev/null || true)
  if [[ $live_db == admin_payroll ]]; then
    say "live_db=admin_payroll exists=yes"
  else
    say "live_db=admin_payroll exists=no"
  fi
  if [[ $stage_db == admin_payroll_staging ]]; then
    say "staging_db=admin_payroll_staging exists=yes"
  else
    say "staging_db=admin_payroll_staging exists=no"
  fi
  if [[ -f $ENV_FILE ]]; then
    say "staging_config=$ENV_FILE present=yes"
  else
    say "staging_config=$ENV_FILE present=no"
  fi
  if [[ -f $SECRET_DIR/db-password ]]; then
    say "db_password_file=$SECRET_DIR/db-password mode=$(stat -c '%a' "$SECRET_DIR/db-password" 2>/dev/null || echo unknown)"
  else
    say "db_password_file=$SECRET_DIR/db-password present=no"
  fi
  if [[ -f $HTPASS ]]; then
    say "basic_auth=yes user=staging"
  else
    say "basic_auth=no"
  fi
  report_dns || true
  if cmd_exists "$UAPI"; then
    local tmp
    tmp=$(mktemp)
    if "$UAPI" --user="$CPANEL_USER" --output=json SubDomain listsubdomains > "$tmp" 2>/dev/null; then
      if grep -q "$FQDN" "$tmp"; then
        say "subdomain=$FQDN present=yes"
      else
        say "subdomain=$FQDN present=no"
      fi
    else
      say "subdomain=unknown"
    fi
    rm -f "$tmp"
  else
    say "subdomain=uapi-not-found"
  fi
  if [[ ! -d $LIVE_APP ]]; then
    die "Live app is missing."
  fi
  say "check done"
}

go_cmd() {
  lock_setup
  say "mode=go"
  check_cmd
  if [[ -e $STAGING_APP ]]; then
    die "Staging docroot already exists. Run refresh, or remove and then go."
  fi
  prepare_tree
  add_subdomain
  load_database
  say "go done"
  say "url=https://$FQDN/"
  say "basic_auth_user=staging"
  say "basic_auth_password_file=$SECRET_DIR/basic-auth.txt"
  say "db_user=payroll_staging"
  say "db_password_file=$SECRET_DIR/db-password"
  say "staging_config=$ENV_FILE"
  report_dns || true
  say "Passwords were not printed. Read the root-only files above."
}

refresh_cmd() {
  lock_setup
  say "mode=refresh"
  [[ -d $STAGING_APP && -f $STAGING_APP/.staging ]] || die "Staging is not installed. Run go first."
  [[ -f $ENV_FILE ]] || die "Staging config is missing. Run remove, then go."
  staging_tree "$STAGING_APP" || die "Staging path is not allowed."
  prepare_tree
  add_subdomain
  load_database
  say "refresh done"
  report_dns || true
}

remove_cmd() {
  lock_setup
  say "mode=remove"
  [[ $STAGING_DB == admin_payroll_staging ]] || die "Refusing to drop $STAGING_DB"
  staging_tree "$STAGING_APP" || die "Refusing to delete $STAGING_APP"
  delete_subdomain || say "Continuing after the subdomain step."
  php "$TOOL" drop-db "$ENV_FILE"
  if [[ -d $STAGING_APP ]]; then
    rm -rf "$STAGING_APP"
    say "removed $STAGING_APP"
  else
    say "staging docroot already absent"
  fi
  rm -f "$ENV_FILE" "$HTPASS"
  if [[ -d $SECRET_DIR ]]; then
    rm -rf "$SECRET_DIR"
    say "removed $SECRET_DIR"
  fi
  say "remove done. Live docroot and admin_payroll were not changed."
}

case $MODE in
  check) check_cmd ;;
  go) go_cmd ;;
  refresh) refresh_cmd ;;
  remove|rollback) remove_cmd ;;
  *)
    echo "Usage: bash $0 check|go|refresh|remove|rollback" >&2
    exit 2
    ;;
esac
