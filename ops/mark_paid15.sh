#!/bin/bash
# ops/mark_paid15.sh — mark 10 outstanding-wage rows Paid for org 2.
# Modes: check (read-only) or go. Run as root from the WHM terminal.
#
#   bash mark_paid15.sh check
#   bash mark_paid15.sh go
#
# Updates admin_outstanding_wages.status to Paid and appends a note.
# Writes the same kind of audit rows the page writes. Does not create a
# pay run and does not change any other table's rows.
# Download this file by the commit SHA reported with it.

set -euo pipefail

APP=/home/renocon/public_html/payroll
BACKUP_DIR=/root/payroll-daily
NOTE='marked paid per son sheet v5, approved by Abdul 2026-11-13'

MODE="${1:-}"
if [[ "$MODE" != "check" && "$MODE" != "go" ]]; then
  echo "Usage: bash mark_paid15.sh check|go" >&2
  exit 2
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this as root from the WHM terminal." >&2
  exit 2
fi
if [[ ! -f "$APP/db.php" ]]; then
  echo "Live app root is missing db.php ($APP)." >&2
  exit 2
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/mark_paid15.lock"
if ! flock -n 9; then
  echo "Another mark_paid15.sh is already running." >&2
  exit 2
fi

say() { printf '%s\n' "$*"; }

dump_wages_table() {
  local out="$1" err="$BACKUP_DIR/pre-markpaid15-mysqldump.err"
  if mysql --batch -N -e 'SELECT 1' admin_payroll >/dev/null 2>"$err"; then
    mysqldump --single-transaction --quick --default-character-set=utf8mb4 admin_payroll admin_outstanding_wages 2>"$err" | gzip -c > "$out"
    rm -f "$err"
    return 0
  fi
  local cnf
  cnf=$(mktemp "$BACKUP_DIR/.markpaid15-my.XXXXXX")
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
    echo "Could not read MySQL credentials. No rows were changed." >&2
    exit 1
  fi
  local dbname
  dbname=$(cat "$cnf.db")
  mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --default-character-set=utf8mb4 "$dbname" admin_outstanding_wages 2>"$err" | gzip -c > "$out"
  rm -f "$cnf" "$cnf.db" "$err"
}

run_php() {
  local action="$1" rollback="${2:-}"
  MARK_ACTION="$action" MARK_APP="$APP" MARK_NOTE="$NOTE" MARK_ROLLBACK="$rollback" php <<'PHP'
<?php
$app = getenv('MARK_APP');
$action = getenv('MARK_ACTION');
$note = getenv('MARK_NOTE');
$rollbackFile = getenv('MARK_ROLLBACK') ?: '';
chdir($app);
$_SERVER['DOCUMENT_ROOT'] = $app;
$_SERVER['HTTP_HOST'] = 'payroll.renocon.ca';
require $app . '/db.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "db.php did not create \$pdo\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
}

function mark_finish($result): void
{
    if ($result instanceof PDOStatement) {
        $result->fetchAll();
        $result->closeCursor();
    }
}

function mark_columns(PDO $pdo): array
{
    $st = $pdo->query('SHOW COLUMNS FROM admin_outstanding_wages');
    $cols = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
    if ($st instanceof PDOStatement) {
        $st->closeCursor();
    }
    return array_flip($cols);
}

function mark_norm(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function mark_store_ok(array $row, string $want): bool
{
    $site = (int)($row['site_id'] ?? 0);
    $flat = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)($row['store_name'] ?? '')));
    if ($want === 'Harvey') {
        if ($site === 23) return true;
        if ($site === 11) return false;
        return strpos($flat, 'harvey') !== false;
    }
    if ($want === 'Westbank') {
        if ($site === 11) return true;
        if ($site === 23) return false;
        return strpos($flat, 'westbank') !== false;
    }
    return false;
}

function mark_bucket(array $row): string
{
    if (mark_store_ok($row, 'Harvey')) return 'Harvey';
    if (mark_store_ok($row, 'Westbank')) return 'Westbank';
    $name = trim((string)($row['store_name'] ?? ''));
    if ($name !== '') return $name;
    $site = (int)($row['site_id'] ?? 0);
    return $site > 0 ? ('site ' . $site) : '(no store)';
}

