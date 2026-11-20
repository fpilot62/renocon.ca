<?php
/**
 * Path: /imports/import_commit.php
 */
session_start();
require_once '../db.php';

ini_set('max_execution_time', 600); 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_org_id = (int)($_POST['target_org_id'] ?? 2);
    $from = $_POST['from_date'];
    $to = $_POST['to_date'];
    $rows = $_POST['rows'] ?? [];

    try {
        $pdo->beginTransaction();

        // 1. Clear existing Status 4 for the range
        $clear = $pdo->prepare("DELETE FROM attendance WHERE org_id = ? AND status = '4' AND DATE(clock_in) BETWEEN ? AND ?");
        $clear->execute([$target_org_id, $from, $to]);

        // 2. Insert records
        $ins = $pdo->prepare("INSERT INTO attendance (user_id, site_id, org_id, clock_in, clock_out, status, Remarks) VALUES (?, ?, ?, ?, ?, '4', ?)");

        foreach ($rows as $r) {
            if (!isset($r['active'])) continue;

            $u_id = isset($r['user_id']) ? $r['user_id'] : null;
            $s_id = isset($r['site_id']) ? $r['site_id'] : null;

            if (!$u_id && !empty($r['user_data'])) {
                $parts = explode('|', $r['user_data']);
                $u_id = $parts[0];
                $s_id = $parts[1] ?? 0;
            }

            if ($u_id) {
                $receipt = "Orig In: " . date('M j, H:i', strtotime($r['clock_in'])) . " | Sync: " . date('Y-m-d H:i');
                $ins->execute([$u_id, $s_id, $target_org_id, $r['clock_in'], $r['clock_out'], $receipt]);
            }
        }

        $pdo->commit();
        header("Location: ../staff_audit.php?from=$from&to=$to&msg=Import+Complete");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("Error: " . $e->getMessage());
    }
}