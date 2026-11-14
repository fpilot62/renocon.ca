#!/bin/bash
# ops/deploy12.sh — employer burden, vacation pay, and invoice GST on Outstanding Wages.
# Modes: check (read-only) or go. Run as root from the WHM terminal.
#
#   bash deploy12.sh check
#   bash deploy12.sh go
#
# The files it copies are pinned to the wages commit below.
# Before overwriting, each live file that PR #9 already installed must still
# match that PR #9 blob. Shared menu and payroll files are not edited.
# The only database change is the vacation_pay and pay_as_invoice columns.

set -euo pipefail

APP=/home/renocon/public_html/payroll
BACKUP_DIR=/root/payroll-daily
PUBLIC_BASE=https://payroll.renocon.ca
BASE_SHA=b2652ccf18854b27b2fdfc811b8068d2e67ba533
PIN_SHA=41e28f8e474b058c0fd83d42b4c1099e8179a386
RAW_PIN="https://raw.githubusercontent.com/fpilot62/renocon.ca/${PIN_SHA}"
RAW_BASE="https://raw.githubusercontent.com/fpilot62/renocon.ca/${BASE_SHA}"

# Live files that already exist from PR #9 and will be replaced.
REPLACE=(
  outstanding_wages.php
  engine/outstanding_wages_lib.php
)
# New file. Absent on the server is expected.
ADD=(
  migrations/20261115_outstanding_wages_vacation_invoice.sql
)

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash deploy12.sh check|go" >&2
  exit 2
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/deploy12.lock"
if ! flock -n 9; then
  echo "Another deploy12.sh is already running." >&2
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
  local src="$1"
  php -r '
    $sql = file_get_contents($argv[1]);
    if ($sql === false) exit(1);
    if (!preg_match("/vacation_pay/", $sql) || !preg_match("/pay_as_invoice/", $sql) || !preg_match("/information_schema\\.COLUMNS/", $sql)) {
      fwrite(STDERR, "Migration is not the vacation and invoice column change.\n");
      exit(1);
    }
    if (preg_match("/\\b(attendance|payroll_finalized|payroll_payments|payroll_masters|payroll_balance)\\b/i", $sql)) {
      fwrite(STDERR, "Migration mentions a payroll or attendance table.\n");
      exit(1);
    }
    $body = preg_replace("/^--.*$/m", "", $sql);
    foreach (preg_split("/;/", (string)$body) as $stmt) {
      $stmt = trim($stmt);
      if ($stmt === "") continue;
      if (!preg_match("/^(SET|PREPARE|EXECUTE|DEALLOCATE)\\b/i", $stmt)) {
        fwrite(STDERR, "Migration has a statement other than the idempotent column add.\n");
        exit(1);
      }
    }
  ' "$src"
}

php_lint() {
  local root="$1" rel
  for rel in "${REPLACE[@]}"; do
    php -l "$root/$rel" || return 1
  done
  say "php -l passed"
}

dump_wages_table() {
  local out="$1" err="$BACKUP_DIR/pre-pr-wagesER-mysqldump.err"
  if mysql --batch -N -e 'SELECT 1' admin_payroll >/dev/null 2>"$err"; then
    mysqldump --single-transaction --quick --default-character-set=utf8mb4 admin_payroll admin_outstanding_wages 2>"$err" | gzip -c > "$out"
    rm -f "$err"
    return 0
  fi
  local cnf
  cnf=$(mktemp "$BACKUP_DIR/.pr12-my.XXXXXX")
  chmod 600 "$cnf"
  if ! PR9_APP="$APP" PR9_CNF="$cnf" php <<'PHP'
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
  then
    rm -f "$cnf" "$cnf.db" "$err"
    die "Could not read MySQL credentials. No files were changed."
  fi
  local dbname
  dbname=$(cat "$cnf.db")
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --default-character-set=utf8mb4 "$dbname" admin_outstanding_wages 2>"$err" | gzip -c > "$out"
  rm -f "$cnf" "$cnf.db" "$err"
}

require_size() {
  local path="$1" min="$2" sz
  sz=$(stat -c '%s' "$path")
  if [[ "$sz" -lt "$min" ]]; then
    die "Backup $path is ${sz} bytes, below ${min}."
  fi
  say "backup $path size=$sz"
}

preflight() {
  local rel base_sum live_sum pin_sum
  local refuse=0
  say "pin ${PIN_SHA}"
  say "expected live base ${BASE_SHA}"
  download "${RAW_PIN}/migrations/20261115_outstanding_wages_vacation_invoice.sql" \
    "$STAGE/migrations/20261115_outstanding_wages_vacation_invoice.sql"
  guard_sql "$STAGE/migrations/20261115_outstanding_wages_vacation_invoice.sql"
  say "migration adds vacation_pay and pay_as_invoice only when missing"
  for rel in "${REPLACE[@]}"; do
    download "${RAW_PIN}/${rel}" "$STAGE/pin/$rel"
    download "${RAW_BASE}/${rel}" "$STAGE/base/$rel"
    php -l "$STAGE/pin/$rel" >/dev/null
    pin_sum=$(md5_of "$STAGE/pin/$rel")
    base_sum=$(md5_of "$STAGE/base/$rel")
    if [[ ! -f "$APP/$rel" ]]; then
      say "FILE $rel MISSING REFUSE"
      refuse=1
      continue
    fi
    live_sum=$(md5_of "$APP/$rel")
    if [[ "$live_sum" == "$base_sum" ]]; then
      say "FILE $rel live_md5=$live_sum matches PR #9 base. pin_md5=$pin_sum"
    elif [[ "$live_sum" == "$pin_sum" ]]; then
      say "FILE $rel live_md5=$live_sum already this pin"
    else
      say "FILE $rel live_md5=$live_sum base_md5=$base_sum pin_md5=$pin_sum DIFFER REFUSE"
      refuse=1
    fi
  done
  rel="migrations/20261115_outstanding_wages_vacation_invoice.sql"
  pin_sum=$(md5_of "$STAGE/$rel")
  if [[ ! -f "$APP/$rel" ]]; then
    say "FILE $rel ABSENT would-add pin_md5=$pin_sum"
  elif [[ "$(md5_of "$APP/$rel")" == "$pin_sum" ]]; then
    say "FILE $rel already this pin"
  else
    say "FILE $rel PRESENT live_md5=$(md5_of "$APP/$rel") pin_md5=$pin_sum DIFFER REFUSE"
    refuse=1
  fi
  if [[ "$refuse" -ne 0 ]]; then
    say "CHECK REFUSED"
    return 1
  fi
  say "CHECK OK"
}

