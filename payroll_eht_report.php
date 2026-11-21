<?php
/**
 * Path: /payroll_eht_report.php
 * Ontario Employer Health Tax for one organization and year.
 * Calculated from stored Ontario remuneration. Nothing is filed.
 */
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/page_gate.php';

[$pdo, $orgId, $isSuper, $actor] = pe_page_org('payroll_eht_report.php', 'view');
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => '$' . number_format((float)$v, 2);
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
$report = pe_eht_annual($pdo, $orgId, $year);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Ontario EHT</title>
<style>
body { font-family: Georgia, serif; margin: 24px; color: #1c1915; background: #f7f4ee; }
table { border-collapse: collapse; background: #fff; }
td, th { border-bottom: 1px solid #e4dccb; padding: 6px 10px; text-align: left; }
.note { max-width: 720px; }
</style>
</head>
<body>
<?= function_exists('privacy_banner_html') ? privacy_banner_html() : '' ?>
<h1>Ontario Employer Health Tax, <?= (int)$year ?></h1>
<p class="note">This is the annual figure from remuneration stored on Ontario sites. It is not filed with the Ministry of Finance. The rate depends on total Ontario remuneration. The tax is that rate times remuneration after this organization's share of the exemption. An associated group over $5,000,000 has no exemption unless you record that the group is under the cap.</p>
<form method="get">
<input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
<label>Year <input name="year" value="<?= (int)$year ?>" size="6"></label>
<button>Show</button>
</form>
<table>
<tr><td>Ontario remuneration</td><td><?= $money($report['remuneration']) ?></td></tr>
<tr><td>Exemption used</td><td><?= $money($report['exemption']) ?></td></tr>
<tr><td>Taxable remuneration</td><td><?= $money($report['taxable']) ?></td></tr>
<tr><td>Rate</td><td><?= $h(number_format((float)$report['rate'] * 100, 3)) ?>%</td></tr>
<tr><td>EHT</td><td><?= $money($report['tax']) ?></td></tr>
<tr><td>Eligible</td><td><?= $report['eligible_configured'] ? 'yes' : 'no' ?></td></tr>
<tr><td>Exemption configured for this organization</td><td><?= $money($report['exemption_configured']) ?></td></tr>
<tr><td>Associated group over $5,000,000</td><td><?= $report['group_over_cap'] ? 'yes' : 'no' ?></td></tr>
</table>
<h2>Ontario stores</h2>
<table>
<tr><th>Store</th><th>Remuneration</th></tr>
<?php foreach ($report['sites'] as $site): ?>
<tr><td><?= $h($site['nick_name']) ?></td><td><?= $money($site['pay']) ?></td></tr>
<?php endforeach; ?>
</table>
</body>
</html>
