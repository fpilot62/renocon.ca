#!/bin/bash
# ops/deploy19_review_no_tax.sh — income tax off on the partial-payment review.
# Modes: check (read-only) or go. Run as root from the WHM terminal.
#
#   bash deploy19_review_no_tax.sh check
#   bash deploy19_review_no_tax.sh go
#
# Current live page and library match pin 61dcb54. This script replaces
# them with pin be645f2. CPP and EI still come from pe_line. Income tax on
# this page starts at zero for every candidate. The review table already
# has son_sheet_gross. Go adds skip_income_tax only when that column is missing.
# Menu id 154 stays put. Payroll payment and balance tables are not written.

set -euo pipefail

APP=/home/renocon/public_html/payroll
BACKUP_DIR=/root/payroll-daily
PUBLIC_BASE=https://payroll.renocon.ca
BASE_SHA=61dcb542c9f7fafba065c6f890491f97ccc2f4c1
PIN_SHA=be645f29cc60e843f505a190be8985c9b89af70a
RAW="https://raw.githubusercontent.com/fpilot62/renocon.ca/${PIN_SHA}"
FILES=(
  partial_payments_review.php
  engine/partial_payments_review_lib.php
  migrations/20261116_admin_partial_payment_reviews.sql
  migrations/20261117_partial_payment_review_gross.sql
  migrations/20261119_partial_payment_review_no_tax.sql
)
# md5 at 61dcb54. The 20261119 file did not exist yet.
BASE_MD5=(
  f6e39288596d04d7d3c2715620e29434
  5e9440fcbaa5ecd3b4d9fd3c5a08f699
  29507762be3b257014461f77a7af2abf
  dce886dc8d47bf5655a7c759a8b860f2
  ""
)
PIN_MD5=(
  836ff788c77fc95e4a5e691d29a1606f
  ef3190ab5b1f0374a2a1113edc9fe4d7
  29507762be3b257014461f77a7af2abf
  dce886dc8d47bf5655a7c759a8b860f2
  f1e2bd8dda1b901d729be56c8d5ee5ad
)

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash deploy19_review_no_tax.sh check|go" >&2
  exit 2
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/deploy19_review_no_tax.lock"
if ! flock -n 9; then
  echo "Another deploy19_review_no_tax.sh is already running." >&2
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
  local out="$1" err="$BACKUP_DIR/pre-pr19-review-mysqldump.err"
  if mysql --batch -N -e 'SELECT 1' admin_payroll >/dev/null 2>"$err"; then
    mysqldump --single-transaction --quick --default-character-set=utf8mb4 admin_payroll admin_partial_payment_reviews 2>"$err" | gzip -c > "$out"
    rm -f "$err"
    return 0
  fi
  local cnf dbname
  cnf=$(mktemp "$BACKUP_DIR/.pr19-my.XXXXXX")
  chmod 600 "$cnf"
  if ! write_cnf "$cnf"; then
    rm -f "$cnf" "$cnf.db" "$err"
    die "Could not read MySQL credentials. Nothing was copied."
  fi
  dbname=$(cat "$cnf.db")
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --default-character-set=utf8mb4 "$dbname" admin_partial_payment_reviews 2>"$err" | gzip -c > "$out"
  rm -f "$cnf" "$cnf.db" "$err"
}

probe() {
  PR9_APP="$APP" php <<'PHP'
<?php
$app = getenv('PR9_APP');
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
ini_set('display_errors', '1');
error_reporting(E_ALL);
require $app . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \$pdo\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
}
$st = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$st->execute(['admin_partial_payment_reviews']);
$n = (int)$st->fetchColumn();
$st->closeCursor();
echo 'TABLE ' . ($n > 0 ? 'present' : 'absent') . PHP_EOL;
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_partial_payment_reviews' AND COLUMN_NAME = 'son_sheet_gross'"
);
$st->execute();
$col = (int)$st->fetchColumn();
$st->closeCursor();
echo 'COLUMN son_sheet_gross ' . ($col > 0 ? 'present' : 'absent') . PHP_EOL;
$st = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_partial_payment_reviews' AND COLUMN_NAME = 'skip_income_tax'"
);
$st->execute();
$skip = (int)$st->fetchColumn();
$st->closeCursor();
echo 'COLUMN skip_income_tax ' . ($skip > 0 ? 'present' : 'absent') . PHP_EOL;
$st = $pdo->prepare(
    "SELECT MenuID, ParentID, MenuTitle FROM menu_manager WHERE LinkURL = 'partial_payments_review.php' ORDER BY MenuID ASC LIMIT 1"
);
$st->execute();
$row = $st->fetch(PDO::FETCH_ASSOC);
$st->closeCursor();
if (!$row) {
    echo "MENU absent" . PHP_EOL;
} else {
    echo 'MENU id=' . (int)$row['MenuID'] . ' parent_id=' . (int)$row['ParentID'] . PHP_EOL;
}
PHP
}

