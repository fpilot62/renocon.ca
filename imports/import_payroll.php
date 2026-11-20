<?php
/**
 * Path: /imports/import_payroll.php
 */
session_start();
require_once '../db.php'; 

$target_org_id = 2; 
$preview = [];

// Fetch ALL active employees for dropdowns
$all_users_stmt = $pdo->prepare("SELECT id, Name, primary_site_id FROM users WHERE org_id = ? AND active = 1 ORDER BY Name ASC");
$all_users_stmt->execute([$target_org_id]);
$all_users = $all_users_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['payroll_file'])) {
    $handle = fopen($_FILES['payroll_file']['tmp_name'], "r");
    fgetcsv($handle); fgetcsv($handle); // Skip 2 headers

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (empty($data[0])) continue;
        $csv_name = trim($data[0]);
        
        $uStmt = $pdo->prepare("SELECT id, Name, primary_site_id FROM users WHERE Name = ? AND org_id = ? LIMIT 1");
        $uStmt->execute([$csv_name, $target_org_id]);
        $exact = $uStmt->fetch(PDO::FETCH_ASSOC);

        $status = $exact ? 'exact' : 'partial';
        $others = [];
        if (!$exact) {
            $fuzzy = substr($csv_name, 0, 3);
            $pStmt = $pdo->prepare("SELECT id, Name, primary_site_id FROM users WHERE Name LIKE ? AND org_id = ? LIMIT 10");
            $pStmt->execute(["%$fuzzy%", $target_org_id]);
            $others = $pStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($others)) $status = 'none';
        }

        $preview[] = ['csv_name'=>$csv_name, 'date'=>$data[1], 'in'=>$data[4], 'out'=>$data[5], 'status'=>$status, 'match'=>$exact, 'others'=>$others];
    }
    fclose($handle);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payroll Sync</title>
    <style>
        body { font-family: sans-serif; background: #f1f5f9; padding: 20px; }
        .container { max-width: 1100px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .match-exact { background: #f0fdf4; } .match-partial { background: #fffbeb; } .match-none { background: #fef2f2; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { padding: 8px; border: 1px solid #e2e8f0; }
        .btn { background: #10b981; color: white; padding: 12px 25px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h2>Payroll Sync Analysis</h2>
    <?php if (empty($preview)): ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="payroll_file" required><br><br>
            <button type="submit" class="btn" style="background:#6366f1;">Analyze CSV</button>
        </form>
    <?php else: ?>
        <form method="POST" action="import_commit.php">
            <div style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:10px;">
                <label><b>Clear Range:</b></label>
                <input type="date" name="from_date" required> to <input type="date" name="to_date" required>
                <input type="hidden" name="target_org_id" value="2">
            </div>
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" checked onchange="const cbs=document.querySelectorAll('.row-cb'); cbs.forEach(c=>c.checked=this.checked);"> All</th>
                        <th>Name in CSV</th>
                        <th>Assign to User</th>
                        <th>Shift</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $i => $row): ?>
                        <tr class="match-<?= $row['status'] ?>">
                            <td align="center"><input type="checkbox" name="rows[<?= $i ?>][active]" class="row-cb" checked></td>
                            <td><strong><?= htmlspecialchars($row['csv_name']) ?></strong></td>
                            <td>
                                <?php if ($row['status'] === 'exact'): ?>
                                    <input type="hidden" name="rows[<?= $i ?>][user_id]" value="<?= $row['match']['id'] ?>">
                                    <input type="hidden" name="rows[<?= $i ?>][site_id]" value="<?= $row['match']['primary_site_id'] ?>">
                                    <b>✓ <?= $row['match']['Name'] ?></b>
                                <?php else: ?>
                                    <select name="rows[<?= $i ?>][user_data]" class="u-sel" data-name="<?= htmlspecialchars($row['csv_name']) ?>" onchange="const val=this.value; document.querySelectorAll('.u-sel').forEach(s=>{if(s.dataset.name===this.dataset.name)s.value=val;});">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($row['others'] as $o): ?><option value="<?= $o['id'] ?>|<?= $o['primary_site_id'] ?>"><?= $o['Name'] ?></option><?php endforeach; ?>
                                        <optgroup label="All Staff">
                                            <?php foreach ($all_users as $u): ?><option value="<?= $u['id'] ?>|<?= $u['primary_site_id'] ?>"><?= $u['Name'] ?></option><?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td><?= $row['date'] ?> (<?= $row['in'] ?>-<?= $row['out'] ?>)
                                <input type="hidden" name="rows[<?= $i ?>][clock_in]" value="<?= date('Y-m-d H:i:s', strtotime($row['date']." ".$row['in'])) ?>">
                                <input type="hidden" name="rows[<?= $i ?>][clock_out]" value="<?= date('Y-m-d H:i:s', strtotime($row['date']." ".$row['out'])) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <br><button type="submit" name="confirm_import" class="btn">FINAL COMMIT TO DATABASE</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>