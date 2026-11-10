<?php
/**
 * Org assignment and soft-delete rules. No database and no live payroll data.
 * Run: php tests/attendance_integrity_test.php
 */
require_once __DIR__ . '/../engine/attendance_integrity_lib.php';
require_once __DIR__ . '/../engine/payroll_db_config.php';

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

$site = ['SId' => 12, 'org_id' => 2];
$employee = ['id' => 9, 'org_id' => 2, 'primary_site_id' => 12];
$saved = att_manual_shift_decision($site, $employee, 5, 6, 2);
check('org comes from the site', $saved['ok'] && $saved['org_id'] === 2);

$otherEmployee = ['id' => 9, 'org_id' => 6, 'primary_site_id' => 4];
$mismatch = att_manual_shift_decision($site, $otherEmployee, 5, 6, 2);
check('employee org must match the site', !$mismatch['ok'] && $mismatch['org_id'] === 0);

$outsider = att_manual_shift_decision($site, $employee, 15, 6, 6);
check('admin cannot save another org', !$outsider['ok']);

$home = att_manual_shift_decision($site, $employee, 15, 2, 2);
check('admin can save their own org', $home['ok'] && $home['org_id'] === 2);

$superOther = att_manual_shift_decision($site, $employee, 5, 6, 0);
check('superadmin can save the site org', $superOther['ok'] && $superOther['org_id'] === 2);

$superScoped = att_manual_shift_decision($site, $employee, 5, 6, 6);
check('selected org still limits a superadmin', !$superScoped['ok']);

$notAdmin = att_manual_shift_decision($site, $employee, 25, 2, 2);
check('non-admin cannot save', !$notAdmin['ok']);

check('login is required', payroll_admin_denial(null, 5) === 'login' && payroll_admin_denial('', 5) === 'login');
check('level above 20 is not an admin', payroll_admin_denial(4, 21) === 'admin' && payroll_admin_denial(4, 20) === null);

$del = att_soft_delete_sql(2, true);
check('delete is an update', strpos($del, 'UPDATE attendance') !== false && strpos($del, 'DELETE FROM attendance') === false);
check('delete records who and when', strpos($del, 'deleted_at') !== false && strpos($del, 'deleted_by') !== false);
check('delete can match the employee or site org', strpos($del, 'u.org_id = ?') !== false && strpos($del, 's.SId') !== false);

$warn = att_manual_mismatch_count_sql(true);
check('warning looks for manual shifts under another org', strpos($warn, att_manual_remark()) === false && strpos($warn, 'a.remarks = ?') !== false && strpos($warn, 'a.org_id <> ?') !== false && strpos($warn, 'u.org_id = ?') !== false && strpos($warn, 's.org_id = ?') !== false);

check('posted ids accept lists', att_posted_ids(['shift_ids' => ['4', '0', '4', '9']]) === [4, 9]);
check('selfie filename is not inlined', att_selfie_data_uri('nophoto.jpg') === '');
check('selfie data uri is kept', att_selfie_data_uri('data:image/jpeg;base64,aaaa') === 'data:image/jpeg;base64,aaaa');

