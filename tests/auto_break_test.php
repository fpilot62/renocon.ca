<?php
/**
 * Auto meal-break rules. No database and no live payroll data.
 * Run: php tests/auto_break_test.php
 */
date_default_timezone_set('UTC');
require_once __DIR__ . '/../engine/auto_break_lib.php';

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

function inside_shift(array $window, string $in, string $out): bool
{
    $start = ab_parse_dt($window['break_start']);
    $end = ab_parse_dt($window['break_end']);
    $inTs = ab_parse_dt($in);
    $outTs = ab_parse_dt($out);
    return $start !== null && $end !== null && $inTs !== null && $outTs !== null
        && $end - $start === AB_BREAK_SECONDS
        && $start >= $inTs && $end <= $outTs;
}

$eight = ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 17:00:00');
check('8 hour shift places 30 minutes on the midpoint', $eight === [
    'break_start' => '2026-06-01 12:45:00',
    'break_end' => '2026-06-01 13:15:00',
]);
check('8 hour window stays inside the shift', $eight !== null && inside_shift($eight, '2026-06-01 09:00:00', '2026-06-01 17:00:00'));

check('exactly 5 hours gets no auto break', ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 14:00:00') === null);
$justOver = ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 14:00:01');
check('one second over 5 hours gets a window inside the shift', $justOver !== null && inside_shift($justOver, '2026-06-01 09:00:00', '2026-06-01 14:00:01'));
check('open shift gets no window', ab_window_for_shift('2026-06-01 09:00:00', null) === null);
check('zero clock-out gets no window', ab_window_for_shift('2026-06-01 09:00:00', '0000-00-00 00:00:00') === null);

$overnight = ab_window_for_shift('2026-06-01 22:00:00', '2026-06-02 06:00:00');
check('overnight midpoint is 01:45 to 02:15', $overnight === [
    'break_start' => '2026-06-02 01:45:00',
    'break_end' => '2026-06-02 02:15:00',
]);

$oddOk = true;
for ($extra = 1; $extra <= 3600; $extra += 30) {
    $out = ab_format_dt(ab_parse_dt('2026-06-01 09:00:00') + AB_THRESHOLD_SECONDS + $extra);
    $window = ab_window_for_shift('2026-06-01 09:00:00', $out);
    if ($window === null || !inside_shift($window, '2026-06-01 09:00:00', $out)) {
        $oddOk = false;
        break;
    }
}
check('windows just over 5 hours stay inside', $oddOk);

$long = ab_window_for_shift('2026-06-01 06:00:00', '2026-06-01 20:00:00');
check('a long shift still gets one 30 minute break', $long !== null && inside_shift($long, '2026-06-01 06:00:00', '2026-06-01 20:00:00') && ($long['break_end'] === '2026-06-01 13:15:00'));

$openFlags = ['is_finalized' => 0, 'payout_status' => 0, 'is_processed' => 0, 'status' => '1', 'clock_in' => '2026-06-01 09:00:00', 'clock_out' => '2026-06-01 17:00:00'];
$insert = ab_plan($openFlags, [], false);
check('a long shift with no break is inserted', $insert['action'] === 'insert' && $insert['window']['break_start'] === '2026-06-01 12:45:00');

$again = ab_plan($openFlags, [['id' => 4, 'source' => 'auto', 'break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00']], false);
check('a second auto break is not inserted', $again['action'] === 'skip' && $again['reason'] === 'already');

$moved = ab_plan($openFlags, [['id' => 4, 'source' => 'auto', 'break_start' => '2026-06-01 12:00:00', 'break_end' => '2026-06-01 12:30:00']], false);
check('an auto break on the wrong times is moved', $moved['action'] === 'move' && $moved['auto_ids'] === [4]);

$dup = ab_plan($openFlags, [
    ['id' => 4, 'source' => 'auto', 'break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00'],
    ['id' => 5, 'source' => 'auto', 'break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00'],
], false);
check('duplicate auto rows collapse to one', $dup['action'] === 'move' && $dup['reason'] === 'duplicate auto');

$real = ab_plan($openFlags, [
    ['id' => 4, 'source' => 'auto', 'break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00'],
    ['id' => 8, 'source' => 'manual', 'break_start' => '2026-06-01 12:00:00', 'break_end' => '2026-06-01 12:20:00'],
], false);
check('a real break removes the auto break', $real['action'] === 'remove_auto' && $real['auto_ids'] === [4]);

$blankSource = ab_plan($openFlags, [['id' => 3, 'break_start' => '2026-06-01 12:00:00', 'break_end' => '2026-06-01 12:10:00']], false);
check('a break with no source counts as real', $blankSource['action'] === 'skip' && $blankSource['reason'] === 'real break');

$legacy = $openFlags;
$legacy['break_start'] = '2026-06-01 12:00:00';
$legacy['break_end'] = '2026-06-01 12:30:00';
$legacyPlan = ab_plan($legacy, [], false);
check('legacy attendance break columns count as a real break', $legacyPlan['action'] === 'skip' && $legacyPlan['reason'] === 'real break');

$short = $openFlags;
$short['clock_out'] = '2026-06-01 13:00:00';
$shortPlan = ab_plan($short, [['id' => 9, 'source' => 'auto', 'break_start' => '2026-06-01 11:00:00', 'break_end' => '2026-06-01 11:30:00']], false);
check('shortening the shift removes the auto break', $shortPlan['action'] === 'remove_auto' && $shortPlan['reason'] === 'not over 5 hours');

foreach ([
    ['is_finalized' => 1],
    ['payout_status' => 2],
    ['is_processed' => 1],
] as $flag) {
    $row = array_merge($openFlags, $flag);
    $plan = ab_plan($row, [], false);
    check('finalized marker ' . json_encode($flag) . ' is left alone', $plan['action'] === 'skip' && $plan['reason'] === 'finalized');
}
check('a payroll_finalized row is left alone', ab_plan($openFlags, [], true)['reason'] === 'finalized');
check('payout_status 0 is not finalized', ab_is_finalized(['payout_status' => 0, 'is_processed' => 0, 'is_finalized' => 0], false) === false);
check('null payout is not finalized', ab_is_finalized(['payout_status' => null, 'is_processed' => 0], false) === false);

$deleted = $openFlags;
$deleted['deleted_at'] = '2026-06-02 01:00:00';
check('a soft-deleted shift is left alone', ab_plan($deleted, [], false)['reason'] === 'deleted');

$transit = $openFlags;
$transit['status'] = '5';
check('transit does not gain an auto break', ab_plan($transit, [], false)['reason'] === 'not a work shift');
$split = $openFlags;
$split['status'] = '6';
check('a split layer does not gain an auto break', ab_plan($split, [['id' => 2, 'source' => 'auto', 'break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00']], false)['action'] === 'remove_auto');

check('a break inside the shift is accepted', ab_break_bounds_error('2026-06-01 09:00:00', '2026-06-01 17:00:00', '2026-06-01T12:00', '2026-06-01T12:30') === null);
check('a break touching the clock-out is accepted', ab_break_bounds_error('2026-06-01 09:00:00', '2026-06-01 17:00:00', '2026-06-01 16:30:00', '2026-06-01 17:00:00') === null);
check('a break after clock-out is rejected', ab_break_bounds_error('2026-06-01 09:00:00', '2026-06-01 17:00:00', '2026-06-01 16:40:00', '2026-06-01 17:10:00') !== null);
check('a break before clock-in is rejected', ab_break_bounds_error('2026-06-01 09:00:00', '2026-06-01 17:00:00', '2026-06-01 08:50:00', '2026-06-01 09:20:00') !== null);
check('a break on an open shift is rejected', ab_validate_posted_breaks('2026-06-01 09:00:00', null, ['2026-06-01 12:00:00'], ['2026-06-01 12:30:00']) !== null);
check('no posted break is fine', ab_validate_posted_breaks('2026-06-01 09:00:00', '2026-06-01 17:00:00', [''], ['']) === null);

$posted = ab_validate_posted_breaks('2026-06-01 09:00:00', '2026-06-01 17:00:00', ['2026-06-01T12:00', '2026-06-01T18:00'], ['2026-06-01T12:30', '2026-06-01T18:15']);
check('one outside break rejects the set', $posted !== null && strpos($posted, 'inside the shift') !== false);

$editor = ab_editor_rows([
    ['break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00', 'source' => 'auto'],
    ['break_start' => '2026-06-01 15:00:00', 'break_end' => '2026-06-01 15:10:00', 'source' => 'manual'],
]);
check('the editor drops the auto break when a real break is saved', count($editor) === 1 && $editor[0]['source'] === 'manual');
check('the editor does not invent a break for an empty save', ab_editor_rows([]) === []);
$onlyAuto = ab_editor_rows([
    ['break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00', 'source' => 'auto'],
    ['break_start' => '2026-06-01 12:45:00', 'break_end' => '2026-06-01 13:15:00', 'source' => 'auto'],
]);
check('the editor keeps a single auto break', count($onlyAuto) === 1 && $onlyAuto[0]['source'] === 'auto');

$forced = ab_collect_breaks(['2026-06-01T12:00'], ['2026-06-01T12:30'], ['auto'], false);
check('manual entry cannot label its own break auto', $forced[0]['source'] === 'manual' && $forced[0]['break_start'] === '2026-06-01 12:00:00');

$finSql = ab_finalized_sql('a', true, true, true);
check('finalized sql covers the four markers', strpos($finSql, 'is_finalized') !== false && strpos($finSql, 'payout_status, 0) <> 0') !== false && strpos($finSql, 'is_processed') !== false && strpos($finSql, 'payroll_finalized') !== false);
$where = ab_backfill_where(true, true, true, true, true);
check('backfill skips deleted and finalized rows and can filter org', strpos($where, 'a.deleted_at IS NULL') !== false && strpos($where, 'NOT ' . $finSql) !== false && strpos($where, 'a.org_id = ?') !== false);
check('backfill length test is strictly over 5 hours', strpos(ab_over_five_hours_sql('a'), '> ' . AB_THRESHOLD_SECONDS) !== false);

$dry = ab_parse_cli([]);
check('dry-run is the default', $dry['apply'] === false && $dry['org'] === null && $dry['error'] === null);
$apply = ab_parse_cli(['--dry-run', '--apply', '--org=12']);
check('apply and org parse', $apply['apply'] === true && $apply['org'] === 12);
check('dry-run after apply wins', ab_parse_cli(['--apply', '--dry-run'])['apply'] === false);
check('bad org is rejected', ab_parse_cli(['--org=0'])['error'] !== null && ab_parse_cli(['--org=abc'])['error'] !== null);
check('unknown option is rejected', ab_parse_cli(['--force'])['error'] !== null);

$line = ab_format_backfill_line([
    'action' => 'insert',
    'reason' => 'no break',
    'window' => $eight,
    'auto_ids' => [],
    'shift' => ['id' => 12, 'org_id' => 3, 'user_id' => 9, 'clock_in' => '2026-06-01 09:00:00', 'clock_out' => '2026-06-01 17:00:00'],
]);
check('dry-run line names the shift and the window', strpos($line, 'shift 12 org 3 user 9') !== false && strpos($line, '2026-06-01 12:45:00 -> 2026-06-01 13:15:00') !== false);

$root = dirname(__DIR__);
$manual = file_get_contents($root . '/manual_attendance.php');
check('manual attendance validates breaks', strpos($manual, 'ab_validate_posted_breaks') !== false);
check('manual attendance applies the auto break', strpos($manual, 'ab_apply_to_shift') !== false);
check('manual attendance does not seed a break row on load', strpos($manual, 'appendFreshBreakRow();') === false);
check('manual attendance does not prefill 12:00', strpos($manual, 'T12:00') === false && strpos($manual, 'T12:30') === false);

$audit = file_get_contents($root . '/staff_audit.php');
check('staff audit applies the auto break when duplicating', strpos($audit, 'ab_copy_breaks') !== false && strpos($audit, 'ab_apply_to_shift') !== false);
check('staff audit saves editor breaks without the old wipe', strpos($audit, 'ab_save_editor_breaks') !== false && strpos($audit, 'DELETE FROM breaks') === false);
check('staff audit labels Auto break', strpos($audit, 'AB_LABEL') !== false && strpos($audit, 'break-badge-auto') !== false);
check('staff audit keeps the auto source in the editor', strpos($audit, 'break_source') !== false);

$script = file_get_contents($root . '/auto_break_backfill.php');
check('backfill refuses the web', strpos($script, "php_sapi_name() !== 'cli'") !== false);
check('backfill documents apply and org', strpos($script, '--apply') !== false && strpos($script, '--org') !== false);

$migration = file_get_contents($root . '/migrations/20261111_break_source.sql');
check('migration adds source idempotently', strpos($migration, 'information_schema.COLUMNS') !== false && strpos($migration, 'breaks') !== false && strpos($migration, 'manual') !== false && strpos($migration, 'source') !== false);

$lib = file_get_contents($root . '/engine/auto_break_lib.php');
$editorFn = substr($lib, (int)strpos($lib, 'function ab_save_editor_breaks'));
$editorFn = substr($editorFn, 0, (int)strpos($editorFn, "\nfunction "));
check('the editor save does not put an auto break back', strpos($editorFn, 'ab_apply_to_shift') === false && strpos($editorFn, 'ab_window_for_shift') === false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
