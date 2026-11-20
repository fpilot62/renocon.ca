<?php
/** Path: /engine/menu_generator.php */

function generateMenu($pdo) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    
    // Fallbacks to prevent errors if session is lost
    $user_lvl = $_SESSION['user_level'] ?? 99; // Default to lowest possible access
    $user_name = $_SESSION['user_full_name'] ?? 'Staff Member';
    $role_id = $_SESSION['user_role'] ?? 0;

    // 1. FETCH THE ACTUAL ROLE NAME
    $role_stmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
    $role_stmt->execute([$role_id]);
    $user_role_name = $role_stmt->fetchColumn() ?: 'User';

    // 2. FETCH ACTIVE MENU ITEMS
    // Logic: Item's required role must be GREATER THAN OR EQUAL to user's role number
    // Example: User Role 20 can see anything marked 20, 30, or 40.
    $sql = "SELECT * FROM menu_manager 
            WHERE IsActive = 1 
            AND UserRole >= :lvl 
            ORDER BY ParentID ASC, SortOrder ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['lvl' => $user_lvl]);
    $all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. ORGANIZE INTO TREE (Parents > Children > Grandchildren)
    $menu_tree = [];
    foreach ($all_items as $item) {
        if ($item['ParentID'] == 0) {
            $menu_tree[$item['MenuID']] = $item;
            $menu_tree[$item['MenuID']]['children'] = [];
        } else {
            // Check if it's a child of a top-level item
            if (isset($menu_tree[$item['ParentID']])) {
                $menu_tree[$item['ParentID']]['children'][$item['MenuID']] = $item;
                $menu_tree[$item['ParentID']]['children'][$item['MenuID']]['grandchildren'] = [];
            } else {
                // Check if it's a grandchild
                foreach ($menu_tree as $top_id => $top_item) {
                    if (isset($top_item['children'][$item['ParentID']])) {
                        $menu_tree[$top_id]['children'][$item['ParentID']]['grandchildren'][] = $item;
                    }
                }
            }
        }
    }

    // CSS and HTML Generation...
    $html = '
    <style>
        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap\');
        :root { --nav-bg: #ffffff; --nav-text: #334155; --brand-blue: #2563eb; --border: #cbd5e1; }
        .top-nav { background: #fff; height: 75px; display: flex; align-items: center; justify-content: space-between; padding: 0 50px; border-bottom: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.05); position: fixed; top: 0; left: 0; right: 0; z-index: 9999; font-family: \'Inter\', sans-serif; }
        .nav-group { display: flex; gap: 10px; list-style: none; margin: 0; padding: 0; height: 100%; align-items: center; }
        .menu-item { position: relative; list-style: none; height: 100%; display: flex; align-items: center; }
        .nav-link { text-decoration: none; color: var(--nav-text); font-size: 13px; font-weight: 800; padding: 10px 15px; border-radius: 8px; transition: 0.2s; text-transform: uppercase; }
        .menu-item:hover > .nav-link { color: var(--brand-blue); background: rgba(37, 99, 235, 0.05); }
        .dropdown { position: absolute; top: 75px; left: 0; background: #ffffff; min-width: 250px; border: 1px solid var(--border); border-radius: 0 0 12px 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); display: none; list-style: none; margin: 0; padding: 10px 0; z-index: 10000; }
        .dropdown .dropdown { top: 0; left: 98%; border-radius: 12px; margin-top: -5px; }
        .menu-item:hover > .dropdown { display: block; }
        .dropdown .nav-link { border-radius: 0; font-size: 14px; padding: 10px 20px; font-weight: 600; color: #475569; border-left: 4px solid transparent; display: flex; justify-content: space-between; align-items: center; text-transform: none; }
        .dropdown .nav-link:hover { background: #f8fafc; color: var(--brand-blue); border-left: 4px solid var(--brand-blue); }
        .user-section { display: flex; align-items: center; gap: 20px; }
        .user-name { font-weight: 800; font-size: 14px; color: #0f172a; display: block; }
        .user-role-label { color: var(--brand-blue); font-weight: 800; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .logout-btn { background: #fff; color: #dc2626; padding: 8px 15px; border-radius: 8px; text-decoration: none; font-weight: 800; font-size: 11px; border: 1px solid #fecaca; }
    </style>

    <nav class="top-nav">
        <ul class="nav-group">';

        foreach ($menu_tree as $top_item) {
            $has_children = !empty($top_item['children']);
            $html .= '<li class="menu-item">';
            $html .= '<a href="' . $top_item['LinkURL'] . '" class="nav-link">' . $top_item['MenuTitle'] . '</a>';
            if ($has_children) {
                $html .= '<ul class="dropdown">';
                foreach ($top_item['children'] as $child) {
                    $has_grandchildren = !empty($child['grandchildren']);
                    $html .= '<li class="menu-item">';
                    $html .= '<a href="' . $child['LinkURL'] . '" class="nav-link">' . $child['MenuTitle'] . '</a>';
                    if ($has_grandchildren) {
                        $html .= '<ul class="dropdown">';
                        foreach ($child['grandchildren'] as $gc) {
                            $html .= '<li><a href="' . $gc['LinkURL'] . '" class="nav-link">' . $gc['MenuTitle'] . '</a></li>';
                        }
                        $html .= '</ul>';
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</li>';
        }

    $html .= '
        </ul>
        <div class="user-section">
            <div class="user-info" style="text-align:right">
                <span class="user-name">' . htmlspecialchars($user_name) . '</span>
                <span class="user-role-label">' . htmlspecialchars($user_role_name) . '</span>
            </div>
            <a href="logout.php" class="logout-btn">LOGOUT</a>
        </div>
    </nav>';

    return $html;
}