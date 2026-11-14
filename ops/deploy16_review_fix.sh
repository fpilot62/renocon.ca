#!/bin/bash
# ops/deploy16_review_fix.sh — finish the partial-payment review install.
# Modes: check (read-only) or go. Run as root from the WHM terminal.
#
#   bash deploy16_review_fix.sh check
#   bash deploy16_review_fix.sh go
#
# The three app files are already on the server from deploy15. This script
# does not replace them when their md5 matches pin 300be82. That pin's PHP
# is fine. deploy15's migrate() passed PDO::ERRMODE to setAttribute.
# The attribute name is PDO::ATTR_ERRMODE.
#
# Go creates admin_partial_payment_reviews (CREATE TABLE IF NOT EXISTS only)
# and inserts the Partial payments menu link. The page adds that link only
# after a superadmin session, so the unauthenticated curl never did.
# If no menu row is titled Review, the link is placed under Admin.
# Payroll payment tables are not changed and nobody is marked paid.

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
EXPECT_MD5=(
  84875fd0b7cba1e13146cde37c0a3ffc
  18189c4e276fd3fad568eec4c5af43ec
  29507762be3b257014461f77a7af2abf
)

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash deploy16_review_fix.sh check|go" >&2
  exit 2
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/deploy16_review_fix.lock"
if ! flock -n 9; then
  echo "Another deploy16_review_fix.sh is already running." >&2
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
    "SELECT m.MenuID, m.ParentID, m.MenuTitle, m.UserRole, p.MenuTitle AS parent_title
     FROM menu_manager m
     LEFT JOIN menu_manager p ON p.MenuID = m.ParentID
     WHERE m.LinkURL = 'partial_payments_review.php'
     ORDER BY m.MenuID ASC LIMIT 1"
);
$st->execute();
$row = $st->fetch(PDO::FETCH_ASSOC);
$st->closeCursor();
if (!$row) {
    echo "MENU absent" . PHP_EOL;
    exit(0);
}
echo 'MENU id=' . (int)$row['MenuID']
    . ' parent_id=' . (int)$row['ParentID']
    . ' parent=' . str_replace(["\n", "\r"], ' ', (string)$row['parent_title'])
    . ' title=' . str_replace(["\n", "\r"], ' ', (string)$row['MenuTitle'])
    . ' role=' . (int)$row['UserRole'] . PHP_EOL;
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
    require $app . '/engine/admin_super_lib.php';
    require $app . '/engine/partial_payments_review_lib.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('db.php did not create $pdo');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    ppr_ensure($pdo);
    ppr_ensure_menu($pdo);
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute(['admin_partial_payment_reviews']);
    $n = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($n !== 1) {
        throw new RuntimeException('admin_partial_payment_reviews was not created');
    }
    $st = $pdo->prepare(
        "SELECT m.MenuID, m.ParentID, m.MenuTitle, m.UserRole, p.MenuTitle AS parent_title
         FROM menu_manager m
         LEFT JOIN menu_manager p ON p.MenuID = m.ParentID
         WHERE m.LinkURL = 'partial_payments_review.php'
         ORDER BY m.MenuID ASC LIMIT 1"
    );
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$row || (int)$row['ParentID'] <= 0) {
        throw new RuntimeException('Partial payments was not placed under Admin or Review.');
    }
    $parent = (string)$row['parent_title'];
    echo 'MENU id=' . (int)$row['MenuID']
        . ' parent=' . $parent
        . ' title=' . $row['MenuTitle']
        . ' role=' . (int)$row['UserRole'] . PHP_EOL;
    if (strcasecmp(trim($parent), 'Review') !== 0) {
        echo 'NOTE no Review submenu was found. The link is under ' . $parent . '.' . PHP_EOL;
    }
    echo "MIGRATION OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}
PHP
}

say "deploy16_review_fix ${MODE}"
say "pin ${PIN_SHA}"
STAGE=$(mktemp -d "$BACKUP_DIR/pr16-stage.XXXXXX")
mkdir -p "$STAGE/pin/engine" "$STAGE/pin/migrations"

refuse=0
for i in "${!FILES[@]}"; do
  rel="${FILES[$i]}"
  expect="${EXPECT_MD5[$i]}"
  download "${RAW}/${rel}" "$STAGE/pin/$rel"
  if [[ "$rel" == *.php ]]; then
    php -l "$STAGE/pin/$rel"
  fi
  pin_sum=$(md5_of "$STAGE/pin/$rel")
  if [[ "$pin_sum" != "$expect" ]]; then
    say "PIN $rel md5=$pin_sum expected=$expect REFUSE"
    refuse=1
    continue
  fi
  say "PIN $rel md5=$pin_sum"
  if [[ ! -f "$APP/$rel" ]]; then
    say "FILE $rel MISSING REFUSE"
    refuse=1
    continue
  fi
  live_sum=$(md5_of "$APP/$rel")
  if [[ "$live_sum" == "$pin_sum" ]]; then
    say "FILE $rel live_md5=$live_sum matches pin"
  else
    say "FILE $rel live_md5=$live_sum pin_md5=$pin_sum DIFFER REFUSE"
    refuse=1
  fi
