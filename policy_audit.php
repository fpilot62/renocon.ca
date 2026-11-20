<?php
/** Path: /payroll/policy_audit.php */
session_start();
require_once 'db.php';

// Auth Guard - Admin Only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] != 1) { 
    header("Location: login.php"); exit; 
}

// Get the current policy version to identify who is "out of date"
$settings = $pdo->query("SELECT policy_version FROM payroll_settings LIMIT 1")->fetch();
$current_v = $settings['policy_version'] ?? 1;

// Fetch all signatures with user details
$query = "SELECT u.email, s.signed_at, s.version_signed, s.ip_address 
          FROM policy_signatures s 
          JOIN users u ON s.user_id = u.id 
          ORDER BY s.signed_at DESC";
$signatures = $pdo->query($query)->fetchAll();

include 'menu_include.php';
?>
<div style="padding: 30px; font-family: sans-serif;">
    <div style="background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
        <h2 style="display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-user-check"></i> Safety Policy Audit Log</span>
            <span style="font-size:14px; background:#008ba3; color:white; padding:5px 12px; border-radius:15px;">
                Current Required Version: v<?= $current_v ?>
            </span>
        </h2>
        
        <table style="width:100%; border-collapse: collapse; margin-top:20px;">
            <thead>
                <tr style="background:#f4f7f6; text-align:left;">
                    <th style="padding:12px; border-bottom:2px solid #ddd;">Employee Email</th>
                    <th style="padding:12px; border-bottom:2px solid #ddd;">Date Signed</th>
                    <th style="padding:12px; border-bottom:2px solid #ddd;">Version</th>
                    <th style="padding:12px; border-bottom:2px solid #ddd;">IP Address</th>
                    <th style="padding:12px; border-bottom:2px solid #ddd;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($signatures as $sig): 
                    $is_current = ($sig['version_signed'] == $current_v);
                ?>
                <tr>
                    <td style="padding:12px; border-bottom:1px solid #eee;"><?= htmlspecialchars($sig['email']) ?></td>
                    <td style="padding:12px; border-bottom:1px solid #eee;"><?= date('M d, Y H:i', strtotime($sig['signed_at'])) ?></td>
                    <td style="padding:12px; border-bottom:1px solid #eee;">v<?= $sig['version_signed'] ?></td>
                    <td style="padding:12px; border-bottom:1px solid #eee; font-family:monospace; font-size:12px;"><?= $sig['ip_address'] ?></td>
                    <td style="padding:12px; border-bottom:1px solid #eee;">
                        <?php if($is_current): ?>
                            <span style="color:green; font-weight:bold;">● Current</span>
                        <?php else: ?>
                            <span style="color:orange; font-weight:bold;">● Outdated</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if(empty($signatures)): ?>
            <p style="text-align:center; padding:20px; color:#666;">No signatures recorded yet.</p>
        <?php endif; ?>
    </div>
</div>