say "deploy12 ${MODE}"
STAGE=$(mktemp -d "$BACKUP_DIR/pr12-stage.XXXXXX")
mkdir -p "$STAGE/pin/engine" "$STAGE/base/engine" "$STAGE/migrations"
if ! preflight; then
  exit 1
fi
if [[ "$MODE" == "check" ]]; then
  say "check did not copy files, did not change the database, and did not take a backup."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
CODE_BAK="$BACKUP_DIR/pre-pr-wagesER_${TS}.tar.gz"
DB_BAK="$BACKUP_DIR/pre-pr-wagesER_${TS}.sql.gz"
ROLLBACK="$BACKUP_DIR/pre-pr-wagesER_${TS}-rollback.sh"
umask 077
say "writing backups before any change"
tar -C "$APP" -czf "$CODE_BAK" "${REPLACE[@]}"
require_size "$CODE_BAK" 1024
dump_wages_table "$DB_BAK"
require_size "$DB_BAK" 128
gzip -t "$DB_BAK"
set +o pipefail
dump_head=$(gzip -dc "$DB_BAK" | head -c 2000 || true)
set -o pipefail
printf '%s' "$dump_head" | grep -q 'admin_outstanding_wages' || die "Wage-table dump does not look like mysqldump. No files were changed."

for rel in "${REPLACE[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    live_sum=$(md5_of "$APP/$rel")
    base_sum=$(md5_of "$STAGE/base/$rel")
    pin_sum=$(md5_of "$STAGE/pin/$rel")
    if [[ "$live_sum" != "$base_sum" && "$live_sum" != "$pin_sum" ]]; then
      die "Live ${rel} changed after check. Stopped before copying. Backup: ${CODE_BAK}"
    fi
  fi
done

say "running vacation and invoice migration"
PR9_APP="$APP" PR9_SQL="$STAGE/migrations/20261115_outstanding_wages_vacation_invoice.sql" php <<'PHP'
<?php
$app = getenv('PR9_APP');
$sqlPath = getenv('PR9_SQL');
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
require $app . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \$pdo\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($sqlPath));
foreach (preg_split('/;/', (string)$sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    if (!preg_match('/^(SET|PREPARE|EXECUTE|DEALLOCATE)\b/i', $stmt)) {
        fwrite(STDERR, "Refusing an unexpected statement.\n");
        exit(1);
    }
    $pdo->exec($stmt);
}
$chk = $pdo->prepare(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)'
);
$chk->execute(['admin_outstanding_wages', 'vacation_pay', 'pay_as_invoice']);
$have = $chk->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('vacation_pay', $have, true) || !in_array('pay_as_invoice', $have, true)) {
    fwrite(STDERR, "vacation_pay or pay_as_invoice is still missing.\n");
    exit(1);
}
echo "columns vacation_pay and pay_as_invoice are present\n";
PHP

owner=$(stat -c '%U:%G' "$APP/outstanding_wages.php")
for rel in "${REPLACE[@]}"; do
  install -o "${owner%%:*}" -g "${owner##*:}" -m 644 "$STAGE/pin/$rel" "$APP/$rel"
  say "copied $rel"
done
install -D -o "${owner%%:*}" -g "${owner##*:}" -m 644 \
  "$STAGE/migrations/20261115_outstanding_wages_vacation_invoice.sql" \
  "$APP/migrations/20261115_outstanding_wages_vacation_invoice.sql"
say "copied migrations/20261115_outstanding_wages_vacation_invoice.sql"

php_lint "$APP"
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "${PUBLIC_BASE}/outstanding_wages.php" || echo "000")
say "curl ${PUBLIC_BASE}/outstanding_wages.php HTTP ${code}"
if [[ "$code" != "302" ]]; then
  die "Expected HTTP 302 without a session, got ${code}. Files are already copied. Restore with: tar -xzf ${CODE_BAK} -C ${APP}"
fi

cat > "$ROLLBACK" <<EOF
#!/bin/bash
# Restores the Outstanding Wages PHP from before this deploy.
# vacation_pay and pay_as_invoice stay on admin_outstanding_wages.
set -euo pipefail
tar -xzf $(printf '%q' "$CODE_BAK") -C $(printf '%q' "$APP")
rm -f $(printf '%q' "$APP/migrations/20261115_outstanding_wages_vacation_invoice.sql")
echo "PHP restored. The new columns were left in place."
echo "Table dump: $(printf '%q' "$DB_BAK")"
EOF
chmod 700 "$ROLLBACK"

say "GO OK"
say "backups:"
say "  $CODE_BAK"
say "  $DB_BAK"
say "rollback:"
say "  bash $ROLLBACK"
echo "----- rollback script -----"
cat "$ROLLBACK"
echo "----- end rollback -----"
