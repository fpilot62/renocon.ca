<?php
/** Path: /inventory/inventory_menu.php */
$current_page = basename($_SERVER['PHP_SELF']);
$nav_items = [
    'inventory_report.php' => 'Report',
    'physical_count.php'   => 'Count',
    'usage.php'            => 'Usage',
    'audit.php'            => 'Audit',
    'waste.php'            => 'Waste',
    'orders.php'           => 'Orders'
];
?>
<style>
    .inventory-nav-bar { 
        background: #1e293b; 
        padding: 12px 25px; 
        border-radius: 0 0 15px 15px; 
        display: flex; 
        align-items: center; 
        justify-content: space-between; 
        margin-bottom: 30px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
        position: fixed; 
        top: 75px; 
        left: 0; 
        right: 0; 
        z-index: 999; 
    }
    .nav-actions { display: flex; gap: 10px; align-items: center; }
    .nav-links-right { display: flex; gap: 15px; }
    .nav-links-right a { color: #94a3b8; font-size: 12px; font-weight: 800; text-decoration: none; text-transform: uppercase; padding: 5px 10px; transition: 0.3s; }
    .nav-links-right a:hover { color: #fff; }
    .nav-links-right a.active { color: #38bdf8; border-bottom: 2px solid #38bdf8; }
    .site-dropdown { background: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-weight: 800; font-size: 13px; color: #1e293b; cursor: pointer; }
    .btn-nav { font-weight: 800; font-size: 11px; text-transform: uppercase; border-radius: 6px; padding: 8px 15px; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
    .btn-nav:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn-delivery { background: #0891b2; color: #fff; }
    .btn-count { background: #f59e0b; color: #1e293b; }
</style>

<div class="inventory-nav-bar">
    <div class="nav-actions">
        <?php if (!empty($is_super) && !empty($orgs)): ?>
        <select class="site-dropdown" onchange="if(window.sipMenuSetOrg){sipMenuSetOrg(this.value);}else{location.href='?org_id='+this.value}" title="Organization">
            <option value="all" <?= (int)($selected_org ?? 0) === 0 ? 'selected' : '' ?>>All orgs</option>
            <?php foreach ($orgs as $o): ?>
                <option value="<?= (int)$o['id'] ?>" <?= (int)($selected_org ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?= inv_site_dropdown_html() ?>
        
        <a href="index.php?rid=<?= $rid ?><?= !empty($selected_org) ? '&org_id='.(int)$selected_org : '' ?>" class="btn-nav btn-delivery">
            <i class="fas fa-truck"></i> LOG DELIVERY
        </a>
        <a href="physical_count.php?rid=<?= $rid ?><?= !empty($selected_org) ? '&org_id='.(int)$selected_org : '' ?>" class="btn-nav btn-count">
            <i class="fas fa-clipboard-check"></i> PHYSICAL COUNT
        </a>
    </div>

    <div class="nav-links-right">
        <?php foreach($nav_items as $url => $label): ?>
            <a href="<?= $url ?>?rid=<?= $rid ?><?= !empty($selected_org) ? '&org_id='.(int)$selected_org : '' ?>" class="<?= ($current_page == $url) ? 'active' : '' ?>">
                <?= $label ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>