<?php
/** Path: /aa_labor_hours_import.php
 * AA Group Labor Hours Detail.xlsx — customer link + super Clock In menu.
 * Marker: aa-labor-hours-20261017-addemp
 * Org locked to AA Group. Never deletes punches. Does not finalize or pay.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine/attendance_audit_lib.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: /login_kiosk.php');
    exit;
}
require_once __DIR__ . '/payroll_scope.php';
if (is_file(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}
require_once __DIR__ . '/engine/aa_labor_hours_lib.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

function aalh_rate_change_href(int $userId, int $orgId, float $fileRate, string $effective): string
{
    if ($userId <= 0) {
        return '';
    }
    $q = ['tab' => 'one', 'user_id' => $userId];
    if ($orgId > 0) {
        $q['org_id'] = $orgId;
    }
    if ($fileRate > 0) {
        $q['new_rate'] = number_format($fileRate, 2, '.', '');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
        $q['effective_date'] = $effective;
    }
    return 'rate_change.php?' . http_build_query($q);
}

function aalh_apply_profile_accepts(?array $preview, array $accepts): ?array
{
    if (!$preview || !$accepts || empty($preview['rows']) || !is_array($preview['rows'])) {
        return $preview;
    }
    foreach ($preview['rows'] as &$r) {
        $uid = (int)($r['user_id'] ?? 0);
        $keep = $accepts[(string)$uid] ?? null;
        if ($uid <= 0 || !is_array($keep)) {
            continue;
        }
        $rate = round((float)($keep['rate'] ?? 0), 2);
        if ($rate <= 0) {
            $rate = round((float)($r['profile_rate'] ?? 0), 2);
        }
        if ($rate <= 0) {
            continue;
        }
        $r['hourly_rate'] = $rate;
        $r['official_rate'] = $rate;
        $r['rate_flag'] = false;
        $r['rate_flag_text'] = '';
        $r['profile_accepted'] = true;
    }
    unset($r);
    return $preview;
}

function aalh_accept_profile_button(int $userId, float $profile): string
{
    if ($userId <= 0 || $profile <= 0) {
        return '';
    }
    $amount = number_format($profile, 2);
    return '<form method="POST" class="rate-inline">'
        . '<input type="hidden" name="aalh_action" value="accept_profile">'
        . '<input type="hidden" name="user_id" value="' . $userId . '">'
        . '<button type="submit" class="rate-accept">Accept profile rate $' . $amount . '</button>'
        . '</form>';
}


$sess_user_level = (int)($_SESSION['user_level'] ?? 99);
$sa_is_super = $sess_user_level <= 10;
$session_org = (int)($_SESSION['org_id'] ?? 0);
$aa_org = aalh_aa_org_id($pdo);
if (!aalh_can_access($sess_user_level, $session_org, $aa_org)) {
    http_response_code(403);
    echo 'This upload is for AA Group admin/manager (or super). It cannot be used for other organizations.';
    exit;
}

aalh_ensure_menu($pdo);
aalh_ensure_batch_tables($pdo);
aalh_ensure_profile_columns($pdo);

$selected_org = $aa_org;

$flash = '';
$flash_err = '';
$parsed = null;
$preview = null;
$openNewEmp = false;
$action = (string)($_POST['aalh_action'] ?? '');

$dir = __DIR__ . '/uploads/aa_labor';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preview') {
    $upErr = (int)($_FILES['labor_file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upErr === UPLOAD_ERR_NO_FILE || empty($_FILES['labor_file']['tmp_name']) || !is_uploaded_file($_FILES['labor_file']['tmp_name'])) {
        $flash_err = 'No Excel file was received. Click Choose File, select the workbook, and check the name appears (not “No file chosen”). Then click Preview.';
    } else {
        $name = (string)($_FILES['labor_file']['name'] ?? 'labor.xlsx');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xlsm'], true)) {
            $flash_err = 'This page reads an Excel workbook (.xlsx). The file chosen is .' . ($ext !== '' ? $ext : 'unknown') . '. In Excel use Save As → Excel Workbook (*.xlsx), then upload that file.';
        } else {
            try {
                $token = bin2hex(random_bytes(16));
                $dest = $dir . '/' . $token . '.xlsx';
                if (!move_uploaded_file($_FILES['labor_file']['tmp_name'], $dest)) {
                    throw new RuntimeException('Could not store upload.');
                }
                @chown($dest, 'renocon');
                $parsed = aalh_parse_labor_hours($dest);
                if (empty($parsed['shifts'])) {
                    $hint = !empty($parsed['errors']) ? ' ' . $parsed['errors'][0] : '';
                    throw new RuntimeException('No shift rows found.' . $hint);
                }
                $preview = aalh_preview_rows($pdo, $selected_org, $parsed);
                if ($preview['site_id'] <= 0) {
                    $where = !empty($parsed['loc_id']) ? ('Loc ID ' . $parsed['loc_id']) : ('site "' . ($parsed['site_name'] ?: '?') . '"');
                    throw new RuntimeException($where . ' is not an AA Group restaurant. Labor Hours Detail still matches the site name. A data dump needs Loc ID saved as the site store number.');
                }
                $_SESSION['aalh_profile_accepts'] = [];
                $_SESSION['aalh_preview'] = [
                    'token' => $token,
                    'org_id' => $selected_org,
                    'site_id' => $preview['site_id'],
                    'site_name' => (string)($preview['site_name'] ?? $parsed['site_name']),
                    'period' => $parsed['period'],
                    'file' => $dest,
                    'file_name' => $name,
                    'parsed' => $parsed,
                    'preview' => $preview,
                ];
                if (!empty($preview['unmatched'])) {
                    $openNewEmp = true;
                }
            } catch (Throwable $e) {
                $flash_err = $e->getMessage();
                $parsed = null;
                $preview = null;
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'import') {
    $sessPrev = $_SESSION['aalh_preview'] ?? null;
    $token = (string)($_POST['token'] ?? '');
    if (!$sessPrev || !hash_equals((string)($sessPrev['token'] ?? ''), $token)) {
        $flash_err = 'Preview expired. Upload the file again.';
    } else {
        $preview = $sessPrev['preview'];
        $parsed = $sessPrev['parsed'];
        $selected_org = $aa_org;
        if ((int)($sessPrev['org_id'] ?? 0) !== $aa_org || (int)($sessPrev['site_id'] ?? 0) <= 0) {
            $flash_err = 'Preview is not for AA Group. Upload the file again.';
            $preview = $sessPrev['preview'];
            $parsed = $sessPrev['parsed'];
        } else {
            try {
                $accepts = (isset($_SESSION['aalh_profile_accepts']) && is_array($_SESSION['aalh_profile_accepts']))
                    ? $_SESSION['aalh_profile_accepts'] : [];
                $preview = aalh_apply_profile_accepts($preview, $accepts);
                $pdo->beginTransaction();
                $res = aalh_import_rows(
                    $pdo,
                    $aa_org,
                    (int)$sessPrev['site_id'],
                    $preview['rows'],
                    (int)$_SESSION['user_id'],
                    (string)($sessPrev['file_name'] ?? ''),
                    (string)($sessPrev['site_name'] ?? '')
                );
                $pdo->commit();
                $flash = $res['inserted'] . ' shift(s) imported as pending punches (not finalized)'
                    . ($res['batch_id'] ? (' — batch #' . (int)$res['batch_id']) : '')
                    . '. Added ' . (int)($res['created_users'] ?? 0) . ' new portal employee(s). '
                    . $res['skipped_dup'] . ' already existed (same employee + date + start HH:MM) and were skipped.'
                    . ' Undo last import removes punches only — new employees stay so the next upload matches.';
                $preview = aalh_preview_rows($pdo, $aa_org, $parsed);
                $_SESSION['aalh_preview']['preview'] = $preview;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash_err = 'Import failed, nothing written: ' . $e->getMessage();
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'undo') {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    if ($batchId <= 0) {
        $flash_err = 'Pick an import batch to undo.';
    } else {
        try {
            $pdo->beginTransaction();
            $und = aalh_undo_batch($pdo, $batchId, $aa_org, (int)$_SESSION['user_id']);
            $pdo->commit();
            $flash = 'Undo removed ' . (int)$und['deleted'] . ' test punch(es) from batch #' . $batchId
                . ' (this upload only — staff-clocked punches were not touched).';
            if ($und['skipped_paid'] > 0) {
                $flash .= ' ' . (int)$und['skipped_paid'] . ' already finalized/paid and left alone.';
            }
            if (!empty($_SESSION['aalh_preview']['parsed']) && (int)($_SESSION['aalh_preview']['org_id'] ?? 0) === $aa_org) {
                $parsed = $_SESSION['aalh_preview']['parsed'];
                $preview = aalh_preview_rows($pdo, $aa_org, $parsed);
                $_SESSION['aalh_preview']['preview'] = $preview;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash_err = 'Undo failed: ' . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_rate') {
    if (!empty($_SESSION['aalh_preview']['preview']) && (int)($_SESSION['aalh_preview']['org_id'] ?? 0) === $aa_org) {
        $parsed = $_SESSION['aalh_preview']['parsed'];
        $preview = $_SESSION['aalh_preview']['preview'];
    }
    $uid = (int)($_POST['user_id'] ?? 0);
    $hourly = round((float)($_POST['new_hourly_rate'] ?? $_POST['hourly_rate'] ?? 0), 2);
    $effective = trim((string)($_POST['effective_date'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if ($sess_user_level > 25) {
        $flash_err = 'An admin has to save the rate.';
    } elseif ($uid <= 0) {
        $flash_err = 'Pick an employee first.';
    } elseif ($hourly <= 0) {
        $flash_err = 'Enter the new hourly rate.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
        $flash_err = 'Effective date is required. That is the first work day the new rate applies.';
    } else {
        $chk = $pdo->prepare('SELECT id, org_id, Name, hourly_rate, official_rate, transit_rate FROM users WHERE id = ? LIMIT 1');
        $chk->execute([$uid]);
        $u = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$u || (int)$u['org_id'] !== $aa_org) {
            $flash_err = 'That employee is not in AA Group.';
        } else {
            $currentHourly = round((float)($u['hourly_rate'] ?? 0), 2);
            $fileRate = round((float)($_POST['file_rate'] ?? 0), 2);
            if ($fileRate <= 0 && is_array($preview) && !empty($preview['rows'])) {
                $fileDate = '';
                foreach ($preview['rows'] as $row) {
                    if ((int)($row['user_id'] ?? 0) !== $uid || empty($row['rate_flag'])) {
                        continue;
                    }
                    $sheet = (isset($row['sheet_rate']) && $row['sheet_rate'] !== null && $row['sheet_rate'] !== '')
                        ? (float)$row['sheet_rate']
                        : (float)($row['hourly_rate'] ?? 0);
                    $rowDate = (string)($row['date'] ?? '');
                    if ($sheet > 0 && ($fileDate === '' || ($rowDate !== '' && $rowDate < $fileDate))) {
                        $fileDate = $rowDate;
                        $fileRate = round($sheet, 2);
                    }
                }
            }
            if (abs($hourly - $currentHourly) < 0.001) {
                $flash_err = $u['Name'] . ' is already $' . number_format($currentHourly, 2)
                    . '. Use Accept profile rate if that amount is correct. The file rate is $' . number_format($fileRate, 2) . '.';
            } else {
            $firstDiff = trim((string)($_POST['first_diff'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstDiff)) {
                $firstDiff = $effective;
            }
            $noteText = 'File rate differed starting ' . $firstDiff . '. Set $' . number_format($hourly, 2) . ' effective ' . $effective . '.';
            if ($note !== '') {
                $noteText .= ' ' . $note;
            }
            $official = ($u['official_rate'] === null || $u['official_rate'] === '') ? null : round((float)$u['official_rate'], 2);
            $transit = ($u['transit_rate'] === null || $u['transit_rate'] === '') ? null : round((float)$u['transit_rate'], 2);
            pe_record_user_rate(
                $pdo,
                $uid,
                $hourly,
                $official,
                $transit,
                $effective,
                (int)$_SESSION['user_id'],
                $noteText,
                'rate_change'
            );
            $markText = 'Rate set $' . number_format($hourly, 2) . ' effective ' . $effective;
            $markedN = 0;
            $siteId = (int)($_SESSION['aalh_preview']['site_id'] ?? 0);
            if ($siteId > 0) {
                $aaIds = aa_ids_where(
                    $pdo,
                    "user_id = ? AND site_id = ? AND org_id = ? AND DATE(clock_in) >= ? AND flag_reason LIKE 'Rate flag:%'",
                    [$uid, $siteId, $aa_org, $effective]
                );
                $aaBefore = aa_befores($pdo, $aaIds);
                $upd = $pdo->prepare(
                    "UPDATE attendance
                        SET is_flagged = 1, flag_reason = ?
                      WHERE user_id = ? AND site_id = ? AND org_id = ?
                        AND DATE(clock_in) >= ?
                        AND flag_reason LIKE 'Rate flag:%'"
                );
                $upd->execute([$markText, $uid, $siteId, $aa_org, $effective]);
                aa_log_ids($pdo, 'update', $aaIds, $aaBefore, 'AA rate mark');
                $markedN = (int)$upd->rowCount();
            }
            if (isset($_SESSION['aalh_profile_accepts'][(string)$uid])) {
                unset($_SESSION['aalh_profile_accepts'][(string)$uid]);
            }
            if (!isset($_SESSION['aalh_rate_marks']) || !is_array($_SESSION['aalh_rate_marks'])) {
                $_SESSION['aalh_rate_marks'] = [];
            }
            $_SESSION['aalh_rate_marks'][(string)$uid] = [
                'name' => (string)$u['Name'],
                'rate' => $hourly,
                'effective' => $effective,
                'first_diff' => $firstDiff,
                'when' => date('Y-m-d H:i'),
                'punches' => $markedN,
            ];
            $flash = $u['Name'] . ' is now $' . number_format($hourly, 2) . ' from ' . $effective . '. The earlier rate stays on file.'
                . ' Marked as changed on ' . $effective . '.'
                . ($markedN > 0 ? (' ' . $markedN . ' imported punch(es) from that date now show the mark.') : '');
            if (!empty($_SESSION['aalh_preview']['parsed']) && (int)($_SESSION['aalh_preview']['org_id'] ?? 0) === $aa_org) {
                $parsed = $_SESSION['aalh_preview']['parsed'];
                $preview = aalh_preview_rows($pdo, $aa_org, $parsed);
                $_SESSION['aalh_preview']['preview'] = $preview;
            }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_new_employee') {
    if (!empty($_SESSION['aalh_preview']['preview']) && (int)($_SESSION['aalh_preview']['org_id'] ?? 0) === $aa_org) {
        $parsed = $_SESSION['aalh_preview']['parsed'];
        $preview = $_SESSION['aalh_preview']['preview'];
    }
    $siteId = (int)($_SESSION['aalh_preview']['site_id'] ?? 0);
    $siteName = (string)($_SESSION['aalh_preview']['site_name'] ?? '');
    $entered = aalh_new_employee_input($_POST);
    $empNo = (string)$entered['data']['emp_no'];
    $name = (string)$entered['data']['name'];
    if ($sess_user_level > 25) {
        $flash_err = 'An admin has to add the new employee.';
    } elseif ($siteId <= 0) {
        $flash_err = 'Preview expired. Upload the file again.';
    } elseif ($entered['error'] !== '') {
        $flash_err = $entered['error'];
        $openNewEmp = true;
    } else {
        try {
            $existed = aalh_map_employee($pdo, $aa_org, $empNo);
            $wage = (float)$entered['data']['wage'];
            $u = aalh_create_aa_hourly($pdo, $aa_org, $siteId, $siteName, $empNo, $name, [
                'name' => $name,
                'hourly_rate' => $entered['salaried'] ? 0 : $wage,
                'official_rate' => $entered['salaried'] ? 0 : $wage,
                'email' => (string)$entered['data']['email'],
                'mobile' => (string)$entered['data']['phone'],
            ]);
            if (!$existed) {
                aalh_save_new_employee_profile($pdo, (int)$u['id'], $entered['data'], $entered['salaried']);
                if (!$entered['salaried']) {
                    pe_record_user_rate(
                        $pdo,
                        (int)$u['id'],
                        $wage,
                        $wage,
                        null,
                        (string)$entered['data']['joining'],
                        (int)$_SESSION['user_id'],
                        'Added from the labor hours file.',
                        'labor_import'
                    );
                }
                $st = $pdo->prepare('SELECT id, Name, user, hourly_rate, official_rate, primary_site_id, assigned_site_ids FROM users WHERE id = ?');
                $st->execute([(int)$u['id']]);
                $reloaded = $st->fetch(PDO::FETCH_ASSOC);
                if ($reloaded) {
                    $u = $reloaded;
                }
            }
            if (!empty($_SESSION['aalh_preview']['parsed']) && (int)($_SESSION['aalh_preview']['org_id'] ?? 0) === $aa_org) {
                $parsed = $_SESSION['aalh_preview']['parsed'];
                $preview = aalh_preview_rows($pdo, $aa_org, $parsed);
                $_SESSION['aalh_preview']['preview'] = $preview;
                if (!empty($preview['unmatched'])) {
                    $openNewEmp = true;
                }
            }
            if ($existed) {
                $flash = $u['Name'] . ' was already on the portal. Nothing new was created.';
            } elseif ($entered['salaried']) {
                $flash = $u['Name'] . ' is on the portal as salaried at $' . number_format((float)$entered['data']['salary'], 2) . ' each pay period. Hours on the file are recorded and are not paid by the hour.';
            } else {
                $flash = $u['Name'] . ' is on the portal at $' . number_format($wage, 2) . ' from ' . $entered['data']['joining'] . '.';
            }
        } catch (Throwable $e) {
            $flash_err = 'Could not add the employee: ' . $e->getMessage();
            $openNewEmp = true;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'accept_profile') {
    if (!empty($_SESSION['aalh_preview']['preview']) && (int)($_SESSION['aalh_preview']['org_id'] ?? 0) === $aa_org) {
        $parsed = $_SESSION['aalh_preview']['parsed'];
        $preview = $_SESSION['aalh_preview']['preview'];
    }
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($sess_user_level > 25) {
        $flash_err = 'An admin has to accept the profile rate.';
    } elseif ($uid <= 0) {
        $flash_err = 'Pick an employee first.';
    } else {
        $chk = $pdo->prepare('SELECT id, org_id, Name, hourly_rate FROM users WHERE id = ? LIMIT 1');
        $chk->execute([$uid]);
        $u = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$u || (int)$u['org_id'] !== $aa_org) {
            $flash_err = 'That employee is not in AA Group.';
        } else {
            $rate = round((float)($u['hourly_rate'] ?? 0), 2);
            if ($rate <= 0) {
                $flash_err = $u['Name'] . ' has no profile hourly rate to accept.';
            } else {
                if (!isset($_SESSION['aalh_profile_accepts']) || !is_array($_SESSION['aalh_profile_accepts'])) {
                    $_SESSION['aalh_profile_accepts'] = [];
                }
                $_SESSION['aalh_profile_accepts'][(string)$uid] = [
                    'name' => (string)$u['Name'],
                    'rate' => $rate,
                    'when' => date('Y-m-d H:i'),
                ];
                $flash = $u['Name'] . ' keeps the profile rate $' . number_format($rate, 2)
                    . '. These punches will use $' . number_format($rate, 2) . ', not the file rate. The profile was not changed.';
            }
        }
    }
} elseif (!empty($_SESSION['aalh_preview']['preview'])) {
    $sessPrev = $_SESSION['aalh_preview'];
    if ((int)$sessPrev['org_id'] === $aa_org) {
        $parsed = $sessPrev['parsed'];
        $preview = $sessPrev['preview'];
    }
}

$profileAccepts = (isset($_SESSION['aalh_profile_accepts']) && is_array($_SESSION['aalh_profile_accepts']))
    ? $_SESSION['aalh_profile_accepts'] : [];
if (is_array($preview)) {
    $preview = aalh_apply_profile_accepts($preview, $profileAccepts);
}

$nNew = 0;
$nDup = 0;
$nWillAdd = 0;
$nNight = 0;
$nRate = 0;
$rateFlagPeople = [];
$newPeople = [];
if ($preview) {
    foreach ($preview['rows'] as $r) {
        if ($r['status'] === 'new') {
            $nNew++;
        } elseif ($r['status'] === 'duplicate') {
            $nDup++;
        } elseif ($r['status'] === 'will_add' || $r['status'] === 'unmatched') {
            $nWillAdd++;
        }
        if (!empty($r['overnight'])) {
            $nNight++;
        }
        if (!empty($r['rate_flag'])) {
            $nRate++;
            $key = (string)($r['emp_no'] ?? '');
            if (!isset($rateFlagPeople[$key])) {
                $rateFlagPeople[$key] = [
                    'name' => (string)($r['mapped_name'] ?: $r['name']),
                    'emp_no' => $key,
                    'user_id' => (int)($r['user_id'] ?? 0),
                    'file' => (float)$r['hourly_rate'],
                    'profile' => (float)($r['profile_rate'] ?? 0),
                    'first_date' => (string)($r['date'] ?? ''),
                    'shifts' => 0,
                ];
            }
            $rateFlagPeople[$key]['shifts']++;
            $flagDate = (string)($r['date'] ?? '');
            if ($flagDate !== '' && ($rateFlagPeople[$key]['first_date'] === '' || $flagDate < $rateFlagPeople[$key]['first_date'])) {
                $rateFlagPeople[$key]['first_date'] = $flagDate;
                $rateFlagPeople[$key]['file'] = (float)$r['hourly_rate'];
            }
        }
        if (($r['status'] ?? '') === 'will_add' || ($r['status'] ?? '') === 'unmatched') {
            $empKey = (string)($r['emp_no'] ?? '');
            if ($empKey !== '') {
                $sheet = (isset($r['sheet_rate']) && $r['sheet_rate'] !== null && $r['sheet_rate'] !== '')
                    ? (float)$r['sheet_rate'] : (float)($r['hourly_rate'] ?? 0);
                if (!isset($newPeople[$empKey])) {
                    $newPeople[$empKey] = [
                        'name' => (string)($r['name'] ?? ''),
                        'emp_no' => $empKey,
                        'file' => $sheet,
                        'first_date' => (string)($r['date'] ?? ''),
                        'shifts' => 0,
                    ];
                }
                $newPeople[$empKey]['shifts']++;
                $rowDate = (string)($r['date'] ?? '');
                if ($rowDate !== '' && ($newPeople[$empKey]['first_date'] === '' || $rowDate < $newPeople[$empKey]['first_date'])) {
                    $newPeople[$empKey]['first_date'] = $rowDate;
                    if ($sheet > 0) {
                        $newPeople[$empKey]['file'] = $sheet;
                    }
                }
            }
        }
    }
}
$token = (string)(($_SESSION['aalh_preview']['token'] ?? ''));
$rateMarks = (isset($_SESSION['aalh_rate_marks']) && is_array($_SESSION['aalh_rate_marks'])) ? $_SESSION['aalh_rate_marks'] : [];
$batches = aalh_list_batches($pdo, $aa_org, 8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AA Labor Hours import</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background:#f1f5f9; font-family: Inter, system-ui, sans-serif; padding-top: 88px; color:#0f172a; }
        .wrap { max-width: 1320px; margin: 0 auto 60px; padding: 0 18px; }
        .card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; box-shadow:0 8px 24px rgba(15,23,42,.05); }
        h1 { font-size:20px; font-weight:900; margin:0 0 6px; }
        .lede { color:#475569; font-size:13px; margin:0 0 14px; line-height:1.45; }
        .filters { display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-bottom:12px; }
        .filters label { display:block; font-size:10px; font-weight:800; text-transform:uppercase; color:#64748b; margin-bottom:4px; }
        .filters input, .filters select { height:36px; border:1px solid #cbd5e1; border-radius:8px; padding:0 10px; font-weight:600; }
        .btn { display:inline-flex; align-items:center; gap:6px; height:36px; padding:0 12px; border-radius:8px; font-weight:800; font-size:12px; text-decoration:none; border:none; cursor:pointer; }
        .btn-go { background:#0f766e; color:#fff; }
        .btn-import { background:#1d4ed8; color:#fff; }
        .btn-import:disabled { opacity:.45; cursor:not-allowed; }
        .btn-ghost { background:#e2e8f0; color:#0f172a; }
        .btn-undo { background:#be123c; color:#fff; }
        .btn-undo:disabled { opacity:.45; cursor:not-allowed; }
        .batches { margin: 0 0 16px; padding: 12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; }
        .batches h2 { font-size:13px; margin:0 0 8px; }
        .batches table { font-size:12px; }
        .flash { background:#ecfdf5; border:1px solid #86efac; color:#14532d; padding:10px 12px; border-radius:8px; font-weight:700; font-size:13px; margin-bottom:12px; }
        .flash.err { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
        .chips { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0 14px; }
        .chip { background:#f8fafc; border:1px solid #e2e8f0; border-radius:999px; padding:5px 12px; font-size:12px; font-weight:700; }
        .chip.ok { background:#dcfce7; border-color:#86efac; color:#14532d; }
        .chip.bad { background:#fee2e2; border-color:#fca5a5; color:#991b1b; }
        .chip.night { background:#e0f2fe; border-color:#7dd3fc; color:#075985; }
        .chip.warn { background:#fef9c3; border-color:#fde047; color:#854d0e; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:8px; border-bottom:2px solid #e2e8f0; }
        td { padding:8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        tr.unmatched td, tr.will_add td { background:#fff7ed; }
        .badge.add { background:#ea580c; color:#fff; }
        tr.duplicate td { background:#f8fafc; color:#64748b; }
        tr.night td { background:#f0f9ff; }
        .badge { display:inline-block; font-size:10px; font-weight:800; text-transform:uppercase; padding:2px 7px; border-radius:6px; margin:0 4px 4px 0; }
        .badge.ok { background:#16a34a; color:#fff; }
        .badge.bad { background:#dc2626; color:#fff; }
        .badge.dup { background:#94a3b8; color:#0f172a; }
        .badge.night { background:#38bdf8; color:#0c4a6e; }
        .muted { color:#64748b; font-size:12px; }
        .stop { background:#fff7ed; border:1px solid #fdba74; color:#9a3412; padding:12px 14px; border-radius:10px; margin:0 0 14px; font-weight:700; }
        .rateflag { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:12px 14px; border-radius:10px; margin:0 0 14px; font-weight:700; }
        .rateflag ul { margin:8px 0 0; padding-left:18px; font-weight:600; }
        .rate-open { background:none; border:0; color:#7f1d1d; font-weight:800; text-decoration:underline; cursor:pointer; padding:0; font-size:inherit; }
        .new-emp-open { background:none; border:0; color:#9a3412; font-weight:800; text-decoration:underline; cursor:pointer; padding:0; font-size:inherit; }
        .rate-inline { display:inline; }
        .rate-accept { background:none; border:0; color:#065f46; font-weight:800; text-decoration:underline; cursor:pointer; padding:0; font-size:inherit; }
        .badge.keep { background:#d1fae5; color:#065f46; }
        .rate-mask { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:80; display:flex; align-items:center; justify-content:center; padding:18px; }
        .rate-mask[hidden] { display:none; }
        .rate-card { background:#fff; color:#0f172a; width:min(460px, 100%); border-radius:14px; padding:18px 18px 14px; box-shadow:0 20px 50px rgba(0,0,0,.25); }
        .rate-card.tall { width:min(680px, 100%); max-height:88vh; overflow:auto; }
        .emp-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 12px; }
        .emp-grid .full { grid-column:1 / -1; }
        #newEmpBank[hidden], #newEmpWageWrap[hidden], #newEmpSalaryWrap[hidden] { display:none; }
        .rate-card h3 { margin:0 0 8px; font-size:18px; }
        .rate-card label { display:block; font-size:11px; font-weight:800; text-transform:uppercase; color:#64748b; margin:10px 0 4px; }
        .rate-card input, .rate-card select { width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:8px; padding:8px 10px; font-size:14px; background:#fff; }
        .rate-actions { display:flex; gap:8px; margin-top:14px; }
        .rate-actions button { border:0; border-radius:8px; padding:8px 14px; font-weight:800; cursor:pointer; }
        .rate-save { background:#047857; color:#fff; }
        .rate-cancel { background:#e2e8f0; color:#0f172a; }
        .ratemark { background:#ecfdf5; border:1px solid #6ee7b7; color:#065f46; padding:12px 14px; border-radius:10px; margin:0 0 14px; font-weight:700; }
        .badge.set { background:#d1fae5; color:#065f46; }
        .stop ul { margin:8px 0 0; padding-left:18px; font-weight:600; }
    </style>
</head>
<body>
<?= function_exists('generateMenu') ? generateMenu($pdo) : '' ?>
<div class="wrap">
    <div class="card">
        <h1>AA Labor Hours</h1>
        <p class="lede">
            Upload the customer <strong>.xlsx</strong> as-is (do not convert to CSV).
            Labor Hours Detail still works. A data dump also works: Date, Loc ID, Emp ID, Wage Rate, Start Time, End Time, Breaks, Hours, Note.
            Times like <strong>09.15</strong> mean 9:15. The file rate is the punch rate.
            Restaurant comes from the sheet (e.g. Bramalea). Employee number in parentheses maps to the portal — names are not guessed.
            End time earlier than start is the <strong>next calendar day</strong>. Same employee + date + start HH:MM is skipped.
            Punches stay <strong>unfinalized / unpaid</strong>. This page writes <strong>AA Group only</strong> — not Popeyes or any other org.
        </p>
        <p class="lede" style="font-weight:800;color:#9a3412;">Unmatched numbers are tagged <strong>Will add employee</strong>. Import creates them at the sheet site (e.g. Bramalea) — it does not merge a different store or emp #. Undo removes punches only; new people stay.</p>

        <form method="POST" enctype="multipart/form-data" class="filters">
            <input type="hidden" name="aalh_action" value="preview">
            <div>
                <label>Organization</label>
                <input type="text" value="AA Group" readonly style="background:#f8fafc;font-weight:800;">
            </div>
            <div>
                <label>Hours .xlsx</label>
                <input type="file" name="labor_file" accept=".xlsx,.xlsm,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel">
                <div class="muted">The box must show the file name. “No file chosen” means nothing is attached yet.</div>
            </div>
            <button class="btn btn-go" type="submit"><i class="fas fa-eye"></i> Preview</button>
            <a class="btn btn-ghost" href="staff_audit.php?org_id=<?= (int)$aa_org ?>">Staff Audit</a>
        </form>

        <?php if ($flash): ?><div class="flash"><?= aalh_h($flash) ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="flash err"><?= aalh_h($flash_err) ?></div><?php endif; ?>

        <div class="batches">
            <h2>Recent imports — Undo last test</h2>
            <p class="muted" style="margin:0 0 8px;">Undo removes only punches from that upload (batch id on each row). Staff-clocked punches are never touched. Finalized or paid rows in the batch are left alone. After undo, the same xlsx can be imported again.</p>
            <?php if (!$batches): ?>
                <p class="muted" style="margin:0;">No import batches yet. After a successful Import, Undo last import appears here.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>When</th>
                        <th>File</th>
                        <th>Site</th>
                        <th>Rows</th>
                        <th>Still on file</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr>
                        <td>#<?= (int)$b['id'] ?><?php if (!empty($b['undone_at'])): ?> <span class="badge dup">Undone</span><?php endif; ?></td>
                        <td><?= aalh_h($b['imported_at']) ?><div class="muted"><?= aalh_h($b['imported_by_name'] ?: ('user #' . $b['imported_by'])) ?></div></td>
                        <td><?= aalh_h($b['file_name'] ?: '—') ?></td>
                        <td><?= aalh_h($b['site_name'] ?: ('site ' . $b['site_id'])) ?></td>
                        <td><?= (int)$b['row_count'] ?></td>
                        <td><?= (int)$b['still_there'] ?><?php if ((int)$b['paid_or_final'] > 0): ?> <span class="muted">(<?= (int)$b['paid_or_final'] ?> finalized/paid)</span><?php endif; ?></td>
                        <td>
                            <?php if (!empty($b['can_undo'])): ?>
                            <form method="POST" onsubmit="return confirm('Remove <?= (int)$b['still_there'] ?> test punch(es) from this upload (batch #<?= (int)$b['id'] ?>)? Does not touch punches the customer/staff clocked. Finalized or paid rows are left alone.');">
                                <input type="hidden" name="aalh_action" value="undo">
                                <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                                <button class="btn btn-undo" type="submit"><i class="fas fa-undo"></i> Undo last import</button>
                            </form>
                            <?php elseif (!empty($b['undone_at'])): ?>
                                <span class="muted">Undone <?= aalh_h($b['undone_at']) ?></span>
                            <?php else: ?>
                                <span class="muted">Nothing left to undo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php if ($parsed && $preview): ?>
            <div class="chips">
                <span class="chip"><?= aalh_h((string)($preview['site_name'] ?? $parsed['site_name'])) ?><?= !empty($parsed['loc_id']) ? ' · Loc ' . aalh_h($parsed['loc_id']) : '' ?> · site <?= (int)$preview['site_id'] ?></span>
                <span class="chip"><?= aalh_h($parsed['period']) ?></span>
                <span class="chip"><?= count($parsed['employees']) ?> employees · <?= count($preview['rows']) ?> shifts</span>
                <span class="chip ok"><?= (int)$nNew ?> new</span>
                <span class="chip warn"><?= (int)$nDup ?> punch<?= ((int)$nDup === 1) ? "" : "es" ?> already on file</span>
                <span class="chip warn"><?= (int)$nWillAdd ?> will add employee</span>
                <span class="chip night"><?= (int)$nNight ?> overnight (end next day)</span>
                <?php if ($nRate > 0): ?><span class="chip bad"><?= (int)$nRate ?> rate flag<?= $nRate === 1 ? '' : 's' ?></span><?php endif; ?>
            </div>

            <?php if ($rateMarks): ?>
                <div class="ratemark">
                    Rate change recorded
                    <ul>
                        <?php foreach ($rateMarks as $m): ?>
                            <li><?= aalh_h((string)$m['name']) ?> — $<?= number_format((float)$m['rate'], 2) ?> effective <?= aalh_h((string)$m['effective']) ?><?php if (!empty($m['first_diff']) && $m['first_diff'] !== $m['effective']): ?> (file first differed <?= aalh_h((string)$m['first_diff']) ?>)<?php endif; ?> · saved <?= aalh_h((string)$m['when']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($profileAccepts): ?>
                <div class="ratemark">
                    Profile rate accepted — the profile stays as it is, and these punches use that rate instead of the file rate.
                    <ul>
                        <?php foreach ($profileAccepts as $m): ?>
                            <li><?= aalh_h((string)($m['name'] ?? '')) ?> — $<?= number_format((float)($m['rate'] ?? 0), 2) ?> · accepted <?= aalh_h((string)($m['when'] ?? '')) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($rateFlagPeople): ?>
                <div class="rateflag">
                    Rate flag — the file rate does not match the employee profile. Change rate updates the profile. Accept profile rate keeps the profile and uses that rate on these punches.
                    <ul>
                        <?php foreach ($rateFlagPeople as $p): ?>
                            <li><?= aalh_h($p['name']) ?> #<?= aalh_h($p['emp_no']) ?> — file $<?= number_format($p['file'], 2) ?>, profile $<?= number_format($p['profile'], 2) ?> · first different day <?= aalh_h((string)$p['first_date']) ?> · <?= (int)$p['shifts'] ?> shift<?= ((int)$p['shifts'] === 1) ? '' : 's' ?>
                                <?php if ((int)$p['user_id'] > 0 && $sess_user_level <= 25): ?>
                                    · <button type="button" class="rate-open" data-user="<?= (int)$p['user_id'] ?>" data-name="<?= aalh_h($p['name']) ?>" data-profile="<?= aalh_h(number_format((float)$p['profile'], 2, '.', '')) ?>" data-file="<?= aalh_h(number_format((float)$p['file'], 2, '.', '')) ?>" data-effective="<?= aalh_h((string)$p['first_date']) ?>">Change rate</button>
                                    · <?= aalh_accept_profile_button((int)$p['user_id'], (float)$p['profile']) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($newPeople): ?>
                <div class="stop">
                    New employee — a window opens for the required profile. Banking is required only for direct deposit. A salaried employee is paid the salary on the paystub; the file wage and hours are not used for pay. Site is <?= aalh_h($parsed['site_name'] ?: 'the sheet site') ?>.
                    <ul>
                        <?php foreach ($newPeople as $p): ?>
                            <li><strong>New employee</strong> <?= aalh_h($p['name']) ?> — #<?= aalh_h($p['emp_no']) ?> · file $<?= number_format((float)$p['file'], 2) ?> · <?= (int)$p['shifts'] ?> shift<?= ((int)$p['shifts'] === 1) ? '' : 's' ?>
                                <?php if ($sess_user_level <= 25): ?>
                                    · <button type="button" class="new-emp-open" data-emp="<?= aalh_h($p['emp_no']) ?>" data-name="<?= aalh_h($p['name']) ?>" data-rate="<?= aalh_h(number_format((float)$p['file'], 2, '.', '')) ?>" data-date="<?= aalh_h((string)$p['first_date']) ?>">Fill profile</button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" onsubmit="return confirm('Import <?= (int)($nNew + $nWillAdd) ?> punch(es)<?= $preview['unmatched'] ? ' and add ' . count($preview['unmatched']) . ' new employee(s)' : '' ?>? Existing same-start rows will be skipped. Punches will NOT be finalized or paid.');">
                <input type="hidden" name="aalh_action" value="import">
                <input type="hidden" name="org_id" value="<?= (int)$aa_org ?>">
                <input type="hidden" name="token" value="<?= aalh_h($token) ?>">
                <p>
                    <button class="btn btn-import" type="submit" <?= (($nNew + $nWillAdd) < 1) ? 'disabled' : '' ?>>
                        <i class="fas fa-file-import"></i> Import <?= (int)($nNew + $nWillAdd) ?> confirmed rows<?= $preview['unmatched'] ? ' (add ' . count($preview['unmatched']) . ' employees)' : '' ?>
                    </button>
                    <span class="muted">Import is allowed with unmatched numbers — they become new AA employees first.</span>
                </p>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>File employee</th>
                        <th>Mapped user</th>
                        <th>Date</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Breaks</th>
                        <th>Rate</th>
                        <th>File hrs</th>
                        <th>Span hrs</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['rows'] as $r): ?>
                    <tr class="<?= aalh_h($r['status'] . ($r['overnight'] ? ' night' : '')) ?>">
                        <td>
                            <?php if ($r['status'] === 'new'): ?><span class="badge ok">New</span><?php endif; ?>
                            <?php if ($r['status'] === 'duplicate'): ?><span class="badge dup">Duplicate #<?= (int)$r['dup_id'] ?></span><?php endif; ?>
                            <?php if ($r['status'] === 'will_add' || $r['status'] === 'unmatched'): ?><span class="badge add">Will add employee</span><?php endif; ?>
                            <?php if (!empty($r['overnight'])): ?><span class="badge night">Ends next day</span><?php endif; ?>
                            <?php if (!empty($r['profile_accepted'])): ?><span class="badge keep">Profile rate</span><?php endif; ?>
                            <?php if (!empty($r['rate_flag'])): ?><span class="badge bad">Rate flag</span><?php endif; ?>
                            <?php
                            $rowMark = $rateMarks[(string)(int)($r['user_id'] ?? 0)] ?? null;
                            if ($rowMark && (string)$r['date'] >= (string)$rowMark['effective']):
                            ?><span class="badge set">Rate set <?= aalh_h((string)$rowMark['effective']) ?></span><?php endif; ?>
                        </td>
                        <td><?= aalh_h($r['name']) ?><div class="muted">#<?= aalh_h($r['emp_no']) ?></div></td>
                        <td>
                            <?php if ($r['user_id']): ?>
                                <?= aalh_h($r['mapped_name']) ?>
                                <div class="muted">users.id <?= (int)$r['user_id'] ?> · <?= aalh_h($r['mapped_user']) ?></div>
                            <?php else: ?>
                                <strong><?= aalh_h(aalh_sheet_display_name((string)$r['name'], (string)$r['emp_no'])) ?></strong>
                                <div class="muted">will create emp<?= aalh_h($r['emp_no']) ?>@aagroup.import.invalid · site <?= (int)$preview['site_id'] ?><?php $np = $newPeople[(string)($r['emp_no'] ?? '')] ?? null; if ($np && $sess_user_level <= 25): ?> · <button type="button" class="new-emp-open" data-emp="<?= aalh_h($np['emp_no']) ?>" data-name="<?= aalh_h($np['name']) ?>" data-rate="<?= aalh_h(number_format((float)$np['file'], 2, '.', '')) ?>" data-date="<?= aalh_h((string)$np['first_date']) ?>">Fill profile</button><?php endif; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= aalh_h($r['date']) ?></td>
                        <td><?= aalh_h($r['clock_in']) ?></td>
                        <td><?= aalh_h($r['clock_out']) ?></td>
                        <td><?= (int)$r['breaks'] ?></td>
                        <td><?= aalh_h(number_format((float)$r['hourly_rate'], 2)) ?><?php if (!empty($r['rate_flag'])): ?><div style="color:#991b1b;font-weight:800;">profile <?= aalh_h(number_format((float)$r['profile_rate'], 2)) ?><?php if ((int)($r['user_id'] ?? 0) > 0 && $sess_user_level <= 25): $person = $rateFlagPeople[(string)($r['emp_no'] ?? '')] ?? null; $sugDate = (string)($person['first_date'] ?? $r['date']); $sugRate = (float)($person['file'] ?? $r['hourly_rate']); ?> · <button type="button" class="rate-open" data-user="<?= (int)$r['user_id'] ?>" data-name="<?= aalh_h((string)($r['mapped_name'] ?: $r['name'])) ?>" data-profile="<?= aalh_h(number_format((float)$r['profile_rate'], 2, '.', '')) ?>" data-file="<?= aalh_h(number_format($sugRate, 2, '.', '')) ?>" data-effective="<?= aalh_h($sugDate) ?>">Change rate</button> · <?= aalh_accept_profile_button((int)$r['user_id'], (float)$r['profile_rate']) ?><?php endif; ?></div><?php endif; ?><?php if (!empty($r['note'])): ?><div class="muted"><?= aalh_h($r['note']) ?></div><?php endif; ?></td>
                        <td><?= $r['hours'] === null ? '—' : aalh_h(number_format((float)$r['hours'], 2)) ?></td>
                        <td><?= $r['raw_span_hours'] === null ? '—' : aalh_h(number_format((float)$r['raw_span_hours'], 2)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<div id="rateBox" class="rate-mask" hidden>
    <form method="POST" class="rate-card" id="rateForm" autocomplete="off">
        <input type="hidden" name="aalh_action" value="save_rate">
        <input type="hidden" name="user_id" id="rateUserId" value="">
        <input type="hidden" name="first_diff" id="rateFirstDiff" value="">
        <input type="hidden" name="file_rate" id="rateFileRate" value="">
        <h3 id="rateTitle">Change rate</h3>
        <p id="rateNow" style="margin:0 0 8px; font-weight:700;"></p>
        <p id="rateSuggest" class="muted" style="margin:0;"></p>
        <label for="rateHourly">New hourly rate</label>
        <input id="rateHourly" name="new_hourly_rate" type="number" step="0.01" min="0.01" required autocomplete="off">
        <label for="rateEffective">Effective date</label>
        <input id="rateEffective" name="effective_date" type="date" required>
        <label for="rateNote">Note</label>
        <input id="rateNote" name="note" type="text" maxlength="255" placeholder="From the labor hours file">
        <p class="muted" style="margin:10px 0 0;">The earlier rate stays on file with its own date. Save stays on this page.</p>
        <div class="rate-actions">
            <button class="rate-save" type="submit">Save</button>
            <button class="rate-cancel" type="button" id="rateCancel">Cancel</button>
        </div>
    </form>
</div>
<?php if ($newPeople && $sess_user_level <= 25):
    $firstNew = reset($newPeople);
    $old = ($action === 'add_new_employee' && $flash_err !== '') ? $_POST : [];
    $v = function (string $key, string $fallback = '') use ($old): string {
        if (array_key_exists($key, $old)) {
            return (string)$old[$key];
        }
        return $fallback;
    };
    $provinces = ['BC','ON','SK','AB','MB','NB','NL','NS','PE','QC','NT','NU','YT'];
?>
<div id="newEmpBox" class="rate-mask" <?= $openNewEmp ? '' : 'hidden' ?>>
    <form method="POST" class="rate-card tall" id="newEmpForm" autocomplete="off">
        <input type="hidden" name="aalh_action" value="add_new_employee">
        <h3 id="newEmpTitle">New employee #<?= aalh_h($v('emp_no', (string)$firstNew['emp_no'])) ?></h3>
        <p class="muted" id="newEmpLead" style="margin:0 0 8px;"><?= count($newPeople) ?> new employee<?= count($newPeople) === 1 ? '' : 's' ?> on this file. Site: <?= aalh_h((string)($parsed['site_name'] ?? '')) ?>. Every field is required. Banking is required only for direct deposit.</p>
        <div class="emp-grid">
            <div>
                <label for="newEmpNo">Employee number</label>
                <input id="newEmpNo" name="emp_no" required value="<?= aalh_h($v('emp_no', (string)$firstNew['emp_no'])) ?>" readonly>
            </div>
            <div>
                <label for="newEmpName">Name</label>
                <input id="newEmpName" name="name" type="text" maxlength="80" required value="<?= aalh_h($v('name', (string)$firstNew['name'])) ?>">
            </div>
            <div>
                <label for="newEmpSin">SIN</label>
                <input id="newEmpSin" name="sin" inputmode="numeric" maxlength="11" required placeholder="123456789" value="<?= aalh_h($v('sin')) ?>">
            </div>
            <div>
                <label for="newEmpDob">Date of birth</label>
                <input id="newEmpDob" name="dob" type="date" required value="<?= aalh_h($v('dob')) ?>">
            </div>
            <div>
                <label for="newEmpGender">Gender</label>
                <select id="newEmpGender" name="gender" required>
                    <option value="">Select</option>
                    <?php foreach (['male' => 'Male', 'female' => 'Female', 'another' => 'Another'] as $gk => $gl): ?>
                    <option value="<?= $gk ?>" <?= $v('gender') === $gk ? 'selected' : '' ?>><?= $gl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="newEmpPhone">Phone number</label>
                <input id="newEmpPhone" name="phone" type="tel" maxlength="20" required value="<?= aalh_h($v('phone')) ?>">
            </div>
            <div class="full">
                <label for="newEmpEmail">Email address</label>
                <input id="newEmpEmail" name="email" type="email" maxlength="120" required value="<?= aalh_h($v('email')) ?>">
            </div>
            <div>
                <label for="newEmpPosition">Position</label>
                <input id="newEmpPosition" name="position" type="text" maxlength="80" required value="<?= aalh_h($v('position')) ?>">
            </div>
            <div>
                <label for="newEmpDate">Joining date</label>
                <input id="newEmpDate" name="joining_date" type="date" required value="<?= aalh_h($v('joining_date', (string)$firstNew['first_date'])) ?>">
            </div>
            <div>
                <label for="newEmpPayType">Pay type</label>
                <select id="newEmpPayType" name="pay_type" required>
                    <option value="hourly" <?= $v('pay_type', 'hourly') !== 'salaried' ? 'selected' : '' ?>>Hourly</option>
                    <option value="salaried" <?= $v('pay_type') === 'salaried' ? 'selected' : '' ?>>Salaried</option>
                </select>
            </div>
            <div id="newEmpWageWrap">
                <label for="newEmpHourly">Wage rate</label>
                <input id="newEmpHourly" name="hourly_rate" type="number" step="0.01" min="0.01" value="<?= aalh_h($v('hourly_rate', number_format((float)$firstNew['file'], 2, '.', ''))) ?>">
            </div>
            <div id="newEmpSalaryWrap" hidden>
                <label for="newEmpSalary">Salary each pay period</label>
                <input id="newEmpSalary" name="salary_amount" type="number" step="0.01" min="0.01" value="<?= aalh_h($v('salary_amount')) ?>">
            </div>
            <div class="full">
                <label for="newEmpStreet">Home address</label>
                <input id="newEmpStreet" name="street_address" type="text" maxlength="120" required value="<?= aalh_h($v('street_address')) ?>">
            </div>
            <div>
                <label for="newEmpCity">City</label>
                <input id="newEmpCity" name="city" type="text" maxlength="80" required value="<?= aalh_h($v('city')) ?>">
            </div>
            <div>
                <label for="newEmpProv">Province</label>
                <select id="newEmpProv" name="province" required>
                    <option value="">Select</option>
                    <?php foreach ($provinces as $pv): ?>
                    <option value="<?= $pv ?>" <?= strtoupper($v('province')) === $pv ? 'selected' : '' ?>><?= $pv ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="newEmpPostal">Postal code</label>
                <input id="newEmpPostal" name="postal_code" maxlength="7" required placeholder="A1A 1A1" value="<?= aalh_h($v('postal_code')) ?>">
            </div>
            <div>
                <label for="newEmpPayMethod">Payment method</label>
                <select id="newEmpPayMethod" name="payment_method" required>
                    <option value="">Select</option>
                    <?php foreach (['cheque' => 'Cheque', 'cash' => 'Cash', 'etransfer' => 'Etransfer', 'direct_deposit' => 'Direct deposit'] as $mk => $ml): ?>
                    <option value="<?= $mk ?>" <?= $v('payment_method') === $mk ? 'selected' : '' ?>><?= $ml ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="newEmpBank" hidden>
            <div class="emp-grid">
                <div class="full">
                    <label for="newEmpBankName">Bank name</label>
                    <input id="newEmpBankName" name="bank_name" type="text" maxlength="80" value="<?= aalh_h($v('bank_name')) ?>">
                </div>
                <div>
                    <label for="newEmpInst">Institution #</label>
                    <input id="newEmpInst" name="bank_institution" inputmode="numeric" maxlength="3" placeholder="003" value="<?= aalh_h($v('bank_institution')) ?>">
                </div>
                <div>
                    <label for="newEmpTransit">Transit number</label>
                    <input id="newEmpTransit" name="bank_transit" inputmode="numeric" maxlength="5" placeholder="12345" value="<?= aalh_h($v('bank_transit')) ?>">
                </div>
                <div class="full">
                    <label for="newEmpAccount">Account number</label>
                    <input id="newEmpAccount" name="bank_account" inputmode="numeric" maxlength="12" value="<?= aalh_h($v('bank_account')) ?>">
                </div>
            </div>
        </div>
        <p class="muted" style="margin:10px 0 0;">Hourly employees are paid the wage rate for their hours. Salaried employees are paid the salary on the paystub. Their hours stay on the file and are not paid by the hour.</p>
        <div class="rate-actions">
            <button class="rate-save" type="submit">Save employee</button>
            <button class="rate-cancel" type="button" id="newEmpCancel">Skip for now</button>
        </div>
    </form>
</div>
<?php endif; ?>
<script>
document.querySelectorAll('.rate-open').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('rateUserId').value = btn.getAttribute('data-user') || '';
        document.getElementById('rateTitle').textContent = 'Change rate — ' + (btn.getAttribute('data-name') || '');
        document.getElementById('rateNow').textContent = 'Profile now $' + (btn.getAttribute('data-profile') || '') + '. File rate $' + (btn.getAttribute('data-file') || '') + '.';
        var firstDay = btn.getAttribute('data-effective') || '';
        var fileRate = btn.getAttribute('data-file') || '';
        document.getElementById('rateHourly').value = fileRate;
        document.getElementById('rateFileRate').value = fileRate;
        document.getElementById('rateEffective').value = firstDay;
        document.getElementById('rateFirstDiff').value = firstDay;
        document.getElementById('rateSuggest').textContent = firstDay
            ? ('Suggested date is the first day this file rate differs: ' + firstDay + '.')
            : '';
        document.getElementById('rateBox').hidden = false;
        document.getElementById('rateHourly').focus();
    });
});
var rateCancel = document.getElementById('rateCancel');
if (rateCancel) {
    rateCancel.addEventListener('click', function () {
        document.getElementById('rateBox').hidden = true;
    });
}
function aalhSyncNewEmp() {
    var payType = document.getElementById('newEmpPayType');
    var method = document.getElementById('newEmpPayMethod');
    if (!payType || !method) return;
    var salaried = payType.value === 'salaried';
    var wageWrap = document.getElementById('newEmpWageWrap');
    var salaryWrap = document.getElementById('newEmpSalaryWrap');
    var wage = document.getElementById('newEmpHourly');
    var salary = document.getElementById('newEmpSalary');
    if (wageWrap) wageWrap.hidden = salaried;
    if (salaryWrap) salaryWrap.hidden = !salaried;
    if (wage) wage.required = !salaried;
    if (salary) salary.required = salaried;
    var direct = method.value === 'direct_deposit';
    var bank = document.getElementById('newEmpBank');
    if (bank) bank.hidden = !direct;
    ['newEmpBankName', 'newEmpInst', 'newEmpTransit', 'newEmpAccount'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.required = direct;
    });
}
function aalhFillNewEmp(btn) {
    var box = document.getElementById('newEmpBox');
    if (!box) return;
    var emp = btn.getAttribute('data-emp') || '';
    var rate = btn.getAttribute('data-rate') || '';
    document.getElementById('newEmpNo').value = emp;
    document.getElementById('newEmpTitle').textContent = 'New employee #' + emp;
    document.getElementById('newEmpName').value = btn.getAttribute('data-name') || '';
    document.getElementById('newEmpHourly').value = rate;
    document.getElementById('newEmpSalary').value = '';
    document.getElementById('newEmpPayType').value = 'hourly';
    document.getElementById('newEmpDate').value = btn.getAttribute('data-date') || '';
    ['newEmpSin', 'newEmpDob', 'newEmpPhone', 'newEmpEmail', 'newEmpPosition', 'newEmpStreet', 'newEmpCity', 'newEmpPostal', 'newEmpBankName', 'newEmpInst', 'newEmpTransit', 'newEmpAccount'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.value = '';
    });
    document.getElementById('newEmpGender').value = '';
    document.getElementById('newEmpProv').value = '';
    document.getElementById('newEmpPayMethod').value = '';
    aalhSyncNewEmp();
    box.hidden = false;
    document.getElementById('newEmpName').focus();
}
var newEmpPayType = document.getElementById('newEmpPayType');
var newEmpPayMethod = document.getElementById('newEmpPayMethod');
if (newEmpPayType) newEmpPayType.addEventListener('change', aalhSyncNewEmp);
if (newEmpPayMethod) newEmpPayMethod.addEventListener('change', aalhSyncNewEmp);
aalhSyncNewEmp();
document.querySelectorAll('.new-emp-open').forEach(function (btn) {
    btn.addEventListener('click', function () { aalhFillNewEmp(btn); });
});
var newEmpCancel = document.getElementById('newEmpCancel');
if (newEmpCancel) {
    newEmpCancel.addEventListener('click', function () {
        document.getElementById('newEmpBox').hidden = true;
    });
}
var newEmpBox = document.getElementById('newEmpBox');
if (newEmpBox && !newEmpBox.hidden) {
    var nameBox = document.getElementById('newEmpName');
    if (nameBox) nameBox.focus();
}
</script>
</body>
</html>
