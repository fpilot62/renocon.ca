<?php
/**
 * Outstanding pay loaded from a private CSV, plus manual period overrides
 * and manual dated payments.
 *
 * Writes only payroll_outstanding_summary, payroll_outstanding_periods,
 * payroll_outstanding_period_amounts, payroll_outstanding_payments, and
 * payroll_outstanding_summary_audit.
 * Never writes payroll_finalized or payroll_payments. Never approves a row
 * and never marks anyone paid.
 *
 * A period cell defaults to payroll_finalized.total_gross minus
 * payroll_finalized.cpp minus payroll_finalized.ei for finalized lines whose
 * period start falls in that half-month. Income tax, cpp2, and vacation are
 * not used. Vacation pay is not a column.
 */

require_once __DIR__ . '/payroll_sheet_review_lib.php';
require_once __DIR__ . '/sheet_hide_lib.php';

function pos_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready || defined('POS_SKIP_MIGRATION')) {
        return;
    }
    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_outstanding_summary (
                id INTEGER PRIMARY KEY,
                org_id INT NOT NULL,
                store_label TEXT NOT NULL,
                sheet_employee TEXT NOT NULL,
                user_id INT NULL,
                match_confidence TEXT NULL,
                total_amount REAL NOT NULL,
                notes TEXT NULL,
                source_file TEXT NULL,
                loaded_at TEXT NOT NULL,
                loaded_by INT NOT NULL DEFAULT 0,
                row_version INT NOT NULL DEFAULT 1,
                UNIQUE (org_id, store_label, sheet_employee)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_outstanding_periods (
                id INTEGER PRIMARY KEY,
                org_id INT NOT NULL,
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                added_by INT NOT NULL DEFAULT 0,
                added_at TEXT NOT NULL,
                UNIQUE (org_id, period_start, period_end)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_outstanding_period_amounts (
                id INTEGER PRIMARY KEY,
                summary_id INT NOT NULL,
                period_id INT NOT NULL,
                amount REAL NULL,
                source TEXT NOT NULL DEFAULT "manual",
                edited_by INT NOT NULL DEFAULT 0,
                edited_at TEXT NOT NULL,
                row_version INT NOT NULL DEFAULT 1,
                UNIQUE (summary_id, period_id)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_outstanding_payments (
                id INTEGER PRIMARY KEY,
                summary_id INT NOT NULL,
                paid_date TEXT NOT NULL,
                amount REAL NOT NULL,
                method TEXT NULL,
                reference_no TEXT NULL,
                note TEXT NULL,
                created_by INT NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                edited_by INT NULL,
                edited_at TEXT NULL,
                deleted INT NOT NULL DEFAULT 0,
                deleted_at TEXT NULL,
                row_version INT NOT NULL DEFAULT 1
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payroll_outstanding_summary_audit (
                id INTEGER PRIMARY KEY,
                org_id INT NOT NULL,
                summary_id INT NOT NULL DEFAULT 0,
                target_type TEXT NOT NULL DEFAULT "summary",
                target_id INT NOT NULL DEFAULT 0,
                batch_key TEXT NOT NULL DEFAULT "",
                action TEXT NOT NULL DEFAULT "edit",
                field_name TEXT NOT NULL DEFAULT "",
                old_value TEXT NULL,
                new_value TEXT NULL,
                actor_id INT NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )'
        );
        $ready = true;
        return;
    }
    $file = dirname(__DIR__) . '/migrations/20261127_payroll_outstanding_summary.sql';
    $sql = is_file($file) ? file_get_contents($file) : false;
    if ($sql === false) {
        throw new RuntimeException('The outstanding summary migration file is missing.');
    }
    $sql = preg_replace('/^--.*$/m', '', (string)$sql);
    foreach (preg_split('/;/', (string)$sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    $ready = true;
}

function pos_audit(PDO $pdo, int $orgId, int $summaryId, string $targetType, int $targetId, string $batch, string $action, string $field, ?string $old, ?string $new, int $actor): void
{
    $pdo->prepare(
        'INSERT INTO payroll_outstanding_summary_audit
            (org_id, summary_id, target_type, target_id, batch_key, action, field_name, old_value, new_value, actor_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$orgId, $summaryId, $targetType, $targetId, $batch, $action, $field, $old, $new, $actor, date('Y-m-d H:i:s')]);
}

function pos_month_name(int $month): string
{
    $names = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return $names[$month] ?? '';
}

function pos_period_label(string $start, string $end): string
{
    $a = DateTime::createFromFormat('Y-m-d', $start);
    $b = DateTime::createFromFormat('Y-m-d', $end);
    if (!$a || !$b) {
        return $start . '–' . $end;
    }
    return pos_month_name((int)$a->format('n')) . ' ' . (int)$a->format('j') . '–' . (int)$b->format('j');
}

/** @return list<array{0:string,1:string}> */
function pos_month_halves(int $year, int $month): array
{
    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
        throw new RuntimeException('That month is not valid.');
    }
    $last = (int)DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month))->format('t');
    return [
        [sprintf('%04d-%02d-01', $year, $month), sprintf('%04d-%02d-15', $year, $month)],
        [sprintf('%04d-%02d-16', $year, $month), sprintf('%04d-%02d-%02d', $year, $month, $last)],
    ];
}

