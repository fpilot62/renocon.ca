<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../sip/db_config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $org_id = (int)($_POST['org_id'] ?? $_SESSION['org_id'] ?? 0);
    if ($org_id <= 0) {
        $org_id = 1;
    }
    $rid = (int)($_POST['rid'] ?? 0);
    $name = $conn->real_escape_string((string)($_POST['name'] ?? ''));
    $p_unit = $conn->real_escape_string((string)($_POST['purchase_unit'] ?? ''));
    $c_unit = $conn->real_escape_string((string)($_POST['count_unit'] ?? ''));
    $pcs = (float)($_POST['pcs_in_box'] ?? 1);
    $cost = (float)($_POST['cost_box'] ?? 0);
    $cost_pc = ($pcs > 0) ? ($cost / $pcs) : 0;

    if ($id > 0) {
        $sql = "UPDATE Gem_inventory_items SET
                purchase_unit='$p_unit', count_unit='$c_unit',
                pcs_in_box='$pcs', cost_box='$cost', cost_pc='$cost_pc'
                WHERE id = $id";
    } else {
        if ($name === '') {
            header('Location: manage_items.php?status=error&org_id=' . $org_id . '&rid=' . $rid);
            exit;
        }

        $sql = "INSERT INTO Gem_inventory_items
                (org_id, site_id, Restaurant_Id, name, purchase_unit, count_unit, pcs_in_box, cost_box, cost_pc, category, IsActive)
                VALUES ($org_id, 0, 0, '$name', '$p_unit', '$c_unit', '$pcs', '$cost', '$cost_pc', 'Food', 1)";
    }

    if ($conn->query($sql)) {
        header('Location: manage_items.php?status=success&org_id=' . $org_id . '&rid=' . $rid);
    } else {
        echo 'Error: ' . $conn->error;
    }
}
