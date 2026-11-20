<?php
/** Path: /bulk_finalize_handler.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
if (is_file(__DIR__ . '/break_waiver_finalize_guard.php')) {
    require_once __DIR__ . '/break_waiver_finalize_guard.php';
}

if (!isset($_SESSION['user_id'])) { die("Session Expired."); }

// --- LOGIC: IF USER CLICKED "CONFIRM & PROCESS" ---
if (isset($_POST['confirm_finalize']) && !empty($_POST['final_ids'])) {
    try {
        $pdo->beginTransaction();
        $ids = array_map('intval', $_POST['final_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sql = "SELECT a.*, u.hourly_rate, u.id as user_id, s.state, s.country, s.nick_name,
                       TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(NULLIF(a.clock_out, '0000-00-00 00:00:00'), NOW())) as gross_seconds,
                       (SELECT SUM(ABS(TIMESTAMPDIFF(SECOND, break_start, break_end))) FROM breaks WHERE shift_id = a.id) as total_break_seconds
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                JOIN sites s ON a.site_id = s.SId
                WHERE a.id IN ($placeholders)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // MATH RECAP
            $work_secs = (float)$row['gross_seconds'];
            $break_secs = (float)($row['total_break_seconds'] ?? ($work_secs > 18000 ? 1800 : 0));
            $net_hrs = round(max(0, ($work_secs - $break_secs) / 3600), 2);
            
            if ($net_hrs > 8.00) continue; // Final safety check

            $as_of = !empty($row['clock_in']) ? date('Y-m-d', strtotime($row['clock_in'])) : date('Y-m-d');
            $rate = pe_profile_hourly_rate($pdo, (int)$row['user_id'], $as_of);
            $reg_pay = round($net_hrs * $rate, 2);
            $vac = round($reg_pay * 0.04, 2);
            $gross = $reg_pay + $vac;
            $cpp = round($reg_pay * 0.0595, 2);
            $ei = round($reg_pay * 0.0163, 2);
            $net_cheque = round($gross - ($cpp + $ei), 2);

            // 1. BALANCE TABLE
            $stmtBal = $pdo->prepare("INSERT INTO payroll_balance (user_id, attendance_id, site_id, reg_hours, hourly_rate, total_gross, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmtBal->execute([$row['user_id'], $row['id'], $row['site_id'], $net_hrs, $rate, $reg_pay]);

            // 2. FINALIZED TABLE
            $stmtFin = $pdo->prepare("INSERT INTO payroll_finalized (attendance_id, user_id, org_id, site_id, country_code, work_date, reg_hours, hourly_rate, reg_pay, vac, total_gross, cpp, ei, paid_amount, status, finalized_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?)");
            $stmtFin->execute([$row['id'], $row['user_id'], $row['org_id'], $row['site_id'], $row['country'], $row['clock_in'], $net_hrs, $rate, $reg_pay, $vac, $gross, $cpp, $ei, $net_cheque, $_SESSION['user_id']]);

            // 3. UPDATE ATTENDANCE
            $pdo->prepare("UPDATE attendance SET payout_status = 1 WHERE id = ?")->execute([$row['id']]);
        }
        $pdo->commit();
        header("Location: staff_audit.php?msg=Processed"); exit;
    } catch (Exception $e) { $pdo->rollBack(); die("Error: " . $e->getMessage()); }
}

// --- LOGIC: INITIAL PREVIEW (WHAT YOU SEE NOW) ---
if (empty($_POST['shift_ids'])) { header("Location: staff_audit.php"); exit; }
$ids = array_map('intval', $_POST['shift_ids']);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT a.*, u.Name as staff_name, u.hourly_rate, s.nick_name,
               TIMESTAMPDIFF(SECOND, a.clock_in, COALESCE(NULLIF(a.clock_out, '0000-00-00 00:00:00'), NOW())) as gross_seconds,
               (SELECT SUM(ABS(TIMESTAMPDIFF(SECOND, break_start, break_end))) FROM breaks WHERE shift_id = a.id) as total_break_seconds
        FROM attendance a JOIN users u ON a.user_id = u.id JOIN sites s ON a.site_id = s.SId WHERE a.id IN ($placeholders)";
$stmt = $pdo->prepare($sql); $stmt->execute($ids); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Preview</title>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 30px; background: #f1f5f9; color: #1e293b; }
        .card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #1e293b; color: white; padding: 12px; text-align: left; font-size: 12px; }
        td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        .over-limit { background: #fee2e2; opacity: 0.7; }
        .badge-ready { background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; }
        .btn-confirm { background: #10b981; color: white; padding: 12px 24px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
<div class="card">
    <h2>🔍 Payroll Finalization Preview</h2>
    <form method="POST">
        <table>
            <thead><tr><th>STAFF</th><th>SITE</th><th>HRS</th><th>RATE</th><th>REG PAY</th><th>VAC</th><th>GROSS</th><th>DEDUCT</th><th>NET</th><th>STATUS</th></tr></thead>
            <tbody>
                <?php $total_net = 0; foreach ($rows as $r): 
                    $work = (float)$r['gross_seconds']; $brk = (float)($r['total_break_seconds'] ?? ($work > 18000 ? 1800 : 0));
                    $hrs = round(max(0, ($work - $brk) / 3600), 2); $as_of = !empty($r['clock_in']) ? date('Y-m-d', strtotime($r['clock_in'])) : date('Y-m-d'); $rate = pe_profile_hourly_rate($pdo, (int)$r['user_id'], $as_of);
                    $reg = round($hrs * $rate, 2); $vac = round($reg * 0.04, 2); $gross = $reg + $vac;
                    $ded = round(($reg * 0.0595) + ($reg * 0.0163), 2); $net = $gross - $ded;
                    $is_over = ($hrs > 8.00); if (!$is_over) { $total_net += $net; echo '<input type="hidden" name="final_ids[]" value="'.$r['id'].'">'; }
                ?>
                <tr class="<?= $is_over ? 'over-limit' : '' ?>">
                    <td><?= $r['staff_name'] ?></td><td><?= $r['nick_name'] ?></td><td><?= $hrs ?>h</td><td>$<?= $rate ?></td>
                    <td>$<?= $reg ?></td><td>$<?= $vac ?></td><td>$<?= $gross ?></td><td>-$<?= $ded ?></td><td><strong>$<?= $net ?></strong></td>
                    <td><?= $is_over ? '<span style="color:red;">SKIP</span>' : '<span class="badge-ready">READY</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:30px; display:flex; justify-content: space-between; align-items:center;">
            <a href="staff_audit.php" style="color:#64748b; text-decoration:none;">⬅️ Cancel & Go Back</a>
            <div>
                <span style="font-size:20px; font-weight:800; margin-right:20px;">Total Net: $<?= number_format($total_net, 2) ?></span>
                <button type="submit" name="confirm_finalize" class="btn-confirm">✅ Confirm & Process Finalized Records</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>