function pos_add_period(PDO $pdo, int $orgId, string $start, string $end, int $actor): int
{
    pos_ensure($pdo);
    $start = psr_valid_date($start) ?? '';
    $end = psr_valid_date($end) ?? '';
    if ($start === '' || $end === '' || $start > $end) {
        throw new RuntimeException('That period is not a valid date range.');
    }
    $have = $pdo->prepare('SELECT id FROM payroll_outstanding_periods WHERE org_id = ? AND period_start = ? AND period_end = ?');
    $have->execute([$orgId, $start, $end]);
    $id = (int)$have->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    $pdo->prepare('INSERT INTO payroll_outstanding_periods (org_id, period_start, period_end, added_by, added_at) VALUES (?,?,?,?,?)')
        ->execute([$orgId, $start, $end, $actor, date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    pos_audit($pdo, $orgId, 0, 'period', $id, bin2hex(random_bytes(8)), 'add_period', 'period', null, $start . ' to ' . $end, $actor);
    return $id;
}

function pos_seed_default_periods(PDO $pdo, int $orgId, int $actor, DateTimeInterface $today): void
{
    pos_ensure($pdo);
    $count = $pdo->prepare('SELECT COUNT(*) FROM payroll_outstanding_periods WHERE org_id = ?');
    $count->execute([$orgId]);
    if ((int)$count->fetchColumn() > 0) {
        return;
    }
    foreach (pos_month_halves((int)$today->format('Y'), (int)$today->format('n')) as $half) {
        pos_add_period($pdo, $orgId, $half[0], $half[1], $actor);
    }
}

function pos_remove_period(PDO $pdo, int $orgId, int $periodId, int $actor): void
{
    pos_ensure($pdo);
    $st = $pdo->prepare('SELECT * FROM payroll_outstanding_periods WHERE id = ? AND org_id = ?');
    $st->execute([$periodId, $orgId]);
    $period = $st->fetch(PDO::FETCH_ASSOC);
    if (!$period) {
        throw new RuntimeException('That period is not in this company. Nothing was removed.');
    }
    $pdo->beginTransaction();
    try {
        $amounts = $pdo->prepare(
            'SELECT a.id, a.summary_id, a.amount, s.org_id
             FROM payroll_outstanding_period_amounts a
             INNER JOIN payroll_outstanding_summary s ON s.id = a.summary_id
             WHERE a.period_id = ? AND s.org_id = ?'
        );
        $amounts->execute([$periodId, $orgId]);
        $batch = bin2hex(random_bytes(8));
        foreach ($amounts->fetchAll(PDO::FETCH_ASSOC) as $amount) {
            pos_audit($pdo, $orgId, (int)$amount['summary_id'], 'period_amount', (int)$amount['id'], $batch, 'remove_period', 'amount', $amount['amount'] !== null ? (string)$amount['amount'] : null, null, $actor);
        }
        $pdo->prepare('DELETE FROM payroll_outstanding_period_amounts WHERE period_id = ?')->execute([$periodId]);
        $pdo->prepare('DELETE FROM payroll_outstanding_periods WHERE id = ? AND org_id = ?')->execute([$periodId, $orgId]);
        pos_audit($pdo, $orgId, 0, 'period', $periodId, $batch, 'remove_period', 'period', $period['period_start'] . ' to ' . $period['period_end'], null, $actor);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return list<array{store:string,sheet_employee:string,total_amount:float,notes:string,user_id:int,match_confidence:string}> */
function pos_parse_csv(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $handle = fopen('php://memory', 'r+');
    if ($handle === false) {
        throw new RuntimeException('The CSV could not be read.');
    }
    fwrite($handle, $text);
    rewind($handle);
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        throw new RuntimeException('The CSV has no header.');
    }
    $map = [];
    foreach ($header as $i => $name) {
        $map[strtolower(trim((string)$name))] = $i;
    }
    foreach (['store', 'sheet_employee', 'total_amount'] as $need) {
        if (!array_key_exists($need, $map)) {
            fclose($handle);
            throw new RuntimeException('The CSV header needs a ' . $need . ' column.');
        }
    }
    $rows = [];
    $seen = [];
    $line = 1;
    while (($cols = fgetcsv($handle)) !== false) {
        $line++;
        if ($cols === [null] || (count($cols) === 1 && trim((string)$cols[0]) === '')) {
            continue;
        }
        $pick = static function (string $name) use ($map, $cols): string {
            if (!array_key_exists($name, $map)) {
                return '';
            }
            return trim((string)($cols[$map[$name]] ?? ''));
        };
        $store = substr($pick('store'), 0, 160);
        $name = substr($pick('sheet_employee'), 0, 160);
        if ($store === '' || $name === '') {
            fclose($handle);
            throw new RuntimeException('Row ' . $line . ' needs a store and an employee name.');
        }
        $amount = psr_parse_amount($pick('total_amount'));
        if (!$amount['ok'] || $amount['value'] === null) {
            fclose($handle);
            throw new RuntimeException('Row ' . $line . ' has no total amount. Nothing was guessed.');
        }
        $userRaw = $pick('user_id');
        $userId = 0;
        if ($userRaw !== '') {
            if (!preg_match('/^\d+$/', $userRaw) || (int)$userRaw <= 0) {
                fclose($handle);
                throw new RuntimeException('Row ' . $line . ' has a user id that is not a positive number.');
            }
            $userId = (int)$userRaw;
        }
        $key = strtolower($store) . "\n" . strtolower($name);
        if (isset($seen[$key])) {
            fclose($handle);
            throw new RuntimeException('Row ' . $line . ' repeats ' . $store . ' / ' . $name . '.');
        }
        $seen[$key] = true;
        $rows[] = [
            'store' => $store,
            'sheet_employee' => $name,
            'total_amount' => (float)$amount['value'],
            'notes' => $pick('notes'),
            'user_id' => $userId,
            'match_confidence' => substr($pick('match_confidence'), 0, 40),
        ];
    }
    fclose($handle);
    if (!$rows) {
        throw new RuntimeException('The CSV has no data rows.');
    }
    return $rows;
}

function pos_totals(array $rows): array
{
    $stores = [];
    $grand = 0.0;
    foreach ($rows as $row) {
        $store = (string)$row['store'];
        if (!isset($stores[$store])) {
            $stores[$store] = ['count' => 0, 'total' => 0.0];
        }
        $stores[$store]['count']++;
        $stores[$store]['total'] = round($stores[$store]['total'] + (float)$row['total_amount'], 2);
        $grand = round($grand + (float)$row['total_amount'], 2);
    }
    ksort($stores);
    return ['rows' => count($rows), 'stores' => $stores, 'grand' => $grand];
}

function pos_format_report(array $report, bool $dry, int $inserted, int $updated): string
{
    $lines = [];
    $lines[] = 'rows ' . (int)$report['rows'];
    $lines[] = 'inserted ' . $inserted;
    $lines[] = 'updated ' . $updated;
    foreach ($report['stores'] as $store => $part) {
        $lines[] = 'store ' . $store . ': ' . (int)$part['count'] . ' row(s), ' . number_format((float)$part['total'], 2, '.', '');
    }
    $lines[] = 'grand ' . number_format((float)$report['grand'], 2, '.', '');
    $lines[] = $dry ? 'dry-run: wrote nothing' : 'loaded';
    return implode("\n", $lines) . "\n";
}

function pos_user_in_org(PDO $pdo, int $orgId, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $st = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ? LIMIT 1');
    $st->execute([$userId, $orgId]);
    return (bool)$st->fetchColumn();
}

function pos_load(PDO $pdo, int $orgId, array $rows, int $actor, string $sourceFile, bool $dry): array
{
    pos_ensure($pdo);
    if ($orgId <= 0) {
        throw new RuntimeException('No company was selected.');
    }
    foreach ($rows as $row) {
        if ((int)$row['user_id'] > 0 && !pos_user_in_org($pdo, $orgId, (int)$row['user_id'])) {
            throw new RuntimeException($row['sheet_employee'] . ' is linked to user ' . (int)$row['user_id'] . ', who is not in this company. Nothing was written.');
        }
    }
    $report = pos_totals($rows);
    if ($dry) {
        return $report + ['inserted' => 0, 'updated' => 0, 'dry' => true];
    }
    $sourceFile = substr(basename($sourceFile), 0, 255);
    $now = date('Y-m-d H:i:s');
    $inserted = 0;
    $updated = 0;
    $find = $pdo->prepare('SELECT id, user_id, row_version FROM payroll_outstanding_summary WHERE org_id = ? AND store_label = ? AND sheet_employee = ? LIMIT 1');
    $ins = $pdo->prepare(
        'INSERT INTO payroll_outstanding_summary
            (org_id, store_label, sheet_employee, user_id, match_confidence, total_amount, notes, source_file, loaded_at, loaded_by, row_version)
         VALUES (?,?,?,?,?,?,?,?,?,?,1)'
    );
    $upd = $pdo->prepare(
        'UPDATE payroll_outstanding_summary
            SET user_id = ?, match_confidence = ?, total_amount = ?, notes = ?, source_file = ?, loaded_at = ?, loaded_by = ?, row_version = row_version + 1
          WHERE id = ? AND org_id = ? AND row_version = ?'
    );
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $find->execute([$orgId, $row['store'], $row['sheet_employee']]);
            $have = $find->fetch(PDO::FETCH_ASSOC);
            $userId = (int)$row['user_id'] > 0 ? (int)$row['user_id'] : null;
            if (!$have) {
                $ins->execute([
                    $orgId, $row['store'], $row['sheet_employee'], $userId,
                    $row['match_confidence'] !== '' ? $row['match_confidence'] : null,
                    $row['total_amount'], $row['notes'] !== '' ? $row['notes'] : null,
                    $sourceFile, $now, $actor,
                ]);
                $inserted++;
                continue;
            }
            if ($userId === null && $have['user_id'] !== null && (int)$have['user_id'] > 0) {
                $userId = (int)$have['user_id'];
            }
            $confidence = $row['match_confidence'] !== '' ? $row['match_confidence'] : null;
            $upd->execute([
                $userId, $confidence, $row['total_amount'],
                $row['notes'] !== '' ? $row['notes'] : null,
                $sourceFile, $now, $actor,
                (int)$have['id'], $orgId, (int)$have['row_version'],
            ]);
            if ($upd->rowCount() < 1) {
                throw new RuntimeException('A summary row changed during the load. Nothing in this load was kept.');
            }
            $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $report + ['inserted' => $inserted, 'updated' => $updated, 'dry' => false];
}

function pos_summary_for_org(PDO $pdo, int $orgId, int $summaryId): array
{
    $st = $pdo->prepare('SELECT * FROM payroll_outstanding_summary WHERE id = ? AND org_id = ?');
    $st->execute([$summaryId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('That summary row is not in this company. Nothing was saved.');
    }
    return $row;
}

function pos_link_employee(PDO $pdo, int $orgId, int $summaryId, int $actor, int $userId, int $rowVersion, bool $allowHidden): void
{
    pos_ensure($pdo);
    if ($userId > 0 && !pos_user_in_org($pdo, $orgId, $userId)) {
        throw new RuntimeException('That employee is not in this company. Nothing was saved.');
    }
    if ($userId > 0 && !$allowHidden) {
        $name = $pdo->prepare('SELECT Name FROM users WHERE id = ? AND org_id = ?');
        $name->execute([$userId, $orgId]);
        if (sheet_person_is_hidden($userId, (string)$name->fetchColumn())) {
            throw new RuntimeException('That user is hidden from Sheet Review. Nothing was saved.');
        }
    }
    $pdo->beginTransaction();
    try {
        $row = pos_summary_for_org($pdo, $orgId, $summaryId);
        if ((int)$row['row_version'] !== $rowVersion) {
            throw new RuntimeException('Someone saved this row after you opened it. Reload and try again. Nothing was saved.');
        }
        $old = $row['user_id'] !== null ? (string)(int)$row['user_id'] : '';
        $new = $userId > 0 ? (string)$userId : '';
        if ($old === $new) {
            $pdo->commit();
            return;
        }
        $upd = $pdo->prepare(
            'UPDATE payroll_outstanding_summary
                SET user_id = ?, match_confidence = ?, row_version = row_version + 1
              WHERE id = ? AND org_id = ? AND row_version = ?'
        );
        $upd->execute([$userId > 0 ? $userId : null, 'manual', $summaryId, $orgId, $rowVersion]);
        if ($upd->rowCount() < 1) {
            throw new RuntimeException('Someone saved this row after you opened it. Reload and try again. Nothing was saved.');
        }
        $batch = bin2hex(random_bytes(8));
        pos_audit($pdo, $orgId, $summaryId, 'summary', $summaryId, $batch, 'link_employee', 'user_id', $old !== '' ? $old : null, $new !== '' ? $new : null, $actor);
        pos_audit($pdo, $orgId, $summaryId, 'summary', $summaryId, $batch, 'link_employee', 'match_confidence', $row['match_confidence'] !== null ? (string)$row['match_confidence'] : null, 'manual', $actor);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Net after employee CPP and EI only. An empty list is blank, not zero.
 * Any missing gross, CPP, or EI makes the cell unknown.
 *
 * @param list<array{gross:?float,cpp:?float,ei:?float}> $lines
 */
function pos_cpp_ei_net(array $lines): array
{
    if (!$lines) {
        return ['state' => 'blank', 'amount' => null];
    }
    $sum = 0.0;
    foreach ($lines as $line) {
        if ($line['gross'] === null || $line['cpp'] === null || $line['ei'] === null) {
            return ['state' => 'unknown', 'amount' => null];
        }
        $sum = round($sum + (float)$line['gross'] - (float)$line['cpp'] - (float)$line['ei'], 2);
    }
    return ['state' => 'amount', 'amount' => $sum];
}

function pos_line_start(array $row): string
{
    return psr_valid_date($row['pay_period'] ?? '') ?: (psr_valid_date($row['work_date'] ?? '') ?: '');
}

/** A finalized line is counted in the half that contains its start date, so it is not added twice. */
function pos_line_in_half(string $start, string $halfStart, string $halfEnd): bool
{
    return $start !== '' && $start >= $halfStart && $start <= $halfEnd;
}

/** @return array<int,list<array{start:string,gross:?float,cpp:?float,ei:?float}>> */
function pos_fetch_lines(PDO $pdo, int $orgId, array $userIds): array
{
    $ids = [];
    foreach ($userIds as $id) {
        if ((int)$id > 0) {
            $ids[(int)$id] = true;
        }
    }
    if (!$ids) {
        return [];
    }
    require_once __DIR__ . '/attendance_integrity_lib.php';
    $live = att_finalized_live_predicate($pdo, 'f');
    $idList = array_keys($ids);
    $holders = implode(',', array_fill(0, count($idList), '?'));
    $sql = "SELECT f.user_id, f.pay_period, f.pay_period_end, f.work_date, f.total_gross, f.cpp, f.ei
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            INNER JOIN sites s ON s.SId = f.site_id AND s.org_id = u.org_id
            WHERE $live AND f.user_id IN ($holders)";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$orgId], $idList));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['user_id']][] = [
            'start' => pos_line_start($row),
            'gross' => psr_db_amount($row['total_gross'] ?? null),
            'cpp' => array_key_exists('cpp', $row) ? psr_db_amount($row['cpp']) : null,
            'ei' => array_key_exists('ei', $row) ? psr_db_amount($row['ei']) : null,
        ];
    }
    return $out;
}

function pos_period_default(array $lines, string $start, string $end): array
{
    $hits = [];
    foreach ($lines as $line) {
        if (pos_line_in_half((string)$line['start'], $start, $end)) {
            $hits[] = $line;
        }
    }
    return pos_cpp_ei_net($hits);
}

function pos_save_period_amount(PDO $pdo, int $orgId, int $summaryId, int $periodId, int $actor, $rawAmount, int $rowVersion, array $payrollDefault): void
{
    pos_ensure($pdo);
    pos_summary_for_org($pdo, $orgId, $summaryId);
    $period = $pdo->prepare('SELECT id FROM payroll_outstanding_periods WHERE id = ? AND org_id = ?');
    $period->execute([$periodId, $orgId]);
    if (!(int)$period->fetchColumn()) {
        throw new RuntimeException('That period is not in this company. Nothing was saved.');
    }
    $parsed = psr_parse_amount($rawAmount);
    if (!$parsed['ok']) {
        throw new RuntimeException('That period amount is not a number. Nothing was saved.');
    }
    $typed = $parsed['value'];
    $payrollAmount = ($payrollDefault['state'] ?? '') === 'amount' ? (float)$payrollDefault['amount'] : null;
    $revert = $typed === null || ($payrollAmount !== null && abs($typed - $payrollAmount) < 0.001);
    $pdo->beginTransaction();
    try {
        $have = $pdo->prepare('SELECT * FROM payroll_outstanding_period_amounts WHERE summary_id = ? AND period_id = ?');
        $have->execute([$summaryId, $periodId]);
        $row = $have->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['row_version'] !== $rowVersion) {
            throw new RuntimeException('Someone saved this period amount after you opened it. Reload and try again. Nothing was saved.');
        }
        if (!$row && $rowVersion !== 0) {
            throw new RuntimeException('Someone saved this period amount after you opened it. Reload and try again. Nothing was saved.');
        }
        $batch = bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');
        if ($revert) {
            if ($row) {
                $pdo->prepare('DELETE FROM payroll_outstanding_period_amounts WHERE id = ? AND summary_id = ? AND row_version = ?')
                    ->execute([(int)$row['id'], $summaryId, $rowVersion]);
                pos_audit($pdo, $orgId, $summaryId, 'period_amount', (int)$row['id'], $batch, 'revert_period', 'amount', $row['amount'] !== null ? (string)$row['amount'] : null, null, $actor);
            }
            $pdo->commit();
            return;
        }
        if ($row) {
            $upd = $pdo->prepare(
                'UPDATE payroll_outstanding_period_amounts
                    SET amount = ?, source = ?, edited_by = ?, edited_at = ?, row_version = row_version + 1
                  WHERE id = ? AND summary_id = ? AND row_version = ?'
            );
            $upd->execute([$typed, 'manual', $actor, $now, (int)$row['id'], $summaryId, $rowVersion]);
            if ($upd->rowCount() < 1) {
                throw new RuntimeException('Someone saved this period amount after you opened it. Reload and try again. Nothing was saved.');
            }
            pos_audit($pdo, $orgId, $summaryId, 'period_amount', (int)$row['id'], $batch, 'edit_period', 'amount', $row['amount'] !== null ? (string)$row['amount'] : null, (string)$typed, $actor);
        } else {
            $pdo->prepare(
                'INSERT INTO payroll_outstanding_period_amounts
                    (summary_id, period_id, amount, source, edited_by, edited_at, row_version)
                 VALUES (?,?,?,?,?,?,1)'
            )->execute([$summaryId, $periodId, $typed, 'manual', $actor, $now]);
            pos_audit($pdo, $orgId, $summaryId, 'period_amount', (int)$pdo->lastInsertId(), $batch, 'edit_period', 'amount', null, (string)$typed, $actor);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pos_payment_fields(array $post): array
{
    $date = psr_valid_date($post['paid_date'] ?? '');
    if ($date === null) {
        throw new RuntimeException('A reconciliation payment needs a date. Nothing was saved.');
    }
    $amount = psr_parse_amount($post['amount'] ?? null);
    if (!$amount['ok'] || $amount['value'] === null) {
        throw new RuntimeException('A reconciliation payment needs an amount. Nothing was saved.');
    }
    return [
        'paid_date' => $date,
        'amount' => (float)$amount['value'],
        'method' => substr(trim((string)($post['method'] ?? '')), 0, 40),
        'reference_no' => substr(trim((string)($post['reference_no'] ?? '')), 0, 80),
        'note' => trim((string)($post['note'] ?? '')),
    ];
}

function pos_add_payment(PDO $pdo, int $orgId, int $summaryId, int $actor, array $post): int
{
    pos_ensure($pdo);
    pos_summary_for_org($pdo, $orgId, $summaryId);
    $fields = pos_payment_fields($post);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO payroll_outstanding_payments
            (summary_id, paid_date, amount, method, reference_no, note, created_by, created_at, row_version, deleted)
         VALUES (?,?,?,?,?,?,?,?,1,0)'
    )->execute([
        $summaryId, $fields['paid_date'], $fields['amount'],
        $fields['method'] !== '' ? $fields['method'] : null,
        $fields['reference_no'] !== '' ? $fields['reference_no'] : null,
        $fields['note'] !== '' ? $fields['note'] : null,
        $actor, $now,
    ]);
    $id = (int)$pdo->lastInsertId();
    pos_audit($pdo, $orgId, $summaryId, 'payment', $id, bin2hex(random_bytes(8)), 'add_payment', 'amount', null, $fields['paid_date'] . ' ' . (string)$fields['amount'], $actor);
    return $id;
}

function pos_payment_for_org(PDO $pdo, int $orgId, int $paymentId): array
{
    $st = $pdo->prepare(
        'SELECT p.*, s.org_id
         FROM payroll_outstanding_payments p
         INNER JOIN payroll_outstanding_summary s ON s.id = p.summary_id
         WHERE p.id = ? AND s.org_id = ?'
    );
    $st->execute([$paymentId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['deleted'] === 1) {
        throw new RuntimeException('That reconciliation payment is not in this company. Nothing was saved.');
    }
    return $row;
}

function pos_edit_payment(PDO $pdo, int $orgId, int $paymentId, int $actor, int $rowVersion, array $post): void
{
    pos_ensure($pdo);
    $fields = pos_payment_fields($post);
    $pdo->beginTransaction();
    try {
        $row = pos_payment_for_org($pdo, $orgId, $paymentId);
        if ((int)$row['row_version'] !== $rowVersion) {
            throw new RuntimeException('Someone saved this payment after you opened it. Reload and try again. Nothing was saved.');
        }
        $upd = $pdo->prepare(
            'UPDATE payroll_outstanding_payments
                SET paid_date = ?, amount = ?, method = ?, reference_no = ?, note = ?, edited_by = ?, edited_at = ?, row_version = row_version + 1
              WHERE id = ? AND summary_id = ? AND row_version = ? AND deleted = 0'
        );
        $now = date('Y-m-d H:i:s');
        $upd->execute([
            $fields['paid_date'], $fields['amount'],
            $fields['method'] !== '' ? $fields['method'] : null,
            $fields['reference_no'] !== '' ? $fields['reference_no'] : null,
            $fields['note'] !== '' ? $fields['note'] : null,
            $actor, $now, $paymentId, (int)$row['summary_id'], $rowVersion,
        ]);
        if ($upd->rowCount() < 1) {
            throw new RuntimeException('Someone saved this payment after you opened it. Reload and try again. Nothing was saved.');
        }
        $batch = bin2hex(random_bytes(8));
        $map = ['paid_date' => 'paid_date', 'amount' => 'amount', 'method' => 'method', 'reference_no' => 'reference_no', 'note' => 'note'];
        foreach ($map as $field => $column) {
            $old = $row[$column] !== null ? (string)$row[$column] : '';
            $new = $fields[$field] !== null ? (string)$fields[$field] : '';
            if ($field === 'amount') {
                $old = $row['amount'] !== null ? (string)(float)$row['amount'] : '';
                $new = (string)$fields['amount'];
                if (abs((float)$old - (float)$new) < 0.001) {
                    continue;
                }
            } elseif ($old === $new) {
                continue;
            }
            pos_audit($pdo, $orgId, (int)$row['summary_id'], 'payment', $paymentId, $batch, 'edit_payment', $field, $old !== '' ? $old : null, $new !== '' ? $new : null, $actor);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pos_delete_payment(PDO $pdo, int $orgId, int $paymentId, int $actor, int $rowVersion): void
{
    pos_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $row = pos_payment_for_org($pdo, $orgId, $paymentId);
        if ((int)$row['row_version'] !== $rowVersion) {
            throw new RuntimeException('Someone saved this payment after you opened it. Reload and try again. Nothing was saved.');
        }
        $upd = $pdo->prepare(
            'UPDATE payroll_outstanding_payments
                SET deleted = 1, deleted_at = ?, edited_by = ?, edited_at = ?, row_version = row_version + 1
              WHERE id = ? AND summary_id = ? AND row_version = ? AND deleted = 0'
        );
        $now = date('Y-m-d H:i:s');
        $upd->execute([$now, $actor, $now, $paymentId, (int)$row['summary_id'], $rowVersion]);
        if ($upd->rowCount() < 1) {
            throw new RuntimeException('Someone saved this payment after you opened it. Reload and try again. Nothing was saved.');
        }
        pos_audit($pdo, $orgId, (int)$row['summary_id'], 'payment', $paymentId, bin2hex(random_bytes(8)), 'delete_payment', 'deleted', '0', '1', $actor);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pos_sum_payments(array $payments): array
{
    $sum = 0.0;
    $latest = '';
    $n = 0;
    foreach ($payments as $payment) {
        if ((int)($payment['deleted'] ?? 0) === 1) {
            continue;
        }
        $sum = round($sum + (float)$payment['amount'], 2);
        $date = (string)($payment['paid_date'] ?? '');
        if ($date !== '' && strcmp($date, $latest) > 0) {
            $latest = $date;
        }
        $n++;
    }
    return ['amount' => $sum, 'paid_on' => $latest, 'count' => $n];
}

function pos_empty_total(array $periodIds): array
{
    $periods = [];
    foreach ($periodIds as $id) {
        $periods[$id] = ['amount' => 0.0, 'unknown' => 0, 'known' => 0];
    }
    return [
        'total' => 0.0,
        'periods' => $periods,
        'balance' => ['amount' => 0.0, 'unknown' => 0, 'known' => 0],
        'paid' => 0.0,
        'final' => ['amount' => 0.0, 'unknown' => 0, 'known' => 0],
    ];
}

function pos_add_amount(array $tot, array $cell): array
{
    if (($cell['state'] ?? '') === 'amount' && $cell['amount'] !== null) {
        $tot['amount'] = round((float)$tot['amount'] + (float)$cell['amount'], 2);
        $tot['known']++;
    } elseif (($cell['state'] ?? '') === 'unknown') {
        $tot['unknown']++;
    }
    return $tot;
}

function pos_figure_text(array $figure): string
{
    $state = (string)($figure['state'] ?? '');
    if ($state === 'unknown' && ($figure['amount'] ?? null) === null && (int)($figure['known'] ?? 0) === 0) {
        $n = (int)($figure['unknown'] ?? 0);
        return $n > 1 ? 'unknown (' . $n . ')' : 'unknown';
    }
    if (($figure['amount'] ?? null) === null && $state !== 'amount') {
        return '';
    }
    if ((int)($figure['known'] ?? 1) === 0 && (int)($figure['unknown'] ?? 0) > 0 && ($figure['amount'] ?? null) === null) {
        return 'unknown';
    }
    $text = number_format((float)($figure['amount'] ?? 0), 2);
    if ((int)($figure['unknown'] ?? 0) > 0) {
        $text .= ' (' . (int)$figure['unknown'] . ' unknown)';
    }
    return $text;
}

function pos_review_table(PDO $pdo, int $orgId, bool $showHidden, DateTimeInterface $today, int $actor = 0): array
{
    pos_ensure($pdo);
    pos_seed_default_periods($pdo, $orgId, $actor, $today);
    $periodRows = $pdo->prepare('SELECT * FROM payroll_outstanding_periods WHERE org_id = ? ORDER BY period_start, period_end, id');
    $periodRows->execute([$orgId]);
    $periods = [];
    foreach ($periodRows->fetchAll(PDO::FETCH_ASSOC) as $period) {
        $periods[] = [
            'id' => (int)$period['id'],
            'start' => (string)$period['period_start'],
            'end' => (string)$period['period_end'],
            'label' => pos_period_label((string)$period['period_start'], (string)$period['period_end']),
        ];
    }
    $loaded = $pdo->prepare('SELECT * FROM payroll_outstanding_summary WHERE org_id = ? ORDER BY store_label, sheet_employee, id');
    $loaded->execute([$orgId]);
    $visible = [];
    foreach ($loaded->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$showHidden && sheet_person_is_hidden((int)($row['user_id'] ?? 0), (string)$row['sheet_employee'])) {
            continue;
        }
        $visible[] = $row;
    }
    $ids = array_map(static function ($row) {
        return (int)$row['id'];
    }, $visible);
    $manual = [];
    $payments = [];
    if ($ids) {
        $holders = implode(',', array_fill(0, count($ids), '?'));
        $amt = $pdo->prepare("SELECT * FROM payroll_outstanding_period_amounts WHERE summary_id IN ($holders)");
        $amt->execute($ids);
        foreach ($amt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $manual[(int)$row['summary_id']][(int)$row['period_id']] = $row;
        }
        $pay = $pdo->prepare("SELECT * FROM payroll_outstanding_payments WHERE summary_id IN ($holders) AND deleted = 0 ORDER BY paid_date, id");
        $pay->execute($ids);
        foreach ($pay->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payments[(int)$row['summary_id']][] = $row;
        }
    }
    $userIds = [];
    foreach ($visible as $row) {
        if ((int)($row['user_id'] ?? 0) > 0) {
            $userIds[] = (int)$row['user_id'];
        }
    }
    $lines = $userIds ? pos_fetch_lines($pdo, $orgId, $userIds) : [];
    $periodIds = array_map(static function ($period) {
        return $period['id'];
    }, $periods);
    $grand = pos_empty_total($periodIds);
    $groups = [];
    foreach ($visible as $row) {
        $summaryId = (int)$row['id'];
        $userId = (int)($row['user_id'] ?? 0);
        $cells = [];
        $balanceUnknown = false;
        $balance = round((float)$row['total_amount'], 2);
        foreach ($periods as $period) {
            $stored = $manual[$summaryId][$period['id']] ?? null;
            if ($stored && (string)$stored['source'] === 'manual' && $stored['amount'] !== null) {
                $cell = [
                    'state' => 'amount',
                    'amount' => round((float)$stored['amount'], 2),
                    'source' => 'manual',
                    'row_version' => (int)$stored['row_version'],
                    'amount_id' => (int)$stored['id'],
                ];
            } else {
                $default = $userId > 0
                    ? pos_period_default($lines[$userId] ?? [], $period['start'], $period['end'])
                    : ['state' => 'blank', 'amount' => null];
                $cell = [
                    'state' => $default['state'],
                    'amount' => $default['amount'],
                    'source' => $default['state'] === 'amount' ? 'payroll' : '',
                    'row_version' => $stored ? (int)$stored['row_version'] : 0,
                    'amount_id' => $stored ? (int)$stored['id'] : 0,
                ];
            }
            $cells[$period['id']] = $cell;
            if ($cell['state'] === 'unknown') {
                $balanceUnknown = true;
            } elseif ($cell['state'] === 'amount') {
                $balance = round($balance + (float)$cell['amount'], 2);
            }
        }
        $paid = pos_sum_payments($payments[$summaryId] ?? []);
        $balanceFigure = $balanceUnknown
            ? ['state' => 'unknown', 'amount' => null]
            : ['state' => 'amount', 'amount' => $balance];
        $final = $balanceUnknown
            ? ['state' => 'unknown', 'amount' => null]
            : ['state' => 'amount', 'amount' => round($balance - (float)$paid['amount'], 2)];
        $built = [
            'id' => $summaryId,
            'store' => (string)$row['store_label'],
            'name' => (string)$row['sheet_employee'],
            'user_id' => $userId,
            'row_version' => (int)$row['row_version'],
            'notes' => (string)($row['notes'] ?? ''),
            'total' => round((float)$row['total_amount'], 2),
            'periods' => $cells,
            'balance' => $balanceFigure,
            'paid' => (float)$paid['amount'],
            'paid_on' => (string)$paid['paid_on'],
            'payment_count' => (int)$paid['count'],
            'payments' => $payments[$summaryId] ?? [],
            'final' => $final,
        ];
        $groups[$built['store']][] = $built;
    }
    ksort($groups);
    $outGroups = [];
    foreach ($groups as $store => $rows) {
        $sub = pos_empty_total($periodIds);
        foreach ($rows as $built) {
            $sub['total'] = round($sub['total'] + (float)$built['total'], 2);
            $sub['paid'] = round($sub['paid'] + (float)$built['paid'], 2);
            foreach ($periodIds as $periodId) {
                $sub['periods'][$periodId] = pos_add_amount($sub['periods'][$periodId], $built['periods'][$periodId]);
            }
            $sub['balance'] = pos_add_amount($sub['balance'], $built['balance']);
            $sub['final'] = pos_add_amount($sub['final'], $built['final']);
            $grand['total'] = round($grand['total'] + (float)$built['total'], 2);
            $grand['paid'] = round($grand['paid'] + (float)$built['paid'], 2);
            foreach ($periodIds as $periodId) {
                $grand['periods'][$periodId] = pos_add_amount($grand['periods'][$periodId], $built['periods'][$periodId]);
            }
            $grand['balance'] = pos_add_amount($grand['balance'], $built['balance']);
            $grand['final'] = pos_add_amount($grand['final'], $built['final']);
        }
        $outGroups[] = ['store' => $store, 'rows' => $rows, 'totals' => $sub];
    }
    return [
        'count' => count($visible),
        'periods' => $periods,
        'groups' => $outGroups,
        'grand' => $grand,
    ];
}
