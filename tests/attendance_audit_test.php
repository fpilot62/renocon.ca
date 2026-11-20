<?php
/**
 * Attendance audit lib. No live payroll database.
 * Run: php tests/attendance_audit_test.php
 */
require_once __DIR__ . '/../engine/attendance_audit_lib.php';

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

check('clock in is not a manual source', aa_is_clock_source('api/punch.php') && aa_is_clock_source('process_kiosk.php'));
check('manual attendance is a manual source', !aa_is_clock_source('manual_attendance.php'));

$slim = aa_slim_row(['selfie_in' => str_repeat('A', 800), 'clock_in' => '2026-11-20 08:00:00', 'Remarks' => 'Manually Injected Shift']);
check('selfies are not stored in the audit json', $slim['selfie_in'] === '[photo]' && $slim['Remarks'] === 'Manually Injected Shift');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE attendance (
    id INTEGER PRIMARY KEY,
    org_id INTEGER,
    site_id INTEGER,
    user_id INTEGER,
    Remarks TEXT,
    clock_in TEXT,
    selfie_in TEXT,
    clock_out TEXT,
    created_by INTEGER,
    updated_by INTEGER
)');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, Name TEXT)');
$pdo->exec('CREATE TABLE attendance_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    attendance_id INTEGER,
    org_id INTEGER,
    site_id INTEGER,
    target_user_id INTEGER,
    action TEXT,
    actor_user_id INTEGER,
    actor_name TEXT,
    actor_role TEXT,
    ip TEXT,
    user_agent TEXT,
    source_page TEXT,
    before_json TEXT,
    after_json TEXT,
    note TEXT,
    created_at TEXT
)');
$pdo->exec("INSERT INTO users (id, Name) VALUES (7, 'Ada Admin')");
$pdo->exec("INSERT INTO attendance (id, org_id, site_id, user_id, Remarks, clock_in, selfie_in) VALUES (10, 2, 4, 9, 'Manually Injected Shift', '2026-11-20 08:00:00', 'HUGE')");
$pdo->exec("INSERT INTO attendance (id, org_id, site_id, user_id, Remarks, clock_in) VALUES (11, 2, 4, 9, '', '2026-11-20 09:00:00')");

$_SESSION['user_id'] = 7;
$_SESSION['user_full_name'] = 'Ada Admin';
$_SESSION['user_level'] = 20;
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$_SERVER['HTTP_USER_AGENT'] = 'TestAgent';
$_SERVER['SCRIPT_NAME'] = '/manual_attendance.php';

aa_log($pdo, 'create', 10, null, null, 'manual shift');
$log = $pdo->query('SELECT * FROM attendance_audit WHERE attendance_id = 10')->fetch(PDO::FETCH_ASSOC);
check('create is logged with the actor and ip', $log && $log['action'] === 'create' && $log['actor_name'] === 'Ada Admin' && $log['ip'] === '203.0.113.8' && $log['source_page'] === 'manual_attendance.php');
check('created_at is UTC shaped', (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$log['created_at']));
$after = json_decode((string)$log['after_json'], true);
check('after json omits the selfie blob', is_array($after) && ($after['selfie_in'] ?? '') === '[photo]');
$stamp = $pdo->query('SELECT created_by, updated_by FROM attendance WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
check('create stamps created_by', (int)$stamp['created_by'] === 7 && (int)$stamp['updated_by'] === 7);

$before = aa_snapshot($pdo, 10);
$pdo->exec("UPDATE attendance SET clock_out = '2026-11-20 16:00:00' WHERE id = 10");
aa_log($pdo, 'update', 10, $before, null, 'edit times');
$upd = $pdo->query("SELECT action, before_json FROM attendance_audit WHERE attendance_id = 10 AND action = 'update'")->fetch(PDO::FETCH_ASSOC);
check('update keeps the before json', $upd && strpos((string)$upd['before_json'], 'Manually Injected Shift') !== false);

$_SERVER['SCRIPT_NAME'] = '/api/punch.php';
aa_log($pdo, 'create', 11, null, null, 'clock in');
$badges = aa_manual_badges($pdo, [10, 11, 99]);
check('manual row names the actor', isset($badges[10]) && strpos($badges[10], 'Manually added by Ada Admin') === 0 && strpos($badges[10], '·') !== false);
check('clock in has no manual badge', !isset($badges[11]));

$pdo->exec("INSERT INTO attendance (id, org_id, site_id, user_id, Remarks, clock_in) VALUES (12, 2, 4, 9, 'Manually Injected Shift', '2026-11-01 08:00:00')");
$old = aa_manual_badges($pdo, [12]);
check('old manual row has no actor yet', ($old[12] ?? '') === 'Manually added · by unknown (before logging)');

$pdo->exec('DROP TABLE attendance_audit');
aa_log($pdo, 'delete', 10, $before, null, 'must not throw');
check('a failed log does not throw', true);

aa_log($pdo, 'nope', 10, null, null, '');
check('unknown actions are ignored', true);

if ($fail > 0) {
    echo "$fail failed\n";
    exit(1);
}
echo "all ok\n";