done
guard_sql "$STAGE/pin/migrations/20261116_admin_partial_payment_reviews.sql"
say "SQL guard ok"

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
  die "Could not read the table or menu state (php exit ${probe_rc})."
fi
TABLE_STATE=$(awk '/^TABLE / { print $2 }' "$STAGE/probe.out")
MENU_LINE=$(awk '/^MENU / { print; exit }' "$STAGE/probe.out")
say "recorded ${MENU_LINE}"
if [[ "$TABLE_STATE" != "present" && "$TABLE_STATE" != "absent" ]]; then
  die "Unexpected table state: ${TABLE_STATE:-empty}"
fi
if [[ "$MENU_LINE" == "MENU absent" ]]; then
  DROP_MENU=1
else
  DROP_MENU=0
fi
if [[ "$TABLE_STATE" == "absent" ]]; then
  DROP_TABLE=1
else
  DROP_TABLE=0
fi

if [[ "$refuse" -ne 0 ]]; then
  say "CHECK REFUSED"
  exit 1
fi
say "CHECK OK"
if [[ "$MODE" == "check" ]]; then
  say "check did not copy files, did not create the table, and did not change the menu."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
CODE_BAK="$BACKUP_DIR/pre-pr16-review_${TS}.tar.gz"
ROLLBACK="$BACKUP_DIR/pre-pr16-review_${TS}-rollback.sh"
umask 077
say "backing up the three live files before creating the table"
tar -C "$APP" -czf "$CODE_BAK" "${FILES[@]}"
say "backup $CODE_BAK"

# Re-check the live bytes after the backup, still before any change.
for rel in "${FILES[@]}"; do
  live_sum=$(md5_of "$APP/$rel")
  pin_sum=$(md5_of "$STAGE/pin/$rel")
  if [[ "$live_sum" != "$pin_sum" ]]; then
    die "Live ${rel} changed after check. Nothing was migrated. Backup: ${CODE_BAK}"
  fi
done
say "live files still match the pin. They are not replaced."

cat > "$ROLLBACK" <<EOF
#!/bin/bash
# Undoes deploy16. Restores the three PHP/SQL files from the backup.
# Drops admin_partial_payment_reviews only if this run created it.
# Removes the Partial payments menu row only if this run added it.
# Does not change payroll_finalized or payroll_payments.
set -euo pipefail
APP=$(printf '%q' "$APP")
CODE_BAK=$(printf '%q' "$CODE_BAK")
DROP_TABLE=$(printf '%q' "$DROP_TABLE")
DROP_MENU=$(printf '%q' "$DROP_MENU")
if [[ -f "\$CODE_BAK" ]]; then
  tar -xzf "\$CODE_BAK" -C "\$APP"
  echo "Restored the three review files from \$CODE_BAK"
fi
PR9_APP="\$APP" PR9_DROP_TABLE="\$DROP_TABLE" PR9_DROP_MENU="\$DROP_MENU" php <<'PHP'
<?php
\$app = getenv('PR9_APP');
chdir(\$app);
\$_SERVER['DOCUMENT_ROOT'] = \$app;
\$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
ini_set('display_errors', '1');
error_reporting(E_ALL);
require \$app . '/db.php';
if (!isset(\$pdo) || !(\$pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \\\$pdo\\n");
    exit(1);
}
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (getenv('PR9_DROP_TABLE') === '1') {
    \$pdo->exec('DROP TABLE IF EXISTS admin_partial_payment_reviews');
    echo "Dropped admin_partial_payment_reviews\\n";
} else {
    echo "Left admin_partial_payment_reviews in place.\\n";
}
if (getenv('PR9_DROP_MENU') === '1') {
    \$st = \$pdo->prepare("DELETE FROM menu_manager WHERE LinkURL = 'partial_payments_review.php'");
    \$st->execute();
    echo "Removed Partial payments menu row\\n";
} else {
    echo "Left the Partial payments menu row in place.\\n";
}
echo "Payroll payment tables were not changed.\\n";
PHP
EOF
chmod 700 "$ROLLBACK"

say "creating the review table and the Admin menu link"
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

say "php -l on the live copies"
php -l "$APP/partial_payments_review.php"
php -l "$APP/engine/partial_payments_review_lib.php"

code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "${PUBLIC_BASE}/partial_payments_review.php" || echo "000")
say "curl ${PUBLIC_BASE}/partial_payments_review.php HTTP ${code}"
if [[ "$code" != "302" && "$code" != "303" ]]; then
  die "Expected HTTP 302 without a session, got ${code}. Restore with: bash ${ROLLBACK}"
fi

say "GO OK"
say "files were left at pin ${PIN_SHA}"
say "backup:"
say "  $CODE_BAK"
say "rollback:"
say "  bash $ROLLBACK"
echo "----- rollback script -----"
cat "$ROLLBACK"
echo "----- end rollback -----"
