<?php
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level'=>10]); // org-scope audit Nov 2026

/** Path: /process_audit.php */
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

try {
    $pdo->beginTransaction();

    foreach ($_POST['rows'] as $key => $data) {
        $hrs = (float)($data['hrs'] ?? 0);
        if ($hrs <= 0) continue;

        // Both tables now use the exact same 21-field layout
        $table = (isset($_POST['merge']) || $key === 'reg') ? 'payroll_finalized' : 'payroll_balance';
        $gross = $hrs * (float)$data['rate'];
        $ded = pe_line($gross, pe_vacation_rate($pdo, (int)$_POST['meta']['user_id'], (int)($_POST['meta']['site_id'] ?? 0), (string)($_POST['meta']['work_date'] ?? date('Y-m-d'))), pe_rates($pdo), [
            'pdo' => $pdo,
            'user_id' => (int)$_POST['meta']['user_id'],
            'site_id' => (int)($_POST['meta']['site_id'] ?? 0),
            'org_id' => (int)($_POST['meta']['org_id'] ?? 0),
            'work_date' => (string)($_POST['meta']['work_date'] ?? date('Y-m-d')),
        ]);
        
        $sql = "INSERT INTO $table (
                    attendance_id, user_id, org_id, site_id, work_date, 
                    reg_hours, hourly_rate, reg_pay, total_gross, 
                    cpp_paid, ei_paid, wcb_wsib_paid, vacation_accrued, 
                    status, created_at, pay_type, comments
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'processed', NOW(), ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $_POST['meta']['shift_id'], $_POST['meta']['user_id'], $_POST['meta']['org_id'], 
            ($_POST['meta']['site_id'] ?? 1), $_POST['meta']['work_date'],
            $hrs, $data['rate'], $gross, $gross, 
            $ded['cpp'], $ded['ei'], $ded['wcb'], $ded['vac'], 
            $data['type'], $data['comment']
        ]);
    }

    $aaId = (int)$_POST['meta']['shift_id'];
    $aaBefore = aa_snapshot($pdo, $aaId);
    $pdo->prepare("UPDATE attendance SET status = 'audited' WHERE id = ?")
        ->execute([$_POST['meta']['shift_id']]);
    aa_log($pdo, 'finalize', $aaId, $aaBefore, null, 'Process audit');

    $pdo->commit();
    header("Location: staff_audit.php?success=1");

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Sync Error: " . $e->getMessage());
}