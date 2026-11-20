<?php
/** Path: /home/admin/domains/payroll.renocon.ca/public_html/process_kiosk.php */
session_start();
require_once 'db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $action = $_POST['action'] ?? '';
    $lat    = $_POST['lat'] ?? '0.00';
    $lng    = $_POST['lng'] ?? '0.00';
    $selfie_raw = $_POST['selfie'] ?? ''; 
    $uid    = $_SESSION['user_id'];
    $oid    = $_SESSION['org_id'] ?? 0;
    $sid    = $_POST['sid'] ?? null;

    // --- STEP 1: SAVE THE IMAGE FILE ---
    $selfie_path = "";
    if (!empty($selfie_raw)) {
        // Create unique name: punch_USERID_TIMESTAMP.jpg
        $img_name = "punch_" . $uid . "_" . time() . ".jpg";
        $selfie_path = "uploads/" . $img_name;
        
        // Convert Base64 string to actual image file
        $data = str_replace('data:image/jpeg;base64,', '', $selfie_raw);
        $data = str_replace(' ', '+', $data);
        file_put_contents($selfie_path, base64_decode($data));
    }

    try {
        if ($action == 'clock_in') {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, org_id, site_id, clock_in, lat_in, lng_in, selfie_in, status) VALUES (?, ?, ?, NOW(), ?, ?, ?, 'working')");
            $stmt->execute([$uid, $oid, $sid, $lat, $lng, $selfie_path]);
            aa_log($pdo, 'create', (int)$pdo->lastInsertId(), null, null, 'Kiosk clock in');
        } 
        
        elseif ($action == 'break_start') {
            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$uid]);
            $att = $stmt->fetch();
            
            if ($att) {
                $ins = $pdo->prepare("INSERT INTO breaks (shift_id, break_start, start_selfie, start_lat, start_lng) VALUES (?, NOW(), ?, ?, ?)");
                $ins->execute([$att['id'], $selfie_path, $lat, $lng]);
                
                $aaId = (int)$att['id'];
                $aaBefore = aa_snapshot($pdo, $aaId);
                $pdo->prepare("UPDATE attendance SET status = 'on_break' WHERE id = ?")->execute([$att['id']]);
                aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Kiosk break start');
            }
        }

        elseif ($action == 'break_end') {
            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND status = 'on_break' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$uid]);
            $att = $stmt->fetch();

            if ($att) {
                $upd = $pdo->prepare("UPDATE breaks SET break_end = NOW(), end_selfie = ?, end_lat = ?, end_lng = ? WHERE shift_id = ? AND break_end IS NULL ORDER BY id DESC LIMIT 1");
                $upd->execute([$selfie_path, $lat, $lng, $att['id']]);
                
                $aaId = (int)$att['id'];
                $aaBefore = aa_snapshot($pdo, $aaId);
                $pdo->prepare("UPDATE attendance SET status = 'working' WHERE id = ?")->execute([$att['id']]);
                aa_log($pdo, 'update', $aaId, $aaBefore, null, 'Kiosk break end');
            }
        }

        elseif ($action == 'clock_out') {
            // Auto-close breaks if still open
            $stmt = $pdo->prepare("UPDATE breaks b 
                                   JOIN attendance a ON b.shift_id = a.id 
                                   SET b.break_end = NOW() 
                                   WHERE a.user_id = ? AND a.clock_out IS NULL AND b.break_end IS NULL");
            $stmt->execute([$uid]);

            $aaIds = aa_ids_where($pdo, 'user_id = ? AND clock_out IS NULL', [$uid]);
            $aaBefore = aa_befores($pdo, $aaIds);
            $stmt = $pdo->prepare("UPDATE attendance SET clock_out = NOW(), lat_out = ?, lng_out = ?, selfie_out = ?, status = 'completed' WHERE user_id = ? AND clock_out IS NULL");
            $stmt->execute([$lat, $lng, $selfie_path, $uid]);
            aa_log_ids($pdo, 'update', $aaIds, $aaBefore, 'Kiosk clock out');
        }

        echo json_encode(['success' => true]);
        exit;

    } catch (Exception $e) {
        // Return JSON error so the "Connection Lost" message stops
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
}