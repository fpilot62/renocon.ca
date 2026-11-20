<?php
/**
 * One-time sign-in from ERP. Direct payroll login is unchanged.
 * Token layout matches erp/sso_token.py.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header("Referrer-Policy: no-referrer");

function sso_deny(string $message): void {
    http_response_code(403);
    header("Content-Type: text/plain; charset=utf-8");
    echo $message;
    exit;
}

function sso_b64(string $text): string {
    $pad = strlen($text) % 4;
    if ($pad) {
        $text .= str_repeat("=", 4 - $pad);
    }
    $raw = base64_decode(strtr($text, "-_", "+/"), true);
    return $raw === false ? "" : $raw;
}

function sso_secret(): string {
    $env = getenv("ERP_SSO_SECRET");
    if (is_string($env) && trim($env) !== "") {
        return trim($env);
    }
    $path = "/home/renocon/apps/erp/data/sso.key";
    if (!is_readable($path)) {
        return "";
    }
    return trim((string) file_get_contents($path));
}

function sso_verify(string $token, string $key): ?array {
    if ($key === "" || strpos($token, ".") === false) {
        return null;
    }
    [$bodyB64, $sigB64] = explode(".", $token, 2);
    $body = sso_b64($bodyB64);
    $sig = sso_b64($sigB64);
    if ($body === "" || $sig === "") {
        return null;
    }
    $expected = hash_hmac("sha256", $body, $key, true);
    if (strlen($sig) !== strlen($expected) || !hash_equals($expected, $sig)) {
        return null;
    }
    $values = explode("\n", $body);
    $fields = ["aud", "exp", "jti", "username", "books_org_id", "payroll_org_id", "payroll_user_id", "erp_person_id", "next"];
    if (count($values) !== count($fields)) {
        return null;
    }
    $payload = array_combine($fields, $values);
    if ($payload["aud"] !== "payroll" || (int) $payload["exp"] < time()) {
        return null;
    }
    return $payload;
}

function sso_consume(string $jti): bool {
    $path = "/home/renocon/apps/erp/data/erp.db";
    if (!is_file($path)) {
        return false;
    }
    $db = new SQLite3($path);
    $db->busyTimeout(5000);
    $db->exec("BEGIN IMMEDIATE");
    $stmt = $db->prepare("SELECT exp, used FROM sso_nonce WHERE jti = :jti");
    $stmt->bindValue(":jti", $jti, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row || (int) $row["used"] === 1 || (int) $row["exp"] < time()) {
        $db->exec("ROLLBACK");
        $db->close();
        return false;
    }
    $update = $db->prepare("UPDATE sso_nonce SET used = 1 WHERE jti = :jti");
    $update->bindValue(":jti", $jti, SQLITE3_TEXT);
    $update->execute();
    $db->exec("COMMIT");
    $db->close();
    return true;
}

$allowed = [
    "/sip/active/modules/scheduling/scheduler_view.php",
    "/daily_report_view.php",
    "/food_cost_view.php",
    "/paid_out_report.php",
    "/inventory/index.php",
    "/inventory/manage_items.php",
    "/inventory/add_invoice.php",
    "/inventory/usage.php",
    "/inventory/physical_count.php",
    "/inventory/inventory_report.php",
    "/payroll_master_ledger.php",
    "/staff_audit.php",
    "/manual_pay_entry.php",
    "/vacation_service_admin.php",
    "/generate_stat_shifts.php",
    "/payroll_cra_payments.php",
    "/generate_t4.php",
    "/bulk_importer.php",
    "/export_quickbooks.php",
];

$token = (string) ($_GET["token"] ?? "");
$payload = sso_verify($token, sso_secret());
if ($payload === null) {
    sso_deny("This sign-in link is not valid.");
}
$next = (string) $payload["next"];
if (!in_array($next, $allowed, true)) {
    sso_deny("That page is not open from ERP.");
}
if (!sso_consume((string) $payload["jti"])) {
    sso_deny("This sign-in link was already used.");
}

require "/home/renocon/public_html/payroll/db.php";
$stmt = $pdo->prepare("SELECT id, Name, org_id, user_role, assigned_site_ids, primary_site_id, active FROM users WHERE id = ?");
$stmt->execute([(int) $payload["payroll_user_id"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || (int) $user["active"] !== 1 || (int) $user["org_id"] !== (int) $payload["payroll_org_id"]) {
    sso_deny("This login is not an active payroll user in that company.");
}

session_regenerate_id(true);
$_SESSION["user_id"] = (int) $user["id"];
$_SESSION["user_full_name"] = $user["Name"];
$_SESSION["org_id"] = (int) $user["org_id"];
$_SESSION["user_level"] = (int) $user["user_role"];
$_SESSION["user_role"] = (int) $user["user_role"];
$_SESSION["assigned_site_ids"] = $user["assigned_site_ids"] ?? "";
$_SESSION["access_scope"] = ((int) $user["user_role"] <= 10) ? "all" : "restricted";
$_SESSION["active_rid"] = (int) ($user["primary_site_id"] ?? 0);
header("Location: " . $next);
exit;
