<?php
/**
 * Daily / Weekly / Monthly POS lines stay separate. SQLite in memory, no live database.
 * Run: php tests/dal_period_lines_test.php
 */
require_once __DIR__ . '/../engine/dal_period_lines.php';

$fail = 0;
function check($label, $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

check('week_split is a Weekly line', dal_line_period('week_split 2026-10-18') === 'weekly');
check('mtd_split is a Monthly line', dal_line_period('mtd_split 2026-10-24') === 'monthly');
check('empty or null remark is a Daily line', dal_line_period('') === 'daily' && dal_line_period(null) === 'daily');

check('Monthly line never needs a Daily POS to type Sales', dal_sales_edit_needs_daily_pos('monthly') === false);
check('Weekly line never needs a Daily POS to type Sales', dal_sales_edit_needs_daily_pos('weekly') === false);
check('Daily line still needs its Daily POS', dal_sales_edit_needs_daily_pos('daily') === true);

check('Monthly red hint asks for the Monthly total, not daily sales', strpos(dal_recheck_hint('monthly'), 'Monthly total') !== false && stripos(dal_recheck_hint('monthly'), 'daily') === false);
check('Weekly red hint asks for the Weekly total, not daily sales', strpos(dal_recheck_hint('weekly'), 'Weekly total') !== false && stripos(dal_recheck_hint('weekly'), 'daily') === false);
check('Daily red hint unchanged', dal_recheck_hint('daily') === 'Type Sales $ — POS unread');
check('Monthly unread tooltip says no daily sales needed', stripos(dal_recheck_title('monthly', true), 'No daily sales needed') !== false);
check('unread note for a Monthly line points at the Monthly line', strpos(dal_need_sales_note(['monthly']), 'Monthly total in red on the Monthly line') !== false);
check('unread note for a Daily line unchanged', dal_need_sales_note(['daily']) === 'POS did not read Sales $. Type it in red on that row.');

check('Monthly line MTD shows its own Monthly total', abs(dal_line_mtd_display('monthly', 15000.00, 12410.21) - 15000.00) < 0.001);
check('empty Monthly line falls back to daily MTD', abs(dal_line_mtd_display('monthly', 0.0, 12410.21) - 12410.21) < 0.001);
check('Weekly line MTD stays the daily-lines MTD', abs(dal_line_mtd_display('weekly', 2515.80, 12410.21) - 12410.21) < 0.001);
check('Daily line MTD stays the daily-lines MTD', abs(dal_line_mtd_display('daily', 768.87, 12410.21) - 12410.21) < 0.001);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE daily_report (report_id INTEGER PRIMARY KEY, restaurant_id INT, report_date TEXT, pos_sales REAL, paid_out REAL, remarks TEXT)');
$ins = $pdo->prepare('INSERT INTO daily_report (restaurant_id, report_date, pos_sales, paid_out, remarks) VALUES (?,?,?,?,?)');
foreach ([
    [20, '2026-10-11', 605.07, 0, null],
    [20, '2026-10-16', 745.87, 0, ''],
    [20, '2026-10-17', 787.45, 0, ''],
    [20, '2026-10-18', 768.87, 0, ''],
    [20, '2026-10-18', 2515.80, 0, 'week_split 2026-10-18'],
    [20, '2026-10-24', 15000.00, 0, 'mtd_split 2026-10-24'],
    [21, '2026-10-18', 999.00, 0, ''],
] as $r) {
    $ins->execute($r);
}
$sum = dal_daily_lines_sum($pdo, 20, '2026-10-01', '2026-10-24');
check('daily-lines sum leaves out Weekly and Monthly lines (no double count)', abs($sum - 2907.26) < 0.001);
check('daily-lines sum stays inside the site', abs(dal_daily_lines_sum($pdo, 21, '2026-10-01', '2026-10-24') - 999.00) < 0.001);
check('weekly window sums daily lines only', abs(dal_daily_lines_sum($pdo, 20, '2026-10-12', '2026-10-18') - 2302.19) < 0.001);

// Source checks on the page itself.
$src = (string)file_get_contents(__DIR__ . '/../daily_report_view.php');
check('page loads the period-lines helper', strpos($src, "require_once 'engine/dal_period_lines.php';") !== false);
check('row Sales $ update reads remarks to know the line type', strpos($src, 'SELECT restaurant_id, report_date, remarks FROM daily_report WHERE report_id = ?') !== false);
check('Daily POS rule only runs for Daily lines on update', (bool)preg_match('/if \(dal_sales_edit_needs_daily_pos\(\$linePeriod\)\) \{\s*\$posErr = dal_pos_required_error/', $src));
check('typed Weekly/Monthly total refreshes the comparison bar', strpos($src, "dal_write_period_verify(\$DAL_UPLOAD_ROOT, \$lineRid, \$linePeriod") !== false);
check('edit panel can send a typed total', strpos($src, "fd.append('manual_sales', typedSales)") !== false && strpos($src, 'id="new_sales"') !== false);
check('totals row still counts Daily lines only', (bool)preg_match("/if \(dal_row_period\(\\\$_r\['remarks'\] \?\? ''\) !== 'daily'\) \{\s*continue;/", $src));
check('MTD SQL still excludes week_split and mtd_split', substr_count($src, "dr2.remarks NOT LIKE '%week_split%' AND dr2.remarks NOT LIKE '%mtd_split%'") >= 2);
check('red hint is per line type', strpos($src, 'dal_recheck_hint($periodKey)') !== false && strpos($src, 'Type Sales $ — POS unread</div>') === false);


// Typed by hand on an uploaded line: file kept, sidecar marked, other sidecar keys kept.
$tmpDir = sys_get_temp_dir() . '/dal_typed_' . bin2hex(random_bytes(3));
@mkdir($tmpDir, 0700, true);
$pdf = $tmpDir . '/2026-10-24_monthly_pos_report.pdf';
file_put_contents($pdf, "%PDF-1.4 test");
file_put_contents($tmpDir . '/2026-10-24_monthly_pos_report.json', json_encode(['orig' => 'oct.pdf', 'period' => 'monthly', 'unclear' => true]));
check('typed mark is written', dal_mark_sales_typed($pdf, 15000.004, 7) === true);
$meta = json_decode((string)file_get_contents($tmpDir . '/2026-10-24_monthly_pos_report.json'), true);
check('POS file is kept after typing', is_file($pdf) && filesize($pdf) > 0);
check('sidecar marks Sales typed by hand with amount and user', !empty($meta['sales_typed']) && abs($meta['sales_typed_amount'] - 15000.00) < 0.001 && (int)$meta['sales_typed_by'] === 7 && !empty($meta['sales_typed_at']));
check('sidecar keeps the original upload details', ($meta['orig'] ?? '') === 'oct.pdf' && ($meta['period'] ?? '') === 'monthly');
check('typed mark refuses a missing file', dal_mark_sales_typed($tmpDir . '/missing.pdf', 1.0) === false);
check('Typed tag shows the amount', strpos(dal_typed_tag_html(['sales_typed' => true, 'sales_typed_amount' => 15000, 'sales_typed_at' => '']), 'Typed $15,000.00') !== false);
check('no tag when not typed', dal_typed_tag_html(['sales_typed' => false]) === '' && dal_typed_tag_html(null) === '');
@unlink($pdf);
@unlink($tmpDir . '/2026-10-24_monthly_pos_report.json');
@rmdir($tmpDir);

check('row Sales box accepts 12,410.21 (text, not number input)', strpos($src, '<input type="text" inputmode="decimal" autocomplete="off" class="dal-manual-amt') !== false);
check('update refuses blank instead of saving $0.00', strpos($src, "Type the amount as a number, for example 12410.21.") !== false && strpos($src, '$val    = (float)$_POST[\'value\'];') === false);
check('update marks the line typed by hand and keeps the file', strpos($src, 'dal_mark_sales_typed((string)$typedPos[\'path\'], (float)$val, (int)$user_id);') !== false);
check('save_day typed total is marked too', substr_count($src, 'dal_mark_sales_typed(') >= 3);
check('Typed tag replaces Recheck on the file link', strpos($src, "if (!empty(\$found['sales_typed'])) {\n        \$html .= dal_typed_tag_html(\$found);") !== false);

if ($fail) {
    echo "\n$fail FAILED\n";
    exit(1);
}
echo "\nall passed\n";
