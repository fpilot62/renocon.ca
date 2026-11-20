<?php
/** Path: /engine/transfer_engine.php */

if (isset($_POST['action'])) {
    $action = $_POST['action']; // 'transfer_out' or 'transfer_in'
    $user_id = $_SESSION['user_id'];
    $now = date('Y-m-d H:i:s');

    if ($action == 'transfer_out') {
        // CLOSE SHIFT A
        $sql = "UPDATE attendance SET 
                clock_out = ?, 
                selfie_out = ?, 
                lat_out = ?, 
                lng_out = ?, 
                transfer_status = 'out' 
                WHERE user_id = ? AND clock_out IS NULL";
        $pdo->prepare($sql)->execute([$now, $_POST['selfie'], $_POST['lat'], $_POST['lng'], $user_id]);
        echo json_encode(['status' => 'In Transit']);
    } 

    if ($action == 'transfer_in') {
        // 1. Find the shift we just transferred FROM
        $stmt = $pdo->prepare("SELECT id, clock_out FROM attendance WHERE user_id = ? AND transfer_status = 'out' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $prev_shift = $stmt->fetch();

        // 2. OPEN SHIFT B
        $sql = "INSERT INTO attendance (user_id, site_id, org_id, clock_in, selfie_in, lat_in, lng_in, transfer_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')";
        $pdo->prepare($sql)->execute([$user_id, $_POST['site_id'], $_SESSION['org_id'], $now, $_POST['selfie'], $_POST['lat'], $_POST['lng']]);
        
        echo json_encode(['status' => 'Arrived at Site B']);
    }
}