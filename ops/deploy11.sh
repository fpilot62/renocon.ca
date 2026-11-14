#!/bin/bash
# ops/deploy11.sh — deploy PR #9 (Order Amounts + Outstanding Wages)
# Modes match ops/deploy10.sh: check (read-only) or go.
#
# Run as root from the WHM terminal. Download this file by its commit SHA;
# the app files it copies are pinned separately to the PR #9 merge commit.
#
#   bash deploy11.sh check
#   bash deploy11.sh go
#
# Live app: /home/renocon/public_html/payroll
# Database: the $pdo from that app's db.php
# Backups:  /root/payroll-daily/pre-pr9-*_$TS
#
# This script adds new files and two menu_manager rows. It does not edit
# menu_generator.php, MENU_MIN_ROLE.php, payroll_scope.php, or db.php.
# It does not UPDATE, DELETE, or ALTER attendance, payroll, or pay-run tables.
# The ledger tables are created with CREATE TABLE IF NOT EXISTS and are left
# in place by the printed rollback.

set -euo pipefail

APP=/home/renocon/public_html/payroll
BACKUP_DIR=/root/payroll-daily
PUBLIC_BASE=https://payroll.renocon.ca
PR9_SHA=b2652ccf18854b27b2fdfc811b8068d2e67ba533
RAW="https://raw.githubusercontent.com/fpilot62/renocon.ca/${PR9_SHA}"

# New files from PR #9. Tests are not copied to the server.
FILES=(
  order_amounts.php
  outstanding_wages.php
  engine/admin_super_lib.php
  engine/order_amounts_lib.php
  engine/outstanding_wages_lib.php
  migrations/20261114_admin_outstanding_wages.sql
)

# Shared files this deploy must not change. They are not in FILES.
SHARED=(
  db.php
  payroll_scope.php
  engine/menu_generator.php
  engine/MENU_MIN_ROLE.php
)

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash deploy11.sh check|go" >&2
  exit 2
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/deploy11.lock"
if ! flock -n 9; then
  echo "Another deploy11.sh is already running." >&2
  exit 2
fi

STAGE=""
cleanup() {
  if [[ -n "$STAGE" && -d "$STAGE" ]]; then
    rm -rf "$STAGE"
  fi
}
trap cleanup EXIT

die() {
  echo "ERROR: $*" >&2
  exit 1
}

say() {
  printf '%s\n' "$*"
}

md5_of() {
  md5sum "$1" | awk '{print $1}'
}

download_tree() {
  local dest="$1"
  local rel
  mkdir -p "$dest/engine" "$dest/migrations"
  for rel in "${FILES[@]}"; do
    mkdir -p "$dest/$(dirname "$rel")"
    curl -fsSL --retry 3 --max-time 60 -o "$dest/$rel" "${RAW}/${rel}" \
      || die "Could not download ${rel} from ${PR9_SHA}"
    if head -c 64 "$dest/$rel" | grep -q '<!DOCTYPE\|404: Not Found'; then
      die "Download for ${rel} was not the file (HTTP error page)."
    fi
  done
  head -c 5 "$dest/order_amounts.php" | grep -q '<?php' || die "order_amounts.php is not PHP"
  head -c 5 "$dest/outstanding_wages.php" | grep -q '<?php' || die "outstanding_wages.php is not PHP"
  grep -q 'CREATE TABLE IF NOT EXISTS admin_outstanding_wages' "$dest/migrations/20261114_admin_outstanding_wages.sql" \
    || die "Migration file is not the outstanding-wages SQL"
}

