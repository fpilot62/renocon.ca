<?php
/** Path: /finalize_dashboard.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$path_to_root = './'; 
require_once 'db.php';

if(!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$oid = $_SESSION['org_id'] ?? 1;

try {
    // Fetching data to finalize
    $sql = "SELECT a.*, u.Name as employee_name, s.nick_name 
            FROM attendance a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN sites s ON a.site_id = s.SId
            WHERE a.org_id = :oid AND a.clock_out IS NOT NULL AND a.is_finalized = 0
            ORDER BY a.clock_in DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['oid' => $oid]);
    $to_finalize = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        /* THE WHITE THEME FORCED */
        :root { --bg: #ffffff !important; --main-bg: #ffffff !important; }
        html, body, .main-wrapper, #main-wrapper { background-color: #ffffff !important; color: #333 !important; margin: 0; font-family: sans-serif; }
        
        .container { padding: 30px; max-width: 1200px; margin: 0 auto; }
        .finalize-card { background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #f8fafc; color: #475569; padding: 12px; text-align: left; border-bottom: 2px solid #e2e8f0; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; }
        
        .btn-finalize { background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'engine/menu_generator.php'; ?>
    <div class="container">
        <div class="finalize-card">
            <h2>Finalize Payroll Period</h2>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Shift Date</th>
                        <th>Total Hours</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($to_finalize as $row): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($row['employee_name']) ?></strong></td>
                        <td><?= date('d M Y', strtotime($row['clock_in'])) ?></td>
                        <td><?= number_format($row['total_hours'], 2) ?> h</td>
                        <td><button class="btn-finalize">Approve</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>