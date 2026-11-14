#!/bin/bash
# ops/deploy15_review.sh — partial payment review page.
# Modes: check (read-only) or go. Run as root from the WHM terminal.
#
#   bash deploy15_review.sh check
#   bash deploy15_review.sh go
#
# Copies the pinned files below. Saving a review writes
# admin_partial_payment_reviews only. This script does not update
# payroll_finalized or payroll_payments and does not mark anyone paid.
# Download this file by its own commit. The app files are pinned separately.

set -euo pipefail

APP=/home/renocon/public_html/payroll
BACKUP_DIR=/root/payroll-daily
PUBLIC_BASE=https://payroll.renocon.ca
PIN_SHA=300be82ed9a0d0d3eca9a238ef8ae77e80a855dc
RAW="https://raw.githubusercontent.com/fpilot62/renocon.ca/${PIN_SHA}"
FILES=(
  partial_payments_review.php
  engine/partial_payments_review_lib.php
  migrations/20261116_admin_partial_payment_reviews.sql
)
NEED=(
  db.php
  payroll_scope.php
  engine/admin_super_lib.php
  engine/attendance_integrity_lib.php
  engine/outstanding_wages_lib.php
)

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash deploy15_review.sh check|go" >&2
  exit 2
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/deploy15_review.lock"
if ! flock -n 9; then
  echo "Another deploy15_review.sh is already running." >&2
  exit 2
fi

STAGE=""
cleanup() {
  if [[ -n "$STAGE" && -d "$STAGE" ]]; then
    rm -rf "$STAGE"
  fi
}
trap cleanup EXIT

die() { echo "ERROR: $*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }
md5_of() { md5sum "$1" | awk '{print $1}'; }

download() {
  local url="$1" dest="$2"
  mkdir -p "$(dirname "$dest")"
  curl -fsSL --retry 3 --max-time 60 -o "$dest" "$url" || die "Could not download $url"
  if head -c 80 "$dest" | grep -q '<!DOCTYPE\|404: Not Found'; then
    die "Download was an error page: $url"
  fi
}

guard_sql() {
  php -r '
    $sql = file_get_contents($argv[1]);
    if ($sql === false) { fwrite(STDERR, "migration unreadable\n"); exit(1); }
    $sql = preg_replace("/^--.*$/m", "", $sql);
    $count = 0;
    foreach (preg_split("/;/", $sql) as $stmt) {
      $stmt = trim($stmt);
      if ($stmt === "") continue;
      $count++;
      if (!preg_match("/^CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+`?admin_partial_payment_reviews`?\\b/i", $stmt)) {
        fwrite(STDERR, "Migration is not only CREATE TABLE IF NOT EXISTS admin_partial_payment_reviews.\n");
        exit(1);
      }
      if (preg_match("/\\b(payroll_finalized|payroll_payments|payroll_masters|payroll_balance|attendance)\\b/i", $stmt)) {
        fwrite(STDERR, "Migration mentions a payroll or attendance table.\n");
        exit(1);
      }
    }
    if ($count !== 1) { fwrite(STDERR, "Migration statement count is {$count}.\n"); exit(1); }
  ' "$1"
}

table_state() {
  PR9_APP="$APP" php <<'PHP'
<?php
$app = getenv('PR9_APP');
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
require $app . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \$pdo\n");
    exit(1);
}
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
}
$st = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$st->execute(['admin_partial_payment_reviews']);
$n = (int)$st->fetchColumn();
$st->closeCursor();
echo $n > 0 ? "present\n" : "absent\n";
PHP
}

