<?php
/** Path: /manager_dashboard_test.php */
session_start();
require_once 'db.php'; 
date_default_timezone_set('America/Vancouver');

if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
include 'menu_include_test.php'; 
?>

<style>
    .date-row { background: #0097a7; color: white; padding: 12px; font-weight: bold; margin-top: 25px; border-radius: 4px; }
    .main-table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .main-table th { background: #1e293b; color: white; padding: 10px; font-size: 12px; text-align: left; }
    .main-table td { padding: 12px; border: 1px solid #e2e8f0; vertical-align: top; font-size: 13px; }
    .toggle-btn { color: #0284c7; cursor: pointer; text-decoration: underline; font-size: 12px; font-weight: bold; display: block; margin-bottom: 5px; }
    .hidden-box { display: none; background: #f8fafc; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; margin-top: 5px; }
    .thumb { width: 45px; height: 45px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; margin: 2px; }
    .pin { color: #e11d48; text-decoration: none; font-size: 12px; display: block; margin-bottom: 4px; }
</style>

<div style="padding: 20px; font-family: sans-serif;">
    <h2>Staff Activity Logs</h2>

    <?php
    $sql = "SELECT a.*, u.Name as staff_name, s.nick_name, DATE(a.clock_in) as s_date 
            FROM attendance a 
            LEFT JOIN users u ON a.user_id = u.id 
            LEFT JOIN sites s ON a.site_id = s.SId 
            ORDER BY a.clock_in DESC";
    $stmt = $pdo->query($sql);

    $last_date = "";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($last_date != $row['s_date']) {
            if ($last_date != "") echo "</tbody></table>";
            $last_date = $row['s_date'];
            echo "<div class='date-row'>" . date('l, F j, Y', strtotime($last_date)) . "</div>";
            echo "<table class='main-table'>
                    <thead>
                        <tr>
                            <th width='15%'>Staff / Site</th>
                            <th width='15%'>Shift Time</th>
                            <th width='15%'>Break Times</th>
                            <th width='15%'>Geo Pins</th>
                            <th width='20%'>Selfies</th>
                            <th width='10%'>Net Hours</th>
                        </tr>
                    </thead>
                    <tbody>";
        }

        $b_stmt = $pdo->prepare("SELECT * FROM breaks WHERE shift_id = ? ORDER BY break_start ASC");
        $b_stmt->execute([$row['id']]);
        $breaks = $b_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculation logic
        $brk_sec = 0;
        foreach($breaks as $b) { if($b['break_end']) $brk_sec += (strtotime($b['break_end']) - strtotime($b['break_start'])); }
        $work_end = $row['clock_out'] ? strtotime($row['clock_out']) : time();
        $net_hrs = round((($work_end - strtotime($row['clock_in'])) - $brk_sec) / 3600, 2);
    ?>
        <tr>
            <td><strong><?= $row['staff_name'] ?></strong><br><small><?= $row['nick_name'] ?></small></td>
            <td>IN: <?= date('g:i a', strtotime($row['clock_in'])) ?><br>OUT: <?= $row['clock_out'] ? date('g:i a', strtotime($row['clock_out'])) : '--' ?></td>
            
            <td>
                <?php if($breaks): ?>
                    <span class="toggle-btn" onclick="toggle('br_<?= $row['id'] ?>')">View (<?= count($breaks) ?>)</span>
                    <div id="br_<?= $row['id'] ?>" class="hidden-box">
                        <?php foreach($breaks as $i => $b): ?>
                            Br<?= $i+1 ?>: <?= date('g:i a', strtotime($b['break_start'])) ?> - <?= $b['break_end'] ? date('g:i a', strtotime($b['break_end'])) : '...' ?><br>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>--<?php endif; ?>
            </td>

            <td>
                <span class="toggle-btn" onclick="toggle('geo_<?= $row['id'] ?>')">View Map Pins</span>
                <div id="geo_<?= $row['id'] ?>" class="hidden-box">
                    <a href="http://maps.google.com/?q=<?= $row['lat_in'] ?>,<?= $row['lng_in'] ?>" target="_blank" class="pin">📍 Shift Start</a>
                    <?php foreach($breaks as $i => $b): ?>
                        <a href="http://maps.google.com/?q=<?= $b['start_lat'] ?>,<?= $b['start_lng'] ?>" target="_blank" class="pin">📍 Br<?= $i+1 ?> Start</a>
                        <?php if($b['end_lat']): ?>
                            <a href="http://maps.google.com/?q=<?= $b['end_lat'] ?>,<?= $b['end_lng'] ?>" target="_blank" class="pin">📍 Br<?= $i+1 ?> End</a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if($row['lat_out']): ?>
                        <a href="http://maps.google.com/?q=<?= $row['lat_out'] ?>,<?= $row['lng_out'] ?>" target="_blank" class="pin">📍 Shift End</a>
                    <?php endif; ?>
                </div>
            </td>

            <td>
                <span class="toggle-btn" onclick="toggle('img_<?= $row['id'] ?>')">View Selfies</span>
                <div id="img_<?= $row['id'] ?>" class="hidden-box">
                    <img src="<?= $row['selfie_in'] ?>" class="thumb" title="Start">
                    <?php foreach($breaks as $b): ?>
                        <?php if($b['start_selfie']): ?><img src="<?= $b['start_selfie'] ?>" class="thumb" title="Br Start"><?php endif; ?>
                        <?php if($b['end_selfie']): ?><img src="<?= $b['end_selfie'] ?>" class="thumb" title="Br End"><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if($row['selfie_out']): ?>
                        <img src="<?= $row['selfie_out'] ?>" class="thumb" title="End">
                    <?php endif; ?>
                </div>
            </td>

            <td style="font-weight:bold; color:#0097a7;"><?= $net_hrs ?>h</td>
        </tr>
    <?php } ?>
    </tbody></table>
</div>

<script>
function toggle(id) {
    var x = document.getElementById(id);
    x.style.display = (x.style.display === "block") ? "none" : "block";
}
</script>