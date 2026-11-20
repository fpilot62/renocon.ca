<?php
/**
 * Org 4 (AA Group) province assignment and unpaid-period recalculation.
 *   php ops/t4127_aa.php dry
 *   php ops/t4127_aa.php apply
 * dry writes nothing. apply updates provinces and unpaid lines inside one transaction.
 */
$mode = $argv[1] ?? 'dry';
$apply = $mode === 'apply';
$app = getenv('PAYROLL_APP') ?: '/home/renocon/public_html/payroll';
require $app . '/db.php';
require $app . '/payroll_engine/payroll_engine.php';
require $app . '/payroll_engine/stat_holidays.php';
if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "db.php did not provide a connection\n");
    exit(1);
}

function aa_cols(PDO $pdo, string $table): array {
    $out = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`') as $c) {
        $out[strtolower((string)$c['Field'])] = true;
    }
    return $out;
}

function aa_infer(array $site): array {
    $blob = strtolower(trim(implode(' ', [
        $site['nick_name'] ?? '', $site['city'] ?? '', $site['state'] ?? '',
        $site['province'] ?? '', $site['street_address'] ?? '', $site['zipcode'] ?? '',
    ])));
    if (preg_match('/\bs\d[a-z]\s?\d[a-z]\d\b/', $blob)) {
        return ['SK', 'postal code'];
    }
    if (preg_match('/\b[klmnp]\d[a-z]\s?\d[a-z]\d\b/', $blob)) {
        return ['ON', 'postal code'];
    }
    $sk = ['saskatchewan', 'saskatoon', 'regina', 'moose jaw', 'prince albert', 'yorkton', 'swift current', 'north battleford', 'estevan', 'weyburn'];
    $on = ['ontario', 'bramalea', 'ajax', 'barrie', 'toronto', 'mississauga', 'brampton', 'vaughan', 'markham', 'richmond hill', 'oakville', 'burlington', 'hamilton', 'london', 'ottawa', 'kitchener', 'waterloo', 'guelph', 'oshawa', 'pickering', 'whitby', 'newmarket', 'aurora', 'milton', 'bradford', 'innisfil', 'orillia', 'kingston', 'windsor', 'sudbury', 'niagara', 'cambridge', 'belleville'];
    foreach ($sk as $name) {
        if (strpos($blob, $name) !== false) {
            return ['SK', $name];
        }
    }
    foreach ($on as $name) {
        if (strpos($blob, $name) !== false) {
            return ['ON', $name];
        }
    }
    return ['', 'unmapped'];
}

function aa_money(float $n): string {
    return number_format($n, 2, '.', '');
}

$cols = aa_cols($pdo, 'payroll_finalized');
$siteCols = aa_cols($pdo, 'sites');
$unpaid = ['1=1'];
if (isset($cols['paid_amount'])) {
    $unpaid[] = 'COALESCE(f.paid_amount,0) <= 0.02';
}
if (isset($cols['paid_at'])) {
    $unpaid[] = "(f.paid_at IS NULL OR f.paid_at IN ('0000-00-00','0000-00-00 00:00:00'))";
}
if (isset($cols['payment_status'])) {
    $unpaid[] = "LOWER(COALESCE(f.payment_status,'')) NOT IN ('paid','remitted','cheque_printed')";
}
if (isset($cols['status'])) {
    $unpaid[] = "LOWER(COALESCE(f.status,'')) NOT IN ('paid','remitted')";
}
if (isset($cols['cra_status'])) {
    $unpaid[] = "LOWER(COALESCE(f.cra_status,'')) NOT IN ('paid','remitted')";
}
$unpaidSql = implode(' AND ', $unpaid);
$periodExpr = isset($cols['pay_period_end']) ? "COALESCE(NULLIF(f.pay_period_end,'0000-00-00'), f.work_date)" : 'f.work_date';
$cpp2 = isset($cols['cpp2']) ? 'COALESCE(f.cpp2,0)' : '0';
$nick = isset($siteCols['nick_name']) ? 's.nick_name' : "CONCAT('Site ', s.SId)";
$city = isset($siteCols['city']) ? 's.city' : "''";
$state = isset($siteCols['state']) ? 's.state' : "''";
$provCol = isset($siteCols['province']) ? 's.province' : "''";
$street = isset($siteCols['street_address']) ? 's.street_address' : "''";
$zip = isset($siteCols['zipcode']) ? 's.zipcode' : "''";

echo $apply ? "AA APPLY org 4\n" : "AA DRY org 4 (no writes)\n";
echo "Provinces inferred from city, address, or postal code:\n";
$sites = $pdo->query(
    "SELECT s.SId, $nick AS nick_name, $city AS city, $state AS state, $provCol AS province, $street AS street_address, $zip AS zipcode
       FROM sites s WHERE s.org_id=4 ORDER BY nick_name, s.SId"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($sites as $site) {
    [$inf, $why] = aa_infer($site);
    $label = $inf === '' ? 'UNMAPPED' : $inf;
    echo '  site ' . $site['SId'] . ' ' . $site['nick_name'] . ' stored=' . ($site['province'] ?: '-') . " inferred=$label ($why)\n";
}

$sql = "SELECT f.user_id, u.Name AS employee, f.site_id, $nick AS nick_name, $city AS city, $state AS state,
               $provCol AS province, $street AS street_address, $zip AS zipcode, $periodExpr AS period_end,
               ROUND(SUM(f.total_gross),2) gross, ROUND(SUM(f.cpp)+SUM($cpp2),2) cpp, ROUND(SUM(f.ei),2) ei,
               ROUND(SUM(f.income_tax),2) tax, SUM(CASE WHEN $unpaidSql THEN 0 ELSE 1 END) AS paid_lines
          FROM payroll_finalized f
          INNER JOIN users u ON u.id=f.user_id AND u.org_id=4
          LEFT JOIN sites s ON s.SId=f.site_id
         GROUP BY f.user_id, u.Name, f.site_id, nick_name, city, state, province, street_address, zipcode, period_end
         HAVING paid_lines=0 AND gross>0
         ORDER BY period_end, nick_name, employee";
$periods = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "Unpaid periods: " . count($periods) . "\n";
echo str_pad('employee', 22) . str_pad('site', 16) . str_pad('period', 12) . str_pad('prov', 6)
    . str_pad('old cpp', 10) . str_pad('old ei', 10) . str_pad('old tax', 10)
    . str_pad('new cpp', 10) . str_pad('new ei', 10) . "new tax\n";

$provinceCount = 0;
$shown = 0;
$stats = 0;
if ($apply) {
    $pdo->beginTransaction();
}
try {
    if ($apply && isset($siteCols['province'])) {
        foreach ($sites as $site) {
            [$inf] = aa_infer($site);
            if ($inf !== '' && strtoupper(trim((string)$site['province'])) !== $inf) {
                $pdo->prepare('UPDATE sites SET province=? WHERE SId=? AND org_id=4')->execute([$inf, (int)$site['SId']]);
                $provinceCount++;
            }
        }
    }
    foreach ($periods as $row) {
        [$inf, $why] = aa_infer($row);
        if ($inf === '') {
            continue;
        }
        $day = substr((string)$row['period_end'], 0, 10);
        $uid = (int)$row['user_id'];
        $sid = (int)$row['site_id'];
        if ($apply) {
            $stats += sh_after_finalize($pdo, $uid, $sid, $day, 0);
            $fresh = pe_refresh_period_stat($pdo, $uid, $sid, $day);
            $newCpp = $fresh ? (float)$fresh['cpp'] + (float)$fresh['cpp2'] : 0.0;
            $newEi = $fresh ? (float)$fresh['ei'] : 0.0;
            $newTax = $fresh ? (float)$fresh['income_tax'] : 0.0;
        } else {
            $ytd = ['pensionable' => 0.0, 'cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0];
            try {
                $ys = $pdo->prepare('SELECT COALESCE(SUM(pensionable),0) p, COALESCE(SUM(cpp),0) c, COALESCE(SUM(cpp2),0) c2, COALESCE(SUM(ei),0) e FROM payroll_period_stat WHERE user_id=? AND site_id=? AND period_end < ?');
                $ys->execute([$uid, $sid, $day]);
                if ($yr = $ys->fetch(PDO::FETCH_ASSOC)) {
                    $ytd = ['pensionable' => (float)$yr['p'], 'cpp' => (float)$yr['c'], 'cpp2' => (float)$yr['c2'], 'ei' => (float)$yr['e']];
                }
            } catch (Throwable $e) {
            }
            $calc = sd_calculate([
                'pay_date' => $day,
                'province' => $inf,
                'periods' => 26,
                'periodic_gross' => (float)$row['gross'],
                'ytd_pensionable' => $ytd['pensionable'],
                'ytd_cpp' => $ytd['cpp'],
                'ytd_cpp2' => $ytd['cpp2'],
                'ytd_ei' => $ytd['ei'],
            ]);
            $newCpp = (float)$calc['cpp'] + (float)$calc['cpp2'];
            $newEi = (float)$calc['ei'];
            $newTax = (float)$calc['income_tax'];
        }
        if ($shown < 40) {
            echo str_pad(substr((string)$row['employee'], 0, 20), 22)
                . str_pad(substr((string)$row['nick_name'], 0, 14), 16)
                . str_pad($day, 12)
                . str_pad($inf, 6)
                . str_pad(aa_money((float)$row['cpp']), 10)
                . str_pad(aa_money((float)$row['ei']), 10)
                . str_pad(aa_money((float)$row['tax']), 10)
                . str_pad(aa_money($newCpp), 10)
                . str_pad(aa_money($newEi), 10)
                . aa_money($newTax) . "\n";
        }
        $shown++;
    }
    if ($apply && $pdo->inTransaction()) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($apply && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'AA failed, transaction rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Counted unpaid periods recalculated: $shown. Province rows " . ($apply ? "updated: $provinceCount" : "that would change are listed above") . ". Stat lines posted: $stats.\n";
echo $apply ? "Paid rows were not modified.\n" : "Dry run wrote nothing.\n";
