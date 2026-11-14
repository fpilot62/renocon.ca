#!/bin/bash
# ops/deploy14.sh — pending wages include vacation pay.
# Modes: check (read-only) or go. Run as root from the WHM terminal.
#
#   bash deploy14.sh check
#   bash deploy14.sh go
#
# Copies the pinned files below. The live outstanding_wages.php and
# engine/outstanding_wages_lib.php must still match the deploy13 pin
# fe031ca982489ea13eb0a6bf33b1b1184115a85c, or already match this fix.
# This does not delete or rewrite wage rows.

set -euo pipefail

APP=/home/renocon/public_html/payroll
BACKUP_DIR=/root/payroll-daily
PUBLIC_BASE=https://payroll.renocon.ca
BASE_SHA=fe031ca982489ea13eb0a6bf33b1b1184115a85c
PIN_SHA=618f16872491b94cc9a6a38410c6b60c87a2ae68
RAW_PIN="https://raw.githubusercontent.com/fpilot62/renocon.ca/${PIN_SHA}"
RAW_BASE="https://raw.githubusercontent.com/fpilot62/renocon.ca/${BASE_SHA}"
FILES=(
  outstanding_wages.php
  engine/outstanding_wages_lib.php
)

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash deploy14.sh check|go" >&2
  exit 2
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/deploy14.lock"
if ! flock -n 9; then
  echo "Another deploy14.sh is already running." >&2
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

preflight() {
  local rel live_sum base_sum pin_sum
  local refuse=0
  say "pin ${PIN_SHA}"
  say "expected live base ${BASE_SHA}"
  for rel in "${FILES[@]}"; do
    download "${RAW_PIN}/${rel}" "$STAGE/pin/$rel"
    download "${RAW_BASE}/${rel}" "$STAGE/base/$rel"
    php -l "$STAGE/pin/$rel"
    pin_sum=$(md5_of "$STAGE/pin/$rel")
    base_sum=$(md5_of "$STAGE/base/$rel")
    if [[ ! -f "$APP/$rel" ]]; then
      say "FILE $rel MISSING REFUSE"
      refuse=1
      continue
    fi
    live_sum=$(md5_of "$APP/$rel")
    if [[ "$live_sum" == "$base_sum" ]]; then
      say "FILE $rel live_md5=$live_sum matches deploy13 ${BASE_SHA}. pin_md5=$pin_sum"
    elif [[ "$live_sum" == "$pin_sum" ]]; then
      say "FILE $rel live_md5=$live_sum already this pin"
    else
      say "FILE $rel live_md5=$live_sum base_md5=$base_sum pin_md5=$pin_sum DIFFER REFUSE"
      refuse=1
    fi
  done
  if [[ "$refuse" -ne 0 ]]; then
    say "CHECK REFUSED"
    return 1
  fi
  say "CHECK OK"
}

smoke() {
  PR9_APP="$APP" php <<'PHP'
<?php
$app = getenv('PR9_APP');
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
try {
    require $app . '/db.php';
    require $app . '/engine/outstanding_wages_lib.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('db.php did not create $pdo');
    }
    ow_ensure($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM admin_outstanding_wages WHERE org_id = ?');
    $st->execute([2]);
    $st->fetchAll();
    $st->closeCursor();
    echo "OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
PHP
}

say "deploy14 ${MODE}"
STAGE=$(mktemp -d "$BACKUP_DIR/pr14-stage.XXXXXX")
mkdir -p "$STAGE/pin/engine" "$STAGE/base/engine"
if ! preflight; then
  exit 1
fi
if [[ "$MODE" == "check" ]]; then
  say "check did not copy files and did not change the database."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
CODE_BAK="$BACKUP_DIR/pre-pr14-pending_${TS}.tar.gz"
ROLLBACK="$BACKUP_DIR/pre-pr14-pending_${TS}-rollback.sh"
umask 077
say "backing up the two PHP files"
tar -C "$APP" -czf "$CODE_BAK" "${FILES[@]}"
sz=$(stat -c '%s' "$CODE_BAK")
if [[ "$sz" -lt 1024 ]]; then
  die "Backup $CODE_BAK is ${sz} bytes."
fi
say "backup $CODE_BAK size=$sz"

for rel in "${FILES[@]}"; do
  live_sum=$(md5_of "$APP/$rel")
  base_sum=$(md5_of "$STAGE/base/$rel")
  pin_sum=$(md5_of "$STAGE/pin/$rel")
  if [[ "$live_sum" != "$base_sum" && "$live_sum" != "$pin_sum" ]]; then
    die "Live ${rel} changed after check. Stopped before copying. Backup: ${CODE_BAK}"
  fi
done

owner=$(stat -c '%U:%G' "$APP/outstanding_wages.php")
for rel in "${FILES[@]}"; do
  install -o "${owner%%:*}" -g "${owner##*:}" -m 644 "$STAGE/pin/$rel" "$APP/$rel"
  say "copied $rel"
done

say "php -l on the live copies"
for rel in "${FILES[@]}"; do
  php -l "$APP/$rel"
done

say "storage smoke test"
if ! smoke; then
  die "Storage setup failed after the copy. Restore with: tar -xzf ${CODE_BAK} -C ${APP}"
fi

code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 25 "${PUBLIC_BASE}/outstanding_wages.php" || echo "000")
say "curl ${PUBLIC_BASE}/outstanding_wages.php HTTP ${code}"
if [[ "$code" != "302" ]]; then
  die "Expected HTTP 302 without a session, got ${code}. Restore with: tar -xzf ${CODE_BAK} -C ${APP}"
fi

cat > "$ROLLBACK" <<EOF
#!/bin/bash
# Restores the two Outstanding Wages PHP files from before deploy14.
# Does not change table rows or columns.
set -euo pipefail
tar -xzf $(printf '%q' "$CODE_BAK") -C $(printf '%q' "$APP")
echo "PHP restored from $(printf '%q' "$CODE_BAK")"
EOF
chmod 700 "$ROLLBACK"

say "GO OK"
say "backup:"
say "  $CODE_BAK"
say "rollback:"
say "  bash $ROLLBACK"
echo "----- rollback script -----"
cat "$ROLLBACK"
echo "----- end rollback -----"