function mark_targets(): array
{
    return [
        ['store' => 'Westbank', 'employee' => 'Rishi', 'period' => 'May 16-31', 'gross' => '3148.00'],
        ['store' => 'Harvey', 'employee' => 'Aaditya', 'period' => 'Feb 16-28', 'gross' => '1118.13'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => '2026-05-01 (May 1-15, 2026)', 'gross' => '2712.06'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => '2026-05-02 (May 16-31, 2026)', 'gross' => '2629.77'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => '2026-06-01 (Jun 1-15, 2026)', 'gross' => '3199.02'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => 'Sept 1', 'gross' => '2717.13'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => 'Sept 2', 'gross' => '1350.38'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => '2026-10-01 (Oct 1-15, 2026)', 'gross' => '1030.46'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => '2026-10-02 (Oct 16-31, 2026)', 'gross' => '1568.75'],
        ['store' => 'Harvey', 'employee' => 'Arshpreet Kaur', 'period' => 'Nov 1-Mar 2', 'gross' => '7331.99'],
    ];
}

function mark_load(PDO $pdo, bool $lock): array
{
    $sql = 'SELECT * FROM admin_outstanding_wages WHERE org_id = 2';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($st instanceof PDOStatement) {
        $st->closeCursor();
    }
    return $rows;
}

function mark_find(array $rows, array $target): array
{
    $wantEmp = mark_norm($target['employee']);
    $wantPeriod = mark_norm($target['period']);
    $hits = [];
    foreach ($rows as $row) {
        if ((int)$row['org_id'] !== 2) continue;
        if (!mark_store_ok($row, $target['store'])) continue;
        if (mark_norm((string)$row['employee_name']) !== $wantEmp) continue;
        if (mark_norm((string)$row['pay_period']) !== $wantPeriod) continue;
        if (number_format((float)$row['gross'], 2, '.', '') !== $target['gross']) continue;
        $hits[] = $row;
    }
    return $hits;
}

function mark_pending_amount(array $row, bool $hasVacation): float
{
    $amount = (float)$row['gross'];
    if ($hasVacation && $row['vacation_pay'] !== null && $row['vacation_pay'] !== '') {
        $amount += (float)$row['vacation_pay'];
    }
    return round($amount, 2);
}

function mark_print_pending(array $rows, bool $hasVacation, string $label): void
{
    $buckets = [];
    $overall = 0.0;
    $count = 0;
    foreach ($rows as $row) {
        if ((string)$row['status'] !== 'Pending') continue;
        $bucket = mark_bucket($row);
        if (!isset($buckets[$bucket])) {
            $buckets[$bucket] = 0.0;
        }
        $amount = mark_pending_amount($row, $hasVacation);
        $buckets[$bucket] = round($buckets[$bucket] + $amount, 2);
        $overall = round($overall + $amount, 2);
        $count++;
    }
    ksort($buckets);
    echo $label . " pending rows " . $count;
    echo $hasVacation ? " (gross + vacation pay)\n" : " (gross only; vacation_pay column is absent)\n";
    foreach ($buckets as $name => $amount) {
        echo "PENDING\t" . $name . "\t" . number_format($amount, 2, '.', '') . "\n";
    }
    echo "PENDING\toverall\t" . number_format($overall, 2, '.', '') . "\n";
    if ($label === 'after') {
        $harvey = $buckets['Harvey'] ?? null;
        $west = $buckets['Westbank'] ?? null;
        $harveyOk = $harvey !== null && abs($harvey - 122232.05) < 0.001;
        $westOk = $west !== null && $west >= 72214.14 && $west <= 72214.15;
        $allOk = abs($overall - 276001.08) < 0.02;
        echo 'EXPECTED Harvey 122232.05 ' . ($harveyOk ? 'OK' : 'CHECK') . "\n";
        echo 'EXPECTED Westbank 72214.14 to 72214.15 ' . ($westOk ? 'OK' : 'CHECK') . "\n";
        echo 'EXPECTED overall about 276001.08 ' . ($allOk ? 'OK' : 'CHECK') . "\n";
    }
}

function mark_note(string $existing, string $note): string
{
    $existing = str_replace(["\r\n", "\r"], "\n", $existing);
    if ($existing === '') return $note;
    if (strpos($existing, $note) !== false) return $existing;
    return rtrim($existing) . "\n" . $note;
}

