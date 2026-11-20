<?php
/** * Path: /inventory/physical_count.php 
 * PRODUCTION INTERFACE - PHYSICAL STOCK AUDIT LOG HISTORY & TRACKING VIEW
 * Features: View historical audit counts site-wise, edit inline counts, delete past entries, or log a fresh count.
 * Ending $ = pieces counted × catalog cost/pc (Master Items). Same-day duplicate saves keep the higher qty.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_lvl = (int)($_SESSION['user_level'] ?? 99);
require_once __DIR__ . '/_org_sites.php';

function inv_unit_cost(array $row): float {
    $pc = (float)($row['cost_pc'] ?? 0);
    if ($pc > 0) {
        return $pc;
    }
    $box = (float)($row['cost_box'] ?? 0);
    $pcs = (float)($row['pcs_in_box'] ?? 0);
    if ($box > 0 && $pcs > 0) {
        return $box / $pcs;
    }
    return 0.0;
}

// =========================================================================
// ⚡ BACKEND ACTION CONTROLLER: PROCESSES MODIFICATIONS & DELETIONS
// =========================================================================
$alert_msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    
    // ACTION 1: CORRECTION / OVERWRITE SUBMITTED BY MANAGER
    if ($_POST['action_type'] === 'update_single_item' && !empty($_POST['audit_date']) && !empty($_POST['item_name'])) {
        try {
            $editSite = (int)($_POST['site_id'] ?? $rid);
            if ($editSite <= 0) {
                $alert_msg = inv_pick_site_banner('Pick a restaurant before changing a count.');
            } else {
            // Find item ID from matching name
            $findItem = $pdo->prepare("SELECT Id FROM Gem_inventory_items WHERE name = ? LIMIT 1");
            $findItem->execute([$_POST['item_name']]);
            $item_id = $findItem->fetchColumn();
            
            if ($item_id) {
                $upStmt = $pdo->prepare("UPDATE Gem_inventory_audit SET qty_recorded = ? WHERE site_id = ? AND item_id = ? AND DATE(count_date) = ?");
                $upStmt->execute([(float)$_POST['new_qty'], $editSite, $item_id, $_POST['audit_date']]);
                $alert_msg = "<div class='alert alert-success font-weight-bold shadow-sm'><i class='fas fa-check-circle mr-1'></i> Inventory stock item quantity adjusted successfully.</div>";
            }
            }
        } catch (Exception $e) {
            $alert_msg = "<div class='alert alert-danger font-weight-bold'>Update SQL Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    
    // ACTION 2: COMPLETE REMOVAL OF LOG LINE
    if ($_POST['action_type'] === 'delete_single_item' && !empty($_POST['audit_date']) && !empty($_POST['item_name'])) {
        try {
            $editSite = (int)($_POST['site_id'] ?? $rid);
            if ($editSite <= 0) {
                $alert_msg = inv_pick_site_banner('Pick a restaurant before removing a count line.');
            } else {
            $findItem = $pdo->prepare("SELECT Id FROM Gem_inventory_items WHERE name = ? LIMIT 1");
            $findItem->execute([$_POST['item_name']]);
            $item_id = $findItem->fetchColumn();
            
            if ($item_id) {
                $delStmt = $pdo->prepare("DELETE FROM Gem_inventory_audit WHERE site_id = ? AND item_id = ? AND DATE(count_date) = ?");
                $delStmt->execute([$editSite, $item_id, $_POST['audit_date']]);
                $alert_msg = "<div class='alert alert-warning font-weight-bold shadow-sm'><i class='fas fa-trash-alt mr-1'></i> Audited line item record removed completely.</div>";
            }
            }
        } catch (Exception $e) {
            $alert_msg = "<div class='alert alert-danger font-weight-bold'>Delete SQL Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

$mode = isset($_GET['action']) && $_GET['action'] === 'new' ? 'input' : 'history';
if ($mode === 'input' && (int)$rid <= 0) {
    $mode = 'history';
    $alert_msg = inv_pick_site_banner('Pick a restaurant before adding a physical count.');
}
if (isset($_GET['err']) && $_GET['err'] === 'pick_site' && $alert_msg === '') {
    $alert_msg = inv_pick_site_banner();
}


// -------------------------------------------------------------------------
// 📑 VIEW CONTEXT A: INVENTORY INPUT FORM ENGINE
// -------------------------------------------------------------------------
if ($mode === 'input') {
    $stmt = $pdo->prepare("SELECT Id, name, Unit, pcs_in_box, cost_pc, cost_box FROM Gem_inventory_items WHERE (Restaurant_Id = 0 OR Restaurant_Id = ?) AND IsActive = 1 ORDER BY name ASC");
    $stmt->execute([$rid]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} 
// -------------------------------------------------------------------------
// 📑 VIEW CONTEXT B: HISTORICAL AUDIT LOG DISPLAY ENGINE
// -------------------------------------------------------------------------
else {
    $siteIds = inv_query_site_ids();
    $in = implode(',', array_map('intval', $siteIds ?: [0]));
    $log_sql = "SELECT DATE(count_date) as audit_date, site_id, COUNT(DISTINCT item_id) as distinct_items
                FROM Gem_inventory_audit 
                WHERE site_id IN ($in)
                GROUP BY DATE(count_date), site_id
                ORDER BY audit_date DESC, site_id";
    $historical_logs = $pdo->query($log_sql)->fetchAll(PDO::FETCH_ASSOC);

    $detail_sql = "SELECT DATE(a.count_date) as audit_date, a.site_id, a.item_id, i.name,
                          MAX(a.qty_recorded) as qty_recorded, i.Unit, i.cost_pc, i.cost_box, i.pcs_in_box
                   FROM Gem_inventory_audit a
                   JOIN Gem_inventory_items i ON a.item_id = i.Id
                   WHERE a.site_id IN ($in)
                   GROUP BY DATE(a.count_date), a.site_id, a.item_id, i.name, i.Unit, i.cost_pc, i.cost_box, i.pcs_in_box
                   ORDER BY audit_date DESC, a.site_id, i.name ASC";
    $raw_details = $pdo->query($detail_sql)->fetchAll(PDO::FETCH_ASSOC);

    $grouped_details = [];
    $day_ending = [];
    foreach ($raw_details as $det) {
        $unitCost = inv_unit_cost($det);
        $det['unit_cost'] = $unitCost;
        $det['line_value'] = (float)$det['qty_recorded'] * $unitCost;
        $gkey = $det['audit_date'] . '-' . (int)($det['site_id'] ?? 0);
        $grouped_details[$gkey][] = $det;
        if (!isset($day_ending[$gkey])) {
            $day_ending[$gkey] = 0.0;
        }
        $day_ending[$gkey] += $det['line_value'];
    }
}

if (file_exists("../engine/menu_generator.php")) {
    require_once "../engine/menu_generator.php"; 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Physical Stock Counts Console | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 130px; }
        .page-card { background: white; border-radius: 15px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; margin-bottom: 80px; }
        
        .count-input { width: 140px; font-weight: 800; text-align: center; border: 2px solid #cbd5e1; border-radius: 8px; color: #2563eb; }
        .count-input:focus { border-color: #2563eb; background: #eff6ff; outline: none; }
        .unit-select { border: 2px solid #cbd5e1; background: #f8fafc; font-weight: 700; color: #475569; border-radius: 8px; height: calc(2.25rem + 2px) !important; }
        .save-btn-fixed { position: fixed; bottom: 30px; right: 50px; padding: 15px 40px; border-radius: 50px; font-weight: 800; z-index: 1050; background: #f59e0b; color: #1e293b; border: none; box-shadow: 0 10px 20px rgba(245, 158, 11, 0.4); transition: all 0.3s ease; text-transform: uppercase; letter-spacing: 0.5px; }
        .save-btn-fixed:hover { transform: translateY(-3px); background: #d97706; color: white; box-shadow: 0 15px 25px rgba(217, 119, 6, 0.5); }
        
        .log-row-header { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; cursor: pointer; transition: all 0.15s ease; }
        .log-row-header:hover { background: #edf2f7; border-color: #cbd5e1; }
        .detail-expanded-panel { background: white; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; padding: 15px 25px; margin-top: -12px; margin-bottom: 15px; display: none; }
        
        .inventory-nav-bar { background: #1e293b; padding: 12px 25px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .nav-actions { display: flex; gap: 10px; align-items: center; }
        .nav-links-right { display: flex; gap: 20px; }
        .nav-links-right a { color: #94a3b8; font-size: 13px; font-weight: 700; text-decoration: none; transition: 0.3s; }
        .nav-links-right a:hover { color: #fff; }
        .nav-links-right a.active { color: #38bdf8; border-bottom: 2px solid #38bdf8; padding-bottom: 4px; }
        .site-dropdown { background: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-weight: 800; font-size: 13px; color: #1e293b; cursor: pointer; }
        .btn-nav { font-weight: 800; font-size: 11px; text-transform: uppercase; border-radius: 6px; padding: 8px 15px; border: none; display: flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-delivery { background: #0891b2; color: #fff; }
        .btn-count { background: #f59e0b; color: #1e293b; }
        
        /* Inline Correction Styling elements */
        .inline-modify-field { max-width: 90px; font-weight: bold; text-align: center; height: 26px; font-size: 12px; border: 1px solid #cbd5e1; border-radius: 4px; }
        .btn-inline-action { padding: 2px 8px; font-size: 10px; font-weight: bold; border-radius: 4px; text-transform: uppercase; border: none; margin-left: 4px; }
        .ending-badge { background:#ecfdf5; color:#14532d; border:1px solid #86efac; font-size:12px; font-weight:800; padding:4px 10px; border-radius:8px; }
        .line-value { font-weight:800; color:#14532d; white-space:nowrap; }
        .unit-cost { color:#64748b; font-weight:700; white-space:nowrap; }
        .ending-total-bar { position:fixed; bottom:30px; left:50px; background:#14532d; color:#fff; padding:12px 22px; border-radius:50px; font-weight:800; z-index:1050; box-shadow:0 10px 20px rgba(20,83,45,.25); }
    </style>
</head>
<body>
    <?php if (function_exists('generateMenu')) { echo generateMenu($pdo); } ?>

    <div class="container-fluid px-5">
        
        <div class="inventory-nav-bar">
            <div class="nav-actions">
                <?= inv_org_dropdown_html() ?>
                <?= inv_site_dropdown_html() ?>

                <a href="index.php?rid=<?= $rid ?><?= htmlspecialchars($inv_org_q) ?>" class="btn-nav btn-delivery">
                    <i class="fas fa-truck-loading"></i> LOG NEW DELIVERY
                </a>
                <a href="physical_count.php?rid=<?= $rid ?><?= htmlspecialchars($inv_org_q) ?>" class="btn-nav btn-count">
                    <i class="fas fa-clipboard-check"></i> PHYSICAL COUNT
                </a>
            </div>

            <div class="nav-links-right">
                <?php 
                    $current_page = basename($_SERVER['PHP_SELF']); 
                    $nav_items = [
                        'inventory_report.php' => 'Report',
                        'physical_count.php'   => 'Count',
                        'usage.php'            => 'Usage',
                        'audit.php'            => 'Audit',
                        'waste.php'            => 'Waste',
                        'orders.php'           => 'Orders'
                    ];
                    foreach($nav_items as $url => $label): 
                        $active = ($current_page == $url) ? 'active' : '';
                ?>
                    <a href="<?= $url ?>?rid=<?= $rid ?><?= htmlspecialchars($inv_org_q) ?>" class="<?= $active ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="page-card">
            
            <?= $alert_msg ?>
            
            <?php if ($mode === 'input'): ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 style="font-weight: 900; color: #1e293b;"><i class="fas fa-calculator text-warning mr-2"></i>Physical Stock Count</h2>
                        <p class="text-muted small">Enter the current shelf counts. Ending $ uses Master Items cost/pc × pieces.</p>
                    </div>
                    <div style="display:flex; gap:12px; align-items:center;">
                        <input type="date" form="mainCountForm" name="count_date" class="form-control font-weight-bold text-center" value="<?= date('Y-m-d') ?>" style="width:180px; border: 2px solid #cbd5e1; border-radius: 8px;">
                        <a href="physical_count.php?rid=<?=$rid?><?= htmlspecialchars($inv_org_q) ?>" class="btn btn-outline-secondary font-weight-bold" style="border-radius:8px;"><i class="fas fa-arrow-left"></i> View Log Logs</a>
                    </div>
                </div>

                <form id="mainCountForm" action="save_audit.php" method="POST">
                    <input type="hidden" name="rid" value="<?= $rid ?>">
                    <table class="table table-hover align-middle" id="countTable">
                        <thead class="text-muted small">
                            <tr style="text-transform: uppercase; letter-spacing: 0.5px; background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                <th>Inventory Item Description Name</th>
                                <th width="16%">Unit Pack Type</th>
                                <th width="14%" class="text-center">Count Entered</th>
                                <th width="12%" class="text-right">Pieces</th>
                                <th width="10%" class="text-right">Cost / Pc</th>
                                <th width="12%" class="text-right">Ending $</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($items as $i): 
                                $pack_multiplier = (float)($i['pcs_in_box'] ?? 1);
                                if ($pack_multiplier <= 0) { $pack_multiplier = 1.00; }
                                $unitCost = inv_unit_cost($i);
                            ?>
                            <tr class="item-row">
                                <td class="align-middle">
                                    <span style="font-size:13px; font-weight: 800; color:#0f172a;"><?= strtoupper(htmlspecialchars($i['name'])) ?></span>
                                    <input type="hidden" class="pack-size" value="<?= $pack_multiplier ?>">
                                    <input type="hidden" class="unit-cost-value" value="<?= htmlspecialchars(number_format($unitCost, 4, '.', '')) ?>">
                                </td>
                                <td class="align-middle">
                                    <select name="items[<?= $i['Id'] ?>][unit_type]" class="form-control form-control-sm unit-select">
                                        <option value="boxes" selected>Boxes / Case (<?= (int)$pack_multiplier ?> pcs)</option>
                                        <option value="pcs">Pieces (Single / Unit)</option>
                                    </select>
                                </td>
                                <td class="text-center align-middle">
                                    <input type="number" step="0.01" name="items[<?= $i['Id'] ?>][entered_qty]" class="form-control count-input mx-auto" placeholder="0.00">
                                </td>
                                <td class="text-right align-middle text-primary font-weight-bold">
                                    <span class="total-pcs-display">0.00</span>
                                    <input type="hidden" name="items[<?= $i['Id'] ?>][pcs]" class="total-pcs-value" value="0.00">
                                </td>
                                <td class="text-right align-middle unit-cost">$<?= number_format($unitCost, 2) ?></td>
                                <td class="text-right align-middle line-value"><span class="row-value-display">$0.00</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="ending-total-bar">Ending inventory <span id="endingTotal">$0.00</span></div>
                    <button type="submit" class="save-btn-fixed">
                        SAVE & AUDIT NOW <i class="fas fa-check-circle ml-2"></i>
                    </button>
                </form>

            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 style="font-weight: 900; color: #1e293b;"><i class="fas fa-history text-primary mr-2"></i>Physical Count Records Log</h2>
                        <p class="text-muted small">Ending $ is pieces × Master Items cost/pc. Same-day double saves show one row (higher count) so the total is not doubled.</p>
                    </div>
                    <?php if ((int)$rid <= 0): ?>
                    <a href="#" class="btn btn-warning font-weight-bold px-4 shadow-sm" style="border-radius:8px; color:#1e293b;" onclick="alert('Pick a restaurant before adding a physical count.'); return false;"><i class="fas fa-plus-circle"></i> ADD NEW COUNT ENTRY</a>
                    <?php else: ?>
                    <a href="physical_count.php?rid=<?=$rid?><?= htmlspecialchars($inv_org_q) ?>&action=new" class="btn btn-warning font-weight-bold px-4 shadow-sm" style="border-radius:8px; color:#1e293b;"><i class="fas fa-plus-circle"></i> ADD NEW COUNT ENTRY</a>
                    <?php endif; ?>
                </div>

                <div class="audit-log-history-list-wrapper mt-4">
                    <?php if(!empty($historical_logs)): foreach($historical_logs as $log): 
                        $logSite = (int)($log['site_id'] ?? $rid);
                        $formatted_date_id = $log['audit_date'] . '-' . $logSite;
                        $ending = (float)($day_ending[$formatted_date_id] ?? 0);
                        $logNick = inv_site_nick($logSite);
                    ?>
                        <div class="log-container-node">
                            <div class="log-row-header" onclick="toggleDetailPanelCollapse('<?= $formatted_date_id ?>')">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="icon-indicator-round mr-3" style="background:#e0f2fe; color:#0369a1; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div>
                                        <strong style="font-size:14px; color:#0f172a;"><?= date('l, M j, Y', strtotime($log['audit_date'])) ?></strong>
                                        <?php if (inv_all_sites() && $logNick !== ''): ?>
                                        <span class="badge badge-info ml-2" style="font-size:10px; font-weight:700;"><?= htmlspecialchars($logNick) ?></span>
                                        <?php endif; ?>
                                        <span class="badge badge-secondary ml-2" style="font-size:10px; font-weight:700;"><?= (int)$log['distinct_items'] ?> Items Audited</span>
                                        <span class="ending-badge ml-2">Ending $<?= number_format($ending, 2) ?></span>
                                    </div>
                                </div>
                                <div class="text-muted font-weight-bold" id="chevron-indicator-<?= $formatted_date_id ?>">
                                    View Records <i class="fas fa-chevron-down ml-1"></i>
                                </div>
                            </div>
                            
                            <div class="detail-expanded-panel" id="panel-breakdown-<?= $formatted_date_id ?>">
                                <table class="table table-sm table-hover border-0 mb-0">
                                    <thead class="text-secondary" style="font-size:11px; text-transform:uppercase; background:#f8fafc;">
                                        <tr>
                                            <th>Product Stock Name</th>
                                            <th class="text-center" width="18%">Recorded Count</th>
                                            <th class="text-right" width="12%">Cost / Pc</th>
                                            <th class="text-right" width="14%">Ending $</th>
                                            <th class="text-right" width="22%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(isset($grouped_details[$formatted_date_id])): foreach($grouped_details[$formatted_date_id] as $line): ?>
                                            <tr>
                                                <td class="align-middle text-dark font-weight-bold"><?= htmlspecialchars($line['name']) ?></td>
                                                
                                                <td class="align-middle text-center">
                                                    <form method="POST" action="" id="form-mod-<?= crc32($formatted_date_id . $line['name']) ?>" style="display:inline-flex; align-items:center; gap:6px;">
                                                        <input type="hidden" name="action_type" value="update_single_item">
                                                        <input type="hidden" name="audit_date" value="<?= htmlspecialchars((string)$log['audit_date']) ?>">
                                                        <input type="hidden" name="site_id" value="<?= $logSite ?>">
                                                        <input type="hidden" name="item_name" value="<?= htmlspecialchars($line['name']) ?>">
                                                        
                                                        <input type="number" step="0.01" name="new_qty" class="inline-modify-field" value="<?= round($line['qty_recorded'], 2) ?>">
                                                        <span class="text-muted small font-weight-bold"><?= strtoupper($line['Unit'] ?: 'pcs') ?></span>
                                                    </form>
                                                </td>
                                                <td class="align-middle text-right unit-cost">$<?= number_format((float)$line['unit_cost'], 2) ?></td>
                                                <td class="align-middle text-right line-value">$<?= number_format((float)$line['line_value'], 2) ?></td>
                                                
                                                <td class="align-middle text-right">
                                                    <button type="submit" form="form-mod-<?= crc32($formatted_date_id . $line['name']) ?>" class="btn-inline-action btn-nav btn-delivery" title="Save Adjustments">
                                                        <i class="fas fa-save mr-1"></i> Fix
                                                    </button>
                                                    
                                                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Permanently purge this item audit history item row entry?')">
                                                        <input type="hidden" name="action_type" value="delete_single_item">
                                                        <input type="hidden" name="audit_date" value="<?= htmlspecialchars((string)$log['audit_date']) ?>">
                                                        <input type="hidden" name="site_id" value="<?= $logSite ?>">
                                                        <input type="hidden" name="item_name" value="<?= htmlspecialchars($line['name']) ?>">
                                                        <button type="submit" class="btn-inline-action btn-danger text-white" title="Wipe Line Item">
                                                            <i class="fas fa-trash-alt"></i> Wipe
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                        <tr style="background:#ecfdf5;">
                                            <td colspan="3" class="text-right font-weight-bold">Ending inventory</td>
                                            <td class="text-right line-value">$<?= number_format($ending, 2) ?></td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; else: ?>
                        <div class="text-center py-5 text-muted border rounded" style="background:#f8fafc; border-style:dashed !important;">
                            <i class="fas fa-folder-open fa-3x mb-3 text-muted" style="opacity:0.4;"></i>
                            <h5 class="font-weight-bold">No Historical Audit Logs Found</h5>
                            <p class="small mb-0">There are no physical stock counts logged under this store branch site location profile yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
    function toggleDetailPanelCollapse(dateStrId) {
        const targetPanel = document.getElementById('panel-breakdown-' + dateStrId);
        const textChevron = document.getElementById('chevron-indicator-' + dateStrId);
        
        if (targetPanel.style.display === 'block') {
            targetPanel.style.display = 'none';
            textChevron.innerHTML = 'View Records <i class="fas fa-chevron-down ml-1"></i>';
        } else {
            targetPanel.style.display = 'block';
            textChevron.innerHTML = 'Hide Records <i class="fas fa-chevron-up ml-1"></i>';
        }
    }

    function money(n) {
        return '$' + (n || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function refreshEndingTotal() {
        let sum = 0;
        document.querySelectorAll('.item-row').forEach(function(row) {
            const pcs = parseFloat((row.querySelector('.total-pcs-value') || {}).value) || 0;
            const cost = parseFloat((row.querySelector('.unit-cost-value') || {}).value) || 0;
            sum += pcs * cost;
        });
        const el = document.getElementById('endingTotal');
        if (el) el.innerText = money(sum);
    }

    function calculateRowTotal(rowElement) {
        const inputField = rowElement.querySelector('.count-input');
        if(!inputField) return;

        const selectField = rowElement.querySelector('.unit-select');
        const packSizeField = rowElement.querySelector('.pack-size');
        
        const qty = parseFloat(inputField.value) || 0;
        const unitType = selectField.value;
        const packSize = parseFloat(packSizeField.value) || 1;
        const cost = parseFloat((rowElement.querySelector('.unit-cost-value') || {}).value) || 0;

        const total = (unitType === 'boxes') ? (qty * packSize) : qty;
        
        rowElement.querySelector('.total-pcs-display').innerText = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        rowElement.querySelector('.total-pcs-value').value = total.toFixed(2);
        const valEl = rowElement.querySelector('.row-value-display');
        if (valEl) valEl.innerText = money(total * cost);
        refreshEndingTotal();
    }

    document.querySelectorAll('.count-input').forEach(input => {
        input.addEventListener('input', function() { calculateRowTotal(this.closest('tr')); });
    });

    document.querySelectorAll('.unit-select').forEach(select => {
        select.addEventListener('change', function() { calculateRowTotal(this.closest('tr')); });
    });
    </script>
</body>
</html>