# Accept only the two CREATE TABLE statements. Comments are ignored.
# Any other statement, or any mention of payroll/attendance tables, refuses go.
guard_sql() {
  local src="$1"
  php -r '
    $sql = file_get_contents($argv[1]);
    if ($sql === false) { fwrite(STDERR, "migration unreadable\n"); exit(1); }
    $sql = preg_replace("/^--.*$/m", "", $sql);
    $allowed = [
      "admin_outstanding_wages" => false,
      "admin_outstanding_wage_edits" => false,
    ];
    $forbidden = "/\b(attendance|payroll_finalized|payroll_payments|payroll_masters|payroll_balance|payroll_payment_batches|pay_run)\b/i";
    foreach (preg_split("/;/", $sql) as $stmt) {
      $stmt = trim($stmt);
      if ($stmt === "") continue;
      if (preg_match($forbidden, $stmt)) {
        fwrite(STDERR, "Migration mentions a payroll or attendance table.\n");
        exit(1);
      }
      if (preg_match("/^\s*CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?(admin_outstanding_wages|admin_outstanding_wage_edits)`?\b/i", $stmt, $m)) {
        $allowed[strtolower($m[1])] = true;
        continue;
      }
      fwrite(STDERR, "Migration has a statement other than the two CREATE TABLE statements.\n");
      exit(1);
    }
    if (!$allowed["admin_outstanding_wages"] || !$allowed["admin_outstanding_wage_edits"]) {
      fwrite(STDERR, "Migration is missing one of the two ledger tables.\n");
      exit(1);
    }
  ' "$src"
}

php_lint() {
  local root="$1"
  local rel
  local ok=0
  for rel in "${FILES[@]}"; do
    case "$rel" in
      *.php)
        if php -l "$root/$rel"; then
          ok=$((ok + 1))
        else
          return 1
        fi
        ;;
    esac
  done
  say "php -l passed on ${ok} PHP files"
}

# Writes human-readable DB/menu facts. Exit 0 when go may continue, 1 to refuse.
db_report() {
  local apply="${1:-0}"
  local rollback_file="${2:-}"
  PR9_APP="$APP" PR9_APPLY="$apply" PR9_ROLLBACK="$rollback_file" php <<'PHP'
<?php
$app = getenv('PR9_APP');
$apply = getenv('PR9_APPLY') === '1';
$rollbackFile = getenv('PR9_ROLLBACK') ?: '';
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
$_SERVER['SCRIPT_NAME'] = '/deploy11.php';
require $app . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \$pdo\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function pr9_table(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$name]);
    return (int)$st->fetchColumn() > 0;
}

$refuse = false;
foreach (['admin_outstanding_wages', 'admin_outstanding_wage_edits'] as $table) {
    $present = pr9_table($pdo, $table);
    echo 'TABLE ' . $table . ' ' . ($present ? 'present' : 'absent') . PHP_EOL;
}

$menuTable = pr9_table($pdo, 'menu_manager');
$renderer = is_file($app . '/engine/menu_generator.php') ? 'engine/menu_generator.php' : '(renderer file not in the app root)';
if ($menuTable) {
    echo 'MENU_STORAGE database rows in menu_manager. ' . $renderer . ' only draws those rows and is not edited.' . PHP_EOL;
} else {
    echo 'MENU_STORAGE menu_manager table is absent. A PHP file is not used as the menu store.' . PHP_EOL;
    echo "REFUSE menu_manager is missing, so the Admin links cannot be inserted.\n";
    $refuse = true;
}

if (!$menuTable) {
    exit($refuse ? 1 : 0);
}

$cols = $pdo->query('SHOW COLUMNS FROM menu_manager')->fetchAll(PDO::FETCH_COLUMN);
$have = array_flip($cols);
foreach (['MenuID', 'MenuTitle', 'LinkURL', 'ParentID', 'IsActive', 'UserRole'] as $need) {
    if (!isset($have[$need])) {
        echo "REFUSE menu_manager is missing column {$need}.\n";
        $refuse = true;
    }
}
if ($refuse) {
    exit(1);
}

$parentSt = $pdo->query(
    "SELECT * FROM menu_manager
     WHERE (ParentID = 0 OR ParentID IS NULL)
       AND UPPER(TRIM(MenuTitle)) IN ('ADMIN', 'ADMINISTRATION')
     ORDER BY MenuID ASC LIMIT 1"
);
$parent = $parentSt ? $parentSt->fetch(PDO::FETCH_ASSOC) : false;
if (!$parent) {
    echo "REFUSE no top-level Admin menu row (MenuTitle ADMIN or ADMINISTRATION, ParentID 0).\n";
    echo "Shared menu PHP is not modified to invent one.\n";
    exit(1);
}
$parentId = (int)$parent['MenuID'];
echo 'ADMIN_PARENT MenuID=' . $parentId
    . ' title=' . str_replace(["\n", "\r"], ' ', (string)$parent['MenuTitle'])
    . ' UserRole=' . (int)$parent['UserRole']
    . ' IsActive=' . (string)$parent['IsActive'] . PHP_EOL;

