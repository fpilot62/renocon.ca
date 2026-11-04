<?php
/**
 * Path: /engine/payroll_cheque_lib.php
 * Cheque register for Payroll Center.
 *
 * Records each cheque printed from generate_pay_documents.php, voids numbers
 * without reusing them, and marks the underlying finalized rows paid once.
 * Does not recalculate gross, CPP, EI, tax, or vacation. Those stay on
 * payroll_finalized. A payment is capped at the stored net still outstanding.
 */

function pcr_payment_methods(): array
{
    return ['E-Transfer', 'Cheque', 'Cash'];
}

function pcr_payment_method($raw): string
{
    $raw = trim((string)$raw);
    return in_array($raw, pcr_payment_methods(), true) ? $raw : 'E-Transfer';
}

function pcr_ymd($raw): string
{
    $raw = substr(trim((string)$raw), 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
}

/** How one stored payment should read on Payroll Center. */
function pcr_payment_method_label(string $method, ?string $refNo = null, $chequeNumber = null): string
{
    $method = trim($method);
    $key = strtolower($method);
    if ($key === 'cheque') {
        $num = (int)$chequeNumber;
        $ref = trim((string)$refNo);
        if ($num <= 0 && preg_match('/^(?:CHQ-)?(\d+)$/i', $ref, $m)) {
            $num = (int)$m[1];
        }
        if ($num > 0) {
            return 'Cheque #' . $num;
        }
        if ($ref !== '' && !preg_match('/^B\d+$/', $ref) && !preg_match('/^TXN-/i', $ref)) {
            return 'Cheque #' . $ref;
        }
        return 'Cheque';
    }
    if ($key === 'e-transfer' || $key === 'etransfer' || $key === 'e transfer') {
        return 'e-Transfer';
    }
    if ($key === 'eft' || $key === 'direct deposit' || $key === 'direct-deposit') {
        return 'EFT';
    }
    if ($key === 'cash') {
        return 'Cash';
    }
    return $method;
}

/**
 * Payment type for a row. Recorded payments win. The employee EFT flag is
 * used only when nothing has been recorded.
 */
function pcr_payment_type_label(array $payments, bool $eftNoCheque): string
{
    $labels = [];
    foreach ($payments as $p) {
        if (!is_array($p) || !empty($p['reversed_at'])) {
            continue;
        }
        $label = pcr_payment_method_label(
            (string)($p['method'] ?? ''),
            isset($p['ref_no']) ? (string)$p['ref_no'] : null,
            $p['cheque_number'] ?? null
        );
        if ($label === '') {
            $label = 'Payment';
        }
        if (!in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }
    if ($labels) {
        return implode(' · ', $labels);
    }
    return $eftNoCheque ? 'EFT · no cheque' : '';
}

/**
 * Payments that belong to one finalized line. A line's own payroll_id matches
 * directly. The same user, store, and work date also match the master that
 * pcr_apply_payment uses when the line never stored that id.
 */
function pcr_line_payment_rows(array $line, array $payments): array
{
    $pid = (int)($line['payroll_id'] ?? 0);
    $uid = (int)($line['user_id'] ?? 0);
    $sid = (int)($line['site_id'] ?? 0);
    $day = pcr_ymd($line['work_date'] ?? '');
    $out = [];
    $seen = [];
    foreach ($payments as $p) {
        if (!is_array($p)) {
            continue;
        }
        $payMaster = (int)($p['payroll_id'] ?? 0);
        $hit = ($pid > 0 && $payMaster === $pid);
        if (!$hit && $uid > 0 && (int)($p['user_id'] ?? 0) === $uid && (int)($p['site_id'] ?? 0) === $sid && $day !== '') {
            $start = pcr_ymd($p['pay_period_start'] ?? '');
            $end = pcr_ymd($p['pay_period_end'] ?? '');
            $hit = ($start !== '' && $end !== '' && $day >= $start && $day <= $end);
        }
        if (!$hit) {
            continue;
        }
        $id = (int)($p['id'] ?? 0);
        $key = $id > 0 ? ('id' . $id) : md5((string)json_encode($p));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $p;
    }
    return $out;
}

function pcr_lines_payment_label(array $lines, array $payments, bool $eftNoCheque): string
{
    $rows = [];
    $seen = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        foreach (pcr_line_payment_rows($line, $payments) as $p) {
            $id = (int)($p['id'] ?? 0);
            $key = $id > 0 ? ('id' . $id) : md5((string)json_encode($p));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $p;
        }
    }
    return pcr_payment_type_label($rows, $eftNoCheque);
}

/** A real pay date, or today when the caller does not supply one. */
function pcr_coerce_payment_date(?string $raw): string
{
    $valid = pcr_valid_payment_date($raw);
    return $valid ?? date('Y-m-d');
}

function pcr_valid_payment_date(?string $raw): ?string
{
    $raw = trim((string)$raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if (!$dt || $dt->format('Y-m-d') !== $raw) {
        return null;
    }
    if ($raw < '2018-01-01' || $raw > date('Y-m-d', strtotime('+14 days'))) {
        return null;
    }
    return $raw;
}

/** Stored net still owing on one finalized line. Same columns as the ledger. */
function pcr_line_remaining(float $gross, float $eeCpp, float $eeEi, float $eeTax, float $paid): float
{
    $net = max(0, round($gross - $eeCpp - $eeEi - $eeTax, 2));
    return round(max(0, $net - $paid), 2);
}

/**
 * Amount printed on the cheque. Matches the paystub net (vacation payout
 * included only when the org pays vacation each period) minus what is already
 * recorded as paid. A fully paid group is 0, so no new cheque is printed.
 */
function pcr_cheque_face_amount(
    float $gross,
    float $eeCpp,
    float $eeEi,
    float $eeTax,
    float $paid,
    bool $vacPaidMode,
    float $vacEarn
): float {
    $net = $gross + ($vacPaidMode ? $vacEarn : 0) - $eeCpp - $eeEi - $eeTax;
    return round(max(0, $net - $paid), 2);
}

/** How much of a requested payment may be stored. Never more than the remaining net. */
function pcr_payable_amount(float $net, float $paid, float $requested): float
{
    $remaining = round(max(0, $net - $paid), 2);
    $requested = round($requested, 2);
    if ($requested <= 0.009 || $remaining <= 0.009) {
        return 0.0;
    }
    return round(min($requested, $remaining), 2);
}

/** Next unused cheque number after $floor (exclusive). $used includes voided numbers. */
function pcr_number_after(int $floor, array $used): int
{
    $n = $floor + 1;
    $taken = [];
    foreach ($used as $u) {
        $taken[(int)$u] = true;
    }
    while (isset($taken[$n])) {
        $n++;
    }
    return $n;
}

/** Daily series used by the old PDF (ymd then a trailing 0, first cheque is +1). */
function pcr_date_floor(?string $ymd = null): int
{
    $ts = $ymd ? strtotime($ymd) : time();
    if ($ts === false) {
        $ts = time();
    }
    return (int)(date('ymd', $ts) . '0');
}

function pcr_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $st = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '` LIKE ?');
    $st->execute([$column]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $column) . '` ' . $definition);
    }
}

