#!/bin/bash
# Local fixture for setup_staging_v1.sh. Does not contact the payroll server.
# Run: bash tests/staging_setup_test.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
fail=0
ok() { echo "ok  $1"; }
bad() { echo "FAIL $1"; fail=$((fail + 1)); }

mkdir -p "$TMP/live/engine" "$TMP/live/sip" "$TMP/bin" "$TMP/private"
cat > "$TMP/live/db.php" <<'PHP'
<?php
$host = 'localhost';
$user = 'payroll_live';
$pass = 'live-secret-not-for-staging';
$dbname = 'admin_payroll';
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass);
PHP
cat > "$TMP/live/sip/db_config.php" <<'PHP'
<?php
$host = 'localhost';
$user = 'payroll_live';
$pass = 'live-secret-not-for-staging';
$dbname = 'admin_payroll';
PHP
cat > "$TMP/live/forecast_cron.php" <<'PHP'
<?php
$dest_db = "admin_payroll";
$src_db = "fpilot_penticton";
PHP
cat > "$TMP/live/index.php" <<'PHP'
<?php ?><!DOCTYPE html><html><body><h1>Payroll</h1></body></html>
PHP
printf 'live only\n' > "$TMP/live/robots.txt"

cat > "$TMP/scrub.sql" <<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY,
  email TEXT,
  tel_mobile TEXT,
  sin TEXT,
  bank_account TEXT,
  bank_transit TEXT,
  transit_rate TEXT
);
INSERT INTO users VALUES (7, 'person@example.com', '2505550100', '123456789', '998877', '12345', '18.50');
SQL
sqlite3 "$TMP/scrub.sqlite" < "$TMP/scrub.sql"

cat > "$TMP/bin/mysql" <<'SH'
#!/bin/bash
log=${STAGING_TEST_LOG:?}
state=${STAGING_TEST_STATE:?}
{
  printf 'ARGV'
  printf ' %q' "$@"
  printf '\n'
} >> "$log"
sql=""
prev=""
for arg in "$@"; do
  if [[ $prev == --execute || $prev == -e ]]; then
    sql=$arg
  fi
  prev=$arg
done
if [[ -z $sql ]]; then
  sql=$(cat)
  printf 'SQL %s\n' "$sql" >> "$log"
