<?php
/** Path: /save_payroll.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/stat_holidays.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attendance_id = (int)$_POST['attendance_id'];
    $merge = isset($_POST['merge_tables']) && $_POST['merge_tables'] == '1';
    
    // CATCH THE NEW GLOBAL LABELS FROM THE FORM
    $ctry_code    = $_POST['country_code'] ?? 'CA';
    $safety_label = $_POST['safety_label'] ?? 'WCB';
    $tax_label    = $_POST['tax_label']    ?? 'EI/CPP';

    try {
        $pdo->beginTransaction();

        $stmt_orig = $pdo->prepare("SELECT user_id, org_id, site_id, clock_in FROM attendance WHERE id = ?");
        $stmt_orig->execute([$attendance_id]);
        $orig = $stmt_orig->fetch();

        if (!$orig) { throw new Exception("Original shift record not found."); }
        $as_of = !empty($orig['work_date']) ? substr((string)$orig['work_date'], 0, 10) : (!empty($orig['clock_in']) ? date('Y-m-d', strtotime($orig['clock_in'])) : date('Y-m-d'));
        $profile_rate = pe_profile_hourly_rate($pdo, (int)$orig['user_id'], $as_of);

        // --- PREPARE STATEMENTS (UPDATED WITH NEW COLUMNS) ---
        $sqlFinal = "INSERT INTO payroll_finalized (
                        attendance_id, country_code, tax_label, safety_label,
                        category, display_label, user_id, org_id, site_id, 
                        work_date, reg_hours, hourly_rate, reg_pay, 
                        cpp, ei, wcb, vac, pay_type, status, finalized_by
                    ) VALUES (?, ?, ?, ?, 'Multi-Row', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)";
        $stmtFinal = $pdo->prepare($sqlFinal);

        $sqlBal = "INSERT INTO payroll_balance (
                        user_id, attendance_id, org_id, site_id, 
                        reg_hours, hourly_rate, total_gross, pay_type, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        $stmtBal = $pdo->prepare($sqlBal);

        // --- 1. PROCESS PRIMARY ROW ---
        $h_acc = (float)$_POST['acc_hrs'];
        $r_acc = $profile_rate;
        $accGross = $h_acc * $r_acc;
        $acc = pe_line($accGross, pe_vacation_rate($pdo, (int)$orig['user_id'], (int)$orig['site_id'], $as_of), pe_rates($pdo), [
            'pdo' => $pdo, 'user_id' => (int)$orig['user_id'], 'site_id' => (int)$orig['site_id'], 'org_id' => (int)$orig['org_id'], 'work_date' => $as_of,
        ]);
        
        $stmtFinal->execute([
            $attendance_id, 
            $ctry_code, 
            $tax_label, 
            $safety_label,
            $_POST['acc_label'], 
            $orig['user_id'], 
            $orig['org_id'], 
            $orig['site_id'],
            $orig['clock_in'], 
            $h_acc, 
            $r_acc, 
            $accGross,
            (float)$acc['cpp'] + (float)($acc['cpp2'] ?? 0),
            (float)$acc['ei'],
            (float)$acc['wcb'],
            (float)$acc['vac'], 
            $_POST['acc_pay_type'], 
            $_SESSION['user_id']
        ]);

        // --- 2. PROCESS EXTRA ROWS ---
        if (!empty($_POST['extra_hrs'])) {
            foreach ($_POST['extra_hrs'] as $index => $hour_val) {
                $h = (float)$hour_val;
                if ($h <= 0) continue; 
                $extra_type = strtolower((string)($_POST['extra_pay_type'][$index] ?? $_POST['extra_type'][$index] ?? ''));
                $r = pe_profile_pay_rate($pdo, (int)$orig['user_id'], $extra_type === 'overtime' ? 'overtime' : 'regular', $as_of);
                $extraGross = $h * $r;
                $extra = pe_line($extraGross, pe_vacation_rate($pdo, (int)$orig['user_id'], (int)$orig['site_id'], $as_of), pe_rates($pdo), [
                    'pdo' => $pdo, 'user_id' => (int)$orig['user_id'], 'site_id' => (int)$orig['site_id'], 'org_id' => (int)$orig['org_id'], 'work_date' => $as_of,
                ]);

                if ($merge) {
                    $stmtFinal->execute([
                        $attendance_id, 
                        $ctry_code, 
                        $tax_label, 
                        $safety_label,
                        $_POST['extra_label'][$index], 
                        $orig['user_id'], 
                        $orig['org_id'], 
                        $orig['site_id'],
                        $orig['clock_in'], 
                        $h, 
                        $r, 
                        $extraGross,
                        (float)$extra['cpp'] + (float)($extra['cpp2'] ?? 0),
                        (float)$extra['ei'],
                        (float)$extra['wcb'],
                        (float)$extra['vac'], 
                        $_POST['extra_pay_type'][$index], 
                        $_SESSION['user_id']
                    ]);
                } else {
                    $gross = $extraGross;
                    $total_burden = $gross + (float)$extra['cpp_er'] + (float)($extra['cpp2_er'] ?? 0) + (float)$extra['ei_er'] + (float)$extra['wcb'] + (float)$extra['vac'];

                    $stmtBal->execute([
                        $orig['user_id'], 
                        $attendance_id, 
                        $orig['org_id'], 
                        $orig['site_id'], 
                        $h, 
                        $r, 
                        $total_burden, 
                        $_POST['extra_pay_type'][$index]
                    ]);
                }
            }
        }

        // Update attendance status so it disappears from the audit list
        $pdo->prepare("UPDATE attendance SET payout_status = 1 WHERE id = ?")->execute([$attendance_id]);
        sh_after_finalize($pdo, (int)$orig['user_id'], (int)$orig['site_id'], $as_of, (int)($_SESSION['user_id'] ?? 0));
        
        $pdo->commit();
        header("Location: staff_audit.php?msg=Success");
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        die("Error saving payroll: " . $e->getMessage());
    }
}