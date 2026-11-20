<?php
/**
 * Path: /roe_help.php
 * How to file an ROE. Payroll admins only, same gate as roe.php.
 * The checklist reads the same users columns as engine/roe_lib.php and never prints a SIN.
 */
require_once __DIR__ . '/engine/roe_boot.php';
require_once __DIR__ . '/engine/help_qa_lib.php';
try {
    help_qa_ensure_link($pdo, 'How to file an ROE', 'roe_help.php', 25);
} catch (Throwable $e) {
}

$users = ps_list_users($pdo, $orgId);
$picked = (int)($_GET['user_id'] ?? 0);
$check = null;
if ($picked > 0) {
    $check = roe_help_scan($pdo, $orgId, $picked);
}
$reasons = roe_reason_codes();

$links = [
    ['ROE Web sign-in (GCKey or your bank)', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe/access-roe.html'],
    ['How to complete the record of employment form', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/reports/roe-guide.html'],
    ['Reason codes: Block 16 of that guide, and Table 16.1 in Appendix D', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe/user-requirements/appendix-d.html'],
    ['Payroll extract file specification (XML version 2.0, Appendix D)', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe/user-requirements/appendix-d.html'],
    ['Field rules, including expected recall (Appendix A)', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe/user-requirements/appendix-a.html'],
    ['Register for ROE Web', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/ei-roe/register-roe.html'],
    ['ROE Web registration and representatives', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/reports/roe-web-admin-guide.html'],
    ['ROE Web user guide (upload, drafts, submit)', 'https://www.canada.ca/en/employment-social-development/programs/ei/ei-list/reports/completing-single-roe.html'],
];

roe_page_head('How to file an ROE');
echo function_exists('generateMenu') ? generateMenu($pdo) : '';
echo privacy_banner_html();
?>
<div class="container-fluid" style="max-width:980px">
    <p><a href="roe.php?org_id=<?= (int)$orgId ?>">&larr; ROE (Record of Employment)</a></p>
    <h4 class="font-weight-bold">How to file an ROE</h4>
    <p class="text-muted">For payroll admins. This page follows the buttons on <b>ROE (Record of Employment)</b> and Service Canada's pages. It does not file the ROE for you.</p>
    <?= roe_flash_html() ?>

    <div class="sheet" id="roeEmpPicker">
        <h6 class="font-weight-bold">1. Before you start</h6>
        <p>An ROE needs a real social insurance number, the legal name, the street, the city, the province, and the postal code. The job title and the hire date are worth checking. The last day for which paid, the final pay period ending date, the insurable hours, and the insurable earnings are not columns on the employee. They are filled from finalized pay lines when you create the draft.</p>
        <p>The ROE code looks for these <b>users</b> columns, in this order, and uses the first one that exists:</p>
        <ul>
            <li>SIN: <code>sin</code>, <code>SIN</code>, <code>social_insurance_number</code>, <code>social_insurance</code>, <code>sin_number</code></li>
            <li>Legal first name: <code>legal_first_name</code>, <code>first_name</code>, <code>firstname</code>, <code>fname</code>, <code>FirstName</code></li>
            <li>Legal last name: <code>legal_last_name</code>, <code>last_name</code>, <code>lastname</code>, <code>lname</code>, <code>LastName</code></li>
            <li>Street: <code>street_address</code>, <code>address</code>, <code>address1</code></li>
            <li>City: <code>city</code></li>
            <li>Province: <code>province</code>, <code>state</code></li>
            <li>Postal code: <code>postal_code</code>, <code>zipcode</code>, <code>zip</code></li>
            <li>Job title (Block 13, optional): <code>job_title</code>, <code>position</code>, <code>designation</code>, <code>occupation</code></li>
            <li>Hire date: <code>hire_date</code>, <code>start_date</code>, <code>date_of_joining</code>, <code>joining_date</code></li>
        </ul>
        <p>Employee Hub saves the name in <code>given_name</code> and <code>surname</code>, and the address in <code>street_address</code>, <code>city</code>, <code>province</code>, and <code>postal_code</code>. The ROE code does not read <code>given_name</code> or <code>surname</code>. If those are the only name columns, the checklist marks the legal name missing. Bulk import stores a start date in <code>StartDate</code>. The ROE code does not read <code>StartDate</code>.</p>
        <form method="get" class="form-inline mb-3">
            <input type="hidden" name="org_id" value="<?= (int)$orgId ?>">
            <label class="mr-2">Employee</label>
            <select name="user_id" class="form-control form-control-sm mr-2" required>
                <option value="">— choose —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $picked ? 'selected' : '' ?>><?= roe_h($u['Name']) ?> (#<?= (int)$u['id'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-dark">Check this employee</button>
        </form>
        <?php if ($picked > 0 && $check === null): ?>
            <div class="alert alert-danger">That employee is outside this organization.</div>
        <?php elseif ($check): ?>
            <p><a href="<?= roe_h($check['edit_url']) ?>">Edit <?= roe_h($check['name']) ?> in Employee Hub</a></p>
            <table class="table table-sm table-bordered t">
                <thead><tr><th>Field</th><th>Column the ROE code uses</th><th>On this employee</th></tr></thead>
                <tbody>
                <?php foreach ($check['fields'] as $f): ?>
                    <tr>
                        <td><?= roe_h($f['label']) ?></td>
                        <td><code><?= roe_h($f['column']) ?></code></td>
                        <td><?= $f['ok'] ? '<b class="text-success">On file</b>' : '<b class="text-danger">Missing</b>' ?><?= $f['note'] !== '' ? '<div class="text-muted">' . roe_h($f['note']) . '</div>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">2. Generate the ROE in this app</h6>
        <ol>
            <li>Open <b>ROE (Record of Employment)</b>.</li>
            <li>Under <b>Legal employers (CRA payroll accounts on stores)</b>, set <b>Pay period type</b>, <b>Known period end</b> (weekly, bi-weekly, and 13-per-year need one), <b>Contact first</b>, <b>Contact last</b>, <b>Area</b>, <b>Phone</b>, and <b>Ext</b>. Press <b>Save</b>.</li>
            <li>Under <b>New ROE</b>, choose <b>Employee</b>, <b>Legal employer (BN15)</b>, and <b>Reason (block 16)</b>. <b>Last day for which paid (optional)</b> can stay blank. Blank uses the last paid work day.</li>
            <li>Press <b>Create draft from pay lines</b>. Blocks 10, 11, 12, 15A, and 15C are filled from finalized pay lines at that employer's stores.</li>
            <li>On the draft, set <b>Block 14 expected recall</b>. Press <b>Save draft</b>. When the red list is empty, press <b>Mark ready to download</b>.</li>
            <li>On the list, tick the ready rows. Leave <b>Issue = D: ROE Web saves as draft (review there)</b>. Press <b>Download selected ready ROEs (.BLK)</b>. One business number downloads as one .BLK file. Several download as a zip with one .BLK per business number. <b>Issue = S: ROE Web submits on upload</b> tells ROE Web to submit when the file is uploaded. Nothing is sent from this app.</li>
        </ol>
        <p class="mb-1"><b>Reason (block 16).</b> The dropdown is the separation codes in Appendix D, Table 16.1. The guide explains the letter in plain words. Code C (return to school) is being phased out on the guide. This dropdown does not have C. Use E03 for return to school, or J00 for apprentice training.</p>
        <ul>
            <?php foreach ($reasons as $code => $label): ?>
            <li><b><?= roe_h($code) ?></b> — <?= roe_h($label) ?>. <?= roe_h(roe_help_reason_plain($code)) ?></li>
            <?php endforeach; ?>
        </ul>
        <p>K00 and K15 also need <b>Block 18 comments</b> on the draft. The box says it holds 160 characters and must not repeat something already on the ROE.</p>
        <p class="mb-1"><b>Block 14 expected recall.</b> Appendix D allows three codes. The draft dropdown is the same three. There is no seasonal recall code in that table or on the paper Block 14 instructions (date, Unknown, or Not returning).</p>
        <ul>
            <li><b>U – Unknown</b>. This is the default on a new draft, and Appendix D says U is the default online. Leave <b>Recall date (only for Y)</b> blank.</li>
            <li><b>Y – Expected date of recall</b>. Fill <b>Recall date (only for Y)</b>. The date has to be after <b>Block 11 last day for which paid</b>. Y is not allowed when the reason starts with E (quit), G (mandatory retirement), or M (dismissal).</li>
            <li><b>N – Not returning</b>. Leave the recall date blank.</li>
        </ul>
        <p class="mb-0">The download is XML version 2.0, FileVersion W-2.0, saved as a .BLK file. The app checks it against the ROE Web schema before the download starts.</p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">3. ROE Web: register, upload, review, submit</h6>
        <p>Sign in from the ROE Web access page with a <b>GCKey</b> or a <b>Sign-In Partner</b> (your bank). Create an ROE Web profile if you do not have one. You get a user reference number.</p>
        <p>The <b>Primary Officer</b> registers the organization: the organization profile, the CRA business number, and an identity check (CRA My Account, or in person at a Service Canada Centre with identification and the user reference number). Service Canada mails an authorization code to the chief executive officer or business owner. Enter that code, then accept the agreement. Until that is done, the organization is not ready to issue ROEs.</p>
        <p>To file from this app: sign in, and on the left under <b>I want to…</b> choose <b>Upload a Payroll Extract file</b>. Upload the .BLK. Then open <b>View Payroll Extract files</b>.</p>
        <p><b>An uploaded draft is not issued.</b> The ROE Web user guide says no serial number is assigned until the ROE is submitted to Service Canada and passes the edits. On the draft you can <b>Edit</b> or, when there is no error, <b>Verify</b> and then submit. The confirmation page shows the serial number only after a successful submit. The same page says <b>draft ROEs will be deleted after 15 days if not issued</b>. <b>Save as Draft</b> keeps it for those 15 days and does not issue it.</p>
        <p class="mb-0">After ROE Web shows a serial number, come back to the draft that you marked ready and use <b>Record as issued</b>. The box asks for a letter and 8 digits.</p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">4. An accountant filing for a client</h6>
        <p>The accountant uses their own ROE Web profile. They do not use the client's GCKey. On the organization profile, a firm that files for clients marks itself as a <b>Service Provider</b>.</p>
        <p class="mb-0">The client's <b>Primary Officer</b> invites the accountant. The accountant needs a user reference number from their own profile (it is on the welcome page). The Primary Officer searches that number on <b>Invite Representative</b>, confirms the person, and sets the rights. Access to a business number is <b>None</b>, <b>Read</b>, or <b>Read and write</b>. Filing needs read and write on that payroll account (the RP). The Primary Officer can also be the one who grants that access. A representative cannot change their own rights.</p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">5. Deadlines</h6>
        <p>These rules are from Service Canada's guide, <i>How to complete the record of employment form</i>.</p>
        <p><b>Electronic ROE, weekly, biweekly, or semi-monthly.</b> Issue it within 5 calendar days after the end of the pay period in which the interruption of earnings happens.</p>
        <p><b>Electronic ROE, monthly, or 13 pay periods a year.</b> Issue it by the earlier of two dates: 5 calendar days after the end of the pay period in which the interruption happens, or 15 calendar days after the first day of the interruption.</p>
        <p><b>Paper ROE.</b> Issue it within 5 calendar days of the first day of the interruption, or of the day the employer becomes aware of the interruption. Give Part 1 to the employee. They submit that copy if they apply for EI. Send Part 2 to Service Canada's ROE centre, P.O. Box 9000, Bathurst NB E2A 4T3. Keep Part 3 for 6 years. If you still have all three copies, you can correct a paper ROE by striking out the wrong value, writing the right one, and initialling it. Do not use white-out. If you have already handed out the copies, you cannot correct that form. Issue an amended ROE instead.</p>
        <p><b>Amended ROE.</b> Issue one when you must change, correct, or update an ROE you already issued; when a departure that was not final becomes final and you still owe money (the guide's example is vacation pay); when you issued an ROE in error (enter "Previous ROE issued in error" in Block 18); or when Service Canada asks. Fill in every block on the amended ROE, not only the blocks that changed. You cannot cancel an ROE that was already issued. If the original was paper and you are amending it electronically, enter "Amending a paper ROE" in Block 18 and include the paper serial number.</p>
        <p class="mb-0"><b>Block 2, Serial number of ROE amended or replaced.</b> The guide uses that title. Its instructions say to put the original serial number there when you issue an amended ROE. It does not describe a second, different replacement form. In this app, <b>Start an amendment</b> on an issued ROE puts that serial in block 2 on the new draft.</p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">6. Troubleshooting</h6>
        <p><b>Social Insurance Number is not valid.</b> On 20 November 2026 a test upload of Renocon's payroll extract was processed by ROE Web. The only error was the test (dummy) SIN. That is the evidence the file format itself was accepted. Put the employee's real 9-digit SIN on the employee file and create the draft again. This app blocks an empty SIN, a SIN that is not 9 digits, and 000000000. It warns when the number fails the check digit. The full SIN is not shown on the ROE screen.</p>
        <p><b>Business number or payroll account (RP) rejected.</b> Block 5 is the store's CRA payroll account, <code>sites.payroll_account</code>. This app requires 9 digits, then RP, then 4 digits, starting with 1, 7, or 8. The last four digits cannot be 0000. The ROE Web account has to be registered for that same payroll account, and the person uploading needs read and write on that business number. A wrong business number on the organization profile can lead to a call from Service Canada rather than an issued ROE.</p>
        <p><b>File format.</b> The download is XML version 2.0. If the schema check fails, this app does not download the file and lists the errors on the ROE screen. Fix those on the draft and download again. Do not rename the .BLK to another type before upload.</p>
        <p><b>Duplicates.</b> Appendix A says the first day worked or the last day for which paid must not overlap an earlier web ROE for the same employer payroll account and the same SIN. This app reports that as "Blocks 10/11 overlap" for the same employee and business number, and it will not mark the draft ready. Void the extra draft, or shorten the dates so they do not overlap, then download again.</p>
        <p class="mb-0"><b>Failed records on View Payroll Extract files.</b> Open <b>View Payroll Extract files</b>. A record that failed the edits is not issued and has no serial number. Use <b>Edit</b> (or fix the employee and the draft in this app and upload a new file). <b>Search Draft ROEs</b> finds a draft by SIN or by the date it was saved. <b>Verify</b> is available only when the draft has no error. Submit after that. If 15 days pass without a submit, ROE Web deletes the draft and you upload again.</p>
    </div>

    <div class="sheet">
        <h6 class="font-weight-bold">7. Official pages</h6>
        <p>Checked with <code>curl -sI</code> on 20 November 2026. Each address below answered HTTP 200 on that URL. These addresses redirected to the Canada.ca 404 page and are not linked: the old payroll-extract guide, <code>ei-roe/codes.html</code>, and <code>ei-roe.html</code>. The reason-code meanings are Block 16 of the guide. The electronic codes and the file specification are Appendix D.</p>
        <ul class="mb-0">
            <?php foreach ($links as [$label, $url]): ?>
            <li><a href="<?= roe_h($url) ?>"><?= roe_h($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
</body></html>
<?php

function roe_help_reason_plain(string $code): string
{
    $plain = [
        'A00' => 'Use this for a layoff, the end of a contract or a season, or a shutdown.',
        'A01' => 'The guide lists employer bankruptcy or receivership under shortage of work. This is the electronic code for that case.',
        'B00' => 'The employee is on strike or locked out.',
        'D00' => 'The employee is off for illness or injury. If illness comes before maternity, the guide says to use illness, because you use the code that applies first.',
        'E00' => 'The employee chose to leave. The guide says to add the kind of quit in Block 18 on paper. Online, pick the matching subcode instead.',
        'E02' => 'Quit to follow a spouse.',
        'E03' => 'Quit to return to school. This replaces paper code C.',
        'E04' => 'Quit for health reasons.',
        'E05' => 'Voluntary retirement. Mandatory retirement is G00.',
        'E06' => 'Quit to take another job.',
        'E09' => 'Quit because the employer relocated.',
        'E10' => 'Quit to care for a dependant.',
        'E11' => 'Quit to become self-employed.',
        'F00' => 'Maternity leave for a person who is pregnant or has recently given birth. Adoptive parents and other parents use P00.',
        'G00' => 'Mandatory retirement.',
        'G07' => 'Retirement under a workforce reduction approved by Service Canada.',
        'H00' => 'The Service Canada Work-Sharing program.',
        'J00' => 'Leaving for a government-approved apprenticeship.',
        'K00' => 'None of the other codes fit. Block 18 is required. The guide says to use K only in exceptional cases, for example the death of an employee.',
        'K12' => 'Other, because the pay period type changed.',
        'K13' => 'Other, because ownership changed.',
        'K14' => 'Other, because Employment Insurance asked for the ROE.',
        'K15' => 'Other, Canadian Forces. Block 18 is required.',
        'K16' => 'Other, at the employee\'s request.',
        'K17' => 'Other, because the payroll service provider changed.',
        'M00' => 'The employer ended the employment, including a suspension. This is not a layoff and not mandatory retirement.',
        'M08' => 'Dismissed inside the probationary period.',
        'N00' => 'A leave of absence. Do not use this for illness, maternity, parental leave, or compassionate care.',
        'P00' => 'Parental or adoption leave.',
        'Z00' => 'Compassionate care or family caregiver leave.',
    ];
    return $plain[$code] ?? '';
}

function roe_help_scan(PDO $pdo, int $orgId, int $userId): ?array
{
    $who = $pdo->prepare('SELECT id, Name FROM users WHERE id = ? AND org_id = ?');
    $who->execute([$userId, $orgId]);
    $person = $who->fetch(PDO::FETCH_ASSOC);
    if (!$person) {
        return null;
    }
    $cols = roe_table_columns($pdo, 'users');
    $map = [
        'first' => roe_pick_col($cols, ['legal_first_name', 'first_name', 'firstname', 'fname', 'FirstName']),
        'last' => roe_pick_col($cols, ['legal_last_name', 'last_name', 'lastname', 'lname', 'LastName']),
        'street' => roe_pick_col($cols, ['street_address', 'address', 'address1']),
        'city' => roe_pick_col($cols, ['city']),
        'province' => roe_pick_col($cols, ['province', 'state']),
        'postal' => roe_pick_col($cols, ['postal_code', 'zipcode', 'zip']),
        'occupation' => roe_pick_col($cols, ['job_title', 'position', 'designation', 'occupation']),
        'hire' => roe_pick_col($cols, ['hire_date', 'start_date', 'date_of_joining', 'joining_date']),
        'sin' => roe_pick_col($cols, ['sin', 'SIN', 'social_insurance_number', 'social_insurance', 'sin_number']),
    ];
    $select = ['id'];
    foreach ($map as $key => $col) {
        if ($col !== null) {
            $select[] = $col . ' AS f_' . $key;
        }
    }
    foreach (['given_name', 'surname', 'StartDate'] as $extra) {
        if (isset($cols[$extra])) {
            $select[] = $extra;
        }
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? AND org_id = ?');
    $st->execute([$userId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $blank = static function ($v): bool {
        return trim((string)$v) === '';
    };
    $fields = [];
    $sinNote = '';
    $sinOk = false;
    if ($map['sin'] === null) {
        $sinNote = 'The users table has none of the SIN columns this ROE code looks for.';
    } else {
        $stored = (string)($row['f_sin'] ?? '');
        if ($stored !== '' && function_exists('privacy_open_sensitive')) {
            $stored = privacy_open_sensitive($stored);
        }
        $digits = roe_normalize_sin($stored);
        if ($digits === '') {
            $sinNote = 'No SIN is stored.';
        } elseif (strlen($digits) !== 9 || $digits === '000000000') {
            $sinNote = 'The stored SIN is not 9 digits, or it is all zeros. The digits are not shown here.';
        } elseif (!roe_luhn_ok($digits)) {
            $sinNote = 'The stored SIN fails the check-digit test. Confirm it with the employee. The digits are not shown here.';
        } else {
            $sinOk = true;
            $sinNote = 'A 9-digit SIN is stored and passes the check digit. The digits are not shown here.';
        }
    }
    $fields[] = ['label' => 'Real SIN', 'column' => $map['sin'] ?? '(no SIN column)', 'ok' => $sinOk, 'note' => $sinNote];

    $firstOk = $map['first'] !== null && !$blank($row['f_first'] ?? '');
    $firstNote = $map['first'] === null ? 'None of the legal-first-name columns exist.' : '';
    if (!$firstOk && !$blank($row['given_name'] ?? '')) {
        $firstNote = 'given_name has a value, and the ROE code does not read given_name. Add the legal first name to ' . ($map['first'] ?? 'a first-name column the ROE code reads') . '.';
    }
    $fields[] = ['label' => 'Legal first name', 'column' => $map['first'] ?? '(no first-name column)', 'ok' => $firstOk, 'note' => $firstNote];

    $lastOk = $map['last'] !== null && !$blank($row['f_last'] ?? '');
    $lastNote = $map['last'] === null ? 'None of the legal-last-name columns exist.' : '';
    if (!$lastOk && !$blank($row['surname'] ?? '')) {
        $lastNote = 'surname has a value, and the ROE code does not read surname.';
    }
    $fields[] = ['label' => 'Legal last name', 'column' => $map['last'] ?? '(no last-name column)', 'ok' => $lastOk, 'note' => $lastNote];

    foreach ([
        'street' => 'Street',
        'city' => 'City',
        'province' => 'Province',
        'postal' => 'Postal code',
    ] as $key => $label) {
        $col = $map[$key];
        $ok = $col !== null && !$blank($row['f_' . $key] ?? '');
        if ($key === 'postal' && $ok) {
            $pc = roe_normalize_postal((string)$row['f_postal']);
            $ok = (bool)preg_match('/^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/', $pc);
            $note = $ok ? '' : 'The postal code is not in the form A1A1A1.';
        } else {
            $note = $col === null ? 'That column is not on users.' : '';
        }
        $fields[] = ['label' => $label, 'column' => $col ?? '(column not on users)', 'ok' => $ok, 'note' => $note];
    }

    $jobOk = $map['occupation'] !== null && !$blank($row['f_occupation'] ?? '');
    $fields[] = [
        'label' => 'Job title',
        'column' => $map['occupation'] ?? '(no job-title column)',
        'ok' => $jobOk,
        'note' => 'Block 13 is optional. A draft can still be marked ready without it.',
    ];

    $hireOk = $map['hire'] !== null && roe_valid_date(substr((string)($row['f_hire'] ?? ''), 0, 10)) !== null;
    $hireNote = 'Block 10 uses the first finalized pay line, not this date. The draft warns when the two differ.';
    if ($map['hire'] === null && !$blank($row['StartDate'] ?? '')) {
        $hireNote = 'StartDate has a value, and the ROE code does not read StartDate. ' . $hireNote;
    }
    $fields[] = ['label' => 'Hire date', 'column' => $map['hire'] ?? '(no hire-date column)', 'ok' => $hireOk, 'note' => $hireNote];

    $fcols = roe_table_columns($pdo, 'payroll_finalized');
    $hours = [];
    foreach (['reg_hours', 'overtime_hours', 'travel_hours'] as $c) {
        if (isset($fcols[$c])) {
            $hours[] = 'COALESCE(f.' . $c . ', 0)';
        }
    }
    $hoursSql = $hours ? implode(' + ', $hours) : '0';
    $sql = 'SELECT COUNT(*) AS n, MAX(f.work_date) AS last_work, MIN(f.work_date) AS first_work, COALESCE(SUM(f.total_gross), 0) AS gross, COALESCE(SUM(' . $hoursSql . '), 0) AS hours
            FROM payroll_finalized f
            INNER JOIN users u ON u.id = f.user_id AND u.org_id = ?
            WHERE f.user_id = ?';
    if (function_exists('att_finalized_live_predicate')) {
        $sql .= ' AND ' . att_finalized_live_predicate($pdo, 'f');
    }
    try {
        $pay = $pdo->prepare($sql);
        $pay->execute([$orgId, $userId]);
        $payRow = $pay->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0];
    } catch (Throwable $e) {
        $payRow = ['n' => 0, 'first_work' => '', 'last_work' => '', 'gross' => 0, 'hours' => 0];
    }
    $hasPay = (int)$payRow['n'] > 0;
    $payNote = $hasPay
        ? 'Finalized lines on file: ' . (int)$payRow['n'] . '. First work date ' . substr((string)$payRow['first_work'], 0, 10) . ', last work date ' . substr((string)$payRow['last_work'], 0, 10) . '. Hours column total ' . $payRow['hours'] . '. total_gross sum ' . $payRow['gross'] . '. Create draft from pay lines turns these into Block 11, Block 12, Block 15A, and Block 15C. They are not stored on the employee.'
        : 'No finalized pay lines for this employee in this organization. Create draft from pay lines cannot fill Block 11 (last day for which paid), Block 12 (final pay period ending), Block 15A (insurable hours), or Block 15C (insurable earnings).';
    $fields[] = [
        'label' => 'Last day worked, final pay period, insurable hours and earnings',
        'column' => 'payroll_finalized.work_date, total_gross, reg_hours, overtime_hours, travel_hours',
        'ok' => $hasPay,
        'note' => $payNote,
    ];

    $edit = 'employee_hub.php?search=' . rawurlencode((string)$person['Name']);
    if (function_exists('ps_is_superadmin') && ps_is_superadmin()) {
        $edit .= '&org_filter=' . (int)$orgId;
    }
    return ['name' => (string)$person['Name'], 'edit_url' => $edit, 'fields' => $fields];
}
