<?php
/** Path: /payroll/safety_policy.php */
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// 1. Fetch current settings
$settings = $pdo->query("SELECT safety_policy_text, policy_version FROM payroll_settings LIMIT 1")->fetch();

// 2. Handle Signature
if (isset($_POST['sign_policy'])) {
    $stmt = $pdo->prepare("INSERT INTO policy_signatures (user_id, version_signed, ip_address) VALUES (?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $settings['policy_version'], $_SERVER['REMOTE_ADDR']]);
    header("Location: kiosk.php"); exit; 
}

include 'menu_include.php';
?>
<div style="max-width: 800px; margin: 40px auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); font-family: sans-serif;">
    <h1 style="text-align:center; color:#2c3e50;">Safety Policy Acknowledgement</h1>
    <p style="text-align:center; color:#666;">Current Policy Version: <?= htmlspecialchars($settings['policy_version']) ?></p>

    <div style="height: 450px; overflow-y: scroll; border: 1px solid #eee; padding: 25px; background: #fdfdfd; margin: 20px 0; border-radius: 6px; white-space: pre-wrap; font-size:14px; line-height:1.7; color:#333;">
        <?= htmlspecialchars($settings['safety_policy_text'] ?? 'Policy content is currently being updated. Please check back later.') ?>
    </div>

    <form method="POST">
        <label style="display:flex; align-items:start; gap:12px; cursor:pointer; margin-bottom:25px; padding:15px; background:#fff3cd; border-radius:6px; border:1px solid #ffeeba;">
            <input type="checkbox" required style="margin-top:4px;">
            <span style="font-size:14px;">I certify that I have read the RENOCON Health and Safety manual (v<?= htmlspecialchars($settings['policy_version']) ?>) and agree to comply with all safety regulations while on site.</span>
        </label>
        
        <button type="submit" name="sign_policy" style="background:#27ae60; color:white; border:none; padding:20px; width:100%; border-radius:6px; font-size:18px; font-weight:bold; cursor:pointer; transition:0.2s;">SIGN AND PROCEED</button>
    </form>
</div>