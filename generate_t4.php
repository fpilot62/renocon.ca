<?php
/**
 * Path: /generate_t4.php
 * T4 box totals and ROE insurable earnings from stored payroll lines.
 * This does not recalculate a closed year and it does not transmit a slip or an ROE.
 */
require_once __DIR__ . '/payroll_engine/payroll_engine.php';
require_once __DIR__ . '/payroll_engine/page_gate.php';

[$pdo, $orgId, $isSuper, $actor] = pe_page_org('generate_t4.php', 'view');
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v) => '$' . number_format((float)$v, 2);
$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}
$userId = (int)($_GET['emp_id'] ?? $_GET['user_id'] ?? 0);
$people = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ? ORDER BY Name');
$people->execute([$orgId]);
$employees = $people->fetchAll(PDO::FETCH_ASSOC);
$report = null;
if ($userId > 0) {
    $report = pe_t4_roe($pdo, $orgId, $userId, $year);
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>T4 and ROE inputs</title>
<style>
body { font-family: Georgia, serif; margin: 24px; color: #1c1915; background: #f7f4ee; }
table { border-collapse: collapse; background: #fff; }
td, th { border-bottom: 1px solid #e4dccb; padding: 6px 10px; text-align: left; }
.note { max-width: 720px; }
</style>
</head>
<body>
<h1>T4 and ROE inputs</h1>
<p class="note">These figures are the amounts already stored on payroll lines for this organization. They are not transmitted to the CRA. A line with no deduction edition was stored before this T4127 engine wrote it, so do not file that year until those lines have been reviewed.</p>
<form method="get">
<input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
<label>Year <input name="year" value="<?= (int)$year ?>" size="6"></label>
<label>Employee
<select name="emp_id">
<option value="">Choose</option>
<?php foreach ($employees as $e): ?>
<option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === $userId ? 'selected' : '' ?>><?= $h($e['Name']) ?></option>
<?php endforeach; ?>
</select>
</label>
<button>Show</button>
</form>
<?php if ($report && empty($report['ok'])): ?>
<p><?= $h($report['error'] ?? 'Nothing to show.') ?></p>
<?php elseif ($report): ?>
<table>
<tr><th>Box</th><th>Amount</th></tr>
<tr><td>14 Employment income</td><td><?= $money($report['box14_employment_income']) ?></td></tr>
<tr><td>16 Employee CPP (base and first additional)</td><td><?= $money($report['box16_cpp']) ?></td></tr>
<tr><td>16A Employee CPP2</td><td><?= $money($report['box16a_cpp2']) ?></td></tr>
<tr><td>18 Employee EI</td><td><?= $money($report['box18_ei']) ?></td></tr>
<tr><td>22 Income tax</td><td><?= $money($report['box22_tax']) ?></td></tr>
<tr><td>24 EI insurable earnings</td><td><?= $money($report['box24_insurable']) ?></td></tr>
<tr><td>26 CPP pensionable earnings</td><td><?= $money($report['box26_pensionable']) ?></td></tr>
<tr><td>Employer CPP / CPP2 / EI</td><td><?= $money($report['cpp_er']) ?> / <?= $money($report['cpp2_er']) ?> / <?= $money($report['ei_er']) ?></td></tr>
</table>
<?php if ((int)$report['lines_without_edition'] > 0): ?>
<p class="note"><?= (int)$report['lines_without_edition'] ?> line(s) in this year have no T4127 edition stamp. They are included as stored. Do not file from them until someone has checked the deductions.</p>
<?php endif; ?>
<h2>ROE insurable earnings by line</h2>
<table>
<tr><th>Work date</th><th>Store</th><th>Insurable</th><th>Hours</th></tr>
<?php foreach ($report['roe_periods'] as $p): ?>
<tr>
<td><?= $h($p['work_date']) ?></td>
<td><?= (int)$p['site_id'] ?></td>
<td><?= $money($p['insurable']) ?></td>
<td><?= $h(number_format($p['hours'], 2)) ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</body>
</html>