check('live sql hides deleted attendance', att_live_predicate_sql('a') === 'a.deleted_at IS NULL');
$fin = att_finalized_live_predicate_sql('f');
check('payroll sql keeps rows with no attendance', strpos($fin, 'IFNULL(f.attendance_id, 0) = 0') !== false && strpos($fin, 'att_soft.deleted_at IS NOT NULL') !== false);
$threw = false;
try {
    att_live_predicate_sql('a;drop');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('sql alias rejects punctuation', $threw);

$pdo = new PDO('sqlite::memory:');
check('missing deleted_at column does not change the query yet', att_live_predicate($pdo, 'a') === '1=1');
check('missing column leaves finalized rows visible', att_finalized_live_predicate($pdo, 'f') === '1=1');

check('undo refuses a paid finalized row in sql', strpos(att_undo_paid_sql(), 'paid_amount') !== false && strpos(att_undo_finalized_sql(), 'paid_amount') !== false);
check('undo leaves a soft-deleted shift alone', strpos(att_undo_shift_sql(), 'deleted_at IS NULL') !== false);

$parsed = payroll_parse_env("# comment\nDB_HOST=localhost\nDB_PASS=\"rotated-value\"\nNOT A PAIR\n");
check('env parser keeps the password', ($parsed['DB_PASS'] ?? '') === 'rotated-value' && ($parsed['DB_HOST'] ?? '') === 'localhost');
check('env parser skips comments', !array_key_exists('comment', $parsed));

$example = file_get_contents(__DIR__ . '/../config/payroll.env.example');
check('example password is a placeholder', strpos($example, PAYROLL_DB_PASS_PLACEHOLDER) !== false);
$refused = false;
try {
    payroll_db_config(__DIR__ . '/../config/payroll.env.example');
} catch (RuntimeException $e) {
    $refused = true;
}
check('example file is not a live config', $refused);

$tmp = tempnam(sys_get_temp_dir(), 'payenv');
file_put_contents($tmp, "DB_HOST=127.0.0.1\nDB_NAME=admin_payroll\nDB_USER=payroll\nDB_PASS=" . PAYROLL_DB_PASS_PLACEHOLDER . "\n");
$placeholderRefused = false;
try {
    payroll_db_config($tmp);
} catch (RuntimeException $e) {
    $placeholderRefused = true;
}
check('placeholder password is refused', $placeholderRefused);
file_put_contents($tmp, "DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME=admin_payroll\nDB_USER=payroll\nDB_PASS=rotated-value\n");
$cfg = payroll_db_config($tmp);
check('config reads the rotated password', ($cfg['DB_PASS'] ?? '') === 'rotated-value' && ($cfg['DB_NAME'] ?? '') === 'admin_payroll');
unlink($tmp);

$root = dirname(__DIR__);
$manual = file_get_contents($root . '/manual_attendance.php');
check('manual attendance does not default org to 1', strpos($manual, "\$_SESSION['org_id'] ?? 1") === false);
check('manual attendance asks the site for org', strpos($manual, 'att_manual_shift_decision') !== false && strpos($manual, 'SELECT SId, org_id FROM sites') !== false);
check('manual dropdowns are prepared', strpos($manual, 'users WHERE org_id = ?') !== false && strpos($manual, 'sites WHERE org_id = ?') !== false);
check('manual defaults the site from the employee', strpos($manual, 'data-primary-site') !== false && strpos($manual, 'applyEmployeePrimarySite') !== false);
check('manual page requires an admin', strpos($manual, 'payroll_require_admin') !== false);

$audit = file_get_contents($root . '/staff_audit.php');
check('staff audit soft-deletes', strpos($audit, 'att_soft_delete_sql') !== false);
check('staff audit does not hard-delete attendance', strpos($audit, 'DELETE FROM attendance') === false);
check('staff audit warns on mismatched manual shifts', strpos($audit, 'att_manual_mismatch_count_sql') !== false && strpos($audit, 'org-mismatch-warn') !== false);
check('staff audit hides deleted rows', strpos($audit, 'a.deleted_at IS NULL') !== false);

foreach (['undo_finalization.php', 'get_staff_audit.php', 'delete_attendance.php'] as $file) {
    $src = file_get_contents($root . '/' . $file);
    check($file . ' requires admin login', strpos($src, 'payroll_require_admin') !== false && strpos($src, 'payroll_require_scope_admin') !== false);
}
$delete = file_get_contents($root . '/delete_attendance.php');
check('delete endpoint soft-deletes', strpos($delete, 'att_soft_delete_sql') !== false && strpos($delete, 'No IDs provided') !== false);
check('delete endpoint does not hard-delete', strpos($delete, 'DELETE FROM attendance') === false);
$undo = file_get_contents($root . '/undo_finalization.php');
check('undo runs only after a posted confirm', strpos($undo, "REQUEST_METHOD") !== false && strpos($undo, 'att_undo_finalized_shifts') !== false && strpos($undo, "confirm") !== false);
$feed = file_get_contents($root . '/get_staff_audit.php');
check('audit feed hides deleted rows', strpos($feed, 'att_live_predicate') !== false);

$sync = file_get_contents($root . '/daily_sync.php');
check('daily sync loads the env file', strpos($sync, 'payroll_db_config') !== false && strpos($sync, 'config/payroll.env') !== false);
check('daily sync has no inline password', !preg_match('/\$(db_)?pass(word)?\s*=\s*[\'"][^\'"]+[\'"]/i', $sync));

foreach (['payroll_master_ledger.php', 'payroll_payment_status.php', 'generate_pay_documents.php', 'engine/payroll_cheque_lib.php', 'engine/payroll_payment_status_lib.php', 'engine/payroll_sheet_review_lib.php'] as $file) {
    $src = file_get_contents($root . '/' . $file);
    check($file . ' ignores soft-deleted attendance', strpos($src, 'att_finalized_live_predicate') !== false || strpos($src, 'att_live_predicate') !== false);
}

$migration = file_get_contents($root . '/migrations/20261110_attendance_soft_delete.sql');
check('migration adds deleted_at and deleted_by', strpos($migration, 'deleted_at') !== false && strpos($migration, 'deleted_by') !== false && strpos($migration, 'information_schema.COLUMNS') !== false);

if ($fail > 0) {
    echo $fail . " failed\n";
    exit(1);
}
echo "all passed\n";
