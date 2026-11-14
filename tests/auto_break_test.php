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
    if ($start === null || $end === null || $inTs === null || $outTs === null) {
        return false;
    }
    return $end - $start === ab_break_minutes($outTs - $inTs) * 60
        && $start >= $inTs && $end <= $outTs;
}

check('5 hours is 19 minutes', ab_break_minutes(5 * 3600) === 19);
check('6 hours is 23 minutes', ab_break_minutes(6 * 3600) === 23);
check('8 hours is 30 minutes', ab_break_minutes(8 * 3600) === 30);
check('10 hours is 38 minutes', ab_break_minutes(10 * 3600) === 38);
check('16 hours is 60 minutes', ab_break_minutes(16 * 3600) === 60);
check('under 5 hours is no break', ab_break_minutes(5 * 3600 - 1) === 0);

$eight = ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 17:00:00');
check('8 hour shift places 30 minutes on the midpoint', $eight !== null && $eight['break_start'] === '2026-06-01 12:45:00' && $eight['break_end'] === '2026-06-01 13:15:00' && $eight['minutes'] === 30);
check('8 hour window stays inside the shift', $eight !== null && inside_shift($eight, '2026-06-01 09:00:00', '2026-06-01 17:00:00'));

$five = ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 14:00:00');
check('exactly 5 hours gets 19 minutes', $five !== null && $five['minutes'] === 19 && $five['break_start'] === '2026-06-01 11:20:30' && $five['break_end'] === '2026-06-01 11:39:30');
check('one second under 5 hours gets no auto break', ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 13:59:59') === null);
check('open shift gets no window', ab_window_for_shift('2026-06-01 09:00:00', null) === null);
check('zero clock-out gets no window', ab_window_for_shift('2026-06-01 09:00:00', '0000-00-00 00:00:00') === null);

$six = ab_window_for_shift('2026-06-01 09:00:00', '2026-06-01 15:00:00');
check('6 hours is 23 minutes inside the shift', $six !== null && $six['minutes'] === 23 && inside_shift($six, '2026-06-01 09:00:00', '2026-06-01 15:00:00'));
$ten = ab_window_for_shift('2026-06-01 08:00:00', '2026-06-01 18:00:00');
check('10 hours is 38 minutes inside the shift', $ten !== null && $ten['minutes'] === 38 && inside_shift($ten, '2026-06-01 08:00:00', '2026-06-01 18:00:00'));
$sixteen = ab_window_for_shift('2026-06-01 06:00:00', '2026-06-01 22:00:00');
check('16 hours is 60 minutes inside the shift', $sixteen !== null && $sixteen['minutes'] === 60 && inside_shift($sixteen, '2026-06-01 06:00:00', '2026-06-01 22:00:00'));

$overnight = ab_window_for_shift('2026-06-01 22:00:00', '2026-06-02 06:00:00');
check('overnight midpoint is 01:45 to 02:15', $overnight !== null && $overnight['break_start'] === '2026-06-02 01:45:00' && $overnight['break_end'] === '2026-06-02 02:15:00');

$oddOk = true;
for ($extra = 0; $extra <= 3600; $extra += 30) {
    $out = ab_format_dt(ab_parse_dt('2026-06-01 09:00:00') + AB_THRESHOLD_SECONDS + $extra);
    $window = ab_window_for_shift('2026-06-01 09:00:00', $out);
    if ($window === null || !inside_shift($window, '2026-06-01 09:00:00', $out)) {
        $oddOk = false;
        break;
    }
}
check('windows of at least 5 hours stay inside', $oddOk);

$long = ab_window_for_shift('2026-06-01 06:00:00', '2026-06-01 20:00:00');
check('a 14 hour shift gets one prorated break', $long !== null && $long['minutes'] === 53 && inside_shift($long, '2026-06-01 06:00:00', '2026-06-01 20:00:00'));

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
check('shortening the shift removes the auto break', $shortPlan['action'] === 'remove_auto' && $shortPlan['reason'] === 'under 5 hours');

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
check('backfill length test includes a 5 hour shift', strpos(ab_over_five_hours_sql('a'), '>= ' . AB_THRESHOLD_SECONDS) !== false);

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
    'shift' => ['id' => 12, 'org_id' => 3, 'site_id' => 7, 'user_id' => 9, 'clock_in' => '2026-06-01 09:00:00', 'clock_out' => '2026-06-01 17:00:00'],
]);
check('dry-run line names the shift, the hours, and the window', strpos($line, 'shift 12 org 3 site 7 user 9 worked 8.00h') !== false && strpos($line, 'add 30m 2026-06-01 12:45:00 -> 2026-06-01 13:15:00') !== false);
check('site counts are listed for one org', ab_format_site_counts([2 => [14 => ['add' => 4, 'move' => 0, 'remove' => 1]]], 2) === 'org 2 site 14: 4 to add, 0 to move, 1 to remove');