$links = [
    ['Order Amounts', 'order_amounts.php', 900],
    ['Outstanding Wages', 'outstanding_wages.php', 901],
];
$plans = [];
$find = $pdo->prepare(
    'SELECT * FROM menu_manager WHERE LinkURL = ? OR LinkURL LIKE ? ORDER BY MenuID ASC'
);
foreach ($links as $link) {
    [$title, $url, $sort] = $link;
    $find->execute([$url, '%/' . $url]);
    $rows = $find->fetchAll(PDO::FETCH_ASSOC);
    $count = count($rows);
    if ($count > 1) {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['MenuID'];
        }
        echo 'LINK ' . $url . ' count=' . $count . ' MenuIDs=' . implode(',', $ids) . ' COLLISION REFUSE' . PHP_EOL;
        $refuse = true;
        continue;
    }
    if ($count === 1) {
        $row = $rows[0];
        $sameParent = (int)$row['ParentID'] === $parentId;
        $sameRole = (int)$row['UserRole'] === 10;
        $active = (string)$row['IsActive'] === '1';
        $action = ($sameParent && $sameRole && $active) ? 'keep' : 'update-role-parent';
        echo 'LINK ' . $url . ' count=1 MenuID=' . (int)$row['MenuID']
            . ' ParentID=' . (int)$row['ParentID']
            . ' UserRole=' . (int)$row['UserRole']
            . ' IsActive=' . (string)$row['IsActive']
            . ' action=' . $action . PHP_EOL;
        $plans[] = ['action' => $action, 'title' => $title, 'url' => $url, 'sort' => $sort, 'row' => $row];
        continue;
    }
    echo 'LINK ' . $url . " count=0 action=insert ParentID={$parentId} UserRole=10\n";
    $plans[] = ['action' => 'insert', 'title' => $title, 'url' => $url, 'sort' => $sort, 'row' => null];
}
if ($refuse) {
    exit(1);
}
$inserted = [];
$restores = [];
if ($apply) {
    foreach ($plans as $plan) {
        if ($plan['action'] === 'keep') {
            continue;
        }
        if ($plan['action'] === 'update-role-parent') {
            $row = $plan['row'];
            $restores[] = $row;
            $sets = ['`UserRole` = ?', '`ParentID` = ?', '`IsActive` = ?'];
            $vals = [10, $parentId, 1];
            if (isset($have['MenuTitle'])) {
                $sets[] = '`MenuTitle` = ?';
                $vals[] = $plan['title'];
            }
            $vals[] = (int)$row['MenuID'];
            $pdo->prepare('UPDATE menu_manager SET ' . implode(', ', $sets) . ' WHERE MenuID = ?')->execute($vals);
            echo 'LINK ' . $plan['url'] . ' updated MenuID=' . (int)$row['MenuID'] . PHP_EOL;
            continue;
        }
        $data = [
            'MenuTitle' => $plan['title'],
            'LinkURL' => $plan['url'],
            'ParentID' => $parentId,
            'IsActive' => 1,
            'UserRole' => 10,
        ];
        if (isset($have['Module']) && isset($parent['Module'])) {
            $data['Module'] = (string)$parent['Module'];
        }
        if (isset($have['SortOrder'])) {
            $data['SortOrder'] = $plan['sort'];
        }
        $fields = [];
        $holders = [];
        $vals = [];
        foreach ($data as $col => $val) {
            if (!isset($have[$col])) {
                continue;
            }
            $fields[] = '`' . str_replace('`', '', $col) . '`';
            $holders[] = '?';
            $vals[] = $val;
        }
        $pdo->prepare(
            'INSERT INTO menu_manager (' . implode(',', $fields) . ') VALUES (' . implode(',', $holders) . ')'
        )->execute($vals);
        $newId = (int)$pdo->lastInsertId();
        $inserted[] = $newId;
        echo 'LINK ' . $plan['url'] . ' inserted MenuID=' . $newId . PHP_EOL;
    }
}

