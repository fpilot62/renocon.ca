<?php
/** Path: /finalize_dashboard.php */
require_once 'db.php';
include 'menu_include.php';

// Distance calculation for the 10m Geofence Rule
function getDistance($lat1, $lon1, $lat2, $lon2) {
    $r = 6371000; 
    $p = pi() / 180;
    $a = 0.5 - cos(($lat2 - $lat1) * $p) / 2 + cos($lat1 * $p) * cos($lat2 * $p) * (1 - cos(($lon2 - $lon1) * $p)) / 2;
    return 2 * $r * asin(sqrt($a));
}

// Fetch shifts using your exact table columns: `site id`, `user id`, and `clock out`
$query = "SELECT a.*, s.nick_name, s.target_lat, s.target_lng, s.geo_radius_meters, s.max_hours, u.Name as emp_name
          FROM attendance a
          JOIN sites s ON a.`site id` = s.SId
          JOIN users u ON a.`user id` = u.id
          WHERE a.`is finalized` = 0";

$stmt = $pdo->query($query);
?>

<div class="container" style="margin-top:20px;">
    <h2>Payroll Audit Dashboard</h2>
    <table class="table table-bordered table-striped">
        <thead class="thead-dark">
            <tr>
                <th>Employee</th>
                <th>Site</th>
                <th>Geofence (10m)</th>
                <th>Audit Hrs (-30m Break)</th>
                <th>Govt Pay (Max)</th>
                <th>Bank Adjust</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $stmt->fetch()): 
                // Rule 1: Geofence Check
                $dist = getDistance($row['target_lat'], $row['target_lng'], $row['lat'], $row['Ing']);
                $is_violation = ($dist > ($row['geo_radius_meters'] ?? 10));

                // Rule 2: 30m Break Logic (if shift >= 8h)
                $raw_hrs = (strtotime($row['clock out']) - strtotime($row['clock_in'])) / 3600;
                $audit_hrs = ($raw_hrs >= 8) ? ($raw_hrs - 0.5) : $raw_hrs;

                // Rule 3: Govt Max Requirement (Balance Logic)
                $max_allowed = $row['max_hours'] ?? 8.0;
                $balance_diff = $audit_hrs - $max_allowed; 
            ?>
            <tr>
                <td><?= htmlspecialchars($row['emp_name']) ?></td>
                <td><?= htmlspecialchars($row['nick_name']) ?></td>
                <td style="color: <?= $is_violation ? 'red' : 'green' ?>; font-weight:bold;">
                    <?= round($dist, 1) ?>m <?= $is_violation ? '⚠️' : '✅' ?>
                </td>
                <td><?= number_format($audit_hrs, 2) ?>h</td>
                <td><b><?= number_format($max_allowed, 2) ?>h</b></td>
                <td style="color: <?= ($balance_diff < 0) ? '#d9534f' : '#337ab7' ?>; font-weight:bold;">
                    <?= ($balance_diff > 0 ? '+' : '') . number_format($balance_diff, 2) ?>h
                </td>
                <td>
                    <a href="process_finalize.php?id=<?= $row['id'] ?>" class="btn btn-success btn-sm">Confirm & Pay</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>