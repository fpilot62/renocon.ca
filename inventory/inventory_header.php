<?php
/** Path: /inventory/inventory_header.php */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$current_page = basename($_SERVER['PHP_SELF']);

// 1. Get current RID from URL or Session
if (isset($_GET['rid'])) {
    $_SESSION['active_rid'] = (int)$_GET['rid'];
}
$rid = $_SESSION['active_rid'] ?? 6;

// 2. Load sites from the session we saw in your diagnostics
$sites_list = $_SESSION['inventory_sites'] ?? [];
?>
<style>
    body { padding-top: 155px !important; background: #f1f5f9; font-family: 'Inter', sans-serif; }
    .inv-sub-nav { 
        background: #1e293b; color: white; padding: 12px 25px; position: fixed; 
        top: 72px; left: 0; right: 0; z-index: 1020; display: flex; 
        justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
    }
    .site-sel { width: 240px; background: #334155; color: #38bdf8; border: 1px solid #475569; font-weight: 700; height: 38px; }
    .nav-links a { color: #94a3b8; margin-left: 20px; font-size: 13px; font-weight: 600; text-decoration: none; transition: 0.2s; }
    .nav-links a:hover, .nav-links a.active { color: #fff; border-bottom: 2px solid #38bdf8; padding-bottom: 5px; }
</style>

<div class="inv-sub-nav">
    <div class="d-flex align-items-center">
        <select class="form-control form-control-sm site-sel mr-3" onchange="location.href='<?= $current_page ?>?rid='+this.value">
            <option value="0" <?= (int)$rid <= 0 ? 'selected' : '' ?>>All Sites</option>
            <?php if (!empty($sites_list)): ?>
                <?php foreach($sites_list as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $rid == $s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nick_name']) ?>
                    </option>
                <?php endforeach; ?>
            <?php else: ?>
                <option>No Sites Assigned</option>
            <?php endif; ?>
        </select>
        
        <a href="log_delivery.php?rid=<?= $rid ?>" class="btn btn-sm btn-info mr-2 text-white shadow-sm">
            <i class="fas fa-truck mr-1"></i> LOG DELIVERY
        </a>

        <a href="physical_count.php?rid=<?= $rid ?>" class="btn btn-sm btn-warning font-weight-bold shadow-sm">
            <i class="fas fa-clipboard-check mr-1"></i> PHYSICAL COUNT
        </a>
    </div>

    <div class="nav-links">
        <a href="inventory_report.php?rid=<?= $rid ?>" class="<?= $current_page == 'inventory_report.php' ? 'active' : '' ?>">REPORT</a>
        <a href="usage_history.php?rid=<?= $rid ?>" class="<?= $current_page == 'usage_history.php' ? 'active' : '' ?>">HISTORY</a>
        <a href="audit.php?rid=<?= $rid ?>" class="<?= $current_page == 'audit.php' ? 'active' : '' ?>">AUDIT</a>
    </div>
</div>