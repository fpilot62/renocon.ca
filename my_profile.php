<?php
/**
 * Path: /my_profile.php
 * Employee self-serve profile — ONLY WHERE id = session user_id.
 * Editable: email, password, PIN, address, mobile.
 * SIN: masked read-only + optional request note.
 * Bank: read-only; updates via Direct Deposit form upload (PDF/JPG/PNG).
 */
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/employee_selfserve_lib.php';
require_once __DIR__ . '/engine/menu_generator.php';

ess_require_login();
require_once __DIR__ . '/contact_lib.php';
$user = ess_load_self($pdo);
if (!$user) {
    header('Location: login_employee.php');
    exit;
}

$uid = (int)$user['id'];
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_profile';

    if ($action === 'request_sin') {
        $note = trim((string)($_POST['sin_request_note'] ?? ''));
        if ($note === '') {
            $error = 'Add a short note for the SIN update request.';
        } else {
            ess_append_remark($pdo, $uid, 'SIN UPDATE REQUEST', $note);
            $msg = 'SIN update request sent to your administrator.';
            $user = ess_load_self($pdo);
        }
    } elseif ($action === 'upload_direct_deposit') {
        $result = ess_dd_store_upload($pdo, $user, $_FILES['direct_deposit_file'] ?? []);
        if (!empty($result['ok'])) {
            $msg = 'Direct deposit form uploaded — pending manager verification. Payroll bank details will not change until approved.';
            $user = ess_load_self($pdo);
        } else {
            $error = $result['error'] ?? 'Upload failed.';
        }
    } else {
        $email   = trim((string)($_POST['email'] ?? ''));
        $mobile  = trim((string)($_POST['Tel_Mobile'] ?? ''));
        $pin     = trim((string)($_POST['pin'] ?? ''));
        $street  = trim((string)($_POST['street_address'] ?? ''));
        $city    = trim((string)($_POST['city'] ?? ''));
        $prov    = strtoupper(trim((string)($_POST['province'] ?? '')));
        $postal  = strtoupper(preg_replace('/\s+/', '', (string)($_POST['postal_code'] ?? '')));
        $new_pass = (string)($_POST['new_password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        $current_email = trim((string) ($user['email'] ?? ''));
        $current_mobile = trim((string) ($user['Tel_Mobile'] ?? ''));
        $email_changed = strcasecmp($email, $current_email) !== 0;
        $mobile_changed = ess_phone_digits($mobile) !== ess_phone_digits($current_mobile);

        if ($email_changed && contact_real_email($email) === '') {
            $error = 'Enter a real email address.';
        } elseif ($pin !== '' && !preg_match('/^[0-9A-Za-z]{3,10}$/', $pin)) {
            $error = 'PIN must be 3–10 letters or digits.';
        } elseif ($new_pass !== '' && strlen($new_pass) < 4) {
            $error = 'New password must be at least 4 characters.';
        } elseif ($new_pass !== '' && $new_pass !== $confirm) {
            $error = 'Password confirmation does not match.';
        } elseif ($prov !== '' && !preg_match('/^[A-Z]{2}$/', $prov)) {
            $error = 'Province must be a 2-letter code (e.g. ON).';
        } else {
            try {
                $chk = $pdo->prepare("SELECT id FROM users WHERE org_id = ? AND email = ? AND id <> ? LIMIT 1");
                $chk->execute([(int)$user['org_id'], $email, $uid]);
                if ($email_changed && $chk->fetch()) {
                    $error = 'That email is already used by another employee in your organization.';
                } else {
                    $send_to = '';
                    $code = '';
                    if ($email_changed || $mobile_changed) {
                        $send_to = contact_send_target($email, $email_changed, $current_email);
                        if ($send_to === '') {
                            $error = 'Add a real email so we can send a confirmation code. Text messages are not set up, so we verify by email only.';
                        } else {
                            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            if (!contact_mail_code($send_to, $code)) {
                                $error = 'The confirmation email was not sent. Nothing was changed.';
                            }
                        }
                    }
                }
                if ($error === '') {
                    $store_email = $email_changed ? $current_email : $email;
                    $store_mobile = $mobile_changed
                        ? ($current_mobile !== '' ? $current_mobile : null)
                        : ($mobile !== '' ? $mobile : null);
                    $sql = "UPDATE users SET email=?, Tel_Mobile=?, pin=?, street_address=?, city=?, province=?, postal_code=?";
                    $params = [
                        $store_email,
                        $store_mobile,
                        $pin !== '' ? $pin : null,
                        $street !== '' ? $street : null,
                        $city !== '' ? $city : null,
                        $prov !== '' ? $prov : null,
                        $postal !== '' ? $postal : null,
                    ];
                    if ($new_pass !== '') {
                        $sql .= ", password=?";
                        $params[] = password_hash($new_pass, PASSWORD_DEFAULT);
                    }
                    $sql .= " WHERE id=?";
                    $params[] = $uid;
                    $pdo->prepare($sql)->execute($params);

                    $_SESSION['user_full_name'] = $user['Name'];
                    if ($code !== '') {
                        contact_store_pending(
                            $email_changed ? contact_real_email($email) : '',
                            $mobile_changed ? contact_real_phone($mobile) : '',
                            $code
                        );
                        $msg = 'Profile saved. We sent a code to your email. The current email stays active until you confirm it. Text messages are not set up, so this code is email only.';
                    } else {
                        $msg = 'Profile saved.';
                    }
                    $user = ess_load_self($pdo);
                }
            } catch (Throwable $e) {
                $error = 'Could not save profile. Please try again.';
            }
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'email_saved' && $msg === '') {
    $msg = 'Email saved. You can update the rest of your profile below.';
}

$sin_masked = ess_mask_sin($user['sin'] ?? '');
$bank_acct_masked = ess_mask_bank_account($user['bank_account'] ?? '');
$has_bank = (ess_phone_digits($user['bank_transit'] ?? '') !== ''
    || ess_phone_digits($user['bank_institution'] ?? '') !== ''
    || ess_phone_digits($user['bank_account'] ?? '') !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; margin: 0; color: #0f172a; padding-top: 90px; }
        .wrap { max-width: 720px; margin: 0 auto 40px; padding: 0 16px; }
        h1 { font-size: 1.45rem; font-weight: 800; margin: 0 0 6px; letter-spacing: -0.02em; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 18px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        .card h2 { margin: 0 0 14px; font-size: 14px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #334155; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group.full { grid-column: 1 / -1; }
        label { font-size: 12px; font-weight: 700; color: #475569; }
        input, textarea, select { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; font-family: inherit; }
        input:disabled, input[readonly] { background: #f8fafc; color: #64748b; }
        .pay-btn { display: inline-block; padding: 10px 18px; background: #0891b2; color: #fff; border: none; border-radius: 8px; font-weight: 800; font-size: 13px; cursor: pointer; text-decoration: none; }
        .pay-btn:hover { background: #0e7490; }
        .pay-btn-outline { background: #fff; color: #0891b2; border: 1px solid #0891b2; }
        .alert { padding: 10px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
        .alert-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .alert-err { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .readonly-val { font-size: 15px; font-weight: 600; color: #0f172a; padding: 8px 0; }
        .muted { color: #94a3b8; font-size: 12px; margin-top: 4px; line-height: 1.4; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?= generateMenu($pdo) ?>
<div class="wrap">
    <h1>My Profile</h1>
    <p class="sub"><?= htmlspecialchars($user['Name'] ?? '') ?> — only you can edit these fields on your own record.</p>

    <?php if ($msg !== ''): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" class="card">
        <input type="hidden" name="action" value="save_profile">
        <h2>Contact &amp; login</h2>
        <div class="grid">
            <div class="form-group full">
                <label>Full name (read-only)</label>
                <input type="text" value="<?= htmlspecialchars($user['Name'] ?? '') ?>" disabled>
            </div>
            <div class="form-group full">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                <p class="muted">Changing email or mobile sends a code. The current email stays active until you confirm it. Text messages are not set up.</p>
            </div>
            <div class="form-group full">
                <label for="Tel_Mobile">Mobile phone</label>
                <input type="tel" id="Tel_Mobile" name="Tel_Mobile" value="<?= htmlspecialchars($user['Tel_Mobile'] ?? '') ?>" placeholder="6475551234">
            </div>
            <div class="form-group">
                <label for="pin">Clock PIN</label>
                <input type="text" id="pin" name="pin" maxlength="10" value="<?= htmlspecialchars($user['pin'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Username (read-only)</label>
                <input type="text" value="<?= htmlspecialchars($user['user'] ?? '') ?>" disabled>
            </div>
            <div class="form-group">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" placeholder="Leave blank to keep">
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
            </div>
        </div>

        <h2 style="margin-top:22px">Address</h2>
        <div class="grid">
            <div class="form-group full">
                <label for="street_address">Street address</label>
                <input type="text" id="street_address" name="street_address" maxlength="120"
                       value="<?= htmlspecialchars($user['street_address'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" maxlength="80" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="province">Province</label>
                <input type="text" id="province" name="province" maxlength="2" placeholder="ON"
                       value="<?= htmlspecialchars($user['province'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="postal_code">Postal code</label>
                <input type="text" id="postal_code" name="postal_code" maxlength="7"
                       value="<?= htmlspecialchars($user['postal_code'] ?? '') ?>">
            </div>
        </div>

        <div class="actions">
            <button type="submit" class="pay-btn">Save profile</button>
            <a class="pay-btn pay-btn-outline" href="kiosk.php">Back to kiosk</a>
        </div>
    </form>

    <div class="card">
        <h2>SIN (protected)</h2>
        <div class="readonly-val"><?= htmlspecialchars($sin_masked) ?></div>
        <p class="muted">SIN cannot be edited here. Request an update and your administrator will verify and change it.</p>
        <form method="POST" style="margin-top:10px">
            <input type="hidden" name="action" value="request_sin">
            <div class="form-group full">
                <label for="sin_request_note">Request SIN update</label>
                <textarea id="sin_request_note" name="sin_request_note" rows="2" maxlength="400"
                          placeholder="e.g. Digits are wrong — please contact me to update."></textarea>
            </div>
            <div class="actions">
                <button type="submit" class="pay-btn pay-btn-outline">Send SIN request</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Banking (protected)</h2>
        <?php if ($has_bank): ?>
            <div class="grid">
                <div class="form-group">
                    <label>Transit (on file)</label>
                    <div class="readonly-val"><?= htmlspecialchars($user['bank_transit'] ?: '—') ?></div>
                </div>
                <div class="form-group">
                    <label>Institution (on file)</label>
                    <div class="readonly-val"><?= htmlspecialchars($user['bank_institution'] ?: '—') ?></div>
                </div>
                <div class="form-group full">
                    <label>Account (on file)</label>
                    <div class="readonly-val"><?= htmlspecialchars($bank_acct_masked) ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="readonly-val">No bank account on file yet</div>
        <?php endif; ?>
        <p class="muted">Account numbers cannot be edited here (fraud risk). Upload a completed Direct Deposit form — we auto-extract banking fields for your manager to verify. Live payroll bank details change only after Approve.</p>

        <?php
        $dd_at = trim((string)($user['direct_deposit_uploaded_at'] ?? ''));
        $dd_pending = !empty($user['direct_deposit_upload_pending']);
        $dd_file = trim((string)($user['direct_deposit_file'] ?? ''));
        $dd_orig = trim((string)($user['direct_deposit_orig_name'] ?? ''));
        $dd_raw_status = (string)($user['dd_verify_status'] ?? '');
        $dd_status = ess_dd_status_label($dd_raw_status, $user['direct_deposit_upload_pending'] ?? 0);
        $is_pending = $dd_pending || $dd_raw_status === 'pending_manager_verification';
        $is_rejected = $dd_raw_status === 'rejected';
        $is_approved = $dd_raw_status === 'approved';
        if ($dd_at !== '' && $dd_file !== ''):
        ?>
            <div class="alert <?= $is_rejected ? 'alert-err' : 'alert-ok' ?>" style="margin-top:12px;">
                Form submitted on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($dd_at))) ?>
                <?php if ($dd_orig !== ''): ?> (<?= htmlspecialchars($dd_orig) ?>)<?php endif; ?>
                — <strong><?= htmlspecialchars($dd_status) ?></strong>
                <?php if ($is_pending): ?>
                    <div class="muted" style="margin-top:6px;">
                        Extracted (not live yet): Transit <?= htmlspecialchars($user['dd_pending_transit'] ?: '—') ?>
                        · Institution <?= htmlspecialchars($user['dd_pending_institution'] ?: '—') ?>
                        · Account <?= !empty($user['dd_pending_account']) ? ('***' . htmlspecialchars(substr((string)$user['dd_pending_account'], -4))) : '—' ?>
                        <?php if (!empty($user['dd_pending_name'])): ?> · Name <?= htmlspecialchars($user['dd_pending_name']) ?><?php endif; ?>
                    </div>
                <?php elseif ($is_approved): ?>
                    <div class="muted" style="margin-top:6px;">Verified values are now on your payroll bank record above.</div>
                <?php elseif ($is_rejected): ?>
                    <div class="muted" style="margin-top:6px;">Please upload a clearer form, or contact your manager.</div>
                <?php endif; ?>
                · <a href="direct_deposit_download.php?user_id=<?= (int)$uid ?>">Download your upload</a>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" style="margin-top:12px">
            <input type="hidden" name="action" value="upload_direct_deposit">
            <div class="form-group full">
                <label for="direct_deposit_file">Update banking — upload your completed Direct Deposit form</label>
                <input type="file" id="direct_deposit_file" name="direct_deposit_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                <p class="muted">PDF, JPG, or PNG · max 8 MB · always queued for manager verification</p>
            </div>
            <div class="actions">
                <button type="submit" class="pay-btn"><?= $is_pending ? 'Replace &amp; re-submit' : 'Upload form' ?></button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
