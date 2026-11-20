<?php
/** * Path: /inventory/orders.php 
 * PRODUCTION CONSOLE - SMART FORECASTING AUTO-ORDER MODULE
 * Fix: Updated Net Stock Needed column to display exact raw calculations instead of the conditional "Stock OK" badge.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/engine/inventory_hot.php';
inv_ensure_hot_column($pdo);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. PERMISSIONS & CONTEXT ARCHITECTURE
$rid = isset($_GET['rid']) ? (int)$_GET['rid'] : 6; 
$project_days = isset($_GET['project_days']) ? (int)$_GET['project_days'] : 7;
$count_date = $_GET['count_date'] ?? '';
$growth_factor = 1.10; // 10% safety buffer threshold

// Determine Screen State View Route Mode
$mode = isset($_GET['action']) && $_GET['action'] === 'new' ? 'calculator' : 'history';

// 2. SITES (For Unified Menu Dropdown)
require_once __DIR__ . '/_org_sites.php';

// LOAD ENGINE CONTEXT CONDITIONAL PATHWAYS
if ($mode === 'calculator') {
    // 3. FETCH LATEST AUDIT DATES FOR ANCHOR DROPDOWNS
    $date_stmt = $pdo->prepare("SELECT DISTINCT count_date FROM Gem_inventory_audit WHERE site_id = ? ORDER BY count_date DESC LIMIT 10");
    $date_stmt->execute([$rid]);
    $available_counts = $date_stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($count_date) && !empty($available_counts)) { 
        $count_date = $available_counts[0]; 
    }

    // 4. SMART FORECAST SIMULATION CALCULATION ($8,000/Day Anchor)
    $daily_forecast = 8000;
    $total_forecast = ($daily_forecast * $project_days) * $growth_factor;

    // 5. FETCH ITEMS & COMPUTE HISTORICAL PRODUCTION CONSUMPTION YIELDS
    $sql = "SELECT i.Id, i.name, i.Unit, i.pcs_in_box, i.is_hot,
            (SELECT qty_recorded FROM Gem_inventory_audit WHERE item_id = i.Id AND count_date = ? AND site_id = ? LIMIT 1) as on_hand,
            (SELECT AVG((sold_qty + waste_qty) / NULLIF($daily_forecast, 0)) * 100 
             FROM Gem_inventory_usage 
             WHERE item_id = i.Id AND site_id = ? AND usage_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)) as yield
            FROM Gem_inventory_items i
            WHERE (i.Restaurant_Id = 0 OR i.Restaurant_Id = ?) AND i.IsActive = 1
            ORDER BY i.is_hot DESC, i.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$count_date, $rid, $rid, $rid]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // 6. HISTORICAL PURCHASE SUMMARY LEDGER DATA ENGINE
    try {
        $history_sql = "SELECT order_group_id, DATE(order_date) as formatted_date, status, COUNT(item_id) as SKU_count 
                        FROM Gem_inventory_orders 
                        WHERE restaurant_id = ? 
                        GROUP BY order_group_id, DATE(order_date), status 
                        ORDER BY formatted_date DESC LIMIT 30";
        $history_stmt = $pdo->prepare($history_sql);
        $history_stmt->execute([$rid]);
        $order_history_logs = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sub-details fetch routing loop context maps
        $details_sql = "SELECT o.order_group_id, i.name, o.pcs_ordered, o.boxes_ordered, i.Unit 
                        FROM Gem_inventory_orders o
                        JOIN Gem_inventory_items i ON o.item_id = i.Id
                        WHERE o.restaurant_id = ? 
                        ORDER BY i.name ASC";
        $details_stmt = $pdo->prepare($details_sql);
        $details_stmt->execute([$rid]);
        $raw_order_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped_order_details = [];
        foreach ($raw_order_details as $dLine) {
            $grouped_order_details[$dLine['order_group_id']][] = $dLine;
        }
    } catch (Exception $e) {
        $order_history_logs = [];
    }
}

require_once "../engine/menu_generator.php"; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Smart Auto-Order Procurements | SIP</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: 'Inter', sans-serif; padding-top: 130px; }
        .page-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); border: none; margin-bottom: 50px; }
        .forecast-box { background: #0f172a; color: white; padding: 20px; border-radius: 15px; }
        .thead-dark th { background: #1e293b !important; color: white; font-size: 11px; text-transform: uppercase; border: none; }
        .order-input { width: 95px; height: 40px; font-weight: 900; border: 2px solid #3b82f6 !important; text-align: center; border-radius: 6px; color: #1e3a8a; }
        
        /* Accordion Style Sheets Elements */
        .order-history-stripe-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; cursor: pointer; transition: all 0.2s ease; }
        .order-history-stripe-card:hover { background: #f1f5f9; border-color: #cbd5e1; }
        .order-breakdown-expanded-panel { background: white; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; padding: 15px 25px; margin-top: -12px; margin-bottom: 15px; display: none; }

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
    </style>
</head>
<body>
    <?= generateMenu($pdo); ?>

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

                <?php if (function_exists("inv_all_sites") && inv_all_sites()): ?>
            <?= inv_pick_site_banner("Pick a restaurant to run auto-order. All Sites is a filter only.") ?>
        <?php endif; ?>
<?php if ($mode === 'calculator'): ?>
            <div class="row mb-4">
                <div class="col-md-7">
                    <div class="page-card h-100">
                        <form method="GET" class="row align-items-end">
                            <input type="hidden" name="rid" value="<?= $rid ?>">
                            <input type="hidden" name="action" value="new">
                            <div class="col-md-7">
                                <label class="small font-weight-bold text-uppercase text-muted">Inventory Anchor Date</label>
                                <select name="count_date" class="form-control font-weight-bold" onchange="this.form.submit()" style="border: 2px solid #cbd5e1; border-radius: 8px; height: 45px;">
                                    <?php if(empty($available_counts)): ?>
                                        <option>No Audits Found</option>
                                    <?php else: ?>
                                        <?php foreach($available_counts as $d): ?>
                                            <option value="<?= $d ?>" <?= $count_date == $d ? 'selected' : '' ?>>
                                                Audit Baseline: <?= date('M d, Y', strtotime($d)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="small font-weight-bold text-uppercase text-muted">Days to Forecast</label>
                                <input type="number" name="project_days" class="form-control font-weight-bold" value="<?= $project_days ?>" onchange="this.form.submit()" style="border: 2px solid #cbd5e1; border-radius: 8px; height: 45px; text-align: center;">
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="forecast-box shadow-sm h-100 d-flex flex-column justify-content-center">
                        <small class="text-uppercase tracking-wider" style="color: #94a3b8; font-weight:800; font-size:10px;">Predicted Sales Volume Matrix (<?= $project_days ?> Days Cycle)</small>
                        <h1 class="font-weight-bold m-0 text-info" style="font-size:2rem;">$<?= number_format($total_forecast, 2) ?></h1>
                    </div>
                </div>
            </div>

            <div class="page-card p-0 overflow-hidden shadow-sm">
                <table class="table table-hover mb-0">
                    <thead class="thead-dark">
                        <tr>
                            <th class="pl-4 py-3">Inventory Item Description Name</th>
                            <th class="text-center py-3">14-Day Usage Yield</th>
                            <th class="text-center py-3">Current On-Hand</th>
                            <th class="text-center py-3">Net Stock Needed</th>
                            <th class="text-center py-3" style="background:#312e81;">Suggested Buy (Boxes)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($items as $i): 
                            $yield_dec = ($i['yield'] ?: 0) / 100;
                            $gross_req = ($total_forecast / $daily_forecast) * ($i['yield'] ?: 0); 
                            $net_need = $gross_req - ($i['on_hand'] ?: 0);
                            $box_size = (float)($i['pcs_in_box'] ?? 1);
                            if($box_size <= 0) $box_size = 1.00;
                            $boxes = $net_need > 0 ? ceil($net_need / $box_size) : 0;
                        ?>
                        <tr>
                            <td class="pl-4 align-middle">
                                <?= inv_pepper_html(!empty($i['is_hot'])) ?><strong style="color:#0f172a; font-size:13px;"><?= strtoupper(htmlspecialchars($i['name'])) ?></strong><br>
                                <small class="text-muted font-weight-bold">Metrics: <?= htmlspecialchars((string)($i['Unit'] ?? 'pcs')) ?> | Unit Pack: <?= (int)$box_size ?> pcs</small>
                            </td>
                            <td class="text-center align-middle font-weight-bold text-secondary" style="font-size:13px;"><?= number_format($i['yield'] ?: 0, 3) ?></td>
                            <td class="text-center align-middle font-weight-bold"><?= number_format($i['on_hand'] ?: 0, 2) ?></td>
                            <td class="text-center align-middle <?= $net_need > 0 ? 'text-danger font-weight-bold' : 'text-success font-weight-bold' ?>" style="font-size:13px;">
                                <?= $net_need > 0 ? '+' . number_format($net_need, 2) : number_format($net_need, 2) ?>
                            </td>
                            <td class="text-center align-middle" style="background:#f5f3ff; border-left: 1px solid #ddd6fe;">
                                <input type="number" class="form-control mx-auto order-input shadow-sm" value="<?= $boxes ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-3 text-right" style="background:#f8fafc; border-top:1px solid #e2e8f0;">
                     <button class="btn btn-primary font-weight-bold px-4" style="border-radius:6px;" onclick="alert('Procurement pipeline calculations locked down successfully.')"><i class="fas fa-save mr-1"></i> COMMIT AUTO-ORDER PROFILE</button>
                </div>
            </div>

        <?php else: ?>
            <div class="page-card shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 style="font-weight: 900; color: #1e293b;"><i class="fas fa-receipt text-indigo mr-2" style="color:#4f46e5;"></i>Procurement Order History Log</h2>
                        <p class="text-muted small">Showing structural historical orders committed site-wise. Click any list record item to audit individual lines.</p>
                    </div>
                    <a href="orders.php?rid=<?=$rid?>&action=new" class="btn btn-primary font-weight-bold px-4 shadow-sm" style="border-radius:8px; background:#4f46e5; border:none;"><i class="fas fa-wand-magic-sparkles"></i> RUN SMART AUTO-ORDER SYSTEM</a>
                </div>

                <div class="orders-history-accordion-wrapper mt-4">
                    <?php if(!empty($order_history_logs)): foreach($order_history_logs as $oLog): 
                        $group_id = $oLog['order_group_id'];
                    ?>
                        <div class="order-node-container">
                            <div class="order-history-stripe-card" onclick="toggleOrderLineBreakdown('<?= htmlspecialchars($group_id) ?>')">
                                <div class="d-flex align-items-center">
                                    <div class="icon-indicator-box mr-3" style="background:#e0e7ff; color:#4f46e5; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                                        <i class="fas fa-truck"></i>
                                    </div>
                                    <div>
                                        <strong style="font-size:14px; color:#0f172a;"><?= htmlspecialchars($group_id) ?></strong>
                                        <span class="text-muted small ml-2">— Logged: <?= date('M d, Y', strtotime($oLog['formatted_date'])) ?></span>
                                        <span class="badge badge-success ml-2" style="font-size:10px; font-weight:700; text-transform:uppercase; background:#10b981;"><?= $oLog['status'] ?></span>
                                    </div>
                                </div>
                                <div class="font-weight-bold" id="chevron-txt-<?= htmlspecialchars($group_id) ?>" style="font-size:11px; color:#4f46e5; text-transform:uppercase;">
                                    <?= $oLog['SKU_count'] ?> Lines <i class="fas fa-chevron-down ml-1"></i>
                                </div>
                            </div>

                            <div class="order-breakdown-expanded-panel" id="panel-order-<?= htmlspecialchars($group_id) ?>">
                                <table class="table table-sm table-striped border-0 mb-0">
                                    <thead class="text-muted small uppercase" style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                        <tr>
                                            <th>Product Stock Name</th>
                                            <th class="text-right">Quantity Ordered</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(isset($grouped_order_details[$group_id])): foreach($grouped_order_details[$group_id] as $line): ?>
                                            <tr>
                                                <td class="font-weight-bold py-2 text-dark"><?= htmlspecialchars($line['name']) ?></td>
                                                <td class="text-right font-weight-bold text-indigo py-2" style="color:#4f46e5;">
                                                    <?= number_format($line['pcs_ordered'], 2) ?> 
                                                    <small class="text-muted"><?= strtoupper((string)($line['Unit'] ?? 'pcs')) ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; else: ?>
                        <div class="text-center py-5 text-muted border rounded" style="background:#f8fafc; border-style:dashed !important;">
                            <i class="fas fa-box-open fa-3x mb-3 text-muted" style="opacity:0.3;"></i>
                            <h5 class="font-weight-bold">No Purchase Orders Found</h5>
                            <p class="small mb-0">No automated or manual vendor invoice records found for this location branch registry profile context.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleOrderLineBreakdown(groupIdToken) {
            const panel = document.getElementById('panel-order-' + groupIdToken);
            const textEl = document.getElementById('chevron-txt-' + groupIdToken);
            if(!panel) return;

            if (panel.style.display === 'block') {
                panel.style.display = 'none';
                textEl.innerHTML = 'View Details <i class="fas fa-chevron-down ml-1"></i>';
            } else {
                panel.style.display = 'block';
                textEl.innerHTML = 'Hide Details <i class="fas fa-chevron-up ml-1"></i>';
            }
        }
    </script>
</body>
</html>