fi
stripped=${sql//admin_payroll_staging/}
if printf '%s' "$stripped" | grep -Eiq '(drop|create|alter|insert|update|delete|grant|truncate|replace)[[:space:]].*admin_payroll([^_]|$)'; then
  echo "shim refused live write" >> "$log"
  exit 3
fi
if printf '%s' "$sql" | grep -q "SHOW DATABASES LIKE 'admin_payroll'"; then
  printf 'admin_payroll\n'
  exit 0
fi
if printf '%s' "$sql" | grep -q "SHOW DATABASES LIKE 'admin_payroll_staging'"; then
  if [[ -f $state/db ]]; then
    printf 'admin_payroll_staging\n'
  fi
  exit 0
fi
if printf '%s' "$sql" | grep -q "FROM mysql.user"; then
  if [[ -f $state/user ]]; then
    printf '1\n'
  else
    printf '0\n'
  fi
  exit 0
fi
if printf '%s' "$sql" | grep -q 'SHOW GRANTS'; then
  printf "%s\n" "GRANT USAGE ON *.* TO 'payroll_staging'@'localhost'"
  printf "%s\n" "GRANT ALL PRIVILEGES ON \`admin_payroll_staging\`.* TO 'payroll_staging'@'localhost'"
  exit 0
fi
if printf '%s' "$sql" | grep -q 'CREATE DATABASE'; then
  : > "$state/db"
fi
if printf '%s' "$sql" | grep -q 'DROP DATABASE'; then
  rm -f "$state/db"
fi
if printf '%s' "$sql" | grep -q 'CREATE USER'; then
  : > "$state/user"
fi
if printf '%s' "$sql" | grep -q 'DROP USER'; then
  rm -f "$state/user"
fi
exit 0
SH
cat > "$TMP/bin/mysqldump" <<'SH'
#!/bin/bash
if [[ ${1:-} == --help ]]; then
  printf '%s\n' 'column-statistics no-tablespaces --single-transaction'
  exit 0
fi
log=${STAGING_TEST_LOG:?}
{
  printf 'DUMP'
  printf ' %q' "$@"
  printf '\n'
} >> "$log"
db=${@: -1}
if [[ $db != admin_payroll ]]; then
  echo "shim refused dump of $db" >> "$log"
  exit 4
fi
if ! printf '%s\n' "$*" | grep -q -- '--single-transaction'; then
  echo "shim missing single-transaction" >> "$log"
  exit 5
fi
printf '%s\n' 'CREATE TABLE users (id INT);'
printf '%s\n' "INSERT INTO users VALUES (7);"
SH
cat > "$TMP/bin/uapi" <<'SH'
#!/bin/bash
log=${STAGING_TEST_LOG:?}
{
  printf 'UAPI'
  printf ' %q' "$@"
  printf '\n'
} >> "$log"
joined="$*"
if printf '%s' "$joined" | grep -q 'payroll.ecommi.com' && ! printf '%s' "$joined" | grep -q 'staging.payroll.ecommi.com'; then
  echo "shim saw a live host" >> "$log"
  exit 6
fi
if printf '%s' "$joined" | grep -q addsubdomain; then
  doc=${STAGING_APP:-/home/renocon/staging_payroll}
  if printf '%s' "$joined" | grep -Eq 'dir=/home/renocon/public_html'; then
    doc=/home/renocon/public_html/payroll
  fi
  printf 'documentroot: %s\n' "$doc" > "${STAGING_USERDATA:?}"
fi
printf '%s\n' '{"result":{"status":1,"errors":null,"data":null}}'
SH
cat > "$TMP/bin/dig" <<'SH'
#!/bin/bash
if [[ $* == *NS*ecommi.com* || $* == *'NS ecommi.com'* ]]; then
  printf '%s\n' 'ns1.digitalocean.com.'
  exit 0
fi
if [[ $* == *staging.payroll.ecommi.com* ]]; then
  exit 0
fi
if [[ $* == *payroll.ecommi.com* ]]; then
  printf '%s\n' '159.89.125.184'
fi
SH
chmod +x "$TMP/bin/mysql" "$TMP/bin/mysqldump" "$TMP/bin/uapi" "$TMP/bin/dig"

export STAGING_ALLOW_NONROOT=1
export LIVE_APP=$TMP/live
export STAGING_APP=$TMP/staging
export STAGING_SECRET_DIR=$TMP/secrets
export STAGING_ENV_FILE=$TMP/private/payroll-staging.env
export STAGING_HTPASS=$TMP/htpass/passwd
export STAGING_MYSQL=$TMP/bin/mysql
export STAGING_MYSQLDUMP=$TMP/bin/mysqldump
export STAGING_UAPI=$TMP/bin/uapi
export STAGING_DIG=$TMP/bin/dig
export STAGING_USERDATA=$TMP/userdata
export STAGING_TEST_LOG=$TMP/mysql.log
export STAGING_TEST_STATE=$TMP/state
export STAGING_SCRUB_DSN=sqlite:$TMP/scrub.sqlite
export STAGING_SKIP_DISK_CHECK=1
mkdir -p "$STAGING_TEST_STATE"

set +e
check_out=$(bash "$ROOT/ops/setup_staging_v1.sh" check 2>&1)
check_code=$?
set -e
printf '%s\n' "$check_out" > "$TMP/check.out"
[[ $check_code -eq 0 ]] && ok "check exits 0" || bad "check exited $check_code"
grep -q 'dns_matches=no' "$TMP/check.out" && ok "check sees missing staging DNS" || bad "check DNS match"
grep -q 'DigitalOcean' "$TMP/check.out" && ok "check names DigitalOcean" || bad "check DNS host"
grep -q 'staging.payroll.ecommi.com. A 159.89.125.184' "$TMP/check.out" && ok "check prints Abdul's A record" || bad "check A record"
grep -q 'live_db=admin_payroll exists=yes' "$TMP/check.out" && ok "check sees live database" || bad "check live db"
[[ ! -d $STAGING_APP ]] && ok "check does not create the docroot" || bad "check created a docroot"

set +e
go_out=$(bash "$ROOT/ops/setup_staging_v1.sh" go 2>&1)
go_code=$?
set -e
printf '%s\n' "$go_out" > "$TMP/go.out"
[[ $go_code -eq 0 ]] && ok "go exits 0" || { bad "go exited $go_code"; cat "$TMP/go.out" >&2; }
dbpass=$(sed -n '2p' "$TMP/secrets/db-password")
authpass=$(sed -n 's/^password=//p' "$TMP/secrets/basic-auth.txt")
if [[ -n $dbpass ]] && ! grep -q "$dbpass" "$TMP/go.out"; then
  ok "go does not print the database password"
else
  bad "database password leaked or was empty"
fi
if [[ -n $authpass ]] && ! grep -q "$authpass" "$TMP/go.out"; then
  ok "go does not print the basic-auth password"
else
  bad "basic-auth password leaked or was empty"
fi
grep -q 'live-secret-not-for-staging' "$TMP/live/db.php" && ok "live db.php was not rewritten" || bad "live db.php changed"
if grep -q 'live-secret-not-for-staging' "$STAGING_APP/db.php"; then
  bad "staging db.php kept the live password"
else
  ok "staging db.php dropped the live password"
fi
grep -q 'admin_payroll_staging' "$STAGING_APP/forecast_cron.php" && ok "forecast cron points at staging" || bad "forecast cron"
grep -q 'fpilot_penticton' "$STAGING_APP/forecast_cron.php" && ok "forecast source database kept" || bad "forecast source"
grep -q 'auto_prepend_file=' "$STAGING_APP/.user.ini" && ok "prepend is installed" || bad "user.ini"
grep -q 'AuthType Basic' "$STAGING_APP/.htaccess" && ok "basic auth is installed" || bad "htaccess auth"
grep -q 'noindex' "$STAGING_APP/.htaccess" && ok "htaccess noindex" || bad "htaccess noindex"
grep -q 'Disallow: /' "$STAGING_APP/robots.txt" && ok "robots.txt disallows all" || bad "robots"
grep -q 'STAGING' "$STAGING_APP/engine/staging.php" && ok "guard overlay is on staging" || bad "overlay"
[[ ! -f $TMP/live/engine/staging.php ]] && ok "overlay did not write the live tree" || bad "live tree gained staging.php"
grep -q 'addsubdomain' "$STAGING_TEST_LOG" && ok "go calls addsubdomain" || bad "addsubdomain"
grep -q 'disallowdot=0' "$STAGING_TEST_LOG" && ok "nested subdomain keeps the dot" || bad "disallowdot"
grep -q 'dir=/staging_payroll' "$STAGING_TEST_LOG" && ok "subdomain dir is outside public_html" || bad "subdomain dir"
grep -q -- '--single-transaction' "$STAGING_TEST_LOG" && ok "dump uses a single transaction" || bad "single-transaction"
if grep -q 'shim refused live write' "$STAGING_TEST_LOG"; then
  bad "shim refused a live write"
else
  ok "no live write was attempted"
fi
email=$(sqlite3 "$TMP/scrub.sqlite" "SELECT email FROM users WHERE id=7")
phone=$(sqlite3 "$TMP/scrub.sqlite" "SELECT tel_mobile FROM users WHERE id=7")
rate=$(sqlite3 "$TMP/scrub.sqlite" "SELECT transit_rate FROM users WHERE id=7")
[[ $email == 'staff+7@staging.invalid' ]] && ok "go scrubbed the staff email" || bad "scrub email ($email)"
[[ -z $phone ]] && ok "go blanked the phone" || bad "scrub phone"
[[ $rate == 18.50 ]] && ok "go kept the transit pay rate" || bad "scrub rate ($rate)"
mode=$(stat -c '%a' "$TMP/secrets/db-password")
[[ $mode == 600 ]] && ok "password file is mode 600" || bad "password mode $mode"
[[ $(stat -c '%a' "$STAGING_ENV_FILE") == 600 ]] && ok "staging config is mode 600" || bad "config mode"

# refresh keeps the same password and still does not touch live
before=$dbpass
set +e
refresh_out=$(bash "$ROOT/ops/setup_staging_v1.sh" refresh 2>&1)
refresh_code=$?
set -e
[[ $refresh_code -eq 0 ]] && ok "refresh exits 0" || { bad "refresh exited $refresh_code"; printf '%s\n' "$refresh_out" >&2; }
after=$(sed -n '2p' "$TMP/secrets/db-password")
[[ $before == "$after" ]] && ok "refresh keeps the database password" || bad "refresh rotated the password"
! grep -q "$after" <<< "$refresh_out" && ok "refresh does not print the password" || bad "refresh leaked the password"
grep -q 'live-secret-not-for-staging' "$TMP/live/db.php" && ok "refresh left live db.php alone" || bad "refresh changed live"

set +e
rm_out=$(bash "$ROOT/ops/setup_staging_v1.sh" remove 2>&1)
rm_code=$?
set -e
[[ $rm_code -eq 0 ]] && ok "remove exits 0" || { bad "remove exited $rm_code"; printf '%s\n' "$rm_out" >&2; }
[[ ! -d $STAGING_APP ]] && ok "remove deletes the staging docroot" || bad "docroot remains"
[[ -f $TMP/live/db.php ]] && ok "remove leaves live code" || bad "live code missing"
[[ ! -f $STAGING_TEST_STATE/db ]] && ok "remove drops the staging database" || bad "staging database remains"
grep -q 'delsubdomain' "$STAGING_TEST_LOG" && ok "remove deletes the subdomain" || bad "delsubdomain"
if grep -q 'payroll.ecommi.com' "$STAGING_TEST_LOG" && ! grep -q 'staging.payroll.ecommi.com' "$STAGING_TEST_LOG"; then
  bad "a live hostname was sent to uapi"
else
  ok "uapi was only asked about the staging name"
fi

# TARGET switch
target_out=$(bash "$ROOT/ops/deploy_to_staging.sh" check)
printf '%s\n' "$target_out" | grep -q 'PAYROLL_APP=/home/renocon/staging_payroll' && ok "wrapper defaults to staging" || bad "wrapper app"
printf '%s\n' "$target_out" | grep -q 'PAYROLL_DB=admin_payroll_staging' && ok "wrapper defaults to the staging database" || bad "wrapper db"
cat > "$TMP/pinned.sh" <<'SH'
#!/bin/bash
set -euo pipefail
APP=/home/renocon/public_html/payroll
echo "$APP"
SH
if bash "$ROOT/ops/deploy_to_staging.sh" "$TMP/pinned.sh" check >/dev/null 2>"$TMP/pin.err"; then
  bad "wrapper ran a script pinned to live"
else
  ok "wrapper refuses a script pinned to live"
fi
cat > "$TMP/honors.sh" <<'SH'
#!/bin/bash
set -euo pipefail
echo "APP=${PAYROLL_APP:-/home/renocon/public_html/payroll}"
echo "DB=${PAYROLL_DB:-admin_payroll}"
SH
honor=$(bash "$ROOT/ops/deploy_to_staging.sh" "$TMP/honors.sh" check)
printf '%s\n' "$honor" | grep -q 'APP=/home/renocon/staging_payroll' && ok "wrapper exports the staging docroot" || bad "export app"
printf '%s\n' "$honor" | grep -q 'DB=admin_payroll_staging' && ok "wrapper exports the staging database" || bad "export db"
live_target=$(TARGET=live bash "$ROOT/ops/deploy_to_staging.sh" check)
printf '%s\n' "$live_target" | grep -q 'PAYROLL_APP=/home/renocon/public_html/payroll' && ok "TARGET=live stays on live" || bad "target live"

if [[ $fail -eq 0 ]]; then
  echo "all passed"
else
  echo "$fail failed"
  exit 1
fi