write_cnf() {
  local cnf="$1"
  PR9_APP="$APP" PR9_CNF="$cnf" php <<'PHP'
<?php
$app = getenv('PR9_APP');
$cnf = getenv('PR9_CNF');
$src = file_get_contents($app . '/db.php');
if ($src === false) exit(1);
$vars = [];
$toks = token_get_all($src);
$n = count($toks);
for ($i = 0; $i < $n; $i++) {
    if (!is_array($toks[$i]) || $toks[$i][0] !== T_VARIABLE) continue;
    $name = $toks[$i][1];
    $j = $i + 1;
    while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
    if ($j >= $n || $toks[$j] !== '=') continue;
    $j++;
    while ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) $j++;
    if ($j < $n && is_array($toks[$j]) && $toks[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
        $vars[$name] = stripcslashes(substr($toks[$j][1], 1, -1));
    }
}
$host = $vars['$host'] ?? $vars['$db_host'] ?? 'localhost';
$name = $vars['$dbname'] ?? $vars['$db_name'] ?? $vars['$database'] ?? 'admin_payroll';
$user = $vars['$username'] ?? $vars['$db_user'] ?? $vars['$user'] ?? '';
$pass = $vars['$password'] ?? $vars['$db_pass'] ?? $vars['$pass'] ?? '';
$env = $app . '/config/payroll.env';
if (($user === '' || $pass === '') && is_file($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v, " \t\"'");
        if ($k === 'DB_HOST' && $v !== '') $host = $v;
        if ($k === 'DB_NAME' && $v !== '') $name = $v;
        if ($k === 'DB_USER' && $v !== '') $user = $v;
        if ($k === 'DB_PASS' && $v !== '') $pass = $v;
    }
}
if ($user === '' || $pass === '') exit(1);
file_put_contents($cnf, "[client]\nuser=" . $user . "\npassword=" . $pass . "\nhost=" . $host . "\n");
file_put_contents($cnf . '.db', $name);
chmod($cnf, 0600);
PHP
}

dump_review_table() {
  local out="$1" err="$BACKUP_DIR/pre-pr15-review-mysqldump.err"
  if mysql --batch -N -e 'SELECT 1' admin_payroll >/dev/null 2>"$err"; then
    mysqldump --single-transaction --quick --default-character-set=utf8mb4 admin_payroll admin_partial_payment_reviews 2>"$err" | gzip -c > "$out"
    rm -f "$err"
    return 0
  fi
  local cnf
  cnf=$(mktemp "$BACKUP_DIR/.pr15-my.XXXXXX")
  chmod 600 "$cnf"
  if ! write_cnf "$cnf"; then
    rm -f "$cnf" "$cnf.db" "$err"
    die "Could not read MySQL credentials. Nothing was copied."
  fi
  local dbname
  dbname=$(cat "$cnf.db")
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --default-character-set=utf8mb4 "$dbname" admin_partial_payment_reviews 2>"$err" | gzip -c > "$out"
  rm -f "$cnf" "$cnf.db" "$err"
}

preflight() {
  local rel live_sum pin_sum
  local refuse=0
  say "pin ${PIN_SHA}"
  for rel in "${NEED[@]}"; do
    if [[ ! -f "$APP/$rel" ]]; then
      say "NEED $rel MISSING REFUSE"
      refuse=1
    else
      say "NEED $rel present"
    fi
  done
  for rel in "${FILES[@]}"; do
    download "${RAW}/${rel}" "$STAGE/pin/$rel"
    if [[ "$rel" == *.php ]]; then
      php -l "$STAGE/pin/$rel"
    fi
    pin_sum=$(md5_of "$STAGE/pin/$rel")
    say "PIN $rel md5=$pin_sum"
    if [[ ! -f "$APP/$rel" ]]; then
      say "FILE $rel absent on the server (new file)"
      continue
    fi
    live_sum=$(md5_of "$APP/$rel")
    if [[ "$live_sum" == "$pin_sum" ]]; then
      say "FILE $rel live_md5=$live_sum already this pin"
    else
      say "FILE $rel live_md5=$live_sum pin_md5=$pin_sum DIFFER REFUSE"
      refuse=1
    fi
  done
  guard_sql "$STAGE/pin/migrations/20261116_admin_partial_payment_reviews.sql"
  say "SQL guard ok"
  local state
  state=$(table_state | tr -d '\r')
  say "TABLE admin_partial_payment_reviews ${state}"
  printf '%s\n' "$state" > "$STAGE/table_state"
  if [[ "$refuse" -ne 0 ]]; then
    say "CHECK REFUSED"
    return 1
  fi
  say "CHECK OK"
}

migrate() {
  PR9_APP="$APP" php <<'PHP'
<?php
$app = getenv('PR9_APP');
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
require $app . '/db.php';
require $app . '/engine/partial_payments_review_lib.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \$pdo\n");
    exit(1);
}
$pdo->setAttribute(PDO::ERRMODE, PDO::ERRMODE_EXCEPTION);
ppr_ensure($pdo);
$st = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$st->execute(['admin_partial_payment_reviews']);
$n = (int)$st->fetchColumn();
$st->closeCursor();
if ($n !== 1) {
    fwrite(STDERR, "admin_partial_payment_reviews was not created\n");
    exit(1);
}
echo "MIGRATION OK\n";
PHP
}

