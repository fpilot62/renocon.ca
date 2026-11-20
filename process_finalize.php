<?php
/** Path: /process_finalize.php */
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    try {
        $stmt = $pdo->prepare("SELECT a.*, u.max_hrs, u.Wage 
                               FROM attendance a 
                               JOIN users u ON a.user_id = u.id 
                               WHERE a.id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();

        if (!$data) die("Error: Record not found.");

        // Calculation
        $raw_hrs = (strtotime($data['clock_out']) - strtotime($data['clock_in'])) / 3600;
        $shift_audit = ($raw_hrs >= 8) ? ($raw_hrs - 0.5) : $raw_hrs;

        $week_stmt = $pdo->prepare("SELECT SUM(approved_hours) FROM attendance 
                                    WHERE user_id = ? AND is_finalized = 1 
                                    AND YEARWEEK(clock_in, 1) = YEARWEEK(?, 1)");
        $week_stmt->execute([$data['user_id'], $data['clock_in']]);
        $weekly_total_before = $week_stmt->fetchColumn() ?: 0;

        $remaining_cap = max(0, $data['max_hrs'] - $weekly_total_before);
        $pay_this_shift = min($shift_audit, $remaining_cap);
        $bank_adjustment = max(0, $shift_audit - $pay_this_shift);
        $gross_pay = $pay_this_shift * $data['Wage'];
        $day = !empty($data['clock_in']) ? date('Y-m-d', strtotime($data['clock_in'])) : date('Y-m-d');
        pe_line($gross_pay, pe_vacation_rate($pdo, (int)$data['user_id'], (int)$data['site_id'], $day), pe_rates($pdo), [
            'pdo' => $pdo, 'user_id' => (int)$data['user_id'], 'site_id' => (int)$data['site_id'], 'org_id' => (int)($data['org_id'] ?? 0), 'work_date' => $day,
        ]);
        sh_after_finalize($pdo, (int)$data['user_id'], (int)$data['site_id'], $day, 0);

        $pdo->beginTransaction();

        // 1. Update Attendance
        $pdo->prepare("UPDATE attendance SET approved_hours = ?, is_finalized = 1 WHERE id = ?")
            ->execute([$shift_audit, $id]);

        // 2. Insert into Ledger (The table we just recreated)
        $ledger = $pdo->prepare("INSERT INTO payroll_records (user_id, site_id, total_hours, rate_used, gross_pay, date_processed) 
                                 VALUES (?, ?, ?, ?, ?, NOW())");
        $ledger->execute([$data['user_id'], $data['site_id'], $pay_this_shift, $data['Wage'], $gross_pay]);

        // 3. Bank hours if over 40
        if ($bank_adjustment > 0) {
            $pdo->prepare("INSERT INTO hour_balances (user_id, hours_added, reason, date_added) 
                           VALUES (?, ?, 'Weekly Cap Surplus', NOW())")
                ->execute([$data['user_id'], $bank_adjustment]);
        }

        $pdo->commit();
        header("Location: finalize_dashboard.php?success=1");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo "Database Error: " . $e->getMessage();
    }
}