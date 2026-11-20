<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f7f6; margin: 0; color: #333; }
        .container { padding: 30px; max-width: 1100px; margin: auto; }
        .staff-welcome { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center; border-top: 5px solid #0097a7; }
        .date-badge { background: #e0f2f1; color: #00796b; padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 0.9em; }
        
        /* Admin Table Styles */
        .date-row { background: #37474f; color: white; padding: 10px 15px; font-weight: bold; margin-top: 20px; border-radius: 4px 4px 0 0; }
        table { width: 100%; border-collapse: collapse; background: white; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        th { background: #cfd8dc; color: #455a64; padding: 12px; text-align: left; font-size: 0.85em; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #eceff1; }
        .thumb { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<?php include 'menu_generator.php'; ?>
<div class="container">

    <?php if ($_SESSION['user_level'] == 4): ?>
        <div class="staff-welcome">
            <span class="date-badge"><?= date('l, F j, Y') ?></span>
            <h1 style="margin-top:20px;">Welcome, <?= htmlspecialchars($_SESSION['user_name']) ?></h1>
            <p style="color: #666; font-size: 1.1em; line-height: 1.6;">
                Your payroll profile is active.<br>
                Attendance data is currently being processed for the next pay cycle.<br>
                Please contact your Supervisor for specific hours-balance inquiries.
            </p>
        </div>
    <?php else: ?>
        <h2><?= ucfirst($role_context) ?> Control Panel</h2>
        <?php if(empty($grouped_logs)): ?>
            <div style="background:white; padding:20px; border-radius:8px; text-align:center;">No attendance records found for your sites.</div>
        <?php else: ?>
            <?php foreach ($grouped_logs as $date => $logs): ?>
                <div class="date-row"><?= date('M d, Y', strtotime($date)) ?></div>
                <table>
                    <thead>
                        <tr><th>Staff Member</th><th>Location</th><th>Clock In</th><th>Clock Out</th><th>Selfie</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><b><?= htmlspecialchars($l['StaffName']) ?></b></td>
                            <td><?= htmlspecialchars($l['SiteDisplayName'] ?? 'Main Office') ?></td>
                            <td><?= date('g:i A', strtotime($l['clock_in'])) ?></td>
                            <td><?= $l['clock_out'] ? date('g:i A', strtotime($l['clock_out'])) : '<span style="color:#d32f2f"><i>On Clock</i></span>' ?></td>
                            <td>
                                <?php if($l['selfie_in']): ?>
                                    <img src="<?= $path_to_root . $l['selfie_in'] ?>" class="thumb">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

</div>
</body>
</html>