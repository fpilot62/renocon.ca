<?php
/**
 * Path: /alarm/api/get_alarms.php
 * Method: GET
 * Headers: Authorization: Bearer <token>
 * Query: ?range=upcoming|past|all  (default: all)
 *
 * Returns alarms for the logged-in user:
 * - Created by them
 * - Assigned to them (by email or shared_user_ids)
 * - Within 5 days past and all future
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../db.php';
require_once 'alarm_jwt_helper.php';
$auth = alarm_require_auth();
$uid    = (int)$auth['user_id'];
$range  = $_GET['range'] ?? 'all';

try {
    // Get user email for matching assigned alarms
    $uStmt = $pdo->prepare("SELECT email, tel as Tel_Mobile FROM alarm_users WHERE user_id = ? LIMIT 1");
    $uStmt->execute([$uid]);
    $uRow  = $uStmt->fetch(PDO::FETCH_ASSOC);
    $email = $uRow['email'] ?? '';

    // Date boundaries
    $past_limit = date('Y-m-d', strtotime('-30 days'));
    $today      = date('Y-m-d');

    // Build query based on range
    $date_condition = '';
    if ($range === 'upcoming') {
        $date_condition = "AND t.task_date >= '$today'";
    } elseif ($range === 'past') {
        $date_condition = "AND t.task_date >= '$past_limit' AND t.task_date < '$today'";
    } else {
        $date_condition = "AND t.task_date >= '$past_limit'";
    }

    $stmt = $pdo->prepare("
        SELECT t.*,
               u.Name as creator_name,
               u.Tel_Mobile as creator_phone
        FROM alarm_tasks t
        LEFT JOIN users u ON u.id = t.created_by
        WHERE (t.is_active = 1 OR t.status IN ('Completed', 'done', 'Done'))
          AND (
              t.created_by = ?
              OR t.assigned_to_email = ?
              OR t.assigned_email = ?
              OR FIND_IN_SET(?, REPLACE(t.shared_user_ids, ' ', ''))
          )
          $date_condition
        ORDER BY t.task_date DESC, t.task_time DESC
    ");
    $stmt->execute([$uid, $email, $email, $uid]);
    $alarms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for mobile
    foreach ($alarms as &$alarm) {
        $alarm['task_id']       = (int)$alarm['task_id'];
        $alarm['is_flagged']    = (bool)($alarm['is_flagged'] ?? false);
        $alarm['is_active']     = (bool)$alarm['is_active'];
        $alarm['snooze_minutes']= (int)($alarm['snooze_minutes'] ?? 5);
        $alarm['notify_before'] = (int)($alarm['notify_before'] ?? 30);
        $alarm['fired_count']   = (int)($alarm['fired_count'] ?? 0);
        // Check if this alarm is due soon (within notify_before minutes)
        $alarm_ts    = strtotime($alarm['task_date'] . ' ' . $alarm['task_time']);
        $now_ts      = time();
        $diff_mins   = ($alarm_ts - $now_ts) / 60;
        $alarm['is_due_soon']  = ($diff_mins >= 0 && $diff_mins <= $alarm['notify_before']);
        $alarm['is_overdue']   = ($diff_mins < 0 && $alarm['status'] !== 'Completed');
        $alarm['minutes_until']= round($diff_mins);
    }

    echo json_encode(['success' => true, 'alarms' => $alarms, 'count' => count($alarms)]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}


/**
 * ============================================================
 * FILE: alarm/api/get_alarms.php
 * LAST MODIFIED: June 2026
 * ============================================================
 * OBJECTIVE:
 *   Return alarms for the authenticated user from alarm_tasks table.
 *
 * LOGIC:
 *   - Validates JWT via alarm_require_auth()
 *   - Gets user email from alarm_users table
 *   - Returns alarms where user is creator OR assigned
 *   - Calculates is_overdue and is_due_soon flags
 *   - Default range: 5 days past to all future
 *
 * PROCESS:
 *   1. Bearer token validated by alarm_require_auth()
 *   2. Get user email from alarm_users by user_id
 *   3. Query alarm_tasks by created_by OR assigned_to_email
 *   4. Add computed fields: is_overdue, is_due_soon, minutes_until
 *   5. Return JSON array
 *
 * CHANGES LOG:
 *   - June 2026: Switched from jwt_helper.php to alarm_jwt_helper.php
 *   - June 2026: Switched user lookup from users to alarm_users table
 * ============================================================
 */