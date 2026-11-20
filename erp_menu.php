<?php
/**
 * Read-only menu for the ERP. Generated from menu_manager, the payroll nav table.
 */
ini_set("display_errors", "0");
ob_start();
require "/home/renocon/public_html/payroll/db.php";
ob_end_clean();

header("Content-Type: application/json; charset=utf-8");

function payroll_path($url) {
    $url = trim((string)$url);
    if ($url === "" || $url === "#" || stripos($url, "ms-quick-assist:") === 0) {
        return "";
    }
    $parts = parse_url($url);
    $path = $parts["path"] ?? "";
    if ($path === "" && isset($parts["host"])) {
        return "";
    }
    if ($path !== "" && $path[0] !== "/") {
        $path = "/" . $path;
    }
    $base = strtolower(basename($path));
    if (in_array($base, ["logout.php", "login.php", "login_kiosk.php"], true)) {
        return "";
    }
    if (!empty($parts["host"])) {
        $host = strtolower($parts["host"]);
        if (!in_array($host, ["payroll.ecommi.com", "payroll.renocon.ca"], true)) {
            return "";
        }
    }
    return $path;
}

function payroll_role($value) {
    $number = (int)$value;
    if (in_array($number, [10, 20, 25, 30, 35, 40, 1000], true)) {
        return $number;
    }
    return 25;
}

try {
    $rows = $pdo->query(
        "SELECT MenuID, ParentID, MenuTitle, LinkURL, SortOrder, UserRole
         FROM menu_manager
         WHERE CAST(IsActive AS UNSIGNED) = 1
         ORDER BY SortOrder, MenuID"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row["MenuID"]] = $row;
    }
    $groups = [];
    $seen = [];
    foreach ($rows as $row) {
        $path = payroll_path($row["LinkURL"] ?? "");
        if ($path === "") {
            continue;
        }
        if (isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $parentId = (int)($row["ParentID"] ?? 0);
        $label = "Menu";
        if ($parentId && isset($byId[$parentId])) {
            $label = trim((string)$byId[$parentId]["MenuTitle"]) ?: "Menu";
        }
        if (!isset($groups[$label])) {
            $groups[$label] = ["label" => $label, "links" => []];
        }
        $groups[$label]["links"][] = [
            "label" => trim((string)$row["MenuTitle"]),
            "path" => $path,
            "role" => payroll_role($row["UserRole"] ?? 25),
        ];
    }
    echo json_encode(["app" => "payroll", "groups" => array_values($groups)], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["app" => "payroll", "groups" => []]);
}