if ($apply && $rollbackFile !== '') {
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '$app = ' . var_export($app, true) . ';';
    $lines[] = 'chdir($app);';
    $lines[] = 'require $app . "/db.php";';
    $lines[] = 'if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "db.php did not create \$pdo\n"); exit(1); }';
    $lines[] = '$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);';
    if ($inserted) {
        $lines[] = '$del = $pdo->prepare("DELETE FROM menu_manager WHERE MenuID = ? AND (LinkURL = ? OR LinkURL = ?)");';
        $lines[] = 'foreach (' . var_export($inserted, true) . ' as $id) {';
        $lines[] = '    $del->execute([(int)$id, "order_amounts.php", "outstanding_wages.php"]);';
        $lines[] = '    echo "removed menu row $id\n";';
        $lines[] = '}';
    }
    if ($restores) {
        $lines[] = '$fix = $pdo->prepare("UPDATE menu_manager SET UserRole = ?, ParentID = ?, IsActive = ? WHERE MenuID = ? AND (LinkURL = ? OR LinkURL = ?)");';
        foreach ($restores as $row) {
            $lines[] = '$fix->execute(['
                . (int)$row['UserRole'] . ', '
                . (int)$row['ParentID'] . ', '
                . var_export((string)$row['IsActive'], true) . ', '
                . (int)$row['MenuID'] . ', '
                . '"order_amounts.php", "outstanding_wages.php"]);';
            $lines[] = 'echo ' . var_export('restored menu row ' . (int)$row['MenuID'] . "\n", true) . ';';
        }
    }
    if (!$inserted && !$restores) {
        $lines[] = 'echo "no menu rows were inserted or changed\n";';
    }
    $lines[] = 'echo "ledger tables were left in place\n";';
    file_put_contents($rollbackFile, implode("\n", $lines) . "\n");
    chmod($rollbackFile, 0700);
}

exit($refuse ? 1 : 0);
PHP
}

