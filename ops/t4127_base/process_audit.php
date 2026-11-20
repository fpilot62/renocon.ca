<?php
/** Path: /process_audit.php */
require_once 'db.php';

try {
    $pdo->beginTransaction();

    foreach ($_POST['rows'] as $key => $data) {
        $hrs = (float)($data['hrs'] ?? 0);
        if ($hrs <= 0) continue;

        // Both tables now use the exact same 21-field layout
        $table = (isset($_POST['merge']) || $key === 'reg') ? 'payroll_finalized' : 'payroll_balance';
        $gross = $hrs * (float)$data['rate'];
        
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
            ($gross * 0.0595), ($gross * 0.0163), ($gross * 0.0155), ($gross * 0.04), 
            $data['type'], $data['comment']
        ]);
    }

    $pdo->prepare("UPDATE attendance SET status = 'audited' WHERE id = ?")
        ->execute([$_POST['meta']['shift_id']]);

    $pdo->commit();
    header("Location: staff_audit.php?success=1");

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("Sync Error: " . $e->getMessage());
}