<?php
/** Path: /fetch_sites.php */
require_once 'db.php';

if (isset($_GET['org_id'])) {
    $org_id = (int)$_GET['org_id'];
    $stmt = $pdo->prepare("SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC");
    $stmt->execute([$org_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}
?>