apply_migration() {
  PR9_APP="$APP" php <<'PHP'
<?php
$app = getenv('PR9_APP');
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
ini_set('display_errors', '1');
error_reporting(E_ALL);
try {
    require $app . '/db.php';
    require $app . '/engine/partial_payments_review_lib.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('db.php did not create $pdo');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ppr_ensure($pdo);
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_partial_payment_reviews' AND COLUMN_NAME = 'son_sheet_gross'"
    );
    $st->execute();
    $col = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($col !== 1) {
        throw new RuntimeException('son_sheet_gross is missing');
    }
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_partial_payment_reviews' AND COLUMN_NAME = 'skip_income_tax'"
    );
    $st->execute();
    $skip = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($skip !== 1) {
        throw new RuntimeException('skip_income_tax was not added');
    }
    if (!function_exists('pe_line')) {
        $eng = $app . '/payroll_engine/payroll_engine.php';
        if (!is_file($eng)) {
            throw new RuntimeException('payroll_engine/payroll_engine.php is missing');
        }
        require_once $eng;
    }
    if (!function_exists('pe_line')) {
        throw new RuntimeException('pe_line is missing');
    }
    echo "MIGRATION OK\n";
    echo "ENGINE pe_line\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}
PHP
}

say "deploy19_review_no_tax ${MODE}"
say "live base ${BASE_SHA}"
say "pin ${PIN_SHA}"
STAGE=$(mktemp -d "$BACKUP_DIR/pr19-stage.XXXXXX")
mkdir -p "$STAGE/pin/engine" "$STAGE/pin/migrations"

refuse=0
for i in "${!FILES[@]}"; do
  rel="${FILES[$i]}"
  download "${RAW}/${rel}" "$STAGE/pin/$rel"
  if [[ "$rel" == *.php ]]; then
    php -l "$STAGE/pin/$rel"
  fi
  pin_sum=$(md5_of "$STAGE/pin/$rel")
  expect="${PIN_MD5[$i]}"
  base="${BASE_MD5[$i]}"
  if [[ "$pin_sum" != "$expect" ]]; then
    say "PIN $rel md5=$pin_sum expected=$expect REFUSE"
    refuse=1
    continue
  fi
  say "PIN $rel md5=$pin_sum"
  if [[ ! -f "$APP/$rel" ]]; then
    if [[ -n "$base" ]]; then
      say "FILE $rel MISSING REFUSE"
      refuse=1
    else
      say "FILE $rel absent on the server (new file)"
    fi
    continue
  fi
  live_sum=$(md5_of "$APP/$rel")
  if [[ "$live_sum" == "$pin_sum" ]]; then
    say "FILE $rel live_md5=$live_sum already this pin"
  elif [[ -n "$base" && "$live_sum" == "$base" ]]; then
    say "FILE $rel live_md5=$live_sum matches ${BASE_SHA}"
  else
    say "FILE $rel live_md5=$live_sum base_md5=$base pin_md5=$pin_sum DIFFER REFUSE"
    refuse=1
  fi
done

probe_err="$STAGE/probe.err"
set +e
probe >"$STAGE/probe.out" 2>"$probe_err"
probe_rc=$?
set -e
cat "$STAGE/probe.out" 2>/dev/null || true
if [[ -s "$probe_err" ]]; then
  say "----- probe stderr -----"
  cat "$probe_err" >&2
  say "----- end probe stderr -----"
fi
if [[ "$probe_rc" -ne 0 ]]; then
  die "Could not read the review table (php exit ${probe_rc})."
fi
TABLE_STATE=$(awk '/^TABLE / { print $2 }' "$STAGE/probe.out")
GROSS_STATE=$(awk '/^COLUMN son_sheet_gross / { print $3 }' "$STAGE/probe.out")
SKIP_STATE=$(awk '/^COLUMN skip_income_tax / { print $3 }' "$STAGE/probe.out")
MENU_LINE=$(awk '/^MENU / { print; exit }' "$STAGE/probe.out")
say "recorded table=$TABLE_STATE son_sheet_gross=$GROSS_STATE skip_income_tax=$SKIP_STATE ${MENU_LINE}"
if [[ "$TABLE_STATE" != "present" ]]; then
  say "TABLE admin_partial_payment_reviews is not present REFUSE"
  refuse=1
fi
if [[ "$GROSS_STATE" != "present" ]]; then
  say "COLUMN son_sheet_gross is not present REFUSE"
  refuse=1
fi
if [[ "$MENU_LINE" != "MENU id=154 "* && "$MENU_LINE" != "MENU id=154" ]]; then
  say "NOTE expected menu id 154 under Admin. Found: ${MENU_LINE}"
fi

if [[ "$refuse" -ne 0 ]]; then
  say "CHECK REFUSED"
  exit 1