function pcr_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payroll_cheques (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            user_id INT NOT NULL,
            site_id INT NOT NULL DEFAULT 0,
            cheque_number INT NOT NULL,
            cheque_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            period_start DATE NULL,
            period_end DATE NULL,
            employee_name VARCHAR(160) NOT NULL DEFAULT '',
            status VARCHAR(16) NOT NULL DEFAULT 'issued',
            issue_nonce VARCHAR(64) NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            voided_by INT NULL,
            voided_at DATETIME NULL,
            void_reason VARCHAR(255) NULL,
            paid_at DATETIME NULL,
            UNIQUE KEY org_cheque (org_id, cheque_number),
            UNIQUE KEY org_nonce_user_site (org_id, issue_nonce, user_id, site_id),
            KEY org_period (org_id, period_start, period_end),
            KEY org_user (org_id, user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payroll_cheque_lines (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cheque_id INT NOT NULL,
            finalized_id INT NOT NULL,
            line_remaining DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_id INT NULL,
            KEY cheque_id (cheque_id),
            KEY finalized_id (finalized_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    pcr_add_column($pdo, 'users', 'eft_no_cheque', 'TINYINT(1) NOT NULL DEFAULT 0');
    pcr_add_column($pdo, 'payroll_payments', 'reversed_at', 'DATETIME NULL');
    pcr_add_column($pdo, 'payroll_payments', 'reversed_by', 'INT NULL');
    pcr_add_column($pdo, 'payroll_payments', 'cheque_id', 'INT NULL');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payroll_payment_batches (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            org_id INT NOT NULL,
            period_start DATE NULL,
            period_end DATE NULL,
            created_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            undone_by INT NULL,
            undone_at DATETIME NULL,
            KEY org_created (org_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS payroll_payment_batch_lines (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            user_id INT NOT NULL,
            site_id INT NOT NULL DEFAULT 0,
            finalized_id INT NOT NULL,
            payment_id INT NOT NULL,
            employee_name VARCHAR(160) NOT NULL DEFAULT '',
            site_name VARCHAR(160) NOT NULL DEFAULT '',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            method VARCHAR(32) NOT NULL DEFAULT '',
            paid_date DATE NULL,
            reference_no VARCHAR(64) NULL,
            note VARCHAR(255) NULL,
            created_line TINYINT(1) NOT NULL DEFAULT 0,
            created_user_id INT NULL,
            owed_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            KEY batch_id (batch_id),
            KEY payment_id (payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    pcr_add_column($pdo, 'payroll_payment_batch_lines', 'created_line', 'TINYINT(1) NOT NULL DEFAULT 0');
    pcr_add_column($pdo, 'payroll_payment_batch_lines', 'created_user_id', 'INT NULL');
    pcr_add_column($pdo, 'payroll_payment_batch_lines', 'owed_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    $ready = true;
}

function pcr_set_eft(PDO $pdo, int $orgId, int $userId, bool $on): void
{
    pcr_ensure($pdo);
    $st = $pdo->prepare('UPDATE users SET eft_no_cheque = ? WHERE id = ? AND org_id = ?');
    $st->execute([$on ? 1 : 0, $userId, $orgId]);
    if ($st->rowCount() < 1) {
        $chk = $pdo->prepare('SELECT id FROM users WHERE id = ? AND org_id = ?');
        $chk->execute([$userId, $orgId]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Employee is not in this organization.');
        }
    }
}

function pcr_eft_flags(PDO $pdo, int $orgId): array
{
    pcr_ensure($pdo);
    $st = $pdo->prepare('SELECT id FROM users WHERE org_id = ? AND eft_no_cheque = 1');
    $st->execute([$orgId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $out[(int)$id] = true;
    }
    return $out;
}

function pcr_next_number(PDO $pdo, int $orgId, ?string $onDate = null): int
{
    pcr_ensure($pdo);
    $floor = pcr_date_floor($onDate ?: date('Y-m-d'));
    $st = $pdo->prepare(
        'SELECT cheque_number FROM payroll_cheques
         WHERE org_id = ? AND cheque_number > ? AND cheque_number < ?'
    );
    $st->execute([$orgId, $floor, $floor + 100000]);
    $used = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return pcr_number_after($floor, $used);
}

function pcr_refresh_master_status(PDO $pdo, int $masterId): void
{
    if ($masterId <= 0) {
        return;
    }
    $net = (float)$pdo->query('SELECT net_payable FROM payroll_masters WHERE id = ' . (int)$masterId)->fetchColumn();
    $paid = (float)$pdo->query(
        'SELECT COALESCE(SUM(amount),0) FROM payroll_payments
         WHERE payroll_id = ' . (int)$masterId . ' AND reversed_at IS NULL'
    )->fetchColumn();
    $status = ($paid <= 0.01) ? 'Unpaid' : (($paid + 0.01 >= $net) ? 'Paid' : 'Partial');
    $pdo->prepare('UPDATE payroll_masters SET status = ? WHERE id = ?')->execute([$status, $masterId]);
}

/**
 * Record a payment against one finalized line. Caps at the stored remaining net.
 * Returns the amount actually stored (0 when nothing was owing).
 */
function pcr_apply_payment(
    PDO $pdo,
    int $lineId,
    float $amount,
    string $method,
    int $orgId,
    ?int &$paymentId = null,
    ?string $refNo = null,
    ?int $chequeId = null,
    ?string $paidDate = null
): float {
    pcr_ensure($pdo);
    $paymentId = null;
    $method = pcr_payment_method($method);
    $q = $pdo->prepare(
        "SELECT f.id AS line_id, f.attendance_id, f.user_id, f.site_id, f.payroll_id, f.work_date,
                f.pay_period, f.pay_period_end, f.total_gross, f.paid_amount AS db_paid_amount,
                f.cpp AS ee_cpp, f.ei AS ee_ei, f.income_tax AS ee_tax,
                f.cpp_er AS er_cpp, f.ei_er AS er_ei, f.wcb AS er_wcb, f.vac AS er_vac
         FROM payroll_finalized f
         INNER JOIN users u ON u.id=f.user_id AND u.org_id=?
         INNER JOIN sites s ON s.SId=f.site_id AND s.org_id=u.org_id
         WHERE f.id = ? LIMIT 1"
    );
    $q->execute([$orgId, $lineId]);
    $line = $q->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        return 0.0;
    }

    $gross = (float)$line['total_gross'];
    $ded = (float)$line['ee_cpp'] + (float)$line['ee_ei'] + (float)$line['ee_tax'];
    $net = max(0, $gross - $ded);
    $paidNow = (float)$line['db_paid_amount'];
    $amount = pcr_payable_amount($net, $paidNow, $amount);
    if ($amount <= 0.009) {
        return 0.0;
    }

    $paidOn = pcr_coerce_payment_date($paidDate);
    $newPaid = $paidNow + $amount;
    $fully = (round($newPaid, 2) + 0.005 >= round($net, 2));
    $newStatus = $fully ? 'paid' : 'pending';
    $newPayStatus = $fully ? 'Paid' : 'Partial';

    $pdo->prepare(
        "UPDATE payroll_finalized
            SET status = ?, payment_status = ?, paid_amount = ?, last_paid_date = ?, paid_at = NOW()
          WHERE id = ?"
    )->execute([$newStatus, $newPayStatus, $newPaid, $paidOn, $lineId]);

    if (!empty($line['attendance_id'])) {
        $pdo->prepare(
            "UPDATE payroll_balance
                SET paid_amount = COALESCE(paid_amount,0) + ?,
                    balance_remaining = GREATEST(0, COALESCE(balance_remaining, ?) - ?),
                    status = ?, last_paid_date = ?, paid_at = NOW()
              WHERE attendance_id = ?"
        )->execute([$amount, $net, $amount, $newStatus, $paidOn, $line['attendance_id']]);
    }

    $masterId = pcr_resolve_master_id($pdo, $line, $orgId);
    $ref = $refNo !== null && $refNo !== '' ? substr($refNo, 0, 40) : ('TXN-' . strtoupper(uniqid()));
    $pdo->prepare(
        "INSERT INTO payroll_payments
            (payroll_id, recipient, amount, date_paid, payment_type, method, ref_no, cheque_id)
         VALUES (?, 'Employee', ?, ?, 'employee_net', ?, ?, ?)"
    )->execute([$masterId, $amount, $paidOn, $method, $ref, $chequeId]);
    $paymentId = (int)$pdo->lastInsertId();
    pcr_refresh_master_status($pdo, $masterId);
    return $amount;
}

function pcr_resolve_master_id(PDO $pdo, array $line, int $orgId): int
{
    if (!empty($line['payroll_id']) && (int)$line['payroll_id'] > 0) {
        return (int)$line['payroll_id'];
    }
    $find = $pdo->prepare(
        "SELECT id FROM payroll_masters
         WHERE org_id = ? AND user_id = ? AND site_id = ? AND pay_period_start <= ? AND pay_period_end >= ?
         ORDER BY id DESC LIMIT 1"
    );
    $find->execute([$orgId, $line['user_id'], $line['site_id'], $line['work_date'], $line['work_date']]);
    if ($mid = $find->fetchColumn()) {
        return (int)$mid;
    }

    $start = $line['work_date'];
    $end = !empty($line['pay_period_end']) ? $line['pay_period_end'] : $line['work_date'];
    $gross = (float)$line['total_gross'];
    $cra = (float)$line['ee_cpp'] + (float)$line['ee_ei'] + (float)$line['ee_tax'];
    $er = (float)$line['er_cpp'] + (float)$line['er_ei'] + (float)$line['er_wcb'] + (float)$line['er_vac'];
    $net = max(0, $gross - $cra);
    $ins = $pdo->prepare(
        "INSERT INTO payroll_masters
            (org_id, site_id, user_id, pay_period_start, pay_period_end,
             gross_total, er_contributions, cra_deductions, net_payable, status, cra_status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP())"
    );
    $ins->execute([$orgId, $line['site_id'], $line['user_id'], $start, $end, $gross, $er, $cra, $net, 'Unpaid', 'Unpaid']);
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE payroll_finalized SET payroll_id = ? WHERE id = ?')->execute([$newId, $line['line_id']]);
    return $newId;
}

/**
 * Insert one issued cheque, or return the cheque already stored for this
 * nonce + employee (a browser resubmit does not mint a second number).
 * $lines is a list of ['finalized_id' => int, 'remaining' => float].
 */
function pcr_issue_cheque(PDO $pdo, array $spec, array $lines): array
{
    pcr_ensure($pdo);
    $orgId = (int)$spec['org_id'];
    $userId = (int)$spec['user_id'];
    $nonce = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($spec['issue_nonce'] ?? '')), 0, 64);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $siteId = (int)($spec['site_id'] ?? 0);
        if ($nonce !== '') {
            $existing = pcr_find_nonce($pdo, $orgId, $nonce, $userId, $siteId);
            if ($existing) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return $existing;
            }
        }
        $requested = (int)($spec['cheque_number'] ?? 0);
        $number = $requested > 0 ? $requested : pcr_next_number($pdo, $orgId, $spec['cheque_date'] ?? null);
        $guard = 0;
        while ($guard < 1000) {
            $taken = $pdo->prepare('SELECT id FROM payroll_cheques WHERE org_id = ? AND cheque_number = ? LIMIT 1');
            $taken->execute([$orgId, $number]);
            if (!$taken->fetchColumn()) {
                break;
            }
            $number++;
            $guard++;
        }
        $ins = $pdo->prepare(
            "INSERT INTO payroll_cheques
                (org_id, user_id, site_id, cheque_number, cheque_date, amount, period_start, period_end,
                 employee_name, status, issue_nonce, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?, 'issued', ?, ?, NOW())"
        );
        $ins->execute([
            $orgId,
            $userId,
            (int)($spec['site_id'] ?? 0),
            $number,
            $spec['cheque_date'],
            round((float)$spec['amount'], 2),
            $spec['period_start'] ?? null,
            $spec['period_end'] ?? null,
            substr((string)($spec['employee_name'] ?? ''), 0, 160),
            $nonce !== '' ? $nonce : null,
            (int)($spec['created_by'] ?? 0),
        ]);
        $id = (int)$pdo->lastInsertId();
        $ln = $pdo->prepare(
            'INSERT INTO payroll_cheque_lines (cheque_id, finalized_id, line_remaining) VALUES (?,?,?)'
        );
        foreach ($lines as $line) {
            $fid = (int)($line['finalized_id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            $ln->execute([$id, $fid, round((float)($line['remaining'] ?? 0), 2)]);
        }
        if ($ownTx) {
            $pdo->commit();
        }
        return ['id' => $id, 'number' => $number, 'amount' => round((float)$spec['amount'], 2), 'status' => 'issued', 'created' => true];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($nonce !== '' && stripos($e->getMessage(), 'Duplicate') !== false) {
            $existing = pcr_find_nonce($pdo, $orgId, $nonce, $userId, (int)($spec['site_id'] ?? 0));
            if ($existing) {
                return $existing;
            }
        }
        throw $e;
    }
}

function pcr_find_nonce(PDO $pdo, int $orgId, string $nonce, int $userId, int $siteId): ?array
{
    $ex = $pdo->prepare(
        'SELECT id, cheque_number, amount, status FROM payroll_cheques
         WHERE org_id = ? AND issue_nonce = ? AND user_id = ? AND site_id = ? LIMIT 1'
    );
    $ex->execute([$orgId, $nonce, $userId, $siteId]);
    $row = $ex->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)$row['id'],
        'number' => (int)$row['cheque_number'],
        'amount' => (float)$row['amount'],
        'status' => (string)$row['status'],
        'created' => false,
    ];
}

function pcr_mark_cheque_paid(PDO $pdo, int $chequeId, int $orgId, int $actorId): array
{
    pcr_ensure($pdo);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT * FROM payroll_cheques WHERE id = ? AND org_id = ? FOR UPDATE');
        $st->execute([$chequeId, $orgId]);
        $chq = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chq) {
            throw new RuntimeException('Cheque not found.');
        }
        if ($chq['status'] === 'void') {
            throw new RuntimeException('Cheque #' . $chq['cheque_number'] . ' is void.');
        }
        if ($chq['status'] === 'paid') {
            if ($ownTx) {
                $pdo->commit();
            }
            return ['amount' => 0.0, 'payments' => 0, 'status' => 'paid'];
        }
        $lines = $pdo->prepare('SELECT * FROM payroll_cheque_lines WHERE cheque_id = ?');
        $lines->execute([$chequeId]);
        $applied = 0.0;
        $n = 0;
        $open = 0;
        $ref = 'CHQ-' . $chq['cheque_number'];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            if (!empty($line['payment_id'])) {
                continue;
            }
            $payId = null;
            $got = pcr_apply_payment($pdo, (int)$line['finalized_id'], 1e9, 'Cheque', $orgId, $payId, $ref, $chequeId);
            if ($got > 0.009 && $payId) {
                $pdo->prepare('UPDATE payroll_cheque_lines SET payment_id = ? WHERE id = ?')->execute([$payId, $line['id']]);
                $applied += $got;
                $n++;
            } else {
                $rem = $pdo->prepare(
                    'SELECT GREATEST(0, total_gross - cpp - ei - income_tax - paid_amount)
                     FROM payroll_finalized f
                     INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
                     WHERE f.id = ?'
                );
                $rem->execute([$orgId, (int)$line['finalized_id']]);
                if ((float)$rem->fetchColumn() > 0.02) {
                    $open++;
                }
            }
        }
        $status = ($open === 0) ? 'paid' : 'issued';
        if ($status === 'paid') {
            $pdo->prepare("UPDATE payroll_cheques SET status = 'paid', paid_at = NOW() WHERE id = ? AND status = 'issued'")
                ->execute([$chequeId]);
        }
        if ($ownTx) {
            $pdo->commit();
        }
        return ['amount' => round($applied, 2), 'payments' => $n, 'status' => $status];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pcr_mark_issued_in_period(PDO $pdo, int $orgId, string $from, string $to, int $actorId): array
{
    $st = $pdo->prepare(
        "SELECT id FROM payroll_cheques
         WHERE org_id = ? AND status = 'issued' AND period_start <= ? AND period_end >= ?"
    );
    $st->execute([$orgId, $to, $from]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $paid = 0;
    $amount = 0.0;
    foreach ($ids as $id) {
        $r = pcr_mark_cheque_paid($pdo, $id, $orgId, $actorId);
        if ($r['status'] === 'paid') {
            $paid++;
        }
        $amount += (float)$r['amount'];
    }
    return ['cheques' => $paid, 'amount' => round($amount, 2)];
}

function pcr_reverse_linked_payments(PDO $pdo, int $chequeId, int $orgId, int $actorId): float
{
    $st = $pdo->prepare(
        'SELECT l.finalized_id, l.payment_id, p.amount, p.payroll_id, p.reversed_at
         FROM payroll_cheque_lines l
         INNER JOIN payroll_payments p ON p.id = l.payment_id
         WHERE l.cheque_id = ? AND p.reversed_at IS NULL AND p.cheque_id = ?'
    );
    $st->execute([$chequeId, $chequeId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $reversed = 0.0;
    foreach ($rows as $row) {
        $reversed += pcr_reverse_finalized_payment($pdo, (int)$row['payment_id'], (int)$row['finalized_id'], $orgId, $actorId);
    }
    return round($reversed, 2);
}

/**
 * Reverse one stored payment. Gross, CPP, EI, and tax are not changed.
 * A payment already reversed returns 0.
 */
function pcr_reverse_finalized_payment(PDO $pdo, int $paymentId, int $finalizedId, int $orgId, int $actorId): float
{
    if ($paymentId <= 0 || $finalizedId <= 0) {
        return 0.0;
    }
    $pay = $pdo->prepare('SELECT id, amount, payroll_id, reversed_at FROM payroll_payments WHERE id = ? LIMIT 1');
    $pay->execute([$paymentId]);
    $payment = $pay->fetch(PDO::FETCH_ASSOC);
    if (!$payment || $payment['reversed_at'] !== null) {
        return 0.0;
    }
    $amt = round((float)$payment['amount'], 2);
    if ($amt <= 0) {
        return 0.0;
    }
    $q = $pdo->prepare(
        "SELECT f.id, f.attendance_id, f.paid_amount, f.total_gross, f.cpp, f.ei, f.income_tax
         FROM payroll_finalized f
         INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
         WHERE f.id = ? LIMIT 1"
    );
    $q->execute([$orgId, $finalizedId]);
    $line = $q->fetch(PDO::FETCH_ASSOC);
    if (!$line) {
        return 0.0;
    }
    $newPaid = round(max(0, (float)$line['paid_amount'] - $amt), 2);
    $net = max(0, (float)$line['total_gross'] - (float)$line['cpp'] - (float)$line['ei'] - (float)$line['income_tax']);
    $fully = (round($newPaid, 2) + 0.005 >= round($net, 2)) && $newPaid > 0.009;
    $status = $fully ? 'paid' : 'pending';
    $payStatus = $newPaid <= 0.009 ? 'Unpaid' : ($fully ? 'Paid' : 'Partial');
    $pdo->prepare(
        'UPDATE payroll_finalized SET paid_amount = ?, status = ?, payment_status = ? WHERE id = ?'
    )->execute([$newPaid, $status, $payStatus, $line['id']]);
    if (!empty($line['attendance_id'])) {
        $pdo->prepare(
            'UPDATE payroll_balance
                SET paid_amount = GREATEST(0, COALESCE(paid_amount,0) - ?),
                    balance_remaining = COALESCE(balance_remaining,0) + ?,
                    status = ?
              WHERE attendance_id = ?'
        )->execute([$amt, $amt, $status, $line['attendance_id']]);
    }
    $pdo->prepare('UPDATE payroll_payments SET reversed_at = NOW(), reversed_by = ? WHERE id = ? AND reversed_at IS NULL')
        ->execute([$actorId, $paymentId]);
    pcr_refresh_master_status($pdo, (int)$payment['payroll_id']);
    return $amt;
}

function pcr_void_cheque(PDO $pdo, int $chequeId, int $orgId, int $actorId, string $reason, bool $reversePayment): array
{
    pcr_ensure($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Voided';
    }
    $reason = substr($reason, 0, 255);
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT * FROM payroll_cheques WHERE id = ? AND org_id = ? FOR UPDATE');
        $st->execute([$chequeId, $orgId]);
        $chq = $st->fetch(PDO::FETCH_ASSOC);
        if (!$chq) {
            throw new RuntimeException('Cheque not found.');
        }
        if ($chq['status'] === 'void') {
            if ($ownTx) {
                $pdo->commit();
            }
            return ['number' => (int)$chq['cheque_number'], 'reversed' => 0.0, 'status' => 'void'];
        }
        $reversed = 0.0;
        if ($chq['status'] === 'paid') {
            if (!$reversePayment) {
                throw new RuntimeException('Cheque #' . $chq['cheque_number'] . ' is marked paid. Reverse that cheque payment to void the number.');
            }
            $reversed = pcr_reverse_linked_payments($pdo, $chequeId, $orgId, $actorId);
        }
        $pdo->prepare(
            "UPDATE payroll_cheques
                SET status = 'void', voided_at = NOW(), voided_by = ?, void_reason = ?
              WHERE id = ? AND status <> 'void'"
        )->execute([$actorId, $reason, $chequeId]);
        if ($ownTx) {
            $pdo->commit();
        }
        return ['number' => (int)$chq['cheque_number'], 'reversed' => $reversed, 'status' => 'void'];
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pcr_list_period(PDO $pdo, int $orgId, string $from, string $to): array
{
    pcr_ensure($pdo);
    $st = $pdo->prepare(
        "SELECT * FROM payroll_cheques
         WHERE org_id = ? AND period_start <= ? AND period_end >= ?
         ORDER BY cheque_number ASC, id ASC"
    );
    $st->execute([$orgId, $to, $from]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Active (issued or paid) cheque covering each finalized line, if any. */
function pcr_active_by_line(PDO $pdo, int $orgId, array $lineIds): array
{
    pcr_ensure($pdo);
    $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds))));
    if (!$lineIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($lineIds), '?'));
    $st = $pdo->prepare(
        "SELECT l.finalized_id, c.id AS cheque_id, c.cheque_number, c.status, c.amount
         FROM payroll_cheque_lines l
         INNER JOIN payroll_cheques c ON c.id = l.cheque_id
         WHERE c.org_id = ? AND c.status IN ('issued','paid') AND l.finalized_id IN ($in)
         ORDER BY c.id DESC"
    );
    $st->execute(array_merge([$orgId], $lineIds));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fid = (int)$row['finalized_id'];
        if (!isset($out[$fid])) {
            $out[$fid] = $row;
        }
    }
    return $out;
}

/**
 * Non-reversed payments whose master overlaps this window, or whose
 * payroll_id is already on one of the lines being shown.
 */
function pcr_fetch_period_payments(PDO $pdo, int $orgId, string $from, string $to, array $payrollIds = []): array
{
    pcr_ensure($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $payrollIds))));
    $params = [$orgId, $to, $from];
    $extra = '';
    if ($ids) {
        $extra = ' OR p.payroll_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        foreach ($ids as $id) {
            $params[] = $id;
        }
    }
    $st = $pdo->prepare(
        "SELECT p.id, p.payroll_id, p.method, p.ref_no, c.cheque_number,
                m.user_id, m.site_id, m.pay_period_start, m.pay_period_end
         FROM payroll_payments p
         INNER JOIN payroll_masters m ON m.id = p.payroll_id AND m.org_id = ?
         LEFT JOIN payroll_cheques c ON c.id = p.cheque_id
         WHERE p.reversed_at IS NULL
           AND ((m.pay_period_start <= ? AND m.pay_period_end >= ?)$extra)
         ORDER BY p.id ASC"
    );
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pcr_block_send_back(float $paid, ?array $activeCheque): ?string
{
    if ($paid > 0.01) {
        return 'This shift has a recorded payment. Reverse that payment before sending it back to audit.';
    }
    if ($activeCheque && in_array($activeCheque['status'], ['issued', 'paid'], true)) {
        return 'This shift is on cheque #' . $activeCheque['cheque_number'] . '. Void that cheque before sending it back to audit.';
    }
    return null;
}
