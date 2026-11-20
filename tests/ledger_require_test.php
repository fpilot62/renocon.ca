<?php
/**
 * The pay ledger must load without the attendance-audit bundle.
 * Run: php tests/ledger_require_test.php
 */
$fail = 0;
function check(string $label, bool $cond): void {
    global $fail;
    if ($cond) { echo "ok  $label\n"; return; }
    echo "FAIL $label\n";
    $fail++;
}

$files = [
    __DIR__ . '/../payroll_master_ledger.php',
    __DIR__ . '/../payroll_master_ledger1.php',
];
foreach ($files as $path) {
    $name = basename($path);
    $src = file_get_contents($path);
    check("$name does not hard-require the audit library", !preg_match('/^\s*require(?:_once)?\s+.+attendance_audit_lib\.php/m', $src));
    check("$name loads the audit library only when the file exists", strpos($src, 'is_file($ledgerAuditLib)') !== false);
    check("$name calls the audit log only when that function exists", strpos($src, "function_exists('aa_snapshot')") !== false && strpos($src, "function_exists('aa_log')") !== false);
    check("$name SQL does not touch the audit table or created_by", !preg_match('/\battendance_audit\b|\bcreated_by\b|\bupdated_by\b/', $src));
    if (preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*\'([^\']+)\'/', $src, $m)) {
        foreach ($m[1] as $rel) {
            if (strpos($rel, 'attendance_audit_lib.php') !== false) {
                continue;
            }
            check("$name hard-requires a file that is in the repo ($rel)", is_file(dirname($path) . $rel));
        }
    }
}

if ($fail > 0) { echo $fail . " failed\n"; exit(1); }
echo "all passed\n";