$cols = mark_columns($pdo);
if (!isset($cols['status'], $cols['store_name'], $cols['employee_name'], $cols['pay_period'], $cols['gross'], $cols['org_id'])) {
    fwrite(STDERR, "admin_outstanding_wages is missing a match column.\n");
    exit(1);
}
$hasVacation = isset($cols['vacation_pay']);
$hasNotes = isset($cols['notes']);
$hasUpdatedAt = isset($cols['updated_at']);
$hasUpdatedBy = isset($cols['updated_by']);
$editSt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
);
$editSt->execute(['admin_outstanding_wage_edits']);
$hasEdits = (int)$editSt->fetchColumn() > 0;
$editSt->closeCursor();

$rows = mark_load($pdo, false);
$plan = [];
$fail = 0;
$seen = [];
foreach (mark_targets() as $i => $target) {
    $n = $i + 1;
    $hits = mark_find($rows, $target);
    $label = $target['store'] . ' | ' . $target['employee'] . ' | ' . $target['period'] . ' | ' . $target['gross'];
    if (count($hits) !== 1) {
        $ids = [];
        foreach ($hits as $hit) $ids[] = (int)$hit['id'];
        echo $n . " FAIL matches=" . count($hits) . ($ids ? ' ids=' . implode(',', $ids) : '') . ' ' . $label . "\n";
        $fail++;
        continue;
    }
    $hit = $hits[0];
    $id = (int)$hit['id'];
    if (isset($seen[$id])) {
        echo $n . " FAIL id=$id already matched another target\n";
        $fail++;
        continue;
    }
    $seen[$id] = true;
    $status = (string)$hit['status'];
    echo $n . ' id=' . $id
        . ' status=' . $status
        . ' store=' . str_replace(["\t", "\n"], ' ', (string)$hit['store_name'])
        . ' site=' . (int)($hit['site_id'] ?? 0)
        . ' employee=' . str_replace(["\t", "\n"], ' ', (string)$hit['employee_name'])
        . ' period=' . str_replace(["\t", "\n"], ' ', (string)$hit['pay_period'])
        . ' gross=' . number_format((float)$hit['gross'], 2, '.', '')
        . "\n";
    if ($status !== 'Pending') {
        echo $n . " FAIL id=$id status is $status, not Pending\n";
        $fail++;
        continue;
    }
    $plan[] = $hit;
}
mark_print_pending($rows, $hasVacation, $action === 'apply' ? 'before' : 'current');
if ($fail > 0 || count($plan) !== 10) {
    fwrite(STDERR, "Need exactly 10 pending rows. Found " . count($plan) . " with $fail problem(s).\n");
    exit(1);
}
if ($action !== 'apply') {
    echo "CHECK OK\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $locked = mark_load($pdo, true);
    $ids = [];
    foreach (mark_targets() as $target) {
        $hits = mark_find($locked, $target);
        if (count($hits) !== 1 || (string)$hits[0]['status'] !== 'Pending') {
            throw new RuntimeException('A match changed before the update. Nothing was saved.');
        }
        $ids[] = (int)$hits[0]['id'];
    }
    $ids = array_values(array_unique($ids));
    if (count($ids) !== 10) {
        throw new RuntimeException('The 10 matches are not 10 different rows. Nothing was saved.');
    }
    $byId = [];
    foreach ($locked as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $sets = ['status = ?'];
    if ($hasNotes) $sets[] = 'notes = ?';
    if ($hasUpdatedAt) $sets[] = 'updated_at = NOW()';
    if ($hasUpdatedBy) $sets[] = 'updated_by = ?';
    $upd = $pdo->prepare(
        'UPDATE admin_outstanding_wages SET ' . implode(', ', $sets) . ' WHERE id = ? AND org_id = 2 AND status = ?'
    );
    $log = null;
    if ($hasEdits) {
        $log = $pdo->prepare(
            'INSERT INTO admin_outstanding_wage_edits (wage_id, org_id, actor_id, field_name, before_value, after_value, created_at) VALUES (?,?,?,?,?,?,NOW())'
        );
    }
    $affected = 0;
    $auditIds = [];
    $restore = [];
    foreach ($ids as $id) {
        $row = $byId[$id];
        $oldNotes = (string)($row['notes'] ?? '');
        $newNotes = mark_note($oldNotes, $note);
        $params = ['Paid'];
        if ($hasNotes) $params[] = $newNotes;
        if ($hasUpdatedBy) $params[] = 0;
        $params[] = $id;
        $params[] = 'Pending';
        $upd->execute($params);
        $changed = $upd->rowCount();
        if ($changed !== 1) {
            throw new RuntimeException('Row ' . $id . ' changed ' . $changed . ' rows. Rolled back.');
        }
        $affected += $changed;
        if ($log) {
            $log->execute([$id, 2, 0, 'status', (string)$row['status'], 'Paid']);
            $auditIds[] = (int)$pdo->lastInsertId();
            if ($hasNotes && $newNotes !== $oldNotes) {
                $log->execute([$id, 2, 0, 'notes', $oldNotes, $newNotes]);
                $auditIds[] = (int)$pdo->lastInsertId();
            }
        }
        $restore[] = [
            'id' => $id,
            'status' => (string)$row['status'],
            'notes' => $oldNotes,
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
        ];
    }
    if ($affected !== 10) {
        throw new RuntimeException('Affected ' . $affected . ' rows, not 10. Rolled back.');
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($rollbackFile !== '') {
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '$app = ' . var_export($app, true) . ';';
    $lines[] = 'chdir($app);';
    $lines[] = 'require $app . "/db.php";';
    $lines[] = 'if (!isset($pdo) || !($pdo instanceof PDO)) { fwrite(STDERR, "db.php did not create \\$pdo\\n"); exit(1); }';
    $lines[] = '$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);';
    $lines[] = '$pdo->beginTransaction();';
    $lines[] = 'try {';
    $lines[] = '    $upd = $pdo->prepare("UPDATE admin_outstanding_wages SET status = ?, notes = ?, updated_at = ?, updated_by = ? WHERE id = ? AND org_id = 2");';
    foreach ($restore as $item) {
        $lines[] = '    $upd->execute(['
            . var_export($item['status'], true) . ', '
            . var_export($hasNotes ? $item['notes'] : '', true) . ', '
            . var_export($item['updated_at'], true) . ', '
            . ($item['updated_by'] === null ? 'null' : (int)$item['updated_by']) . ', '
            . (int)$item['id']
            . ']);';
    }
    if ($hasEdits && $auditIds) {
        $lines[] = '    $del = $pdo->prepare("DELETE FROM admin_outstanding_wage_edits WHERE id = ? AND org_id = 2");';
        foreach ($auditIds as $auditId) {
            $lines[] = '    $del->execute([' . (int)$auditId . ']);';
        }
    }
    $lines[] = '    $pdo->commit();';
    $lines[] = '    echo "restored previous status for 10 rows\\n";';
    $lines[] = '} catch (Throwable $e) {';
    $lines[] = '    if ($pdo->inTransaction()) { $pdo->rollBack(); }';
    $lines[] = '    fwrite(STDERR, $e->getMessage() . "\\n");';
    $lines[] = '    exit(1);';
    $lines[] = '}';
    file_put_contents($rollbackFile, implode("\n", $lines) . "\n");
    chmod($rollbackFile, 0700);
}

$after = mark_load($pdo, false);
echo "UPDATED 10\n";
mark_print_pending($after, $hasVacation, 'after');
echo "GO OK\n";
if ($rollbackFile !== '') {
    echo "ROLLBACK php " . $rollbackFile . "\n";
}
PHP
}

say "mark_paid15 ${MODE} org 2"
if ! run_php check; then
  exit 1
fi
if [[ "$MODE" == "check" ]]; then
  say "check did not change any rows."
  exit 0
fi

TS=$(date +%Y%m%d_%H%M%S)
DB_BAK="$BACKUP_DIR/pre-markpaid15_${TS}.sql.gz"
ROLLBACK="$BACKUP_DIR/pre-markpaid15_${TS}-rollback.php"
umask 077
say "dumping admin_outstanding_wages before the update"
dump_wages_table "$DB_BAK"
sz=$(stat -c '%s' "$DB_BAK")
if [[ "$sz" -lt 128 ]]; then
  echo "Backup $DB_BAK is ${sz} bytes. No rows were changed." >&2
  exit 1
fi
gzip -t "$DB_BAK"
say "backup $DB_BAK size=$sz"

if ! run_php apply "$ROLLBACK"; then
  echo "Update failed. The dump is $DB_BAK and no status change was committed." >&2
  exit 1
fi
say "table dump: $DB_BAK"
