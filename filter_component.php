<?php
$filters = syncPayrollFilters();
$orgs = getOrganizations($pdo);
$sites = getSites($pdo, $filters['org_id']);
?>

<form method="GET" style="display: flex; gap: 15px; background: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px; align-items: flex-end; border: 1px solid #e2e8f0;">
    <div>
        <label style="font-size:10px; font-weight:bold; display:block;">ORGANIZATION</label>
        <select name="org_id" onchange="this.form.submit()">
            <option value="all">All Companies</option>
            <?php foreach($orgs as $o): ?>
                <option value="<?=$o['id']?>" <?=$filters['org_id'] == $o['id'] ? 'selected' : ''?>><?=$o['org_name']?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label style="font-size:10px; font-weight:bold; display:block;">SITE LOCATION</label>
        <select name="site_id" onchange="this.form.submit()">
            <option value="all">All Sites</option>
            <?php foreach($sites as $s): ?>
                <option value="<?=$s['SId']?>" <?=$filters['site_id'] == $s['SId'] ? 'selected' : ''?>><?=$s['nick_name']?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label style="font-size:10px; font-weight:bold; display:block;">PAY PERIOD</label>
        <input type="month" name="period" value="<?=$filters['period']?>" onchange="this.form.submit()">
    </div>
    <div style="margin-left:auto;">
        <a href="export_ledger.php?period=<?=$filters['period']?>&org_id=<?=$filters['org_id']?>" style="background:#10b981; color:white; padding:8px 12px; border-radius:4px; text-decoration:none; font-size:12px;">📥 Export CSV</a>
    </div>
</form>