dump_database() {
  local out="$1"
  local err="$BACKUP_DIR/pre-pr9-mysqldump.err"
  if mysql --batch -N -e 'SELECT 1' admin_payroll >/dev/null 2>"$err"; then
    mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 admin_payroll 2>"$err" | gzip -c > "$out"
    rm -f "$err"
    return 0
  fi
  local cnf
  cnf=$(mktemp "$BACKUP_DIR/.pr9-my.XXXXXX")
  chmod 600 "$cnf"
  if ! PR9_APP="$APP" PR9_CNF="$cnf" php <<'PHP'
<?php
$app = getenv('PR9_APP');
$cnf = getenv('PR9_CNF');
$src = file_get_contents($app . '/db.php');
if ($src === false) { exit(1); }
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
$host = $vars['$host'] ?? $vars['$db_host'] ?? $vars['$dbhost'] ?? 'localhost';
$name = $vars['$dbname'] ?? $vars['$db_name'] ?? $vars['$database'] ?? $vars['$db'] ?? 'admin_payroll';
$user = $vars['$username'] ?? $vars['$db_user'] ?? $vars['$user'] ?? $vars['$dbuser'] ?? '';
$pass = $vars['$password'] ?? $vars['$db_pass'] ?? $vars['$pass'] ?? $vars['$dbpass'] ?? '';
if (preg_match('/mysql:host=([^;"\']+);[^"\']*dbname=([^;"\']+)/', $src, $m)) {
    if ($host === 'localhost' || $host === '') $host = $m[1];
    if ($name === 'admin_payroll' || $name === '') $name = $m[2];
}
$env = $app . '/config/payroll.env';
if (($user === '' || $pass === '') && is_file($env)) {
    foreach (file($env, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        if ($k === 'DB_HOST' && $v !== '') $host = $v;
        if ($k === 'DB_NAME' && $v !== '') $name = $v;
        if ($k === 'DB_USER' && $v !== '') $user = $v;
        if ($k === 'DB_PASS' && $v !== '') $pass = $v;
    }
}
if ($user === '' || $pass === '' || $name === '') exit(1);
$ini = "[client]\nuser=" . $user . "\npassword=" . $pass . "\nhost=" . $host . "\n";
if (file_put_contents($cnf, $ini) === false) exit(1);
file_put_contents($cnf . '.db', $name);
chmod($cnf, 0600);
exit(0);
PHP
  then
    rm -f "$cnf" "$cnf.db" "$err"
    die "Could not read MySQL credentials for mysqldump. No app files were changed."
  fi
  local dbname
  dbname=$(cat "$cnf.db")
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "$dbname" 2>"$err" | gzip -c > "$out"
  rm -f "$cnf" "$cnf.db" "$err"
}

require_size() {
  local path="$1"
  local min="$2"
  local sz
  sz=$(stat -c '%s' "$path")
  if [[ "$sz" -le 0 ]]; then
    die "Backup $path is empty."
  fi
  if [[ "$sz" -lt "$min" ]]; then
    die "Backup $path is ${sz} bytes, below the ${min}-byte check."
  fi
  say "backup $path size=$sz"
}

preflight() {
  local rel live pin_sum live_sum
  local refuse=0
  say "PR9 pin ${PR9_SHA}"
  say "app ${APP}"
  if [[ ! -f "$APP/db.php" ]]; then
    die "Live app root is missing db.php (${APP})."
  fi
  download_tree "$STAGE"
  guard_sql "$STAGE/migrations/20261114_admin_outstanding_wages.sql"
  say "migration SQL is the two CREATE TABLE statements only"
  if ! php_lint "$STAGE"; then
    die "php -l failed on the downloaded files."
  fi
  say "shared files are not copied and are not edited:"
  for rel in "${SHARED[@]}"; do
    if [[ -f "$APP/$rel" ]]; then
      say "  leave $rel md5=$(md5_of "$APP/$rel")"
    else
      say "  leave $rel (not present)"
    fi
  done
  for rel in "${FILES[@]}"; do
    pin_sum=$(md5_of "$STAGE/$rel")
    live="$APP/$rel"
    if [[ ! -f "$live" ]]; then
      say "FILE $rel ABSENT would-add pin_md5=$pin_sum"
      continue
    fi
    live_sum=$(md5_of "$live")
    if [[ "$live_sum" == "$pin_sum" ]]; then
      say "FILE $rel PRESENT pin_md5=$pin_sum MATCH already this version"
    else
      say "FILE $rel PRESENT live_md5=$live_sum pin_md5=$pin_sum DIFFER REFUSE"
      say "  expected base for this new file is absent, or already equal to the pinned PR #9 blob"
      refuse=1
    fi
  done
  if ! db_report 0; then
    refuse=1
  fi
  if [[ "$refuse" -ne 0 ]]; then
    say "CHECK REFUSED"
    return 1
  fi
  say "CHECK OK"
  return 0
}

curl_page() {
  local path="$1"
  local url="${PUBLIC_BASE}/${path}"
  local code
  code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "$url" || echo "000")
  say "curl ${url} HTTP ${code}"
  case "$code" in
    301|302|303|307|308|403) return 0 ;;
    *)
      echo "Expected a redirect or 403 without a session, got ${code}." >&2
      return 1
      ;;
  esac
}

say "deploy11 ${MODE}  pin=${PR9_SHA}"
STAGE=$(mktemp -d "$BACKUP_DIR/pr9-stage.XXXXXX")

if ! preflight; then
  exit 1
fi

if [[ "$MODE" == "check" ]]; then
  say "check did not copy files, did not write the database, and did not take a backup."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
CODE_BAK="$BACKUP_DIR/pre-pr9-code_${TS}.tar.gz"
DB_BAK="$BACKUP_DIR/pre-pr9-db_${TS}.sql.gz"
ROLLBACK="$BACKUP_DIR/pre-pr9-rollback_${TS}.sh"
REMOVED_LIST="$BACKUP_DIR/pre-pr9-added_${TS}.txt"
: > "$REMOVED_LIST"