$root = dirname(__DIR__);
$manual = file_get_contents($root . '/manual_attendance.php');
check('manual attendance validates breaks', strpos($manual, 'ab_validate_posted_breaks') !== false);
check('manual attendance applies the auto break', strpos($manual, 'ab_apply_to_shift') !== false);
check('manual attendance does not seed a break row on load', strpos($manual, 'appendFreshBreakRow();') === false);
check('manual attendance does not prefill 12:00', strpos($manual, 'T12:00') === false && strpos($manual, 'T12:30') === false);
check('manual attendance describes the prorated auto break', strpos($manual, 'rounded to the nearest minute') !== false);

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

check('recent clock-outs are the last 3 days', strpos(ab_recent_clockout_sql(true), 'INTERVAL 3 DAY') !== false && strpos(ab_recent_clockout_sql(true), 'deleted_at IS NULL') !== false);
check('org summary lists each company', ab_format_org_counts([2 => ['add' => 3, 'move' => 0, 'remove' => 1]]) === 'org 2: 3 to add, 0 to move, 1 to remove');

$cron = file_get_contents($root . '/auto_break_cron.php');
check('cron refuses the web', strpos($cron, "php_sapi_name() !== 'cli'") !== false);
check('cron locks and applies the same rules', strpos($cron, 'flock') !== false && strpos($cron, 'ab_apply_to_shift') !== false && strpos($cron, 'ab_recent_clockout_sql') !== false);
check('cron does not install itself', strpos($cron, 'crontab') === false);

$backfill = file_get_contents($root . '/auto_break_backfill.php');
check('backfill opens db.php when env config is absent', strpos($backfill, 'ab_open_pdo') !== false && strpos($backfill, "require_once __DIR__ . '/engine/payroll_db_config.php'") === false);
$lib = file_get_contents($root . '/engine/auto_break_lib.php');
$open = substr($lib, (int)strpos($lib, 'function ab_open_pdo'));
$open = substr($open, 0, (int)strpos($open, "\nfunction "));
check('db.php fallback avoids CLI session cookies', strpos($open, 'db.php') !== false && strpos($open, 'session.use_cookies') !== false && strpos($open, 'payroll.env') !== false);

$live = file_get_contents($root . '/ops/pr8_live/staff_audit.php');
check('live staff audit keeps the hard delete', strpos($live, 'DELETE FROM attendance') !== false && strpos($live, 'att_soft_delete') === false && strpos($live, 'attendance_integrity_lib') === false);
check('live staff audit has only the auto-break hooks', strpos($live, 'ab_copy_breaks') !== false && strpos($live, 'ab_apply_to_shift') !== false && strpos($live, 'ab_save_editor_breaks') !== false && strpos($live, 'break_source') !== false && strpos($live, 'AB_LABEL') !== false);

$editorFn = substr($lib, (int)strpos($lib, 'function ab_save_editor_breaks'));
$editorFn = substr($editorFn, 0, (int)strpos($editorFn, "\nfunction "));
check('the editor save does not put an auto break back', strpos($editorFn, 'ab_apply_to_shift') === false && strpos($editorFn, 'ab_window_for_shift') === false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