say "deploy15_review ${MODE}"
STAGE=$(mktemp -d "$BACKUP_DIR/pr15-stage.XXXXXX")
mkdir -p "$STAGE/pin/engine" "$STAGE/pin/migrations"
if ! preflight; then
  exit 1
fi
if [[ "$MODE" == "check" ]]; then
  say "check did not copy files and did not change the database."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
CODE_BAK="$BACKUP_DIR/pre-pr15-review_${TS}.tar.gz"
DUMP="$BACKUP_DIR/pre-pr15-review_${TS}.sql.gz"
ROLLBACK="$BACKUP_DIR/pre-pr15-review_${TS}-rollback.sh"
TABLE_STATE=$(cat "$STAGE/table_state")
umask 077

EXISTING=()
for rel in "${FILES[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    EXISTING+=("$rel")
  fi
done
if [[ ${#EXISTING[@]} -gt 0 ]]; then
  say "backing up existing copies"
  tar -C "$APP" -czf "$CODE_BAK" "${EXISTING[@]}"
  say "backup $CODE_BAK"
else
  say "no previous copies of these files"
  CODE_BAK=""
fi

if [[ "$TABLE_STATE" == "present" ]]; then
  say "dumping admin_partial_payment_reviews"
  dump_review_table "$DUMP"
  sz=$(stat -c '%s' "$DUMP")
  if [[ "$sz" -lt 64 ]]; then
    die "Dump $DUMP is ${sz} bytes. Nothing was copied."
  fi
  say "dump $DUMP size=$sz"
else
  say "table is absent, so there is no dump"
  DUMP=""
fi

owner=$(stat -c '%U:%G' "$APP/outstanding_wages.php" 2>/dev/null || stat -c '%U:%G' "$APP/db.php")
cat > "$ROLLBACK" <<EOF
#!/bin/bash
# Restores the partial-payment review files from before deploy15.
# If the review table did not exist, it is dropped. If it did, its dump is loaded back.
# This does not change payroll_finalized or payroll_payments.
set -euo pipefail
APP=$(printf '%q' "$APP")
BACKUP_DIR=$(printf '%q' "$BACKUP_DIR")
CODE_BAK=$(printf '%q' "$CODE_BAK")
DUMP=$(printf '%q' "$DUMP")
TABLE_STATE=$(printf '%q' "$TABLE_STATE")
if [[ -n "\$CODE_BAK" && -f "\$CODE_BAK" ]]; then
  tar -xzf "\$CODE_BAK" -C "\$APP"
  echo "PHP restored from \$CODE_BAK"
else
  rm -f "\$APP/partial_payments_review.php" "\$APP/engine/partial_payments_review_lib.php" "\$APP/migrations/20261116_admin_partial_payment_reviews.sql"
  echo "Removed the new review files."
fi
if [[ "\$TABLE_STATE" == "absent" ]]; then
  PR9_APP="\$APP" php <<'PHP'
<?php
\$app = getenv('PR9_APP');
chdir(\$app);
\$_SERVER['DOCUMENT_ROOT'] = \$app;
\$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
require \$app . '/db.php';
\$pdo->exec('DROP TABLE IF EXISTS admin_partial_payment_reviews');
echo "Dropped admin_partial_payment_reviews\\n";
PHP
elif [[ -n "\$DUMP" && -f "\$DUMP" ]]; then
  cnf=\$(mktemp "\$BACKUP_DIR/.pr15-rollback.XXXXXX")
  chmod 600 "\$cnf"
  PR9_APP="\$APP" PR9_CNF="\$cnf" php <<'PHP'
<?php
\$app = getenv('PR9_APP');
\$cnf = getenv('PR9_CNF');
\$src = file_get_contents(\$app . '/db.php');
if (\$src === false) exit(1);
\$vars = [];
\$toks = token_get_all(\$src);
\$n = count(\$toks);
for (\$i = 0; \$i < \$n; \$i++) {
    if (!is_array(\$toks[\$i]) || \$toks[\$i][0] !== T_VARIABLE) continue;
    \$name = \$toks[\$i][1];
    \$j = \$i + 1;
    while (\$j < \$n && is_array(\$toks[\$j]) && \$toks[\$j][0] === T_WHITESPACE) \$j++;
    if (\$j >= \$n || \$toks[\$j] !== '=') continue;
    \$j++;
    while (\$j < \$n && is_array(\$toks[\$j]) && \$toks[\$j][0] === T_WHITESPACE) \$j++;
    if (\$j < \$n && is_array(\$toks[\$j]) && \$toks[\$j][0] === T_CONSTANT_ENCAPSED_STRING) {
        \$vars[\$name] = stripcslashes(substr(\$toks[\$j][1], 1, -1));
    }
}
\$host = \$vars['\$host'] ?? \$vars['\$db_host'] ?? 'localhost';
\$name = \$vars['\$dbname'] ?? \$vars['\$db_name'] ?? \$vars['\$database'] ?? 'admin_payroll';
\$user = \$vars['\$username'] ?? \$vars['\$db_user'] ?? \$vars['\$user'] ?? '';
\$pass = \$vars['\$password'] ?? \$vars['\$db_pass'] ?? \$vars['\$pass'] ?? '';
\$env = \$app . '/config/payroll.env';
if ((\$user === '' || \$pass === '') && is_file(\$env)) {
    foreach (file(\$env, FILE_IGNORE_NEW_LINES) as \$line) {
        \$line = trim(\$line);
        if (\$line === '' || \$line[0] === '#' || strpos(\$line, '=') === false) continue;
        [\$k, \$v] = explode('=', \$line, 2);
        \$v = trim(\$v, " \t\"'");
        if (\$k === 'DB_HOST' && \$v !== '') \$host = \$v;
        if (\$k === 'DB_NAME' && \$v !== '') \$name = \$v;
        if (\$k === 'DB_USER' && \$v !== '') \$user = \$v;
        if (\$k === 'DB_PASS' && \$v !== '') \$pass = \$v;
    }
}
if (\$user === '' || \$pass === '') exit(1);
file_put_contents(\$cnf, "[client]\\nuser=" . \$user . "\\npassword=" . \$pass . "\\nhost=" . \$host . "\\n");
file_put_contents(\$cnf . '.db', \$name);
chmod(\$cnf, 0600);
PHP
  dbname=\$(cat "\$cnf.db")
  gunzip -c "\$DUMP" | mysql --defaults-extra-file="\$cnf" "\$dbname"
  rm -f "\$cnf" "\$cnf.db"
  echo "Restored admin_partial_payment_reviews from \$DUMP"
fi
echo "Payroll payment tables were not changed."
EOF
chmod 700 "$ROLLBACK"

for rel in "${FILES[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    live_sum=$(md5_of "$APP/$rel")
    pin_sum=$(md5_of "$STAGE/pin/$rel")
    if [[ "$live_sum" != "$pin_sum" ]]; then
      die "Live ${rel} changed after check. Stopped before copying. Rollback: bash ${ROLLBACK}"
    fi
  fi
  install -o "${owner%%:*}" -g "${owner##*:}" -m 644 "$STAGE/pin/$rel" "$APP/$rel"
  say "copied $rel"
done

say "php -l on the live copies"
php -l "$APP/partial_payments_review.php"
php -l "$APP/engine/partial_payments_review_lib.php"

say "running the review-table migration"
if ! migrate; then
  die "Migration failed. Restore with: bash ${ROLLBACK}"
fi

code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "${PUBLIC_BASE}/partial_payments_review.php" || echo "000")
say "curl ${PUBLIC_BASE}/partial_payments_review.php HTTP ${code}"
if [[ "$code" != "302" && "$code" != "303" ]]; then
  die "Expected HTTP 302 without a session, got ${code}. Restore with: bash ${ROLLBACK}"
fi

say "GO OK"
say "backup:"
if [[ -n "$CODE_BAK" ]]; then
  say "  $CODE_BAK"
else
  say "  (no previous files)"
fi
if [[ -n "$DUMP" ]]; then
  say "  $DUMP"
else
  say "  (table was absent, no dump)"
fi
say "rollback:"
say "  bash $ROLLBACK"
echo "----- rollback script -----"
cat "$ROLLBACK"
echo "----- end rollback -----"