fi
say "CHECK OK"
if [[ "$MODE" == "check" ]]; then
  say "check did not copy files and did not change the database."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
CODE_BAK="$BACKUP_DIR/pre-pr19-review_${TS}.tar.gz"
DUMP="$BACKUP_DIR/pre-pr19-review_${TS}.sql.gz"
ROLLBACK="$BACKUP_DIR/pre-pr19-review_${TS}-rollback.sh"
umask 077

EXISTING=()
for rel in "${FILES[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    EXISTING+=("$rel")
  fi
done
say "backing up the live review files"
tar -C "$APP" -czf "$CODE_BAK" "${EXISTING[@]}"
say "backup $CODE_BAK"
say "dumping admin_partial_payment_reviews"
dump_review_table "$DUMP"
sz=$(stat -c '%s' "$DUMP")
if [[ "$sz" -lt 64 ]]; then
  die "Dump $DUMP is ${sz} bytes. Nothing was copied."
fi
say "dump $DUMP size=$sz"

ROLLBACK_BODY=$(cat <<'EOS'
#!/bin/bash
set -euo pipefail
APP="__APP__"
CODE_BAK="__CODE__"
DUMP="__DUMP__"
BACKUP_DIR="__BACKUP__"
tar -xzf "$CODE_BAK" -C "$APP"
echo "Restored review files from $CODE_BAK"
cnf=$(mktemp "$BACKUP_DIR/.pr19-rollback.XXXXXX")
chmod 600 "$cnf"
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
if ($user === '' || $pass === '') exit(1);
file_put_contents($cnf, "[client]\nuser=" . $user . "\npassword=" . $pass . "\nhost=" . $host . "\n");
file_put_contents($cnf . '.db', $name);
chmod($cnf, 0600);
PHP
dbname=$(cat "$cnf.db")
gunzip -c "$DUMP" | mysql --defaults-extra-file="$cnf" "$dbname"
rm -f "$cnf" "$cnf.db"
echo "Restored admin_partial_payment_reviews from $DUMP"
echo "Menu rows were not changed. Payroll payment tables were not changed."
EOS
)
ROLLBACK_BODY=${ROLLBACK_BODY//__APP__/$APP}
ROLLBACK_BODY=${ROLLBACK_BODY//__CODE__/$CODE_BAK}
ROLLBACK_BODY=${ROLLBACK_BODY//__DUMP__/$DUMP}
ROLLBACK_BODY=${ROLLBACK_BODY//__BACKUP__/$BACKUP_DIR}
printf '%s\n' "$ROLLBACK_BODY" > "$ROLLBACK"
chmod 700 "$ROLLBACK"

for rel in "${FILES[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    live_sum=$(md5_of "$APP/$rel")
    pin_sum=$(md5_of "$STAGE/pin/$rel")
    base=""
    for i in "${!FILES[@]}"; do
      if [[ "${FILES[$i]}" == "$rel" ]]; then
        base="${BASE_MD5[$i]}"
      fi
    done
    if [[ "$live_sum" != "$pin_sum" && "$live_sum" != "$base" ]]; then
      die "Live ${rel} changed after check. Nothing further was copied. Restore with: bash ${ROLLBACK}"
    fi
  fi
  owner=$(stat -c '%U:%G' "$APP/partial_payments_review.php")
  install -o "${owner%%:*}" -g "${owner##*:}" -m 644 "$STAGE/pin/$rel" "$APP/$rel"
  say "copied $rel"
done

say "php -l on the live copies"
php -l "$APP/partial_payments_review.php"
php -l "$APP/engine/partial_payments_review_lib.php"

say "adding skip_income_tax when it is missing"
mig_err="$STAGE/migrate.err"
set +e
apply_migration >"$STAGE/migrate.out" 2>"$mig_err"
mig_rc=$?
set -e
if [[ -s "$STAGE/migrate.out" ]]; then
  cat "$STAGE/migrate.out"
fi
if [[ -s "$mig_err" ]]; then
  say "----- migration stderr -----"
  cat "$mig_err" >&2
  say "----- end migration stderr -----"
fi
if [[ "$mig_rc" -ne 0 ]]; then
  die "Migration failed (php exit ${mig_rc}). Restore with: bash ${ROLLBACK}"
fi

code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "${PUBLIC_BASE}/partial_payments_review.php" || echo "000")
say "curl ${PUBLIC_BASE}/partial_payments_review.php HTTP ${code}"
if [[ "$code" != "302" && "$code" != "303" ]]; then
  die "Expected HTTP 302 without a session, got ${code}. Restore with: bash ${ROLLBACK}"
fi

say "GO OK"
say "backup:"
say "  $CODE_BAK"
say "  $DUMP"
say "rollback:"
say "  bash $ROLLBACK"
echo "----- rollback script -----"
cat "$ROLLBACK"
echo "----- end rollback -----"