say "writing backups before any change"
umask 077
tar -C /home/renocon/public_html -czf "$CODE_BAK" payroll
require_size "$CODE_BAK" 1048576
dump_database "$DB_BAK"
require_size "$DB_BAK" 10240
gzip -t "$DB_BAK"
set +o pipefail
dump_head=$(gzip -dc "$DB_BAK" | head -c 4000 || true)
set -o pipefail
if ! printf '%s' "$dump_head" | grep -q 'CREATE TABLE'; then
  die "Database backup does not look like a mysqldump. No app files were changed."
fi

# Re-check hashes immediately before the copy. The stage tree is still the pin.
for rel in "${FILES[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    if [[ "$(md5_of "$APP/$rel")" != "$(md5_of "$STAGE/$rel")" ]]; then
      die "Live ${rel} changed after check. Stopped before copying. Backups: ${CODE_BAK} ${DB_BAK}"
    fi
  else
    printf '%s\n' "$rel" >> "$REMOVED_LIST"
  fi
done

say "running migration"
guard_sql "$STAGE/migrations/20261114_admin_outstanding_wages.sql"
PR9_APP="$APP" PR9_SQL="$STAGE/migrations/20261114_admin_outstanding_wages.sql" php <<'PHP'
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
$sql = file_get_contents($sqlPath);
$sql = preg_replace('/^--.*$/m', '', (string)$sql);
foreach (preg_split('/;/', (string)$sql) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    if (!preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?admin_outstanding_wage(s|_edits)`?\b/i', $stmt)) {
        fwrite(STDERR, "Refusing to execute an unexpected statement.\n");
        exit(1);
    }
    $pdo->exec($stmt);
    echo "executed CREATE TABLE\n";
}
PHP

owner=$(stat -c '%U:%G' "$APP/db.php")
shared_before="$BACKUP_DIR/pre-pr9-shared_${TS}.md5"
: > "$shared_before"
for rel in "${SHARED[@]}"; do
  if [[ -f "$APP/$rel" ]]; then
    printf '%s  %s\n' "$(md5_of "$APP/$rel")" "$rel" >> "$shared_before"
  fi
done
for rel in "${FILES[@]}"; do
  install -D -o "${owner%%:*}" -g "${owner##*:}" -m 644 "$STAGE/$rel" "$APP/$rel"
  say "copied $rel"
done
while read -r sum rel; do
  [[ -n "$rel" ]] || continue
  now=$(md5_of "$APP/$rel")
  if [[ "$now" != "$sum" ]]; then
    die "Shared file $rel changed during deploy (before $sum now $now). Stop and restore ${CODE_BAK}."
  fi
  say "unchanged $rel md5=$now"
done < "$shared_before"

say "menu rows"
MENU_PHP="$BACKUP_DIR/pre-pr9-menu_${TS}.php"
db_report 1 "$MENU_PHP" || die "Menu insert refused after the file copy. Backups: ${CODE_BAK} ${DB_BAK}"

say "php -l on the live copies"
php_lint "$APP"

say "session-less HTTP check"
curl_page "order_amounts.php"
curl_page "outstanding_wages.php"

{
  echo "#!/bin/bash"
  echo "# Rollback for deploy11 ${TS}. Removes files this run added and menu rows it inserted or changed."
  echo "# Ledger tables admin_outstanding_wages and admin_outstanding_wage_edits are left in place."
  echo "# Code backup: ${CODE_BAK}"
  echo "# Database backup: ${DB_BAK}"
  echo "set -euo pipefail"
  echo "APP=${APP}"
  while IFS= read -r rel; do
    [[ -n "$rel" ]] || continue
    printf 'rm -f %q\n' "$APP/$rel"
  done < "$REMOVED_LIST"
  if [[ -f "$MENU_PHP" ]]; then
    echo "php $(printf '%q' "$MENU_PHP")"
  else
    echo "echo 'no menu rollback php was written'"
  fi
  echo "echo 'rollback finished; ledger tables were left in place'"
} > "$ROLLBACK"
chmod 700 "$ROLLBACK"

say "GO OK"
say "backups:"
say "  $CODE_BAK"
say "  $DB_BAK"
say "rollback (files added by this run, plus menu rows; tables stay):"
say "  bash $ROLLBACK"
echo "----- rollback script -----"
cat "$ROLLBACK"
echo "----- end rollback -----"
