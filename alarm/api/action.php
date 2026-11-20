<?php
/**
 * Path: /alarm/api/action.php
 * Method: POST
 * Headers: Authorization: Bearer <token>
 *
 * Body:
 * { "action": "snooze|stop|delete|accept", "task_id": 123 }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../db.php';
require_once 'alarm_jwt_helper.php';
$auth = alarm_require_auth();
require_once 'config.php';

$uid     = (int)$auth['user_id'];

$body    = json_decode(file_get_contents('php://input'), true);
$action  = trim($body['action']  ?? '');
$task_id = (int)($body['task_id'] ?? 0);

if (!$task_id) {
    echo json_encode(['success' => false, 'error' => 'task_id required']);
    exit;
}

try {
    // Fetch the alarm
    $stmt = $pdo->prepare("SELECT * FROM alarm_tasks WHERE task_id = ? LIMIT 1");
    $stmt->execute([$task_id]);
    $alarm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$alarm) {
        echo json_encode(['success' => false, 'error' => 'Alarm not found']);
        exit;
    }

    switch ($action) {

        case 'snooze':
            $snooze_mins  = (int)($alarm['snooze_minutes'] ?? 5);
            if ($snooze_mins < 1) $snooze_mins = 5;
            $snoozed_until = date('Y-m-d H:i:s', strtotime("+{$snooze_mins} minutes"));
            $pdo->prepare("UPDATE alarm_tasks SET snoozed_until=?, status='Pending' WHERE task_id=?")
                ->execute([$snoozed_until, $task_id]);
            echo json_encode([
                'success'       => true,
                'message'       => "Snoozed for {$snooze_mins} minutes",
                'snoozed_until' => $snoozed_until,
            ]);
            break;

        case 'stop':
            // Check if repeating
            $next_date = null;
            if (!empty($alarm['repeat_type']) && $alarm['repeat_type'] !== 'none') {
                $current = strtotime($alarm['task_date']);
                switch ($alarm['repeat_type']) {
                    case 'hourly':  $next = strtotime('+1 hour', $current);  break;
                    case 'daily':   $next = strtotime('+1 day',  $current);  break;
                    case 'weekly':  $next = strtotime('+1 week', $current);  break;
                    case 'monthly': $next = strtotime('+1 month',$current);  break;
                    case 'yearly':  $next = strtotime('+1 year', $current);  break;
                    default: $next = null;
                }
                if ($next) {
                    $next_date = date('Y-m-d', $next);
                    // Check expiry
                    if (!empty($alarm['repeat_expires']) && $next_date > $alarm['repeat_expires']) {
                        $next_date = null;
                    }
                }
            }

            if ($next_date) {
                // Advance to next occurrence
                $pdo->prepare("UPDATE alarm_tasks SET task_date=?, status='Pending', snoozed_until=NULL, fired_count=fired_count+1, last_fired=NOW() WHERE task_id=?")
                    ->execute([$next_date, $task_id]);
                echo json_encode(['success' => true, 'message' => "Alarm completed. Next occurrence: {$next_date}", 'next_date' => $next_date]);
            } else {
                // No repeat — mark completed
                $pdo->prepare("UPDATE alarm_tasks SET status='Completed', is_active=0, fired_count=fired_count+1, last_fired=NOW() WHERE task_id=?")
                    ->execute([$task_id]);
                echo json_encode(['success' => true, 'message' => 'Alarm stopped and completed']);
            }
            break;

        case 'delete':
            // Only creator can delete
            if ((int)$alarm['created_by'] !== $uid) {
                echo json_encode(['success' => false, 'error' => 'Only the alarm creator can delete it']);
                exit;
            }
            $pdo->prepare("DELETE FROM alarm_tasks WHERE task_id=?")->execute([$task_id]);
            echo json_encode(['success' => true, 'message' => 'Alarm deleted']);
            break;

        case 'accept':
            $pdo->prepare("UPDATE alarm_tasks SET status='Active' WHERE task_id=?")->execute([$task_id]);
            // Notify creator
            $creator = $pdo->prepare("SELECT Tel_Mobile, Name FROM users WHERE id=? LIMIT 1");
            $creator->execute([$alarm['created_by']]);
            $cRow = $creator->fetch(PDO::FETCH_ASSOC);
            if ($cRow && !empty($cRow['Tel_Mobile'])) {
                sendSMS($cRow['Tel_Mobile'], APP_NAME . ": {$auth['name']} accepted your task \"{$alarm['title']}\".");
            }
            echo json_encode(['success' => true, 'message' => 'Alarm accepted and set to Active']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action. Use: snooze, stop, delete, accept']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
