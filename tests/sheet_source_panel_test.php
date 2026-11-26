<?php
/**
 * Sheet Review secondary panels start collapsed, and a selected line
 * can open the sheet-source cells that previously lived only on the sheet page.
 * Run: php tests/sheet_source_panel_test.php
 */
require_once __DIR__ . '/../engine/payroll_sheet_review_lib.php';
require_once __DIR__ . '/../engine/payroll_sheet_grid_lib.php';
require_once __DIR__ . '/../engine/sheet_summary_lib.php';

$fail = 0;
function check(string $label, bool $cond): void
{
    global $fail;
    if ($cond) {
        echo "ok  $label\n";
        return;
    }
    echo "FAIL $label\n";
    $fail++;
}

check('sheet source ids skip a system line', psr_sheet_source_ids([
    ['sheet_row_id' => 0],
    ['sheet_row_id' => 12],
    ['sheet_row_id' => 4],
    ['sheet_row_id' => 12],
]) === [4, 12]);

$review = (string)file_get_contents(__DIR__ . '/../payroll_sheet_review.php');
$sheet = (string)file_get_contents(__DIR__ . '/../payroll_sheet_sheet.php');
check('the sheet page is where the cell panel used to live', strpos($sheet, 'pse_source_window') !== false && strpos($sheet, 'fde047') !== false);
check('review now opens that same cell window', strpos($review, 'pse_source_window') !== false && strpos($review, 'fde047') !== false);
check('a line with no sheet source says so', strpos($review, 'This line has no sheet source.') !== false);
check('selecting a row carries its sheet id', strpos($review, 'data-src') !== false && strpos($review, 'save_sheet_source') !== false);
check('the draft edit does not mark anyone paid', strpos($review, 'nobody was marked paid') !== false && strpos($review, 'pse_apply_edit') !== false);
foreach ([
    'unallocated-evidence' => 'Unallocated cash evidence',
    'company-totals' => 'Company all-period totals',
    'other-company' => 'Show another company beside this total',
    'import' => 'Import a workbook or a proposal',
    'drafts' => 'Draft rows',
    'plans' => 'Dated proposal plans',
    'advances' => 'Unallocated cash and advances',
    'approvals-log' => 'Approvals',
    'not-calculated' => 'Not calculated here',
] as $id => $label) {
    check('collapsed panel ' . $id, strpos($review, 'data-panel="' . $id . '"') !== false && strpos($review, $label) !== false);
}
check('panel open state is remembered', strpos($review, 'psrPanel:') !== false && strpos($review, 'localStorage') !== false);
check('the filter bar and the summary stay outside those panels', strpos($review, 'id="sheetReviewFilters"') !== false && strpos($review, 'Pay summary') !== false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
