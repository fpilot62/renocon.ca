<?php
/** Path: /daily_report_view.php
 * Marker: page-rollback-20261019-list — restore only ticked Daily Activity deletes.
 */
require_once 'db.php';
require_once 'engine/menu_generator.php';
require_once 'engine/page_rollback_lib.php';
require_once 'engine/dal_period_lines.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/engine/orgscope_guard.php'; osg_page(['level' => 30, 'fence' => ['report_id' => 'daily_report', 'restaurant_id' => 'site', 'rid' => 'site', 'site_id' => 'site']]); // org-scope audit Nov 2026

$current_org_id = (int)($_SESSION['org_id'] ?? 0);
$user_lvl       = (int)($_SESSION['user_level'] ?? 99);
$user_id        = (int)($_SESSION['user_id'] ?? 0);

if (!$user_id || $user_lvl > 30) {
    header("Location: kiosk.php?err=access_denied");
    exit;
}

$is_super = ($user_lvl <= 10);
$is_admin = ($user_lvl <= 20);

$DAL_UPLOAD_ROOT = __DIR__ . '/uploads/daily_reports';
$DAL_ALLOWED_EXT = ['pdf','png','jpg','jpeg','webp','gif'];

function dal_ensure_dirs($root) {
    dal_ensure_writable_dir($root);
}

function dal_ensure_writable_dir($dir) {
    $dir = rtrim((string)$dir, '/');
    if ($dir === '') {
        return false;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0775);
    }
    return is_dir($dir) && is_writable($dir);
}

function dal_upload_field_error($file) {
    if (!is_array($file)) {
        return 'Choose a PDF or image to upload.';
    }
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_OK && !empty($file['tmp_name'])) {
        return '';
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'That file is too large. Use a PDF under 64 MB.';
    }
    if ($err === UPLOAD_ERR_PARTIAL) {
        return 'The file did not finish uploading. Try Save again.';
    }
    if ($err === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        return '';
    }
    return 'Could not receive the file (upload error ' . $err . ').';
}

function dal_kind_ok($kind) {
    return $kind === 'pos' || $kind === 'production' || $kind === 'paidout';
}

function dal_ext_ok($name, $allowed) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true);
}

function dal_detect_format($path) {
    $fallback = ['ext' => '', 'mime' => 'application/octet-stream', 'label' => 'File'];
    if (!is_file($path) || !is_readable($path)) {
        return $fallback;
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return $fallback;
    }
    $head = fread($fh, 16) ?: '';
    fclose($fh);
    if (strncmp($head, '%PDF', 4) === 0) {
        return ['ext' => 'pdf', 'mime' => 'application/pdf', 'label' => 'PDF'];
    }
    if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) {
        return ['ext' => 'png', 'mime' => 'image/png', 'label' => 'PNG'];
    }
    if (strlen($head) >= 3 && ord($head[0]) === 0xFF && ord($head[1]) === 0xD8 && ord($head[2]) === 0xFF) {
        return ['ext' => 'jpg', 'mime' => 'image/jpeg', 'label' => 'JPG'];
    }
    if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
        return ['ext' => 'gif', 'mime' => 'image/gif', 'label' => 'GIF'];
    }
    if (strlen($head) >= 12 && strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
        return ['ext' => 'webp', 'mime' => 'image/webp', 'label' => 'WEBP'];
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'pdf' => ['ext' => 'pdf', 'mime' => 'application/pdf', 'label' => 'PDF'],
        'png' => ['ext' => 'png', 'mime' => 'image/png', 'label' => 'PNG'],
        'jpg' => ['ext' => 'jpg', 'mime' => 'image/jpeg', 'label' => 'JPG'],
        'jpeg' => ['ext' => 'jpg', 'mime' => 'image/jpeg', 'label' => 'JPG'],
        'gif' => ['ext' => 'gif', 'mime' => 'image/gif', 'label' => 'GIF'],
        'webp' => ['ext' => 'webp', 'mime' => 'image/webp', 'label' => 'WEBP'],
    ];
    return $map[$ext] ?? $fallback;
}

function dal_site_allowed($rid, $is_super, $assigned_csv) {
    $rid = (int)$rid;
    if ($rid <= 0) return false;
    if ($is_super) return true;
    $ids = array_filter(array_map('intval', explode(',', (string)$assigned_csv)));
    return in_array($rid, $ids, true);
}

function dal_dest_dir($root, $rid, $ym) {
    return rtrim($root, '/') . '/' . (int)$rid . '/' . $ym;
}

function dal_dest_base($kind, $date, $period = '') {
    $prefix = ($date !== '' ? $date . '_' : '');
    $p = strtolower((string)$period);
    $tag = '';
    if ($p === 'weekly') {
        $tag = 'weekly_';
    } elseif ($p === 'monthly') {
        $tag = 'monthly_';
    }
    if ($kind === 'production') {
        return $prefix . $tag . 'production_report';
    }
    if ($kind === 'paidout') {
        return $prefix . $tag . 'paidout_report';
    }
    return $prefix . $tag . 'pos_report';
}

function dal_is_sunday($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        return false;
    }
    $ts = strtotime($date . ' 12:00:00');
    return $ts !== false && (int)date('w', $ts) === 0;
}

function dal_is_month_end($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        return false;
    }
    return $date === date('Y-m-t', strtotime($date . ' 12:00:00'));
}

function dal_row_period($remarks) {
    $rmk = (string)$remarks;
    if (preg_match('/week_split/i', $rmk)) {
        return 'weekly';
    }
    if (preg_match('/mtd_split/i', $rmk)) {
        return 'monthly';
    }
    return 'daily';
}

function dal_period_remark($period, $date) {
    if ($period === 'weekly') {
        return 'week_split ' . $date;
    }
    if ($period === 'monthly') {
        return 'mtd_split ' . $date;
    }
    return '';
}

function dal_find_type_row(PDO $pdo, $rest_id, $r_date, $period) {
    $st = $pdo->prepare("SELECT report_id, pos_sales, paid_out, remarks FROM daily_report WHERE restaurant_id = ? AND report_date = ?");
    $st->execute([(int)$rest_id, $r_date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $er) {
        if (dal_row_period($er['remarks'] ?? '') === $period) {
            return $er;
        }
    }
    return null;
}

function dal_upsert_type_row(PDO $pdo, $rest_id, $r_date, $period, $sales, $paidOut) {
    $period = in_array($period, ['daily', 'weekly', 'monthly'], true) ? $period : 'daily';
    $exist = dal_find_type_row($pdo, $rest_id, $r_date, $period);
    $note = dal_period_remark($period, $r_date);
    if ($exist) {
        $pdo->prepare("UPDATE daily_report SET pos_sales = ?, paid_out = ?, remarks = ? WHERE report_id = ?")
            ->execute([(float)$sales, (float)$paidOut, $note, (int)$exist['report_id']]);
        return (int)$exist['report_id'];
    }
    $pdo->prepare("INSERT INTO daily_report (restaurant_id, report_date, pos_sales, paid_out, remarks) VALUES (?, ?, ?, ?, ?)")
        ->execute([(int)$rest_id, $r_date, (float)$sales, (float)$paidOut, $note]);
    return (int)$pdo->lastInsertId();
}

function dal_week_sunday($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) {
        return '';
    }
    $ts = strtotime($date . ' 12:00:00');
    if ($ts === false) {
        return '';
    }
    $w = (int)date('w', $ts);
    $add = (7 - $w) % 7;
    return date('Y-m-d', strtotime('+' . $add . ' days', $ts));
}

function dal_ensure_paid_out_column(PDO $pdo) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM daily_report LIKE 'paid_out'");
        if ($chk && $chk->fetch()) {
            return;
        }
        $pdo->exec("ALTER TABLE daily_report ADD COLUMN paid_out DECIMAL(11,2) NOT NULL DEFAULT 0 AFTER pos_sales");
    } catch (Exception $e) {
        // keep page usable if ALTER is denied
    }
}

function dal_write_meta($pathNoExt, array $meta) {
    @file_put_contents($pathNoExt . '.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function dal_read_meta($pathNoExt) {
    $metaFile = $pathNoExt . '.json';
    if (!is_file($metaFile)) return null;
    $j = json_decode((string)@file_get_contents($metaFile), true);
    return is_array($j) ? $j : null;
}

function dal_find_existing($root, $rid, $ym, $kind, $date = '', $period = '') {
    $dir = dal_dest_dir($root, $rid, $ym);
    if (!is_dir($dir)) return null;
    $base = dal_dest_base($kind, $date, $period);
    $file = null;
    foreach (glob($dir . '/' . $base . '.*') ?: [] as $p) {
        if (strtolower(pathinfo($p, PATHINFO_EXTENSION)) === 'json') continue;
        $file = $p;
        break;
    }
    if (!$file) return null;
    $noExt = preg_replace('/\.[^.]+$/', '', $file);
    $meta = dal_read_meta($noExt) ?: [];
    $fmt = dal_detect_format($file);
    $bn = basename($file);
    $isPeriodNamed = (strpos($bn, 'weekly_') !== false || strpos($bn, 'monthly_') !== false);
    return [
        'path' => $file,
        'orig' => $meta['orig'] ?? $bn,
        'size' => (int)($meta['size'] ?? filesize($file)),
        'kind' => $kind,
        'ym' => $ym,
        'date' => $date,
        'period' => $period !== '' ? $period : (string)($meta['period'] ?? ''),
        'daily' => ($date !== '' && strpos($bn, (string)$date) === 0 && !$isPeriodNamed),
        'format' => $fmt,
        'unclear' => !empty($meta['unclear']),
        'unclear_reason' => (string)($meta['unclear_reason'] ?? ''),
        'sales_typed' => !empty($meta['sales_typed']),
        'sales_typed_amount' => $meta['sales_typed_amount'] ?? null,
        'sales_typed_at' => (string)($meta['sales_typed_at'] ?? ''),
    ];
}

/** The POS file that belongs to a line: Weekly/Monthly POS for period lines, Daily POS for daily lines. */
function dal_line_pos_file($root, $rid, $date, $period) {
    if ($period === 'weekly' || $period === 'monthly') {
        return dal_find_period_file($root, $rid, $date, 'pos', $period);
    }
    return dal_find_for_row($root, $rid, $date, 'pos');
}

function dal_find_for_row($root, $rid, $reportDate, $kind) {
    $ym = substr((string)$reportDate, 0, 7);
    return dal_find_existing($root, $rid, $ym, $kind, $reportDate, '');
}

function dal_find_period_file($root, $rid, $endDate, $kind, $period) {
    $period = ($period === 'monthly') ? 'monthly' : 'weekly';
    $ym = substr((string)$endDate, 0, 7);
    $found = dal_find_existing($root, $rid, $ym, $kind, $endDate, $period);
    if ($found && !empty($found['path'])) {
        return $found;
    }
    $legacy = dal_find_existing($root, $rid, $ym, $kind, '', '');
    if (!$legacy || empty($legacy['path'])) {
        return null;
    }
    $noExt = preg_replace('/\.[^.]+$/', '', $legacy['path']);
    $meta = dal_read_meta($noExt) ?: [];
    $metaPeriod = strtolower((string)($meta['period'] ?? ''));
    $metaTo = (string)($meta['period_to'] ?? '');
    if ($metaPeriod === $period && ($metaTo === '' || $metaTo === $endDate)) {
        $legacy['period'] = $period;
        return $legacy;
    }
    if ($period === 'weekly' && $metaPeriod === '' && $kind === 'pos') {
        $legacy['period'] = 'weekly';
        return $legacy;
    }
    return null;
}

function dal_has_pos_file($root, $rid, $date) {
    $found = dal_find_for_row($root, $rid, $date, 'pos');
    return $found && !empty($found['path']) && is_file($found['path']) && !empty($found['daily']);
}

function dal_pos_required_error($root, $rid, $date) {
    if (dal_has_pos_file($root, $rid, $date)) {
        return '';
    }
    return 'Daily POS is required.';
}

function dal_store_uploaded($root, $rid, $ym, $kind, $date, $tmp, $origName, $allowed, $period = '') {
    if (!is_uploaded_file($tmp) && !is_file($tmp)) {
        return ['ok' => false, 'error' => 'Missing upload.'];
    }
    if (!dal_kind_ok($kind)) {
        return ['ok' => false, 'error' => 'Use a PDF or image (png, jpg, webp, gif).'];
    }
    $fmt = dal_detect_format($tmp);
    $nameExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($nameExt === 'jpeg') $nameExt = 'jpg';
    if ($fmt['ext'] === '' && dal_ext_ok($origName, $allowed)) {
        $fmt = dal_detect_format($tmp);
        if ($fmt['ext'] === '') {
            $guess = [
                'pdf' => ['ext' => 'pdf', 'mime' => 'application/pdf', 'label' => 'PDF'],
                'png' => ['ext' => 'png', 'mime' => 'image/png', 'label' => 'PNG'],
                'jpg' => ['ext' => 'jpg', 'mime' => 'image/jpeg', 'label' => 'JPG'],
                'jpeg' => ['ext' => 'jpg', 'mime' => 'image/jpeg', 'label' => 'JPG'],
                'gif' => ['ext' => 'gif', 'mime' => 'image/gif', 'label' => 'GIF'],
                'webp' => ['ext' => 'webp', 'mime' => 'image/webp', 'label' => 'WEBP'],
            ];
            $fmt = $guess[$nameExt] ?? ['ext' => '', 'mime' => 'application/octet-stream', 'label' => 'File'];
        }
    }
    if ($fmt['ext'] === '' || !in_array($fmt['ext'], $allowed, true)) {
        return ['ok' => false, 'error' => 'Use a PDF or image (png, jpg, webp, gif).'];
    }
    $ext = $fmt['ext'];
    $dir = dal_dest_dir($root, $rid, $ym);
    if (!dal_ensure_writable_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not save the file. Upload folder is not writable.'];
    }
    $base = dal_dest_base($kind, $date, $period);
    foreach (glob($dir . '/' . $base . '.*') ?: [] as $old) {
        @unlink($old);
    }
    $dest = $dir . '/' . $base . '.' . $ext;
    $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
    if (!$moved) {
        $moved = @copy($tmp, $dest);
        if ($moved && !is_uploaded_file($tmp)) @unlink($tmp);
    }
    if (!$moved || !is_file($dest)) {
        return ['ok' => false, 'error' => 'Could not save the file.'];
    }
    @chmod($dest, 0664);
    $savedFmt = dal_detect_format($dest);
    if ($savedFmt['ext'] !== '' && $savedFmt['ext'] !== $ext) {
        $fixed = $dir . '/' . $base . '.' . $savedFmt['ext'];
        @rename($dest, $fixed);
        if (is_file($fixed)) {
            $dest = $fixed;
            $ext = $savedFmt['ext'];
            $fmt = $savedFmt;
        }
    }
    $meta = [
        'orig' => $origName,
        'size' => (int)filesize($dest),
        'kind' => $kind,
        'ym' => $ym,
        'date' => $date,
        'period' => $period,
        'rid' => (int)$rid,
        'format' => $fmt['label'],
        'mime' => $fmt['mime'],
        'saved_at' => date('c'),
    ];
    dal_write_meta($dir . '/' . $base, $meta);
    require_once __DIR__ . '/engine/pos_report_import.php';
    if ($kind === 'pos') {
        $posRead = pri_read_pos_file($dest);
        return [
            'ok' => true,
            'orig' => $origName,
            'format' => $fmt['label'],
            'dest' => $dest,
            'unclear' => !empty($posRead['unclear']),
            'message' => $posRead['message'] ?? '',
        ];
    }
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        $q = pri_file_image_quality($dest);
        if (!empty($q['unclear'])) {
            pri_write_unclear_meta($dest, [
                'unclear' => true,
                'unclear_reason' => $q['reason'] ?: 'Photo is not clear',
            ]);
        }
    }
    return ['ok' => true, 'orig' => $origName, 'format' => $fmt['label'], 'dest' => $dest];
}

function dal_period_verify_path($root, $rid, $period, $endDate) {
    $ym = substr((string)$endDate, 0, 7);
    $dir = dal_dest_dir($root, $rid, $ym);
    $safePeriod = ($period === 'monthly') ? 'monthly' : 'weekly';
    return $dir . '/period_' . $safePeriod . '_' . $endDate . '.json';
}

function dal_write_period_verify($root, $rid, $period, $from, $to, array $verify) {
    $path = dal_period_verify_path($root, $rid, $period, $to);
    $dir = dirname($path);
    if (!is_dir($dir) && function_exists('dal_ensure_writable_dir') && !dal_ensure_writable_dir($dir)) {
        return false;
    }
    $payload = [
        'period' => ($period === 'monthly') ? 'monthly' : 'weekly',
        'from' => $from,
        'to' => $to,
        'rid' => (int)$rid,
        'period_total' => $verify['period_total'],
        'days_sum' => $verify['days_sum'],
        'diff' => $verify['diff'],
        'status' => $verify['status'],
        'saved_at' => date('c'),
    ];
    return @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false;
}

function dal_read_period_verify($root, $rid, $period, $endDate) {
    $path = dal_period_verify_path($root, $rid, $period, $endDate);
    if (!is_file($path)) {
        return null;
    }
    $j = json_decode((string)@file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

function dal_period_days_sum(PDO $pdo, $rid, $from, $to) {
    // Daily lines only; Weekly/Monthly lines are never added in (engine/dal_period_lines.php).
    return dal_daily_lines_sum($pdo, $rid, (string)$from, (string)$to);
}

function dal_period_row_sales(PDO $pdo, $rid, $endDate, $period) {
    // dal-weekly-pos-zero-20261015: use the period row Sales $ when parsed POS is 0.
    $st = $pdo->prepare("SELECT pos_sales, remarks FROM daily_report WHERE restaurant_id = ? AND report_date = ?");
    $st->execute([(int)$rid, $endDate]);
    $want = ($period === 'monthly') ? 'monthly' : 'weekly';
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $er) {
        if (dal_row_period($er['remarks'] ?? '') === $want) {
            return round((float)$er['pos_sales'], 2);
        }
    }
    return null;
}

function dal_collect_period_windows(PDO $pdo, $root, array $reports) {
    $windows = [];
    foreach ($reports as $r) {
        $rmk = (string)($r['remarks'] ?? '');
        $rid = (int)$r['restaurant_id'];
        $period = '';
        $end = '';
        if (preg_match('/week_split\s+(\d{4}-\d{2}-\d{2})/i', $rmk, $m)) {
            $period = 'weekly';
            $end = $m[1];
        } elseif (preg_match('/mtd_split\s+(\d{4}-\d{2}-\d{2})/i', $rmk, $m)) {
            $period = 'monthly';
            $end = $m[1];
        } elseif (preg_match('/week_split/i', $rmk)) {
            $period = 'weekly';
            $end = substr((string)$r['report_date'], 0, 10);
        } elseif (preg_match('/mtd_split/i', $rmk)) {
            $period = 'monthly';
            $end = substr((string)$r['report_date'], 0, 10);
        }
        if ($period === '' || $end === '') {
            continue;
        }
        $key = $rid . '|' . $period . '|' . $end;
        if (!isset($windows[$key])) {
            $from = ($period === 'weekly')
                ? date('Y-m-d', strtotime('-6 days', strtotime($end)))
                : (substr($end, 0, 8) . '01');
            $windows[$key] = [
                'rid' => $rid,
                'period' => $period,
                'from' => $from,
                'to' => $end,
                'stored' => dal_read_period_verify($root, $rid, $period, $end),
            ];
        }
    }
    $seenYm = [];
    foreach ($reports as $r) {
        $rid = (int)$r['restaurant_id'];
        $ym = substr((string)$r['report_date'], 0, 7);
        $seenYm[$rid . '|' . $ym] = [$rid, $ym];
        $prevTs = strtotime($ym . '-01 -1 day');
        if ($prevTs) {
            $prev = date('Y-m', $prevTs);
            $seenYm[$rid . '|' . $prev] = [$rid, $prev];
        }
    }
    foreach ($seenYm as $pair) {
        $rid = (int)$pair[0];
        $ym = $pair[1];
        $dir = dal_dest_dir($root, $rid, $ym);
        if (!is_dir($dir)) {
            continue;
        }
        foreach (glob($dir . '/period_*.json') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j) || empty($j['to']) || empty($j['period'])) {
                continue;
            }
            $period = ($j['period'] === 'monthly') ? 'monthly' : 'weekly';
            $end = (string)$j['to'];
            $key = $rid . '|' . $period . '|' . $end;
            if (!isset($windows[$key])) {
                $windows[$key] = [
                    'rid' => $rid,
                    'period' => $period,
                    'from' => (string)($j['from'] ?? ''),
                    'to' => $end,
                    'stored' => $j,
                ];
            } elseif (empty($windows[$key]['stored'])) {
                $windows[$key]['stored'] = $j;
            }
        }
        $monthly = dal_find_existing($root, $rid, $ym, 'pos', '');
        if ($monthly && !empty($monthly['path'])) {
            $noExt = preg_replace('/\.[^.]+$/', '', $monthly['path']);
            $meta = dal_read_meta($noExt);
            if (is_array($meta) && isset($meta['period_total']) && $meta['period_total'] !== '' && !empty($meta['period']) && !empty($meta['period_to'])) {
                $period = ($meta['period'] === 'monthly') ? 'monthly' : 'weekly';
                $end = (string)$meta['period_to'];
                $key = $rid . '|' . $period . '|' . $end;
                if (!isset($windows[$key])) {
                    $windows[$key] = [
                        'rid' => $rid,
                        'period' => $period,
                        'from' => (string)($meta['period_from'] ?? ''),
                        'to' => $end,
                        'stored' => $meta,
                    ];
                } elseif (empty($windows[$key]['stored'])) {
                    $windows[$key]['stored'] = $meta;
                }
            }
        }
    }
    $out = [];
    if (!function_exists('sip_period_verify')) {
        return $out;
    }
    foreach ($windows as $key => $w) {
        $to = (string)$w['to'];
        $from = (string)$w['from'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = ($w['period'] === 'weekly')
                ? date('Y-m-d', strtotime('-6 days', strtotime($to)))
                : (substr($to, 0, 8) . '01');
        }
        $stored = is_array($w['stored'] ?? null) ? $w['stored'] : null;
        $parsed = null;
        if ($stored !== null && isset($stored['period_total']) && $stored['period_total'] !== '') {
            $parsed = round((float)$stored['period_total'], 2);
        }
        $rowSales = dal_period_row_sales($pdo, (int)$w['rid'], $to, $w['period']);
        $periodTotal = (abs((float)$parsed) >= 0.005) ? (float)$parsed : $rowSales;
        if ($periodTotal === null || abs((float)$periodTotal) < 0.005) {
            continue;
        }
        $daysSum = dal_period_days_sum($pdo, (int)$w['rid'], $from, $to);
        $title = ($w['period'] === 'monthly') ? 'Monthly' : 'Weekly';
        $verify = dal_period_compare_apply(sip_period_verify((float)$periodTotal, $daysSum, $title), $title);
        if (abs((float)$parsed) < 0.005) {
            dal_write_period_verify($root, (int)$w['rid'], $w['period'], $from, $to, $verify);
        }
        $out[$key] = [
            'rid' => (int)$w['rid'],
            'period' => $w['period'],
            'from' => $from,
            'to' => $to,
            'verify' => $verify,
        ];
    }
    return $out;
}

function dal_period_compare_apply(array $verify, $periodTitle) {
    $pos = round((float)($verify['period_total'] ?? 0), 2);
    $daily = round((float)($verify['days_sum'] ?? 0), 2);
    $diff = round((float)($verify['diff'] ?? ($daily - $pos)), 2);
    $posLab = trim((string)$periodTitle);
    if ($posLab === '') {
        $posLab = 'Period';
    }
    if (!preg_match('/\bPOS\b/i', $posLab)) {
        $posLab .= ' POS';
    }
    if (abs($diff) <= 0.01) {
        $verify['status'] = 'match';
        $verify['label'] = 'MATCH';
        $verify['text'] = 'Daily sales $' . number_format($daily, 2) . ' match ' . $posLab . ' $' . number_format($pos, 2) . '.';
        return $verify;
    }
    $by = number_format(abs($diff), 2);
    if ($diff > 0) {
        $verify['status'] = 'over';
        $verify['label'] = 'OVER $' . $by;
        $verify['text'] = 'Daily sales $' . number_format($daily, 2) . ' are OVER ' . $posLab . ' $' . number_format($pos, 2) . ' by $' . $by;
    } else {
        $verify['status'] = 'short';
        $verify['label'] = 'SHORT $' . $by;
        $verify['text'] = 'Daily sales $' . number_format($daily, 2) . ' are SHORT of ' . $posLab . ' $' . number_format($pos, 2) . ' by $' . $by;
    }
    return $verify;
}

function dal_period_verify_bar(array $info) {
    $v = is_array($info['verify'] ?? null) ? $info['verify'] : [];
    $title = ((string)($info['period'] ?? '') === 'monthly') ? 'Monthly' : (((string)($info['period'] ?? '') === 'weekly') ? 'Weekly' : 'Daily');
    $v = dal_period_compare_apply($v, $title);
    $cls = 'dal-period-verify dal-period-verify-' . $v['status'];
    $text = htmlspecialchars((string)($v['text'] ?? ''));
    $text = str_replace('OVER', '<span class="dal-mismatch">OVER</span>', $text);
    $text = str_replace('SHORT', '<span class="dal-mismatch">SHORT</span>', $text);
    return '<tr class="' . htmlspecialchars($cls) . '" data-rid="' . (int)$info['rid'] . '" data-end="' . htmlspecialchars($info['to']) . '" data-period="' . htmlspecialchars($info['period']) . '">'
        . '<td colspan="13"><div class="dal-period-verify-inner">' . $text . '</div></td></tr>';
}

dal_ensure_dirs($DAL_UPLOAD_ROOT);
dal_ensure_paid_out_column($pdo);

$assigned_csv = '';
if (!$is_super) {
    $u_stmt = $pdo->prepare("SELECT assigned_site_ids FROM users WHERE id = ?");
    $u_stmt->execute([$user_id]);
    $assigned_csv = $u_stmt->fetchColumn() ?? '0';
}

if ($is_super) {
    $all_sites = $pdo->query("SELECT SId, nick_name, org_id FROM sites ORDER BY nick_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT SId, nick_name, org_id FROM sites WHERE org_id = ? AND FIND_IN_SET(SId, ?) ORDER BY nick_name ASC");
    $stmt->execute([(int)$current_org_id, (string)$assigned_csv]);
    $all_sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['action']) && $_GET['action'] === 'dl') {
    $rid  = (int)($_GET['rid'] ?? 0);
    $ym   = preg_replace('/[^0-9\-]/', '', (string)($_GET['ym'] ?? ''));
    $kind = (string)($_GET['kind'] ?? '');
    $date = preg_replace('/[^0-9\-]/', '', (string)($_GET['date'] ?? ''));
    if (!dal_kind_ok($kind) || !preg_match('/^\d{4}-\d{2}$/', $ym) || !dal_site_allowed($rid, $is_super, $assigned_csv)) {
        http_response_code(404);
        exit('File not found.');
    }
    $period = strtolower(trim((string)($_GET['period'] ?? '')));
    if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
        $period = 'daily';
    }
    if ($period === 'weekly' || $period === 'monthly') {
        $end = ($date !== '') ? $date : (preg_match('/^\d{4}-\d{2}$/', $ym) ? $ym . '-01' : '');
        $found = $end !== '' ? dal_find_period_file($DAL_UPLOAD_ROOT, $rid, $end, $kind, $period) : null;
    } else {
        $found = $date !== ''
            ? dal_find_for_row($DAL_UPLOAD_ROOT, $rid, $date, $kind)
            : dal_find_existing($DAL_UPLOAD_ROOT, $rid, $ym, $kind, '');
    }
    if (!$found || empty($found['path']) || !is_file($found['path'])) {
        http_response_code(404);
        exit('File not found.');
    }
    $realRoot = realpath($DAL_UPLOAD_ROOT);
    $realFile = realpath($found['path']);
    if (!$realRoot || !$realFile || strpos($realFile, $realRoot) !== 0) {
        http_response_code(404);
        exit('File not found.');
    }
    $fmt = dal_detect_format($realFile);
    $mime = $fmt['mime'] ?: 'application/octet-stream';
    $downloadName = $found['orig'] ?? basename($realFile);
    if ($fmt['ext'] !== '') {
        $downloadName = preg_replace('/\.[^.]+$/', '', $downloadName) . '.' . $fmt['ext'];
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($realFile));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($realFile);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'upload') {
            $kind = (string)($_POST['kind'] ?? '');
            $rid  = (int)($_POST['restaurant_id'] ?? 0);
            $rDate = trim((string)($_POST['report_date'] ?? ''));
            if (!dal_kind_ok($kind) || $rid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rDate)) {
                echo json_encode(['success' => false, 'error' => 'Missing site, date, or file type.']);
                exit;
            }
            if (!dal_site_allowed($rid, $is_super, $assigned_csv)) {
                echo json_encode(['success' => false, 'error' => 'You cannot upload to that site.']);
                exit;
            }
            if (empty($_FILES['file']['tmp_name']) || (int)$_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Choose a PDF or image to upload.']);
                exit;
            }
            $ym = substr($rDate, 0, 7);
            $res = dal_store_uploaded($DAL_UPLOAD_ROOT, $rid, $ym, $kind, $rDate, $_FILES['file']['tmp_name'], $_FILES['file']['name'], $DAL_ALLOWED_EXT);
            if (!$res['ok']) {
                echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Upload failed.']);
                exit;
            }
            $payload = ['success' => true, 'orig' => $res['orig']];
            if ($kind === 'production' && !empty($res['dest'])) {
                require_once __DIR__ . '/engine/production_usage_import.php';
                $imp = pui_import_production_pdf($pdo, $res['dest'], $rid, $rDate);
                $payload['usage_imported'] = (int)($imp['written'] ?? 0);
                $payload['usage_ok'] = !empty($imp['ok']);
                $payload['usage_message'] = $imp['ok']
                    ? ($imp['message'] ?? '')
                    : (string)($imp['error'] ?? 'Production file saved, but Usage was not updated.');
                $payload['usage_url'] = 'inventory/usage.php?rid=' . (int)$rid . '&usage_date=' . rawurlencode($rDate);
                if (!empty($imp['ok'])) {
                    $payload['usage_url'] .= '&imported=' . (int)$imp['written'];
                }
            }
            echo json_encode($payload);
            exit;
        }

        if ($_POST['action'] === 'save_day') {
            @set_time_limit(180);
            $r_date  = trim((string)($_POST['report_date'] ?? ''));
            $rest_id = (int)($_POST['restaurant_id'] ?? 0);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $r_date) || $rest_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Pick a site and day.']);
                exit;
            }
            if (!dal_site_allowed($rest_id, $is_super, $assigned_csv)) {
                echo json_encode(['success' => false, 'error' => 'You cannot add a log for that site.']);
                exit;
            }
            $mode = ((string)($_POST['mode'] ?? 'create') === 'edit') ? 'edit' : 'create';
            $repReq = (int)($_POST['report_id'] ?? 0);
            $periodRaw = strtolower(trim((string)($_POST['period'] ?? '')));
            $monthEndFlag = in_array(strtolower(trim((string)($_POST['month_end'] ?? ''))), ['1', 'on', 'true', 'yes'], true);
            if ($periodRaw === '') {
                $periodRaw = $monthEndFlag ? 'monthly' : 'daily';
            }
            if (!in_array($periodRaw, ['daily', 'weekly', 'monthly'], true)) {
                $periodRaw = 'daily';
            }
            // One Save can upsert Daily + Weekly + Monthly when those POS files are attached.
            if ($mode === 'edit' && $repReq > 0) {
                $look = $pdo->prepare("SELECT report_id, restaurant_id, report_date, pos_sales, paid_out, remarks FROM daily_report WHERE report_id = ?");
                $look->execute([$repReq]);
                $byId = $look->fetch(PDO::FETCH_ASSOC);
                if ($byId) {
                    $rest_id = (int)$byId['restaurant_id'];
                    $r_date = (string)$byId['report_date'];
                    if (!dal_site_allowed($rest_id, $is_super, $assigned_csv)) {
                        echo json_encode(['success' => false, 'error' => 'You cannot edit that site.']);
                        exit;
                    }
                }
            }

            $ym = substr($r_date, 0, 7);
            $notes = [];
            $stored = [];

            foreach (['pos' => 'pos_file', 'production' => 'prod_file', 'paidout' => 'paidout_file'] as $kind => $fkey) {
                if (empty($_FILES[$fkey])) {
                    continue;
                }
                $upErr = dal_upload_field_error($_FILES[$fkey]);
                if ($upErr !== '') {
                    echo json_encode(['success' => false, 'error' => $upErr]);
                    exit;
                }
                if (empty($_FILES[$fkey]['tmp_name']) || (int)($_FILES[$fkey]['error'] ?? 1) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $res = dal_store_uploaded($DAL_UPLOAD_ROOT, $rest_id, $ym, $kind, $r_date, $_FILES[$fkey]['tmp_name'], $_FILES[$fkey]['name'], $DAL_ALLOWED_EXT, '');
                if (!$res['ok']) {
                    echo json_encode(['success' => false, 'error' => ($res['error'] ?? 'Could not save ' . $kind . ' file.')]);
                    exit;
                }
                $stored[$kind] = $res;
            }
            foreach (['weekly' => 'weekly_pos_file', 'monthly' => 'monthly_pos_file'] as $pKind => $fkey) {
                if (empty($_FILES[$fkey]) || empty($_FILES[$fkey]['tmp_name']) || (int)($_FILES[$fkey]['error'] ?? 1) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $upErr = dal_upload_field_error($_FILES[$fkey]);
                if ($upErr !== '') {
                    echo json_encode(['success' => false, 'error' => $upErr]);
                    exit;
                }
                $res = dal_store_uploaded($DAL_UPLOAD_ROOT, $rest_id, $ym, 'pos', $r_date, $_FILES[$fkey]['tmp_name'], $_FILES[$fkey]['name'], $DAL_ALLOWED_EXT, $pKind);
                if (!$res['ok']) {
                    echo json_encode(['success' => false, 'error' => ($res['error'] ?? 'Could not save ' . $pKind . ' POS.')]);
                    exit;
                }
                $stored[$pKind . '_pos'] = $res;
            }

            $hasDailyFile = !empty($stored['pos']);
            $hasWeeklyFile = !empty($stored['weekly_pos']);
            $hasMonthlyFile = !empty($stored['monthly_pos']);
            $posFound = dal_find_for_row($DAL_UPLOAD_ROOT, $rest_id, $r_date, 'pos');
            $prodFound = dal_find_for_row($DAL_UPLOAD_ROOT, $rest_id, $r_date, 'production');
            $weeklyPos = dal_find_period_file($DAL_UPLOAD_ROOT, $rest_id, $r_date, 'pos', 'weekly');
            $monthlyPos = dal_find_period_file($DAL_UPLOAD_ROOT, $rest_id, $r_date, 'pos', 'monthly');

            require_once __DIR__ . '/engine/pos_report_import.php';
            require_once __DIR__ . '/engine/mtd_allocate.php';

            $manualSalesRaw = trim((string)($_POST['manual_sales'] ?? ''));
            $manualPaidRaw = trim((string)($_POST['manual_paid_out'] ?? ''));
            $manualSales = null;
            $manualPaid = null;
            if ($manualSalesRaw !== '') {
                $tmp = str_replace([',', '$', ' '], '', $manualSalesRaw);
                if (is_numeric($tmp)) { $manualSales = round((float)$tmp, 2); }
            }
            if ($manualPaidRaw !== '') {
                $tmp = str_replace([',', '$', ' '], '', $manualPaidRaw);
                if (is_numeric($tmp)) { $manualPaid = round((float)$tmp, 2); }
            }

            $fileTypes = [];
            if ($hasDailyFile) { $fileTypes[] = 'daily'; }
            if ($hasWeeklyFile) { $fileTypes[] = 'weekly'; }
            if ($hasMonthlyFile) { $fileTypes[] = 'monthly'; }
            if ($fileTypes) {
                $periodRaw = $fileTypes[0];
            }
            $isTypeOnlyEdit = ($mode === 'edit' && $repReq > 0 && !$fileTypes);

            $slipPaid = null;
            $paidFound = dal_find_for_row($DAL_UPLOAD_ROOT, $rest_id, $r_date, 'paidout');
            if ($paidFound && !empty($paidFound['path'])) {
                $slipAmt = pri_parse_paidout_slip(pri_extract_text($paidFound['path']));
                if ($slipAmt !== null) {
                    $slipPaid = $slipAmt;
                    $notes[] = 'Paid Out $' . number_format($slipAmt, 2) . ' from Paid Out report.';
                }
            }

            if ($isTypeOnlyEdit) {
                $look = $pdo->prepare("SELECT report_id, pos_sales, paid_out, remarks FROM daily_report WHERE report_id = ?");
                $look->execute([$repReq]);
                $exist = $look->fetch(PDO::FETCH_ASSOC);
                if (!$exist) {
                    echo json_encode(['success' => false, 'error' => 'That line is gone. Add it again.']);
                    exit;
                }
                $sales = $manualSales !== null ? $manualSales : (float)($exist['pos_sales'] ?? 0);
                $writePaid = $manualPaid !== null ? $manualPaid : (float)($exist['paid_out'] ?? 0);
                if ($manualPaid === null && $slipPaid !== null) {
                    $writePaid = $slipPaid;
                }
                if ($manualSales !== null) {
                    $notes[] = 'Sales $' . number_format($sales, 2) . ' typed (image unread or override).';
                    $typedPos = dal_line_pos_file($DAL_UPLOAD_ROOT, $rest_id, $r_date, $periodRaw);
                    if ($typedPos && !empty($typedPos['path'])) {
                        dal_mark_sales_typed((string)$typedPos['path'], (float)$sales, (int)$user_id);
                    }
                }
                if ($manualPaid !== null) {
                    $notes[] = 'Paid Out $' . number_format((float)$writePaid, 2) . ' typed.';
                }
                $note = dal_period_remark($periodRaw, $r_date);
                $pdo->prepare("UPDATE daily_report SET pos_sales = ?, paid_out = ?, remarks = ? WHERE report_id = ?")
                    ->execute([(float)$sales, (float)$writePaid, $note, (int)$exist['report_id']]);
                $reportId = (int)$exist['report_id'];
                $notes[] = 'Saved as ' . ucfirst($periodRaw) . '.';
                $verify = null;
                $days = [$r_date];
                if ($periodRaw === 'weekly' || $periodRaw === 'monthly') {
                    $periodTitle = ($periodRaw === 'weekly') ? 'Weekly' : 'Monthly';
                    $days = sip_days_for_period($r_date, $periodRaw);
                    if (!$days) {
                        $days = [$r_date];
                    }
                    $daysSum = dal_period_days_sum($pdo, $rest_id, $days[0], $r_date);
                    $verify = dal_period_compare_apply(sip_period_verify((float)$sales, $daysSum, $periodTitle), $periodTitle);
                    dal_write_period_verify($DAL_UPLOAD_ROOT, $rest_id, $periodRaw, $days[0], $r_date, $verify);
                    $notes[] = $verify['text'];
                }
                echo json_encode([
                    'success' => true,
                    'report_id' => $reportId,
                    'pos_sales' => (float)$sales,
                    'paid_out' => (float)$writePaid,
                    'period' => $periodRaw,
                    'types' => [$periodRaw],
                    'days' => count($days),
                    'period_from' => $days[0],
                    'period_to' => $r_date,
                    'message' => implode(' ', array_filter($notes)),
                    'verify' => $verify,
                    'verify_status' => is_array($verify) ? $verify['status'] : null,
                    'verify_text' => is_array($verify) ? $verify['text'] : '',
                ]);
                exit;
            }

            if ($mode === 'create' && !$fileTypes) {
                echo json_encode(['success' => false, 'error' => 'Choose a Daily / Weekly / Monthly POS.']);
                exit;
            }
            $types = $fileTypes ? $fileTypes : [$periodRaw];

            $usage = null;
            if (!empty($stored['production']) && $prodFound && !empty($prodFound['path'])) {
                require_once __DIR__ . '/engine/production_usage_import.php';
                $imp = pui_import_production_pdf($pdo, $prodFound['path'], $rest_id, $r_date);
                $usage = $imp;
                $notes[] = !empty($imp['ok'])
                    ? ($imp['message'] !== '' ? $imp['message'] : ('Read into Usage (' . (int)($imp['written'] ?? 0) . ' items).'))
                    : (string)($imp['error'] ?? 'Production saved, but Usage was not updated.');
            }

            $writtenIds = [];
            $writtenTypes = [];
            $needSalesIds = [];
            $needSalesPeriods = [];
            $lastVerify = null;
            $lastFrom = $r_date;
            $primaryPeriod = $types[0];
            $lastSales = 0.0;
            $lastPaid = 0.0;
            $reportId = 0;

            foreach ($types as $type) {
                $exist = dal_find_type_row($pdo, $rest_id, $r_date, $type);
                $sales = null;
                $paidOut = null;
                $posPath = '';
                if ($type === 'daily' && $posFound && !empty($posFound['path']) && ($hasDailyFile || count($types) === 1)) {
                    $posPath = (string)$posFound['path'];
                } elseif ($type === 'weekly' && $hasWeeklyFile && $weeklyPos && !empty($weeklyPos['path'])) {
                    $posPath = (string)$weeklyPos['path'];
                } elseif ($type === 'monthly' && $hasMonthlyFile && $monthlyPos && !empty($monthlyPos['path'])) {
                    $posPath = (string)$monthlyPos['path'];
                }
                if ($posPath !== '') {
                    $posRead = pri_read_pos_file($posPath);
                    if (!empty($posRead['message'])) {
                        $notes[] = $posRead['message'];
                    }
                    if (empty($posRead['unclear'])) {
                        $sales = $posRead['net_sales'];
                        $paidOut = $posRead['paid_out'];
                    }
                }
                $applyManual = (count($types) === 1);
                if ($applyManual && $manualSales !== null) {
                    $sales = $manualSales;
                    $notes[] = 'Sales $' . number_format($sales, 2) . ' typed (image unread or override).';
                    $typedPos = dal_line_pos_file($DAL_UPLOAD_ROOT, $rest_id, $r_date, $type);
                    if ($typedPos && !empty($typedPos['path'])) {
                        dal_mark_sales_typed((string)$typedPos['path'], (float)$sales, (int)$user_id);
                    }
                }
                if ($sales === null) {
                    $sales = (float)($exist['pos_sales'] ?? 0);
                }
                if ($applyManual && $manualPaid !== null) {
                    $paidOut = $manualPaid;
                    $notes[] = 'Paid Out $' . number_format((float)$paidOut, 2) . ' typed.';
                } elseif ($type === 'daily' && $slipPaid !== null) {
                    $paidOut = $slipPaid;
                } elseif ($paidOut === null) {
                    $paidOut = isset($exist['paid_out']) ? (float)$exist['paid_out'] : 0.0;
                } elseif ($type === 'daily') {
                    $notes[] = 'Paid Out $' . number_format((float)$paidOut, 2) . ' from POS Report.';
                }
                if ($applyManual && $manualPaid !== null) {
                    $paidOut = $manualPaid;
                }

                $rowId = dal_upsert_type_row($pdo, $rest_id, $r_date, $type, (float)$sales, (float)$paidOut);
                $writtenIds[] = $rowId;
                $writtenTypes[] = $type;
                $reportId = $rowId;
                $lastSales = (float)$sales;
                $lastPaid = (float)$paidOut;
                if ($lastSales < 0.005) {
                    $needSalesIds[] = $rowId;
                    $needSalesPeriods[] = $type;
                }
                $lab = ($type === 'weekly') ? 'Weekly' : (($type === 'monthly') ? 'Monthly' : 'Daily');
                $notes[] = $lab . ' $' . number_format((float)$sales, 2) . ($exist ? ' updated' : ' saved') . ' for ' . $r_date . '.';
                if ($type === 'weekly' || $type === 'monthly') {
                    $days = sip_days_for_period($r_date, $type);
                    if (!$days) {
                        $days = [$r_date];
                    }
                    $daysSum = dal_period_days_sum($pdo, $rest_id, $days[0], $r_date);
                    $lastVerify = dal_period_compare_apply(sip_period_verify((float)$sales, $daysSum, $lab), $lab);
                    dal_write_period_verify($DAL_UPLOAD_ROOT, $rest_id, $type, $days[0], $r_date, $lastVerify);
                    $posPeriod = ($type === 'monthly') ? $monthlyPos : $weeklyPos;
                    if ($posPeriod && !empty($posPeriod['path'])) {
                        $noExt = preg_replace('/\.[^.]+$/', '', $posPeriod['path']);
                        $meta = dal_read_meta($noExt) ?: [];
                        $meta['period'] = $type;
                        $meta['period_from'] = $days[0];
                        $meta['period_to'] = $r_date;
                        $meta['period_total'] = $lastVerify['period_total'];
                        dal_write_meta($noExt, $meta);
                    }
                    $notes[] = 'Daily rows stay.';
                    $notes[] = $lastVerify['text'];
                    $primaryPeriod = $type;
                    $lastFrom = $days[0];
                }
            }

            if ($needSalesIds) {
                $notes[] = dal_need_sales_note($needSalesPeriods);
            }
            echo json_encode([
                'success' => true,
                'report_id' => $reportId,
                'report_ids' => $writtenIds,
                'need_sales' => array_values($needSalesIds),
                'pos_sales' => $lastSales,
                'paid_out' => $lastPaid,
                'sales_from_pos' => !$needSalesIds,
                'period' => $primaryPeriod,
                'types' => $writtenTypes,
                'days' => 1,
                'period_from' => $lastFrom,
                'period_to' => $r_date,
                'message' => implode(' ', array_filter($notes)),
                'verify' => $lastVerify,
                'verify_status' => is_array($lastVerify) ? $lastVerify['status'] : null,
                'verify_text' => is_array($lastVerify) ? $lastVerify['text'] : '',
                'usage_ok' => is_array($usage) ? !empty($usage['ok']) : null,
                'usage_imported' => is_array($usage) ? (int)($usage['written'] ?? 0) : 0,
            ]);
            exit;
        }

        if ($_POST['action'] === 'create') {
            $r_date  = trim((string)$_POST['report_date']);
            $rest_id = (int)$_POST['restaurant_id'];
            $sales   = (float)$_POST['pos_sales'];
            $paidOut = (float)($_POST['paid_out'] ?? 0);

            if (empty($r_date) || $rest_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Missing critical parameters.']);
                exit;
            }
            if (!dal_site_allowed($rest_id, $is_super, $assigned_csv)) {
                echo json_encode(['success' => false, 'error' => 'You cannot add a log for that site.']);
                exit;
            }
            $posErr = dal_pos_required_error($DAL_UPLOAD_ROOT, $rest_id, $r_date);
            if ($posErr !== '') {
                echo json_encode(['success' => false, 'error' => $posErr]);
                exit;
            }

            $check = $pdo->prepare("SELECT report_id, remarks FROM daily_report WHERE restaurant_id = ? AND report_date = ?");
            $check->execute([$rest_id, $r_date]);
            foreach ($check->fetchAll(PDO::FETCH_ASSOC) as $er) {
                if (dal_row_period($er['remarks'] ?? '') === 'daily') {
                    echo json_encode(['success' => false, 'error' => 'A daily entry already exists for this date. Click Edit on that daily row.']);
                    exit;
                }
            }

            $ins = $pdo->prepare("INSERT INTO daily_report (restaurant_id, report_date, pos_sales, paid_out) VALUES (?, ?, ?, ?)");
            $ins->execute([$rest_id, $r_date, $sales, $paidOut]);

            echo json_encode(['success' => true, 'report_id' => (int)$pdo->lastInsertId()]);
            exit;
        }

        if ($_POST['action'] === 'update') {
            $rep_id = (int)$_POST['report_id'];
            // Accept 12,410.21 or $12410.21; refuse blank/non-numbers instead of silently saving $0.00.
            $valRaw = str_replace([',', '$', ' ', "\xc2\xa0"], '', trim((string)($_POST['value'] ?? '')));
            if ($valRaw === '' || !is_numeric($valRaw) || (float)$valRaw < 0) {
                echo json_encode(['success' => false, 'error' => 'Type the amount as a number, for example 12410.21.']);
                exit;
            }
            $val    = round((float)$valRaw, 2);
            $field  = (string)($_POST['field'] ?? 'pos_sales');
            $allowedFields = ['pos_sales', 'paid_out'];

            if ($rep_id <= 0) {
                $ridNew = (int)($_POST['restaurant_id'] ?? $_POST['rid'] ?? 0);
                $dateNew = preg_replace('/[^0-9\-]/', '', (string)($_POST['report_date'] ?? $_POST['date'] ?? ''));
                if ($ridNew <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNew) || !dal_site_allowed($ridNew, $is_super, $assigned_csv)) {
                    echo json_encode(['success' => false, 'error' => 'Save this day first, then type Sales.']);
                    exit;
                }
                $find = $pdo->prepare('SELECT report_id FROM daily_report WHERE restaurant_id = ? AND report_date = ?');
                $find->execute([$ridNew, $dateNew]);
                $foundRow = $find->fetch(PDO::FETCH_ASSOC);
                if ($foundRow) {
                    $rep_id = (int)$foundRow['report_id'];
                } else {
                    $boot = $pdo->prepare('INSERT INTO daily_report (restaurant_id, report_date, pos_sales, paid_out) VALUES (?, ?, 0, 0)');
                    $boot->execute([$ridNew, $dateNew]);
                    $rep_id = (int)$pdo->lastInsertId();
                }
            }
            if (!in_array($field, $allowedFields, true)) {
                echo json_encode(['success' => false, 'error' => 'That field cannot be edited here.']);
                exit;
            }

            if ($is_super) {
                $look = $pdo->prepare("SELECT restaurant_id, report_date, remarks FROM daily_report WHERE report_id = ?");
                $look->execute([$rep_id]);
            } else {
                $look = $pdo->prepare("SELECT restaurant_id, report_date, remarks FROM daily_report WHERE report_id = ? AND FIND_IN_SET(restaurant_id, ?)");
                $look->execute([$rep_id, $assigned_csv]);
            }
            $exist = $look->fetch(PDO::FETCH_ASSOC);
            if (!$exist) {
                echo json_encode(['success' => false, 'error' => 'Entry not found.']);
                exit;
            }
            // dal-period-lines-20261122: Weekly/Monthly lines take their own period total; never ask for a Daily POS.
            $linePeriod = dal_line_period($exist['remarks'] ?? '');
            if (dal_sales_edit_needs_daily_pos($linePeriod)) {
                $posErr = dal_pos_required_error($DAL_UPLOAD_ROOT, (int)$exist['restaurant_id'], (string)$exist['report_date']);
                if ($posErr !== '') {
                    echo json_encode(['success' => false, 'error' => $posErr]);
                    exit;
                }
            }

            if ($is_super) {
                $upd = $pdo->prepare("UPDATE daily_report SET {$field} = ? WHERE report_id = ?");
                $upd->execute([$val, $rep_id]);
            } else {
                $upd = $pdo->prepare("UPDATE daily_report SET {$field} = ? WHERE report_id = ? AND FIND_IN_SET(restaurant_id, ?)");
                $upd->execute([$val, $rep_id, $assigned_csv]);
            }

            $updMsg = 'Saved $' . number_format($val, 2) . '.';
            $updVerify = null;
            if ($field === 'pos_sales') {
                // Typed Sales $ on an uploaded line: keep the POS file, mark the line as entered by hand.
                $typedPos = dal_line_pos_file($DAL_UPLOAD_ROOT, (int)$exist['restaurant_id'], substr((string)$exist['report_date'], 0, 10), $linePeriod);
                if ($typedPos && !empty($typedPos['path'])) {
                    dal_mark_sales_typed((string)$typedPos['path'], (float)$val, (int)$user_id);
                    $updMsg = 'Saved $' . number_format($val, 2) . ' typed by hand. POS file kept.';
                }
            }
            if ($field === 'pos_sales' && ($linePeriod === 'weekly' || $linePeriod === 'monthly')) {
                // Comparison is a warning only; the typed period total is already saved.
                require_once __DIR__ . '/engine/mtd_allocate.php';
                $lineTitle = dal_line_title($linePeriod);
                $lineRid = (int)$exist['restaurant_id'];
                $lineDate = substr((string)$exist['report_date'], 0, 10);
                $lineDays = sip_days_for_period($lineDate, $linePeriod);
                if (!$lineDays) {
                    $lineDays = [$lineDate];
                }
                $lineDaysSum = dal_period_days_sum($pdo, $lineRid, $lineDays[0], $lineDate);
                $updVerify = dal_period_compare_apply(sip_period_verify((float)$val, $lineDaysSum, $lineTitle), $lineTitle);
                dal_write_period_verify($DAL_UPLOAD_ROOT, $lineRid, $linePeriod, $lineDays[0], $lineDate, $updVerify);
                $linePos = dal_find_period_file($DAL_UPLOAD_ROOT, $lineRid, $lineDate, 'pos', $linePeriod);
                if ($linePos && !empty($linePos['path'])) {
                    $lineNoExt = preg_replace('/\.[^.]+$/', '', $linePos['path']);
                    $lineMeta = dal_read_meta($lineNoExt) ?: [];
                    $lineMeta['period'] = $linePeriod;
                    $lineMeta['period_from'] = $lineDays[0];
                    $lineMeta['period_to'] = $lineDate;
                    $lineMeta['period_total'] = $updVerify['period_total'];
                    dal_write_meta($lineNoExt, $lineMeta);
                }
                $updMsg = $lineTitle . ' total $' . number_format($val, 2) . ' typed by hand for ' . $lineDate . '. POS file kept.';
                if ($lineDaysSum > 0.004) {
                    $updMsg .= ' ' . $updVerify['text'];
                }
            }
            echo json_encode([
                'success' => true,
                'message' => $updMsg,
                'field' => $field,
                'value' => $val,
                'report_id' => $rep_id,
                'period' => $linePeriod,
                'verify_status' => is_array($updVerify) ? $updVerify['status'] : null,
            ]);
            exit;
        }

        if ($_POST['action'] === 'delete' && !empty($_POST['report_id'])) {
            $rep_id = (int)$_POST['report_id'];
            if (!$is_admin) {
                echo json_encode(['success' => false, 'error' => 'Only an Admin can delete a day.']);
                exit;
            }

            $snap = $pdo->prepare('SELECT * FROM daily_report WHERE report_id = ?');
            $snap->execute([$rep_id]);
            $gone = $snap->fetch(PDO::FETCH_ASSOC);
            if ($gone && !$is_super && !dal_site_allowed((int)$gone['restaurant_id'], $is_super, $assigned_csv)) {
                echo json_encode(['success' => false, 'error' => 'Not allowed for this restaurant.']);
                exit;
            }

            if ($is_super) {
                $del = $pdo->prepare("DELETE FROM daily_report WHERE report_id = ?");
                $del->execute([$rep_id]);
            } else {
                $del = $pdo->prepare("DELETE FROM daily_report WHERE report_id = ? AND FIND_IN_SET(restaurant_id, ?)");
                $del->execute([$rep_id, $assigned_csv]);
            }

            if ($gone && $del->rowCount() > 0) {
                $siteId = (int)($gone['restaurant_id'] ?? 0);
                $orgId = 0;
                if ($siteId > 0) {
                    $os = $pdo->prepare('SELECT org_id FROM sites WHERE SId = ?');
                    $os->execute([$siteId]);
                    $orgId = (int)$os->fetchColumn();
                }
                prb_push($pdo, 'daily_activity', $user_id, $orgId, $siteId, 'delete', ['rows' => [$gone]]);
            }

            echo json_encode(['success' => true]);
            exit;
        }

        if ($_POST['action'] === 'rollback' && $is_admin) {
            $siteFilter = (int)($_POST['site_id'] ?? 0);
            if ($siteFilter <= 0 || !dal_site_allowed($siteFilter, $is_super, $assigned_csv)) {
                echo json_encode(['success' => false, 'error' => 'Pick that restaurant. Rollback does not cross sites.']);
                exit;
            }
            $ids = prb_request_ids();
            if (!$ids) {
                echo json_encode(['success' => false, 'error' => 'Tick the day(s) to restore.']);
                exit;
            }
            $undos = prb_by_ids($pdo, 'daily_activity', $siteFilter, $ids);
            if (!$undos) {
                echo json_encode(['success' => false, 'error' => 'Those deletes are gone or expired.']);
                exit;
            }
            $restored = 0;
            $newId = 0;
            $used = [];
            foreach ($undos as $undo) {
                $payload = json_decode((string)$undo['payload'], true);
                $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
                $n = 0;
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rid = (int)($row['restaurant_id'] ?? 0);
                    if ($rid <= 0 || !dal_site_allowed($rid, $is_super, $assigned_csv)) {
                        continue;
                    }
                    if ($siteFilter > 0 && $rid !== $siteFilter) {
                        continue;
                    }
                    $pk = (int)($row['report_id'] ?? 0);
                    if ($pk > 0) {
                        $chk = $pdo->prepare('SELECT report_id FROM daily_report WHERE report_id = ?');
                        $chk->execute([$pk]);
                        if ($chk->fetchColumn()) {
                            unset($row['report_id']);
                        }
                    }
                    if (prb_insert_assoc($pdo, 'daily_report', $row)) {
                        $n++;
                        $newId = (int)$pdo->lastInsertId();
                    }
                }
                if ($n > 0) {
                    prb_mark_used($pdo, (int)$undo['id']);
                    $restored += $n;
                    $used[] = (int)$undo['id'];
                }
            }
            if ($restored < 1) {
                echo json_encode(['success' => false, 'error' => 'Could not restore those days.']);
                exit;
            }
            echo json_encode(['success' => true, 'restored' => $restored, 'report_id' => $newId, 'ids' => $used]);
            exit;
        }

        if ($_POST['action'] === 'set_rollback_days' && $is_admin) {
            $orgId = (int)($_POST['org_id'] ?? 0);
            $siteId = (int)($_POST['site_id'] ?? 0);
            if ($orgId <= 0 && $siteId > 0) {
                $orgId = prb_org_for_site($pdo, $siteId);
            }
            if ($orgId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Pick an organization or restaurant first.']);
                exit;
            }
            if ($siteId > 0 && !dal_site_allowed($siteId, $is_super, $assigned_csv)) {
                echo json_encode(['success' => false, 'error' => 'Not allowed for this restaurant.']);
                exit;
            }
            $days = prb_set_org_days($pdo, $orgId, (int)($_POST['days'] ?? 7), $user_id);
            echo json_encode(['success' => true, 'days' => $days, 'org_id' => $orgId]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date   = $_GET['end'] ?? date('Y-m-d');
$selected_rid = $_GET['rid'] ?? '';
$selected_rpt = strtolower(trim((string)($_GET['rpt'] ?? 'all')));
if (!in_array($selected_rpt, ['all', 'daily', 'weekly', 'monthly'], true)) {
    $selected_rpt = 'all';
}
$dal_search = trim((string)($_GET['q'] ?? ''));

$org_raw = $_GET['org_id'] ?? ($_COOKIE['sip_org_id'] ?? ($_COOKIE['dal_org_id'] ?? 'all'));
if (!$is_super) {
    $selected_org = $current_org_id;
} else {
    $selected_org = ($org_raw === '' || $org_raw === 'all') ? 0 : (int)$org_raw;
    if (!headers_sent()) {
        $ck = [
            'expires' => time() + 86400 * 120,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        $ckv = $selected_org > 0 ? (string)$selected_org : 'all';
        setcookie('dal_org_id', $ckv, $ck);
        setcookie('sip_org_id', $ckv, $ck);
    }
}

$orgs = [];
if ($is_super) {
    $orgs = $pdo->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$sites = [];
foreach ($all_sites as $s) {
    if ($is_super && $selected_org > 0 && (int)($s['org_id'] ?? 0) !== $selected_org) {
        continue;
    }
    $sites[] = $s;
}
$allowed_rids = array_map(static function ($s) { return (int)$s['SId']; }, $sites);
if ($selected_rid !== '' && !in_array((int)$selected_rid, $allowed_rids, true)) {
    $selected_rid = '';
}

$prbOrgId = (int)($selected_org ?? 0);
if ($prbOrgId <= 0 && (int)$selected_rid > 0) {
    $prbOrgId = prb_org_for_site($pdo, (int)$selected_rid);
}
if ($prbOrgId <= 0 && empty($is_super)) {
    $prbOrgId = (int)($current_org_id ?? 0);
}
$prbDays = $prbOrgId > 0 ? prb_org_days($pdo, $prbOrgId) : 7;
$dalRollbackList = [];
if ($is_admin && (int)$selected_rid > 0) {
    foreach (prb_list($pdo, 'daily_activity', (int)$selected_rid) as $undo) {
        $sum = prb_dal_summary($undo);
        $sum['confirm'] = prb_dal_confirm($sum);
        $sum['when_label'] = prb_when_label((string)$sum['when']);
        $sum['date_label'] = $sum['date'] !== '' ? date('Y-m-d', strtotime($sum['date'])) : '';
        $sum['amount_label'] = $sum['amount'] !== '' ? number_format((float)$sum['amount'], 2) : '';
        $dalRollbackList[] = $sum;
    }
}

$sql = "SELECT dr.*, s.nick_name, s.org_id,
    (SELECT SUM(pos_sales) FROM daily_report dr2
     WHERE dr2.restaurant_id = dr.restaurant_id
     AND dr2.report_date BETWEEN DATE_FORMAT(dr.report_date, '%Y-%m-01') AND dr.report_date
     AND (dr2.remarks IS NULL OR (dr2.remarks NOT LIKE '%week_split%' AND dr2.remarks NOT LIKE '%mtd_split%'))) as mtd_sales_calc,
    (SELECT SUM(fc.Amount) FROM FoodCost fc
     WHERE fc.RId = dr.restaurant_id
     AND fc.InvoiceDate BETWEEN DATE_FORMAT(dr.report_date, '%Y-%m-01') AND dr.report_date) as food_mtd_calc,
    (SELECT SUM((TIMESTAMPDIFF(SECOND, a.clock_in, IF(a.clock_out IS NULL OR a.clock_out = '0000-00-00 00:00:00', NOW(), a.clock_out)) / 3600) * u.official_rate)
     FROM attendance a JOIN users u ON a.user_id = u.id
     WHERE a.site_id = dr.restaurant_id AND DATE(a.clock_in) = dr.report_date) as labor_daily_calc,
    (SELECT SUM((TIMESTAMPDIFF(SECOND, a.clock_in, IF(a.clock_out IS NULL OR a.clock_out = '0000-00-00 00:00:00', NOW(), a.clock_out)) / 3600) * u.official_rate)
     FROM attendance a JOIN users u ON a.user_id = u.id
     WHERE a.site_id = dr.restaurant_id
     AND DATE(a.clock_in) BETWEEN DATE_FORMAT(dr.report_date, '%Y-%m-01') AND dr.report_date) as labor_mtd_calc
    FROM daily_report dr
    INNER JOIN sites s ON dr.restaurant_id = s.SId
    WHERE dr.report_date BETWEEN ? AND ?";

$params = [$start_date, $end_date];
if (!$is_super) {
    $sql .= " AND FIND_IN_SET(dr.restaurant_id, ?)";
    $params[] = $assigned_csv;
}
if ($is_super && $selected_org > 0) {
    $sql .= " AND s.org_id = ?";
    $params[] = $selected_org;
}
if ($selected_rid) {
    $sql .= " AND dr.restaurant_id = ?";
    $params[] = $selected_rid;
}
if ($selected_rpt === 'weekly') {
    $sql .= " AND dr.remarks LIKE '%week_split%'";
} elseif ($selected_rpt === 'monthly') {
    $sql .= " AND dr.remarks LIKE '%mtd_split%'";
} elseif ($selected_rpt === 'daily') {
    $sql .= " AND (dr.remarks IS NULL OR (dr.remarks NOT LIKE '%week_split%' AND dr.remarks NOT LIKE '%mtd_split%'))";
}

$sql .= " ORDER BY s.nick_name ASC, dr.report_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

$havePair = [];
foreach ($reports as $r) {
    $havePair[(int)$r['restaurant_id'] . '|' . (string)$r['report_date']] = true;
}
$fcSql = "SELECT fc.RId AS restaurant_id, fc.InvoiceDate AS report_date,
    s.nick_name, s.org_id,
    (SELECT SUM(fc2.Amount) FROM FoodCost fc2
     WHERE fc2.RId = fc.RId
     AND fc2.InvoiceDate BETWEEN DATE_FORMAT(fc.InvoiceDate, '%Y-%m-01') AND fc.InvoiceDate) AS food_mtd_calc,
    (SELECT SUM(pos_sales) FROM daily_report dr2
     WHERE dr2.restaurant_id = fc.RId
     AND dr2.report_date BETWEEN DATE_FORMAT(fc.InvoiceDate, '%Y-%m-01') AND fc.InvoiceDate
     AND (dr2.remarks IS NULL OR (dr2.remarks NOT LIKE '%week_split%' AND dr2.remarks NOT LIKE '%mtd_split%'))) AS mtd_sales_calc,
    (SELECT SUM((TIMESTAMPDIFF(SECOND, a.clock_in, IF(a.clock_out IS NULL OR a.clock_out = '0000-00-00 00:00:00', NOW(), a.clock_out)) / 3600) * u.official_rate)
     FROM attendance a JOIN users u ON a.user_id = u.id
     WHERE a.site_id = fc.RId AND DATE(a.clock_in) = fc.InvoiceDate) AS labor_daily_calc,
    (SELECT SUM((TIMESTAMPDIFF(SECOND, a.clock_in, IF(a.clock_out IS NULL OR a.clock_out = '0000-00-00 00:00:00', NOW(), a.clock_out)) / 3600) * u.official_rate)
     FROM attendance a JOIN users u ON a.user_id = u.id
     WHERE a.site_id = fc.RId
     AND DATE(a.clock_in) BETWEEN DATE_FORMAT(fc.InvoiceDate, '%Y-%m-01') AND fc.InvoiceDate) AS labor_mtd_calc
    FROM FoodCost fc
    INNER JOIN sites s ON fc.RId = s.SId
    WHERE fc.InvoiceDate BETWEEN ? AND ?";
$fcParams = [$start_date, $end_date];
if (!$is_super) {
    $fcSql .= " AND FIND_IN_SET(fc.RId, ?)";
    $fcParams[] = $assigned_csv;
}
if ($is_super && $selected_org > 0) {
    $fcSql .= " AND s.org_id = ?";
    $fcParams[] = $selected_org;
}
if ($selected_rid) {
    $fcSql .= " AND fc.RId = ?";
    $fcParams[] = $selected_rid;
}
$fcSql .= " GROUP BY fc.RId, fc.InvoiceDate, s.nick_name, s.org_id";
try {
    $fcStmt = $pdo->prepare($fcSql);
    $fcStmt->execute($fcParams);
    foreach ($fcStmt->fetchAll(PDO::FETCH_ASSOC) as $fcRow) {
        if ($selected_rpt === 'weekly' || $selected_rpt === 'monthly') {
            continue;
        }
        $pair = (int)$fcRow['restaurant_id'] . '|' . (string)$fcRow['report_date'];
        if (!empty($havePair[$pair])) {
            continue;
        }
        $reports[] = [
            'report_id' => 0,
            'report_date' => $fcRow['report_date'],
            'restaurant_id' => $fcRow['restaurant_id'],
            'nick_name' => $fcRow['nick_name'],
            'org_id' => $fcRow['org_id'],
            'pos_sales' => 0,
            'paid_out' => 0,
            'mtd_sales_calc' => $fcRow['mtd_sales_calc'],
            'food_mtd_calc' => $fcRow['food_mtd_calc'],
            'labor_daily_calc' => $fcRow['labor_daily_calc'],
            'labor_mtd_calc' => $fcRow['labor_mtd_calc'],
        ];
    }
} catch (Throwable $e) {
}
usort($reports, static function ($a, $b) {
    $n = strcasecmp((string)$a['nick_name'], (string)$b['nick_name']);
    if ($n !== 0) {
        return $n;
    }
    $d = strcmp((string)$b['report_date'], (string)$a['report_date']);
    if ($d !== 0) {
        return $d;
    }
    $ord = ['daily' => 0, 'weekly' => 1, 'monthly' => 2];
    $pa = $ord[dal_row_period($a['remarks'] ?? '')] ?? 0;
    $pb = $ord[dal_row_period($b['remarks'] ?? '')] ?? 0;
    return $pa <=> $pb;
});
if ($dal_search !== '') {
    $q = strtolower($dal_search);
    $reports = array_values(array_filter($reports, static function ($r) use ($q) {
        $rmk = strtolower((string)($r['remarks'] ?? ''));
        $period = (strpos($rmk, 'week_split') !== false) ? 'weekly' : ((strpos($rmk, 'mtd_split') !== false) ? 'monthly' : 'daily');
        if ($q === 'weekly' || $q === 'week') {
            return $period === 'weekly';
        }
        if ($q === 'monthly' || $q === 'month') {
            return $period === 'monthly';
        }
        if ($q === 'daily' || $q === 'day') {
            return $period === 'daily';
        }
        $hay = strtolower((string)($r['nick_name'] ?? '') . ' ' . (string)($r['report_date'] ?? '') . ' ' . $period);
        return strpos($hay, $q) !== false;
    }));
}

function dal_file_link($found, $rid, $reportDate, $kind, $period, $caption) {
    if (!$found || empty($found['path'])) {
        return '';
    }
    $ym = substr((string)$reportDate, 0, 7);
    $q = http_build_query([
        'action' => 'dl',
        'rid' => (int)$rid,
        'ym' => $ym,
        'kind' => $kind,
        'date' => $reportDate,
        'period' => $period,
    ]);
    $fmt = $found['format'] ?? dal_detect_format($found['path']);
    $label = $fmt['label'] ?? 'File';
    $icon = (($fmt['ext'] ?? '') === 'pdf') ? 'fa-file-pdf' : 'fa-file-image';
    $title = htmlspecialchars(($found['orig'] ?? 'file') . ' (' . $label . ')');
    $ext = htmlspecialchars((string)($fmt['ext'] ?? ''));
    $html = '<a class="file-view" href="daily_report_view.php?' . htmlspecialchars($q) . '" data-kind="' . htmlspecialchars($kind) . '" data-rid="' . (int)$rid . '" data-date="' . htmlspecialchars((string)$reportDate) . '" data-period="' . htmlspecialchars($period) . '" data-ext="' . $ext . '" onclick="if(window.dalOpenFilePreview){window.dalOpenFilePreview(this);return false;}" title="' . $title . ' — click to preview beside the list"><i class="fa ' . $icon . '"></i> View ' . htmlspecialchars($caption) . '</a>';
    if (!empty($found['sales_typed'])) {
        $html .= dal_typed_tag_html($found);
    } elseif (!empty($found['unclear'])) {
        $why = htmlspecialchars($found['unclear_reason'] !== '' ? $found['unclear_reason'] : 'Photo is not clear');
        $html .= ' <span class="dal-recheck-amt" title="' . $why . '">Recheck</span>';
    }
    return $html;
}

function dal_file_cell($root, $rid, $reportDate, $kind, $period = 'daily') {
    $html = '<div class="file-cell">';
    $found = null;
    $linkPeriod = 'daily';
    $caption = 'PDF';
    if ($kind === 'pos') {
        $ym = substr((string)$reportDate, 0, 7);
        if ($period === 'weekly') {
            $found = dal_find_existing($root, $rid, $ym, 'pos', $reportDate, 'weekly');
            $linkPeriod = 'weekly';
            $caption = 'POS';
        } elseif ($period === 'monthly') {
            $found = dal_find_existing($root, $rid, $ym, 'pos', $reportDate, 'monthly');
            $linkPeriod = 'monthly';
            $caption = 'POS';
        }
        if (!$found) {
            $found = dal_find_for_row($root, $rid, $reportDate, 'pos');
            $linkPeriod = 'daily';
            $caption = 'POS';
        }
    } else {
        $found = dal_find_for_row($root, $rid, $reportDate, $kind);
    }
    if ($found) {
        $html .= dal_file_link($found, $rid, $reportDate, $kind, $linkPeriod, $caption);
    } else {
        $miss = ($kind === 'pos' && $period === 'daily') ? 'Daily required' : 'None';
        $missClass = ($kind === 'pos' && $period === 'daily') ? 'file-missing file-required' : 'file-missing';
        $html .= '<span class="' . $missClass . '">' . $miss . '</span>';
    }
    $html .= '</div>';
    return $html;
}

function dal_money($n) {
    return '$' . number_format((float)$n, 2);
}

function dal_pct($n) {
    return number_format((float)$n, 1) . '%';
}

function dal_totals_row($sales, $mtdSales, $food, $foodPct, $labor, $laborMtd, $laborPct, $paid, $n) {
    $days = ((int)$n === 1) ? '1 day' : ((int)$n . ' days');
    return '<tr class="dal-total">'
        . '<th class="col-site">Total</th>'
        . '<th class="col-date">' . htmlspecialchars($days) . '</th>'
        . '<th>' . dal_money($sales) . '</th>'
        . '<th>' . dal_money($mtdSales) . '</th>'
        . '<th>' . dal_money($food) . '</th>'
        . '<th>' . dal_pct($foodPct) . '</th>'
        . '<th>' . dal_money($labor) . '</th>'
        . '<th>' . dal_money($laborMtd) . '</th>'
        . '<th>' . dal_pct($laborPct) . '</th>'
        . '<th></th>'
        . '<th></th>'
        . '<th>' . dal_money($paid) . '</th>'
        . '<th></th>'
        . '</tr>';
}

$porQs = http_build_query(array_filter([
    'org_id' => $is_super ? ($selected_org > 0 ? (string)$selected_org : 'all') : null,
    'start' => $start_date,
    'end' => $end_date,
    'rid' => ($selected_rid !== '' && $selected_rid !== '0') ? $selected_rid : null,
], static function ($v) { return $v !== null && $v !== ''; }));

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Daily Activity Report | SIP</title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; margin: 0; padding-top: 130px; }
        .container { padding: 25px; max-width: 1680px; margin: auto; }
        .dal-chrome-toggle { width: 100%; display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; margin-bottom: 8px; cursor: pointer; font-size: 12px; font-weight: 800; color: #0f172a; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .dal-chrome-toggle:hover { background: #f8fafc; }
        #dalToolBar.tool-bar,
        .tool-bar#dalToolBar,
        div#dalToolBar {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            float: none !important;
            clear: both !important;
            flex-direction: column !important;
            flex-wrap: nowrap !important;
            align-items: stretch !important;
            background: white;
            padding: 8px;
            border-radius: 8px;
            margin-bottom: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            box-sizing: border-box;
        }
        #dalToolBar.tool-bar[hidden],
        .tool-bar#dalToolBar[hidden] { display: none !important; }
        #dalStackTable,
        table.dal-stack-table {
            display: table !important;
            width: 100% !important;
            max-width: 100% !important;
            table-layout: fixed !important;
            border-collapse: separate !important;
            border-spacing: 0 8px !important;
            float: none !important;
            clear: both !important;
        }
        #dalStackTable > tbody,
        #dalStackTable tbody {
            display: table-row-group !important;
            width: 100% !important;
        }
        #dalStackTable tr.dal-stack-row,
        #dalStackTable tr {
            display: table-row !important;
            width: 100% !important;
            float: none !important;
            clear: both !important;
        }
        #dalStackTable td.dal-stack-cell,
        #dalStackTable td,
        #dalFilterCard,
        #dalAddCard {
            display: table-cell !important;
            width: 100% !important;
            max-width: 100% !important;
            float: none !important;
            clear: both !important;
            vertical-align: top !important;
            box-sizing: border-box;
        }
        #dalToolBar .col-6,
        #dalToolBar .col-md-6,
        #dalToolBar .col-lg-6,
        #dalToolBar .col-sm-6,
        #dalToolBar .col-xl-6 {
            width: 100% !important;
            max-width: 100% !important;
            flex: 0 0 100% !important;
            float: none !important;
        }
        #dalToolBar .tool-section {
            display: flex !important;
            flex-wrap: wrap;
            gap: 8px;
            align-items: flex-end;
            padding: 8px 10px;
            width: 100% !important;
            max-width: 100% !important;
            flex: none !important;
            float: none !important;
            box-sizing: border-box;
        }
        @media (min-width: 1px) {
            #dalToolBar, #dalStackTable { width: 100% !important; }
            #dalStackTable tr.dal-stack-row { display: table-row !important; width: 100% !important; }
            #dalStackTable td.dal-stack-cell { display: table-cell !important; width: 100% !important; float: none !important; }
        }
        #dalToolBar .tool-section.look { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; }
        #dalToolBar .tool-section.add { background: #f0f9ff; border-radius: 6px; border: 1px solid #bfdbfe; }
        .sec-name { font-size: 9px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #94a3b8; align-self: center; padding: 0 6px 0 0; white-space: nowrap; }
        .tool-field { flex: 0 1 auto; min-width: 0; display: flex; flex-direction: column; align-items: stretch; }
        .tool-field label, .tool-field .tool-lbl { font-size: 11px; font-weight: 800; color: #334155; display: block; margin-bottom: 4px; line-height: 1.2; text-transform: uppercase; position: relative; z-index: 2; }
        .tool-field.dal-file-field { min-width: 158px; flex: 0 0 158px; }
        #dalNeedHint { min-width: 280px; max-width: 420px; font-size: 11px; font-weight: 700; color: #9a3412; line-height: 1.35; align-self: center; }
        .tool-field .sheet-input { padding: 5px 7px; font-size: 12px; }
        .tool-org { width: 150px; }
        .tool-site { width: 160px; }
        .tool-date { width: 132px; }
        .tool-sales { width: 88px; }
        .tool-paid { width: 80px; }
        .tool-file { width: 132px; max-width: 100%; }
        .dal-filepick { position: relative; display: flex; align-items: center; gap: 6px; cursor: pointer; width: 100%; max-width: 180px; overflow: hidden; z-index: 1; }
        .dal-filepick input[type="file"] { position: absolute; left: 0; top: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; font-size: 0; }
        .dal-filepick-btn { flex: 0 0 auto; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 6px 8px; font-size: 11px; font-weight: 800; color: #0f172a; white-space: nowrap; }
        .dal-filepick-name { font-size: 11px; color: #94a3b8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }

        @media (max-width: 720px) {
            .container { padding: 12px; }
            .tool-field { flex: 1 1 46%; }
            .tool-org, .tool-site, .tool-date, .tool-sales, .tool-paid, .tool-file { width: 100%; }
            .btn-load { width: 100%; }
        }
        .paid-cell { min-width: 96px; }
        .btn-load { background: #0f172a; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: 800; font-size: 11px; height: 30px; }
        .btn-rollback { background: #0f766e; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: 800; font-size: 11px; height: 30px; }
        .prb-box { background:#f0fdfa; border:1px solid #99f6e4; border-radius:8px; padding:12px 14px; margin:0 0 14px; }
        .prb-box h3 { margin:0 0 4px; font-size:13px; color:#115e59; }
        .prb-note { margin:0 0 8px; font-size:12px; color:#0f766e; font-weight:600; }
        .prb-table { width:100%; border-collapse:collapse; font-size:12px; }
        .prb-table th { text-align:left; color:#64748b; font-size:10px; text-transform:uppercase; padding:4px 6px; }
        .prb-table td { padding:5px 6px; border-top:1px solid #ccfbf1; }
        .prb-box .btn-rollback { margin-top:10px; height:auto; }
        .btn-add { background: #2563eb; color: white; border: none; padding: 0; border-radius: 4px; cursor: pointer; width: 30px; height: 30px; font-weight: bold; flex: 0 0 30px; }
        .btn-save-day { background: #16a34a; color: white; border: none; padding: 6px 16px; border-radius: 4px; cursor: pointer; font-weight: 800; font-size: 12px; height: 30px; }
        .btn-save-day:hover { background: #15803d; }
        .btn-save-day:disabled { opacity: .55; cursor: wait; }
        .amt-ro { font-weight: 800; color: #0f172a; }
        input.dal-manual-amt { width: 92px; max-width: 100%; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: 800; font-size: 12px; color: #0f172a; }
        input.dal-manual-amt:focus { outline: 2px solid #2563eb; border-color: #2563eb; }
        .dal-typed-tag, .dal-typed-hint { display: inline-block; margin-top: 2px; padding: 0 5px; border-radius: 4px; background: #e0e7ff; color: #3730a3; font-size: 10px; font-weight: 800; }
        .dal-recheck input.dal-manual-amt,
        input.dal-manual-amt.dal-recheck-amt { border: 2px solid #dc2626; background: #fef2f2; color: #b91c1c; }
        .dal-recheck-hint { font-size: 10px; font-weight: 800; color: #dc2626; margin-top: 3px; }
        .dal-date-stack { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; max-width: 100%; }
        .dal-date-stack .dal-date-text { white-space: nowrap; }
        .dal-period-tag { display: inline-block; margin: 0; padding: 1px 7px; border-radius: 999px; font-size: 9px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; line-height: 1.3; }
        .dal-period-weekly { background: #fdba74; color: #7c2d12; }
        .dal-period-monthly { background: #86efac; color: #14532d; }
        .rpt-table tr.data-row.dal-row-weekly td,
        .rpt-table tr.data-row.dal-row-weekly td.col-site,
        .rpt-table tr.data-row.dal-row-weekly td.col-date { background: #ffedd5 !important; }
        .rpt-table tr.data-row.dal-row-monthly td,
        .rpt-table tr.data-row.dal-row-monthly td.col-site,
        .rpt-table tr.data-row.dal-row-monthly td.col-date { background: #dcfce7 !important; }
        .rpt-table tr.data-row.dal-row-weekly td.col-site { box-shadow: inset 4px 0 0 #ea580c; }
        .rpt-table tr.data-row.dal-row-monthly td.col-site { box-shadow: inset 4px 0 0 #16a34a; }
        .rpt-table tr.dal-period-verify td { padding: 5px 10px; font-size: 12px; font-weight: 800; border-top: 0; }
        .rpt-table tr.dal-period-verify-match td { background: #dcfce7 !important; color: #166534; }
        .rpt-table tr.dal-period-verify-short td,
        .rpt-table tr.dal-period-verify-over td { background: #fee2e2 !important; color: #991b1b; }
        .dal-period-verify-inner { letter-spacing: .01em; }
        .dal-mismatch { color: #b91c1c; font-weight: 900; text-decoration: underline; }
        .site-edit { color: #1d4ed8; font-weight: 800; text-decoration: none; cursor: pointer; }
        .site-edit:hover { text-decoration: underline; }
        .btn-row-edit { background: #2563eb; color: #fff; border: none; border-radius: 4px; padding: 3px 8px; font-size: 11px; font-weight: 800; cursor: pointer; margin-right: 6px; }
        .btn-row-edit:hover { background: #1d4ed8; }
        .btn-row-dup { background: #0f766e; color: #fff; border: none; border-radius: 4px; padding: 3px 8px; font-size: 11px; font-weight: 800; cursor: pointer; margin-right: 6px; }
        .btn-row-dup:hover { background: #0d9488; }
        .btn-cancel-edit { background: #e2e8f0; color: #0f172a; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: 800; font-size: 11px; height: 30px; display: none; }
        .tool-section.add.editing { background: #fef9c3; border-color: #facc15; }
        tr.data-row.editing-row td { background: #fef9c3 !important; }
        .rpt-table { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 1280px; background: white; table-layout: auto; }
        .rpt-table th { background: #0f172a; color: white; padding: 14px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .rpt-table td { border-bottom: 1px solid #f1f5f9; padding: 10px 8px; font-size: 13px; vertical-align: middle; background: #fff; }
        .rpt-table th.col-site, .rpt-table td.col-site { width: 140px; min-width: 140px; max-width: 140px; }
        .rpt-table th.col-date, .rpt-table td.col-date { width: 120px; min-width: 120px; max-width: 120px; }
        .rpt-table thead th { position: sticky; top: 0; z-index: 5; }
        .rpt-table thead th.col-site { left: 0; z-index: 7; top: 0; }
        .rpt-table thead th.col-date { left: 140px; z-index: 6; top: 0; }
        .rpt-table thead tr.dal-total th,
        .rpt-table tfoot tr.dal-total th {
            background: #e2e8f0; color: #0f172a; text-transform: none;
            font-size: 13px; font-weight: 800; padding: 10px 8px;
        }
        .rpt-table thead tr.dal-total th { top: 46px; z-index: 5; box-shadow: 0 1px 0 #cbd5e1; }
        .rpt-table thead tr.dal-total th.col-site { left: 0; z-index: 8; top: 46px; }
        .rpt-table thead tr.dal-total th.col-date { left: 140px; z-index: 7; top: 46px; }
        .rpt-table tfoot th { position: sticky; bottom: 0; z-index: 5; box-shadow: 0 -1px 0 #cbd5e1; }
        .rpt-table tfoot th.col-site { left: 0; z-index: 8; }
        .rpt-table tfoot th.col-date { left: 140px; z-index: 7; }
        .rpt-table td.col-site { position: sticky; left: 0; z-index: 3; background: #fff; font-weight: 800; color: #0f172a; white-space: nowrap; }
        .rpt-table td.col-date { position: sticky; left: 140px; z-index: 2; background: #fff; box-shadow: 4px 0 8px -6px rgba(15,23,42,0.35); white-space: normal; overflow: hidden; }
        @media (max-width: 900px) {
            .rpt-table thead th.col-site,
            .rpt-table thead th.col-date,
            .rpt-table thead tr.dal-total th.col-site,
            .rpt-table thead tr.dal-total th.col-date,
            .rpt-table tfoot th.col-site,
            .rpt-table tfoot th.col-date,
            .rpt-table td.col-site,
            .rpt-table td.col-date {
                left: auto;
            }
            .rpt-table td.col-site,
            .rpt-table td.col-date {
                position: static;
                max-width: none;
            }
        }
        .rpt-table tbody tr.data-row:hover td { background: #f8fafc; }
        .rpt-table tbody tr.data-row:hover td.col-site,
        .rpt-table tbody tr.data-row:hover td.col-date { background: #f8fafc; }
        .rpt-table tbody tr.dal-recheck td { background: #fff7ed; }
        .rpt-table tbody tr.dal-recheck td.col-site,
        .rpt-table tbody tr.dal-recheck td.col-date { background: #fff7ed; }
        .dal-recheck-amt { color: #c2410c; font-weight: 800; }
        .val-bad { color: #be123c; font-weight: 800; background: #fff1f2; padding: 2px 6px; border-radius: 4px; }
        .sheet-input { width: 100%; border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; box-sizing: border-box; }
        .new-row { background: #f0f9ff; border-bottom: 2px solid #2563eb; }
        #status-msg { position: fixed; top: 140px; right: 30px; padding: 10px; border-radius: 4px; display: none; z-index: 1000; color: white; font-weight: bold; }
        .icon-trash-row { color: #cbd5e1; cursor: pointer; transition: color 0.15s ease; font-size: 13px; margin-left: 10px; }
        .icon-trash-row:hover { color: #dc2626; }
        .file-cell { display: flex; flex-direction: row; flex-wrap: nowrap; gap: 8px; align-items: center; white-space: nowrap; }
        .file-cell .file-view, .file-cell .dal-recheck-amt { white-space: nowrap; }
        .paid-cell { white-space: nowrap; }
        td.col-files, td.paid-cell { white-space: nowrap; }
        .file-view { color: #1d4ed8; font-weight: 700; font-size: 12px; text-decoration: none; }
        .file-view:hover { text-decoration: underline; }
        .file-missing { color: #94a3b8; font-size: 12px; }
        .file-missing.file-required { color: #be123c; font-weight: 800; }
        .file-pick { font-size: 11px; color: #0f172a; cursor: pointer; font-weight: 700; }
        .file-pick input { display: none; }
        .file-pick.file-save { display: inline-block; background: #16a34a; color: #fff; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: 800; line-height: 1.2; }
        .file-pick.file-save:hover { background: #15803d; }
        .tool-field.pos-required label, .tool-field.pos-required .tool-lbl { color: #be123c; }
        .tool-field.pos-required .sheet-input, .tool-field.pos-required .dal-filepick-btn { border-color: #fca5a5; }
        .dal-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 15px; }
        .dal-head-left { display: flex; align-items: center; min-height: 36px; }
        .dal-head h2 { font-weight: 800; margin: 0; text-align: right; }
        .dal-head .pg-help-panel { margin: 0; }
        .table-wrap { max-height: calc(100vh - 160px); overflow: auto; background: white; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { color: #e2e8f0; }
        th.sortable::after { content: ' \2195'; opacity: .45; font-size: 10px; }
        th.sort-asc::after { content: ' \25B2'; opacity: .95; }
        th.sort-desc::after { content: ' \25BC'; opacity: .95; }
        th.sortable[data-sort-n]::before { content: attr(data-sort-n); display: inline-block; min-width: 14px; margin-right: 4px; padding: 0 5px; border-radius: 8px; background: #38bdf8; color: #0f172a; font-size: 10px; font-weight: 900; }

        #dalPosPreview { display: none; position: fixed; top: 75px; right: 0; width: min(720px, 70vw); min-width: 360px; max-width: 100vw; height: calc(100vh - 75px); background: #fff; box-shadow: -10px 0 28px rgba(15,23,42,.22); z-index: 2147483000; flex-direction: column; }
        #dalPosPreview.open { display: flex; }
        body.dal-preview-open { overflow: hidden; }
        .dal-prev-resize { position: absolute; left: 0; top: 0; bottom: 0; width: 10px; cursor: ew-resize; z-index: 9; background: linear-gradient(90deg, rgba(37,99,235,.35), transparent); }
        .dal-prev-resize:hover, .dal-prev-resize.dragging { background: #2563eb; width: 8px; }
        .dal-prev-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 10px 12px 10px 16px; background: #0f172a; color: #fff; flex: 0 0 auto; }
        .dal-prev-head strong { display: block; font-size: 14px; }
        .dal-prev-head span { display: block; font-size: 11px; color: #cbd5e1; margin-top: 3px; font-weight: 700; }
        .dal-prev-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; justify-content: flex-end; }
        .dal-prev-tools button, .dal-prev-tools .dal-prev-zoom-pct { background: #334155; color: #fff; border: 0; border-radius: 4px; height: 30px; min-width: 30px; padding: 0 8px; font-weight: 800; font-size: 12px; cursor: pointer; }
        .dal-prev-tools button:hover { background: #475569; }
        .dal-prev-zoom-pct { display: inline-flex; align-items: center; justify-content: center; min-width: 48px; cursor: default; }
        .dal-prev-x { background: #334155; color: #fff; border: 0; border-radius: 4px; width: 30px; height: 30px; font-weight: 900; cursor: pointer; flex: 0 0 30px; }
        .dal-prev-x:hover { background: #475569; }
        .dal-prev-body { flex: 1 1 auto; min-height: 0; overflow: auto; background: #0f172a; position: relative; z-index: 1; isolation: isolate; }
        .dal-prev-stage { transform-origin: top left; min-height: 100%; }
        .dal-prev-body img { width: 100%; max-width: none; display: block; background: #fff; }
        .dal-prev-body iframe { display: block; width: 100%; height: 100%; min-height: calc(100vh - 220px); border: 0; background: #fff; }
        .dal-prev-foot { position: relative; z-index: 5; isolation: isolate; background: #fff !important; }
        #dalPrevAmt { pointer-events: auto; position: relative; z-index: 6; }
        body.dal-preview-open iframe[title*="chat" i],
        body.dal-preview-open .tawk-min-container,
        body.dal-preview-open .tawk-button { visibility: hidden !important; }
        .dal-prev-empty { color: #e2e8f0; padding: 24px 16px; font-weight: 700; }
        .dal-prev-foot { padding: 12px 14px 16px; border-top: 1px solid #e2e8f0; background: #fff; }
        .dal-prev-foot label { display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 6px; }
        #dalPrevAmt { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 2px solid #2563eb; border-radius: 6px; font-size: 20px; font-weight: 800; color: #0f172a; }
        .dal-prev-actions { display: flex; gap: 8px; margin-top: 10px; align-items: center; flex-wrap: wrap; }
        .dal-prev-save { background: #16a34a; color: #fff; border: 0; border-radius: 4px; padding: 8px 14px; font-weight: 800; cursor: pointer; }
        .dal-prev-save:hover { background: #15803d; }
        .dal-prev-full { color: #1d4ed8; font-weight: 800; font-size: 12px; }
        @media (max-width: 720px) {
            #dalPosPreview { width: 100vw; min-width: 0; }
            .dal-prev-resize { display: none; }
        }
    </style>
</head>
<body>

<?= generateMenu($pdo); ?>
<div id="status-msg"></div>

<div class="container">
    <div class="dal-head">
        <div class="dal-head-left" id="dalHelpSlot"></div>
        <h2>Daily Activity Report</h2>
        <a class="btn-load" href="paid_out_report.php?<?= htmlspecialchars($porQs) ?>" title="POS paid-out vs the amount saved, plus the slip">Paid Out Report</a>
    </div>
    <script>
    (function () {
        var panel = document.querySelector(".pg-help-from-nav");
        var slot = document.getElementById("dalHelpSlot");
        if (panel && slot) {
            slot.appendChild(panel);
            panel.style.maxWidth = "none";
            panel.style.margin = "0";
            panel.style.padding = "0";
        }
        var ico = document.querySelector("#pgHelpPanel .pg-help-toggle");
        if (ico) {
            ico.title = "Add or Edit can pick Daily, Weekly, or Monthly. Same date can have one line of each. Files are optional.";
            ico.setAttribute("aria-label", "Help — how to add or edit a day");
        }
        var tour = document.getElementById("pgTourStartBtn");
        if (tour) tour.title = "Start audio visual tour";
    })();
    </script>
    <?php if ($dalRollbackList): ?>
    <div class="prb-box" id="dalRollbackBox">
        <h3>Rollback — this restaurant, <?= (int)$prbDays ?> day<?= $prbDays === 1 ? '' : 's' ?></h3>
        <p class="prb-note">Tick the delete(s) to put back. Other deletes stay. All Sites hides this list.</p>
        <table class="prb-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Date</th>
                    <th>Vendor</th>
                    <th>Amount</th>
                    <th>Invoice #</th>
                    <th>Who</th>
                    <th>When</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dalRollbackList as $u): ?>
                <tr>
                    <td><input type="checkbox" class="prb-pick" value="<?= (int)$u['id'] ?>" data-confirm="<?= htmlspecialchars((string)$u['confirm']) ?>" data-line="<?= htmlspecialchars(trim('Daily Activity' . ($u['amount_label'] !== '' ? ' $' . $u['amount_label'] : '') . ($u['date'] !== '' ? ' ' . date('M j', strtotime($u['date'])) : ''))) ?>"></td>
                    <td><?= htmlspecialchars((string)$u['date_label']) ?></td>
                    <td><?= htmlspecialchars((string)$u['vendor']) ?></td>
                    <td><?= $u['amount_label'] !== '' ? '$' . htmlspecialchars((string)$u['amount_label']) : '' ?></td>
                    <td><?= htmlspecialchars((string)$u['invoice']) ?></td>
                    <td><?= htmlspecialchars((string)$u['who']) ?></td>
                    <td><?= htmlspecialchars((string)$u['when_label']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="btn-rollback" id="dalRollbackBtn" title="Restore only the ticked deletes for this restaurant.">Rollback selected</button>
    </div>
    <?php endif; ?>

    <button type="button" class="dal-chrome-toggle" id="dalChromeBtn" aria-expanded="false">Show filters &amp; add POS</button>
    <!-- dal-stack-v4-20261011 -->
    <div class="tool-bar" id="dalToolBar" hidden>
        <table class="dal-stack-table" id="dalStackTable" width="100%" cellspacing="0" cellpadding="0">
        <tr class="dal-stack-row" id="dalFilterRow">
        <td class="dal-stack-cell" id="dalFilterCard">
        <form method="GET" class="tool-section look" id="dalFilter">
            <?php if ($is_super): ?>
            <div class="tool-field">
                <label>Org</label>
                <select name="org_id" class="sheet-input tool-org" onchange="this.form.submit()">
                    <option value="all" <?= $selected_org === 0 ? 'selected' : '' ?>>All organizations</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="tool-field">
                <label>Start</label>
                <input type="date" name="start" value="<?= htmlspecialchars($start_date) ?>" class="sheet-input tool-date">
            </div>
            <div class="tool-field">
                <label>End</label>
                <input type="date" name="end" value="<?= htmlspecialchars($end_date) ?>" class="sheet-input tool-date">
            </div>
            <div class="tool-field">
                <label>Site</label>
                <select name="rid" id="filter_rid" class="sheet-input tool-site" onchange="this.form.submit()">
                    <option value="">All Sites</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['SId'] ?>" <?= (string)$selected_rid === (string)$s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tool-field">
                <label>Reports</label>
                <select name="rpt" id="filter_rpt" class="sheet-input" onchange="this.form.submit()">
                    <option value="all" <?= $selected_rpt === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="daily" <?= $selected_rpt === 'daily' ? 'selected' : '' ?>>Daily</option>
                    <option value="weekly" <?= $selected_rpt === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                    <option value="monthly" <?= $selected_rpt === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                </select>
            </div>
            <div class="tool-field" style="min-width:180px;">
                <label>Search</label>
                <input type="search" name="q" id="dalSearch" class="sheet-input" value="<?= htmlspecialchars($dal_search) ?>" placeholder="weekly, monthly, site" list="dalSearchHints" autocomplete="off">
                <datalist id="dalSearchHints">
                    <option value="weekly"></option>
                    <option value="monthly"></option>
                    <option value="daily"></option>
                </datalist>
            </div>
            <button type="submit" class="btn-load">Filter</button>
            <a class="btn-load" href="paid_out_report.php?<?= htmlspecialchars($porQs) ?>">Paid Out Report</a>
        </form>
        <?php if ($is_admin && $prbOrgId > 0): ?>
        <div class="tool-section look" style="margin-top:8px;">
            <div class="tool-field">
                <label>Rollback days</label>
                <select id="prbDaysSel" class="sheet-input" title="How long Rollback lasts for this organization. Same on every computer.">
                    <?php foreach (prb_allowed_days() as $d): ?>
                    <option value="<?= (int)$d ?>" <?= (int)$prbDays === (int)$d ? 'selected' : '' ?>><?= (int)$d ?> day<?= $d === 1 ? '' : 's' ?><?= $d === 7 ? ' (default)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        </td>
        </tr>
        <tr class="dal-stack-row" id="dalAddRow">
        <td class="dal-stack-cell" id="dalAddCard">
        <div class="tool-section add" id="dalAddBar">
            <span class="sec-name" id="dalFormMode">Add</span>
            <input type="hidden" id="dal_mode" value="create">
            <input type="hidden" id="dal_report_id" value="0">
            <div class="tool-field">
                <label>Day</label>
                <input type="date" id="new_date" class="sheet-input tool-date" value="<?= date('Y-m-d') ?>">
            </div>
            <?php if ($selected_rid === '' || $selected_rid === '0'): ?>
            <div class="tool-field">
                <label>Site</label>
                <select id="new_rid" class="sheet-input tool-site">
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= $s['SId'] ?>"><?= htmlspecialchars($s['nick_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" id="new_rid" value="<?= htmlspecialchars((string)$selected_rid) ?>">
            <?php endif; ?>
            <div class="tool-field dal-file-field" id="dalDailyField">
                <span class="tool-lbl">Daily POS</span>
                <label class="dal-filepick"><input type="file" id="new_pos_file" class="sheet-input tool-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*"><span class="dal-filepick-btn">Choose file</span><span class="dal-filepick-name">empty</span></label>
            </div>
            <div class="tool-field dal-file-field" id="dalWeeklyField">
                <span class="tool-lbl">Weekly POS</span>
                <label class="dal-filepick"><input type="file" id="new_weekly_pos_file" class="sheet-input tool-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*"><span class="dal-filepick-btn">Choose file</span><span class="dal-filepick-name">empty</span></label>
            </div>
            <div class="tool-field dal-file-field" id="dalMonthlyField">
                <span class="tool-lbl">Monthly POS</span>
                <label class="dal-filepick"><input type="file" id="new_monthly_pos_file" class="sheet-input tool-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*"><span class="dal-filepick-btn">Choose file</span><span class="dal-filepick-name">empty</span></label>
            </div>
            <div class="tool-field dal-file-field">
                <span class="tool-lbl">Production</span>
                <label class="dal-filepick"><input type="file" id="new_prod_file" class="sheet-input tool-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*"><span class="dal-filepick-btn">Choose file</span><span class="dal-filepick-name">empty</span></label>
            </div>
            <div class="tool-field dal-file-field">
                <span class="tool-lbl">Paid Out</span>
                <label class="dal-filepick"><input type="file" id="new_paidout_file" class="sheet-input tool-file" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,image/*"><span class="dal-filepick-btn">Choose file</span><span class="dal-filepick-name">empty</span></label>
            </div>
            <div class="tool-field">
                <label id="dalSalesLbl">Sales $ (if POS unread)</label>
                <input type="number" step="0.01" min="0" id="new_sales" class="sheet-input tool-paid" placeholder="0.00" title="Only when the POS does not read. Daily POS: that day's NET SALES. Weekly POS: the Weekly total. Monthly POS: the Monthly total (1st through Day). No daily sales needed for Weekly or Monthly.">
            </div>
            <div class="tool-field">
                <label>Paid Out $</label>
                <input type="number" step="0.01" min="0" id="new_paid" class="sheet-input tool-paid" placeholder="0.00" title="Type Paid Out when the image does not read">
            </div>
            <div class="tool-field" id="dalPeriodField" style="min-width:240px;">
                <label>Report</label>
                <select id="dal_period" class="sheet-input" title="Edit only: change Daily / Weekly / Monthly. Add uses the POS file box.">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly — last 7 days through Day</option>
                    <option value="monthly">Monthly — 1st through Day</option>
                </select>
            </div>
            <div class="tool-field" id="dalNeedHint"><!-- dal-sales-after-20261013 -->Attach Daily / Weekly / Monthly POS — each saves as its own line. If Sales $ does not read, type that line's own total (Weekly total / Monthly total) in red on the row.</div>
            <button type="button" onclick="saveDayReports()" class="btn-save-day" id="dalSaveBtn" title="Attach Daily / Weekly / Monthly POS. Sales $ is typed on the row if unread.">Save</button>
            <button type="button" class="btn-cancel-edit" id="dalCancelEdit" onclick="cancelDalEdit()">Cancel</button>
        </div>
        </td>
        </tr>
        </table>
    </div>


<script>
(function () {
  document.querySelectorAll(".dal-filepick input[type=file]").forEach(function (inp) {
    inp.addEventListener("change", function () {
      var name = inp.parentNode.querySelector(".dal-filepick-name");
      if (!name) return;
      name.textContent = (inp.files && inp.files[0]) ? inp.files[0].name : "empty";
    });
  });
})();
</script>
<script>
(function () {
  var btn = document.getElementById('dalChromeBtn');
  var bar = document.getElementById('dalToolBar');
  if (!btn || !bar) return;
  var key = 'dalChromeOpen';
  function setOpen(open) {
    if (open) bar.removeAttribute('hidden');
    else bar.setAttribute('hidden', '');
    bar.style.setProperty('display', open ? 'block' : 'none', 'important');
    bar.style.setProperty('flex-direction', 'column', 'important');
    bar.style.setProperty('flex-wrap', 'nowrap', 'important');
    bar.style.setProperty('width', '100%', 'important');
    bar.style.removeProperty('grid-template-columns');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.textContent = open ? 'Hide filters & add POS' : 'Show filters & add POS';
    try { localStorage.setItem(key, open ? '1' : '0'); } catch (e) {}
  }
  var stored = null;
  try { stored = localStorage.getItem(key); } catch (e) {}
  setOpen(stored !== '0');
  btn.addEventListener('click', function () { setOpen(btn.getAttribute('aria-expanded') !== 'true'); });
})();
</script>
    <div class="table-wrap">
    <?php
    require_once __DIR__ . '/engine/mtd_allocate.php';
    $dalPeriodBars = dal_collect_period_windows($pdo, $DAL_UPLOAD_ROOT, $reports);
    $dalPeriodVisible = [];
    foreach ($reports as $_vr) {
        $_vp = dal_row_period($_vr['remarks'] ?? '');
        $dalPeriodVisible[(int)$_vr['restaurant_id'] . '|' . substr((string)$_vr['report_date'], 0, 10) . '|' . $_vp] = true;
    }
    $dalPeriodBarAfter = [];
    foreach ($dalPeriodBars as $_vb) {
        $_bp = (string)($_vb['period'] ?? '');
        if ($_bp !== 'weekly' && $_bp !== 'monthly') {
            continue;
        }
        $endKey = $_vb['rid'] . '|' . $_vb['to'] . '|' . $_bp;
        if (!empty($dalPeriodVisible[$endKey])) {
            $dalPeriodBarAfter[$endKey][] = $_vb;
        }
    }
    $dalSumSales = 0.0;
    $dalSumLabor = 0.0;
    $dalSumPaid = 0.0;
    $dalMaxMtd = 0.0;
    $dalMaxFood = 0.0;
    $dalMaxLabMtd = 0.0;
    $dalPctFoodSum = 0.0;
    $dalPctLabSum = 0.0;
    $dalPctN = 0;
    $dalSumN = 0;
    foreach ($reports as $_r) {
        if (dal_row_period($_r['remarks'] ?? '') !== 'daily') {
            continue;
        }
        $dalSumN++;
        $dalSumSales += (float)$_r['pos_sales'];
        $dalSumLabor += (float)($_r['labor_daily_calc'] ?? 0);
        $dalSumPaid += (float)($_r['paid_out'] ?? 0);
        $_mtd = (float)$_r['mtd_sales_calc'];
        $_food = (float)($_r['food_mtd_calc'] ?? $_r['food_day_calc'] ?? 0);
        $_labMtd = (float)($_r['labor_mtd_calc'] ?? 0);
        if ($_mtd > $dalMaxMtd) $dalMaxMtd = $_mtd;
        if ($_food > $dalMaxFood) $dalMaxFood = $_food;
        if ($_labMtd > $dalMaxLabMtd) $dalMaxLabMtd = $_labMtd;
        if ($_mtd > 0.004) {
            $dalPctFoodSum += ($_food / $_mtd) * 100;
            $dalPctLabSum += ($_labMtd / $_mtd) * 100;
            $dalPctN++;
        }
    }
    $dalAvgFoodPct = $dalPctN ? ($dalPctFoodSum / $dalPctN) : 0.0;
    $dalAvgLabPct = $dalPctN ? ($dalPctLabSum / $dalPctN) : 0.0;
    ?>
    <table class="rpt-table" id="reportTable">
        <thead>
            <tr>
                <th class="col-site" width="12%">Restaurant</th>
                <th class="col-date" width="11%">Date</th>
                <th width="9%">Sales</th>
                <th width="11%">MTD Sales</th>
                <th width="10%" title="Month-to-date FoodCost invoices through this day">Food Cost</th>
                <th width="8%" title="MTD Food Cost / MTD Sales">Food %</th>
                <th width="9%" title="This day's clock-in labor $">Labor $</th>
                <th width="10%" title="Month-to-date clock-in labor $ through this day">Labor MTD</th>
                <th width="8%" title="MTD Labor $ / MTD Sales">Labor %</th>
                <th width="10%">POS Report *</th>
                <th width="10%">Production</th>
                <th width="12%">Paid Out</th>
                <th width="8%" style="text-align:center;" data-nosort="1">Action</th>
            </tr>
            <?= dal_totals_row($dalSumSales, $dalMaxMtd, $dalMaxFood, $dalAvgFoodPct, $dalSumLabor, $dalMaxLabMtd, $dalAvgLabPct, $dalSumPaid, $dalSumN) ?>
        </thead>
        <tbody id="tableBody">
            <?php
            foreach ($reports as $r):
                $pos = (float)$r['pos_sales'];
                $paidOut = (float)($r['paid_out'] ?? 0);
                $mtd = (float)$r['mtd_sales_calc'];
                $food = (float)($r['food_mtd_calc'] ?? $r['food_day_calc'] ?? 0);
                $lab_day = (float)($r['labor_daily_calc'] ?? 0);
                $lab_mtd = (float)($r['labor_mtd_calc'] ?? 0);
                $food_pct = ($mtd > 0) ? ($food / $mtd) * 100 : 0;
                $lab_pct  = ($mtd > 0) ? ($lab_mtd / $mtd) * 100 : 0;
                $rid = (int)$r['restaurant_id'];
                $repId = (int)($r['report_id'] ?? 0);
                $rDate = (string)$r['report_date'];
                $rmk = (string)($r['remarks'] ?? '');
                $periodLab = '';
                $periodKey = 'daily';
                if (preg_match('/week_split/i', $rmk)) { $periodLab = 'Weekly'; $periodKey = 'weekly'; }
                elseif (preg_match('/mtd_split/i', $rmk)) { $periodLab = 'Monthly'; $periodKey = 'monthly'; }
                $needsRecheck = ($pos < 0.005) || ($repId <= 0);
                $rowPosFile = ($repId > 0) ? dal_line_pos_file($DAL_UPLOAD_ROOT, $rid, $rDate, $periodKey) : null;
                // A Monthly line is the MTD total itself; Daily/Weekly lines show the daily-lines MTD.
                $mtd = dal_line_mtd_display($periodKey, $pos, $mtd);
                $food_pct = ($mtd > 0) ? ($food / $mtd) * 100 : 0;
                $lab_pct  = ($mtd > 0) ? ($lab_mtd / $mtd) * 100 : 0;
                $periodTotal = '';
                if ($periodKey === 'weekly' || $periodKey === 'monthly') {
                    $pv = dal_read_period_verify($DAL_UPLOAD_ROOT, $rid, $periodKey, $rDate);
                    if (is_array($pv) && isset($pv['period_total']) && $pv['period_total'] !== '') {
                        $periodTotal = number_format((float)$pv['period_total'], 2, '.', '');
                    }
                }
                $date_label = htmlspecialchars(date('M d, Y', strtotime($rDate)));
                $fcMonthStart = date('Y-m-01', strtotime($rDate));
                $fcLink = 'food_cost_view.php?rid=' . $rid . '&start=' . rawurlencode($fcMonthStart) . '&end=' . rawurlencode($rDate);
            ?>
            <tr class="data-row<?= $needsRecheck ? ' dal-recheck' : '' ?><?= $periodKey === 'weekly' ? ' dal-row-weekly' : ($periodKey === 'monthly' ? ' dal-row-monthly' : '') ?>" data-id="<?= $repId ?>" data-rid="<?= $rid ?>" data-date="<?= htmlspecialchars($rDate) ?>" data-period="<?= htmlspecialchars($periodKey) ?>" data-period-total="<?= htmlspecialchars($periodTotal) ?>" data-sales="<?= htmlspecialchars(number_format($pos, 2, '.', '')) ?>" data-paid="<?= htmlspecialchars(number_format($paidOut, 2, '.', '')) ?>">
                <td class="col-site" data-sort="<?= htmlspecialchars($r['nick_name']) ?>"><a href="#dalAddBar" class="site-edit" data-rid="<?= $rid ?>" data-date="<?= htmlspecialchars($rDate) ?>" data-id="<?= $repId ?>" data-name="<?= htmlspecialchars($r['nick_name']) ?>"><?= htmlspecialchars($r['nick_name']) ?></a></td>
                <td class="col-date" data-sort="<?= htmlspecialchars($rDate) ?>"><div class="dal-date-stack"><span class="dal-date-text"><?= $date_label ?></span><?php if ($periodLab !== ''): ?><span class="dal-period-tag dal-period-<?= htmlspecialchars($periodKey) ?>"><?= htmlspecialchars($periodLab) ?></span><?php endif; ?></div></td>
                <td data-sort="<?= htmlspecialchars((string)$pos) ?>"><input type="text" inputmode="decimal" autocomplete="off" class="dal-manual-amt<?= $needsRecheck ? ' dal-recheck-amt' : '' ?>" data-id="<?= $repId ?>" data-rid="<?= $rid ?>" data-date="<?= htmlspecialchars($rDate) ?>" data-field="pos_sales" value="<?= htmlspecialchars(number_format($pos, 2, '.', '')) ?>" title="<?= htmlspecialchars(dal_recheck_title($periodKey, $needsRecheck)) ?>"><?php if ($needsRecheck): ?><div class="dal-recheck-hint"><?= htmlspecialchars(dal_recheck_hint($periodKey)) ?></div><?php elseif ($rowPosFile && !empty($rowPosFile['sales_typed'])): ?><div class="dal-typed-hint" title="Sales $ entered by hand; POS file kept">Typed by hand</div><?php endif; ?></td>
                <td data-sort="<?= htmlspecialchars((string)$mtd) ?>">$<?= number_format($mtd, 2) ?></td>
                <td data-sort="<?= htmlspecialchars((string)$food) ?>"><?php if (abs($food) > 0.004): ?><a href="<?= htmlspecialchars($fcLink) ?>" style="font-weight:800;color:#0f766e;">$<?= number_format($food, 2) ?></a><?php else: ?>$<?= number_format($food, 2) ?><?php endif; ?></td>
                <td data-sort="<?= htmlspecialchars((string)$food_pct) ?>"><span class="<?= ($food_pct > 35) ? 'val-bad' : '' ?>"><?= number_format($food_pct, 1) ?>%</span></td>
                <td data-sort="<?= htmlspecialchars((string)$lab_day) ?>">$<?= number_format($lab_day, 2) ?></td>
                <td data-sort="<?= htmlspecialchars((string)$lab_mtd) ?>">$<?= number_format($lab_mtd, 2) ?></td>
                <td data-sort="<?= htmlspecialchars((string)$lab_pct) ?>"><span class="<?= ($lab_pct > 25) ? 'val-bad' : '' ?>"><?= number_format($lab_pct, 1) ?>%</span></td>
                <td data-sort="<?= dal_find_for_row($DAL_UPLOAD_ROOT, $rid, $r['report_date'], 'pos') ? '1' : '0' ?>"><?= dal_file_cell($DAL_UPLOAD_ROOT, $rid, $r['report_date'], 'pos', $periodKey) ?></td>
                <td data-sort="<?= dal_find_for_row($DAL_UPLOAD_ROOT, $rid, $r['report_date'], 'production') ? '1' : '0' ?>"><?= dal_file_cell($DAL_UPLOAD_ROOT, $rid, $r['report_date'], 'production') ?></td>
                <td class="paid-cell" data-sort="<?= htmlspecialchars((string)$paidOut) ?>">
                    <div class="amt-ro">$<?= number_format($paidOut, 2) ?></div>
                    <?= dal_file_cell($DAL_UPLOAD_ROOT, $rid, $r['report_date'], 'paidout') ?>
                </td>
                <td style="text-align:center;">
                    <button type="button" class="btn-row-edit" data-rid="<?= $rid ?>" data-date="<?= htmlspecialchars($rDate) ?>" data-id="<?= $repId ?>" data-name="<?= htmlspecialchars($r['nick_name']) ?>">Edit</button>
                    <?php if ($repId > 0 && $is_admin): ?>
                    <i class="fa fa-trash-alt icon-trash-row" title="Delete Log Line — Admin only" onclick="deleteReportRecord(<?= $repId ?>)"></i>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
                $afterKey = $rid . '|' . substr($rDate, 0, 10) . '|' . $periodKey;
                if (!empty($dalPeriodBarAfter[$afterKey])) {
                    foreach ($dalPeriodBarAfter[$afterKey] as $_barInfo) {
                        if ((string)($_barInfo['period'] ?? '') === $periodKey) {
                            echo dal_period_verify_bar($_barInfo);
                        }
                    }
                    unset($dalPeriodBarAfter[$afterKey]);
                }
            endforeach; ?>
        </tbody>
        <tfoot>
            <?= dal_totals_row($dalSumSales, $dalMaxMtd, $dalMaxFood, $dalAvgFoodPct, $dalSumLabor, $dalMaxLabMtd, $dalAvgLabPct, $dalSumPaid, $dalSumN) ?>
        </tfoot>
    </table>
    </div>
</div>

<aside id="dalPosPreview" aria-hidden="true">
    <div class="dal-prev-resize" id="dalPrevResize" title="Drag to widen"></div>
    <div class="dal-prev-head">
        <div>
            <strong id="dalPrevTitle">POS report</strong>
            <span id="dalPrevSub"></span>
        </div>
        <div class="dal-prev-tools">
            <button type="button" id="dalPrevZoomOut" title="Zoom out">−</button>
            <span class="dal-prev-zoom-pct" id="dalPrevZoomPct">100%</span>
            <button type="button" id="dalPrevZoomIn" title="Zoom in">+</button>
            <button type="button" id="dalPrevZoomFit" title="Reset zoom">Fit</button>
            <button type="button" id="dalPrevWider" title="Make the receipt panel wider">Wider</button>
            <button type="button" id="dalPrevFullW" title="Use the full screen width">Full</button>
            <button type="button" class="dal-prev-x" id="dalPrevClose" title="Close">&times;</button>
        </div>
    </div>
    <div class="dal-prev-body" id="dalPrevBody"></div>
    <div class="dal-prev-foot" id="dalPrevFoot">
        <label id="dalPrevAmtLabel" for="dalPrevAmt">Sales $</label>
        <input type="text" inputmode="decimal" autocomplete="off" id="dalPrevAmt" placeholder="0.00">
        <div class="dal-prev-actions">
            <button type="button" class="dal-prev-save" id="dalPrevSave">Save figure</button>
            <button type="button" class="dal-prev-x" id="dalPrevClose2" title="Close" style="width:auto;padding:0 10px;height:32px;">Close</button>
            <a class="dal-prev-full" id="dalPrevFull" href="#" target="_blank" rel="noopener">Open full size</a>
        </div>
    </div>
</aside>

<script src="engine/table_sort.js?v=20261008v"></script>
<script>
function flashStatus(text, color, ms) {
    $('#status-msg').text(text).css('background', color || '#16a34a').fadeIn();
    setTimeout(function() { $('#status-msg').fadeOut(); }, ms || 2500);
}
function dalRewriteOldBars() {
    document.querySelectorAll('.dal-period-verify-inner').forEach(function (el) {
        var raw = (el.textContent || '').replace(/\s+/g, ' ').trim();
        var m = raw.match(/^(Weekly|Monthly|Daily) POS \$([0-9,]+\.\d{2}) · days \$([0-9,]+\.\d{2}) · (OVER|SHORT|MATCH)(?: \$([0-9,]+\.\d{2}))?$/i);
        if (!m) return;
        var kind = m[1];
        var pos = m[2];
        var daily = m[3];
        var word = (m[4] || '').toUpperCase();
        var by = m[5] || '';
        var text;
        if (word === 'MATCH') {
            text = 'Daily sales $' + daily + ' match ' + kind + ' POS $' + pos + '.';
        } else if (word === 'OVER') {
            text = 'Daily sales $' + daily + ' are OVER ' + kind + ' POS $' + pos + ' by $' + by;
        } else {
            text = 'Daily sales $' + daily + ' are SHORT of ' + kind + ' POS $' + pos + ' by $' + by;
        }
        el.innerHTML = text.replace('OVER', '<span class="dal-mismatch">OVER</span>').replace('SHORT', '<span class="dal-mismatch">SHORT</span>');
    });
}
window.dalAfterTableSort = function () {
    document.querySelectorAll('tr.dal-period-verify').forEach(function (bar) {
        var rid = bar.getAttribute('data-rid');
        var end = bar.getAttribute('data-end');
        var period = bar.getAttribute('data-period') || '';
        var host = document.querySelector('tr.data-row[data-rid="' + rid + '"][data-date="' + end + '"][data-period="' + period + '"]');
        if (host) host.after(bar);
    });
    dalRewriteOldBars();
};
document.addEventListener('DOMContentLoaded', dalRewriteOldBars);

function startDalEdit(rid, date, reportId, name) {
    $('#dal_mode').val('edit');
    $('#dal_report_id').val(reportId || 0);
    $('#dalFormMode').text('Edit');
    $('#dalAddBar').addClass('editing');
    $('#dalSaveBtn').text('Save edit');
    $('#dalCancelEdit').show();
    $('#new_date').val(date).prop('disabled', true);
    const $rid = $('#new_rid');
    if ($rid.is('select')) {
        $rid.val(String(rid)).prop('disabled', true);
    } else {
        $rid.val(String(rid));
    }
    $('#new_pos_file, #new_weekly_pos_file, #new_monthly_pos_file, #new_prod_file, #new_paidout_file').val('');
    $('tr.data-row').removeClass('editing-row');
    var $row = $('tr.data-row').filter(function() {
        return String($(this).attr('data-id')) === String(reportId || '') && String(reportId || '0') !== '0';
    });
    if (!$row.length) {
        $row = $('tr.data-row').filter(function() {
            return String($(this).attr('data-rid')) === String(rid) && String($(this).attr('data-date')) === String(date);
        });
    }
    $row.addClass('editing-row');
    var rowPeriod = $row.attr('data-period') || 'daily';
    $('#dal_period').prop('disabled', false).val(rowPeriod);
    $('#new_paid').val($row.attr('data-paid') || '');
    $('#new_sales').val('');
    if (typeof dalApplyDateRules === 'function') dalApplyDateRules({ keepPeriod: true });
    var chromeBtn = document.getElementById('dalChromeBtn');
    var toolBar = document.getElementById('dalToolBar');
    if (toolBar && (toolBar.hidden || toolBar.style.display === 'none') && chromeBtn) chromeBtn.click();
    flashStatus('Editing ' + (name || 'this day') + ' — pick Daily, Weekly, or Monthly.', '#2563eb');
    const bar = document.getElementById('dalAddBar');
    if (bar) bar.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cancelDalEdit() {
    $('#dal_mode').val('create');
    $('#dal_report_id').val(0);
    $('#dalFormMode').text('Add');
    $('#dalAddBar').removeClass('editing');
    $('#dalSaveBtn').text('Save');
    $('#dalCancelEdit').hide();
    $('#new_date').prop('disabled', false);
    const $rid = $('#new_rid');
    if ($rid.is('select')) $rid.prop('disabled', false);
    $('#new_pos_file, #new_weekly_pos_file, #new_monthly_pos_file, #new_prod_file, #new_paidout_file').val('');
    $('#dal_period').val('daily');
    $('#new_paid').val('');
    $('#new_sales').val('');
    $('tr.data-row').removeClass('editing-row');
    if (typeof dalApplyDateRules === 'function') dalApplyDateRules();
}

$(document).on('click', '.site-edit, .btn-row-edit', function(e) {
    e.preventDefault();
    startDalEdit(this.getAttribute('data-rid'), this.getAttribute('data-date'), this.getAttribute('data-id'), this.getAttribute('data-name'));
});
function dalLiveSearch() {
    var q = (($('#dalSearch').val() || '') + '').toLowerCase().trim();
    function periodOf($r) { return (($r.attr('data-period') || 'daily') + '').toLowerCase(); }
    function matchRow($r) {
        if (!q) return true;
        var period = periodOf($r);
        if (q === 'weekly' || q === 'week') return period === 'weekly';
        if (q === 'monthly' || q === 'month') return period === 'monthly';
        if (q === 'daily' || q === 'day') return period === 'daily';
        var text = (($r.text() || '') + ' ' + period).toLowerCase();
        return text.indexOf(q) !== -1;
    }
    $('tr.data-row').each(function () {
        var $r = $(this);
        $r.toggle(matchRow($r));
    });
    $('tr.dal-period-verify').each(function () {
        var bar = this;
        var rid = bar.getAttribute('data-rid');
        var end = bar.getAttribute('data-end');
        var period = bar.getAttribute('data-period') || '';
        var host = document.querySelector('tr.data-row[data-rid="' + rid + '"][data-date="' + end + '"][data-period="' + period + '"]');
        var show = host && host.style.display !== 'none' && !host.hidden;
        $(bar).toggle(!!show);
    });
}
$(document).on('input', '#dalSearch', dalLiveSearch);
$(function () { dalLiveSearch(); });

function dalDateParts(iso) {
    if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return { sun: false, monthEnd: false };
    var p = iso.split('-').map(Number);
    var d = new Date(p[0], p[1] - 1, p[2]);
    var last = new Date(p[0], p[1], 0).getDate();
    return { sun: d.getDay() === 0, monthEnd: p[2] === last };
}
function dalPosFilesChosen() {
    function has(id) {
        var el = document.getElementById(id);
        return !!(el && el.files && el.files[0]);
    }
    return has('new_pos_file') || has('new_weekly_pos_file') || has('new_monthly_pos_file');
}
function dalApplyDateRules(opts) {
    opts = opts || {};
    var d = document.getElementById('dalDailyField');
    var w = document.getElementById('dalWeeklyField');
    var m = document.getElementById('dalMonthlyField');
    var hint = document.getElementById('dalNeedHint');
    var sel = document.getElementById('dal_period');
    var wrap = document.getElementById('dalPeriodField');
    var hasFiles = dalPosFilesChosen();
    var modeEl = document.getElementById('dal_mode');
    var mode = (modeEl && modeEl.value) ? modeEl.value : 'create';
    var showReport = (mode === 'edit' && !hasFiles);
    if (d) d.hidden = false;
    if (w) w.hidden = false;
    if (m) m.hidden = false;
    if (wrap) wrap.hidden = !showReport;
    if (sel) sel.disabled = !showReport;
    if (hint) hint.textContent = 'Attach Daily / Weekly / Monthly POS — each saves as its own line. If Sales $ does not read, type that line\'s own total (Weekly total / Monthly total) in red on the row.';
    var salesLbl = document.getElementById('dalSalesLbl');
    if (salesLbl) {
        var pick = 'daily';
        var hasM = document.getElementById('new_monthly_pos_file'), hasW = document.getElementById('new_weekly_pos_file'), hasD = document.getElementById('new_pos_file');
        var chosen = function (x) { return !!(x && x.files && x.files[0]); };
        var nChosen = (chosen(hasD) ? 1 : 0) + (chosen(hasW) ? 1 : 0) + (chosen(hasM) ? 1 : 0);
        if (chosen(hasM) && nChosen === 1) pick = 'monthly';
        else if (chosen(hasW) && nChosen === 1) pick = 'weekly';
        else if (!nChosen && sel && !sel.disabled) pick = sel.value || 'daily';
        salesLbl.textContent = pick === 'monthly' ? 'Monthly total $ (if POS unread)' : (pick === 'weekly' ? 'Weekly total $ (if POS unread)' : 'Sales $ (if POS unread)');
    }
}
document.addEventListener('DOMContentLoaded', function () {
    var day = document.getElementById('new_date');
    if (day) day.addEventListener('change', function () { dalApplyDateRules(); });
    var sel = document.getElementById('dal_period');
    if (sel) sel.addEventListener('change', function () { dalApplyDateRules({ fromPeriod: true, keepPeriod: true }); });
    ['new_pos_file', 'new_weekly_pos_file', 'new_monthly_pos_file'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', function () { dalApplyDateRules(); });
    });
    dalApplyDateRules();
});
function saveDayReports() {
    const rid = $('#new_rid').val();
    if (!rid) {
        alert('Pick a site first (Filter → Site), then Save.');
        return;
    }
    const mode = $('#dal_mode').val() || 'create';
    const posFile = $('#new_pos_file')[0].files[0];
    const weeklyPos = $('#new_weekly_pos_file')[0] ? $('#new_weekly_pos_file')[0].files[0] : null;
    const monthlyPos = $('#new_monthly_pos_file')[0] ? $('#new_monthly_pos_file')[0].files[0] : null;
    const prodFile = $('#new_prod_file')[0].files[0];
    const paidFile = $('#new_paidout_file')[0].files[0];
    const typedPaid = ($('#new_paid').val() || '').trim();
    const typedSales = ($('#new_sales').val() || '').trim();
    var hasPosFile = !!(posFile || weeklyPos || monthlyPos);
    var period = hasPosFile ? '' : ($('#dal_period').val() || 'daily');
    if (mode === 'create' && !hasPosFile) {
        alert('Choose a Daily / Weekly / Monthly POS.');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'save_day');
    fd.append('mode', mode);
    fd.append('report_id', $('#dal_report_id').val() || 0);
    fd.append('restaurant_id', rid);
    fd.append('report_date', $('#new_date').val());
    fd.append('period', period);
    fd.append('month_end', (!hasPosFile && period === 'monthly') ? '1' : '0');
    if (typedPaid !== '') fd.append('manual_paid_out', typedPaid);
    if (typedSales !== '') fd.append('manual_sales', typedSales);
    if (posFile) fd.append('pos_file', posFile, posFile.name);
    if (weeklyPos) fd.append('weekly_pos_file', weeklyPos, weeklyPos.name);
    if (monthlyPos) fd.append('monthly_pos_file', monthlyPos, monthlyPos.name);
    if (prodFile) fd.append('prod_file', prodFile, prodFile.name);
    if (paidFile) fd.append('paidout_file', paidFile, paidFile.name);
    const btn = document.querySelector('.btn-save-day');
    if (btn) btn.disabled = true;
    flashStatus('Reading reports…', '#0f172a');
    fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success) {
                var flashColor = '#16a34a';
                var flashMs = 2500;
                if (res.verify_status && res.verify_status !== 'match') flashColor = '#dc2626';
                var types = res.types || (res.period ? [res.period] : []);
                if (types.indexOf('weekly') >= 0 || types.indexOf('monthly') >= 0 || res.verify_text) flashMs = 8000;
                var needSales = res.need_sales || [];
                if (needSales.length) {
                    flashColor = '#dc2626';
                    flashMs = 8000;
                }
                try { sessionStorage.setItem('dalFlash', JSON.stringify({ text: res.message || 'Saved', color: flashColor, ms: flashMs })); } catch (e) {}
                if (needSales.length) {
                    try { sessionStorage.setItem('dalFocusSales', String(needSales[0])); } catch (e) {}
                }
                if ((types.indexOf('weekly') >= 0 || types.indexOf('monthly') >= 0 || res.period === 'weekly' || res.period === 'monthly') && res.period_from && res.period_to) {
                    var u = new URL(window.location.href);
                    var curStart = u.searchParams.get('start') || '';
                    var curEnd = u.searchParams.get('end') || '';
                    if (!curStart || curStart > res.period_from) u.searchParams.set('start', res.period_from);
                    if (!curEnd || curEnd < res.period_to) u.searchParams.set('end', res.period_to);
                    window.location = u.toString();
                    return;
                }
                location.reload();
                return;
            }
            if (btn) btn.disabled = false;
            alert('Save failed: ' + ((res && res.error) || 'Unknown error'));
        })
        .catch(function() {
            if (btn) btn.disabled = false;
            alert('Save failed. Try again.');
        });
}

function deleteReportRecord(reportId) {
    if (confirm("Delete this daily report log entry? Admin can tick it on the Rollback list.")) {
        const data = { action: 'delete', report_id: parseInt(reportId) };
        $.post(window.location.pathname, data, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Deletion Failed: ' + (res.error || 'Unknown Error'));
            }
        }, 'json');
    }
}
function rollbackDalRecord() {
    var picks = document.querySelectorAll('#dalRollbackBox .prb-pick:checked');
    if (!picks.length) {
        alert('Tick the day(s) to restore.');
        return;
    }
    var ids = [];
    var lines = [];
    var firstConfirm = '';
    picks.forEach(function (cb) {
        ids.push(cb.value);
        lines.push(cb.getAttribute('data-line') || '');
        if (!firstConfirm) firstConfirm = cb.getAttribute('data-confirm') || '';
    });
    var msg = picks.length === 1
        ? (firstConfirm || 'Restore this Daily Activity?')
        : ('Restore these ' + picks.length + ' days only?\n\n' + lines.join('\n') + '\n\nOK = put the ticked ones back. Other deletes stay.');
    if (!confirm(msg)) return;
    $.post(window.location.pathname, { action: 'rollback', site_id: <?= (int)$selected_rid ?>, ids: ids }, function(res) {
        if (res.success) location.reload();
        else alert(res.error || 'Rollback failed');
    }, 'json');
}
document.addEventListener('DOMContentLoaded', function () {
    var b = document.getElementById('dalRollbackBtn');
    if (b) b.addEventListener('click', rollbackDalRecord);
    var daysSel = document.getElementById('prbDaysSel');
    if (daysSel) {
        daysSel.addEventListener('change', function () {
            $.post(window.location.pathname, {
                action: 'set_rollback_days',
                org_id: <?= (int)$prbOrgId ?>,
                site_id: <?= (int)$selected_rid ?>,
                days: daysSel.value
            }, function (res) {
                if (!res || !res.success) alert((res && res.error) || 'Could not save Rollback days');
            }, 'json').fail(function () { alert('Could not save Rollback days'); });
        });
    }
});
(function() {
    try {
        var raw = sessionStorage.getItem('dalFlash');
        if (raw) {
            sessionStorage.removeItem('dalFlash');
            var f = JSON.parse(raw);
            flashStatus(f.text || 'Saved', f.color || '#16a34a', f.ms || 2500);
        }
    } catch (e) {}
    try {
        var focusId = sessionStorage.getItem('dalFocusSales');
        if (focusId) {
            sessionStorage.removeItem('dalFocusSales');
            var inp = document.querySelector('input.dal-manual-amt[data-field="pos_sales"][data-id="' + focusId + '"]');
            if (inp) {
                var row = inp.closest('tr');
                if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(function() { try { inp.focus(); inp.select(); } catch (e2) {} }, 200);
            }
        }
    } catch (e) {}
    if (document.getElementById('reportTable')) makeTableSortable('reportTable');
})();
$(document).on('change', '.dal-manual-amt', function() {
    var el = this;
    var id = parseInt(el.getAttribute('data-id') || '0', 10);
    var field = el.getAttribute('data-field') || 'pos_sales';
    var rid = el.getAttribute('data-rid') || $(el).closest('tr.data-row').attr('data-rid') || '';
    var date = el.getAttribute('data-date') || $(el).closest('tr.data-row').attr('data-date') || '';
    var typed = String(el.value || '').replace(/[,$\s]/g, '');
    if (typed === '' || isNaN(Number(typed)) || Number(typed) < 0) {
        alert('Type the amount as a number, for example 12410.21.');
        return;
    }
    $.post(window.location.pathname, { action: 'update', report_id: id, field: field, value: typed, restaurant_id: rid, report_date: date }, function(res) {
        if (res && res.success) {
            if (res.report_id) el.setAttribute('data-id', String(res.report_id));
            try { sessionStorage.setItem('dalFlash', JSON.stringify({ text: res.message || 'Saved', color: '#16a34a' })); } catch (e) {}
            location.reload();
            return;
        }
        alert((res && res.error) || 'Could not save.');
    }, 'json').fail(function() { alert('Could not save.'); });
});

(function() {
    function el(id) { return document.getElementById(id); }
    var panel = el('dalPosPreview');
    var bodyEl = el('dalPrevBody');
    var titleEl = el('dalPrevTitle');
    var subEl = el('dalPrevSub');
    var amtEl = el('dalPrevAmt');
    var amtLabel = el('dalPrevAmtLabel');
    var fullEl = el('dalPrevFull');
    var saveBtn = el('dalPrevSave');
    var zoomPct = el('dalPrevZoomPct');
    var resizeHandle = el('dalPrevResize');
    var state = { href: '', kind: 'pos', reportId: 0, field: 'pos_sales', rid: 0, date: '', zoom: 1, width: 0 };
    var supportsZoom = (function () {
        try {
            var t = document.createElement('div');
            t.style.zoom = '2';
            return t.style.zoom === '2' && !/firefox/i.test(navigator.userAgent || '');
        } catch (e) { return false; }
    })();

    function clamp(n, lo, hi) { return Math.max(lo, Math.min(hi, n)); }

    function setPanelWidth(px) {
        if (!panel) return;
        var min = 360;
        var max = Math.max(min, window.innerWidth - 8);
        px = clamp(Math.round(px), min, max);
        state.width = px;
        panel.style.width = px + 'px';
        panel.style.maxWidth = '100vw';
        try { localStorage.setItem('dalPrevWidth', String(px)); } catch (e) {}
    }

    function restoreWidth() {
        var saved = 0;
        try { saved = parseInt(localStorage.getItem('dalPrevWidth') || '0', 10) || 0; } catch (e) {}
        if (!saved) saved = Math.min(720, Math.round(window.innerWidth * 0.55));
        setPanelWidth(saved);
    }

    function applyZoom(z) {
        z = Math.round(clamp(z, 0.4, 4) * 100) / 100;
        state.zoom = z;
        var stage = bodyEl ? bodyEl.querySelector('.dal-prev-stage') : null;
        if (stage) {
            if (supportsZoom) {
                stage.style.zoom = String(z);
                stage.style.transform = '';
                stage.style.width = '';
                stage.style.height = '';
            } else {
                stage.style.zoom = '';
                stage.style.transform = 'scale(' + z + ')';
                stage.style.transformOrigin = 'top left';
                stage.style.width = (100 / z) + '%';
                stage.style.height = (100 / z) + '%';
            }
        }
        if (zoomPct) zoomPct.textContent = Math.round(z * 100) + '%';
        try { localStorage.setItem('dalPrevZoom', String(z)); } catch (e) {}
    }

    function restoreZoom() {
        var saved = 1;
        try { saved = parseFloat(localStorage.getItem('dalPrevZoom') || '1') || 1; } catch (e) {}
        applyZoom(saved);
    }

    function closePreview() {
        if (!panel) return;
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('dal-preview-open');
        if (bodyEl) bodyEl.innerHTML = '';
    }

    function focusAmt() {
        if (!amtEl || state.kind === 'production') return;
        try { amtEl.focus(); amtEl.select(); } catch (e) {}
    }

    function guessExt(opts) {
        var e = String(opts.ext || '').toLowerCase().replace(/^\./, '');
        if (e) return e;
        var h = String(opts.href || '').toLowerCase();
        var m = h.match(/\.(pdf|png|jpe?g|webp|gif)(?:$|[?#])/);
        return m ? m[1].replace('jpeg', 'jpg') : '';
    }

    function pdfSrc(href) {
        if (!href) return href;
        if (href.indexOf('#') !== -1) return href;
        return href + '#toolbar=1&navpanes=0&zoom=page-width';
    }

    function openPreview(opts) {
        if (!panel || !bodyEl) return;
        state.href = opts.href || '';
        state.kind = opts.kind || 'pos';
        state.reportId = parseInt(opts.reportId || '0', 10) || 0;
        state.rid = parseInt(opts.rid || '0', 10) || 0;
        state.date = opts.date || '';
        state.field = (state.kind === 'paidout') ? 'paid_out' : 'pos_sales';
        var kindTitle = state.kind === 'paidout' ? 'Paid Out' : (state.kind === 'production' ? 'Production' : 'POS report');
        titleEl.textContent = kindTitle;
        subEl.textContent = [opts.name, opts.date].filter(Boolean).join(' · ');
        amtLabel.textContent = state.kind === 'paidout' ? 'Paid Out $' : 'Sales $';
        amtEl.value = opts.amount || '';
        document.getElementById('dalPrevFoot').style.display = (state.kind === 'production') ? 'none' : 'block';
        if (fullEl) {
            fullEl.href = state.href || '#';
            fullEl.style.display = state.href ? 'inline' : 'none';
        }
        bodyEl.innerHTML = '';
        var ext = guessExt(opts);
        var isPdf = ext === 'pdf' || (!ext && /[?&]action=dl\b/i.test(state.href));
        if (!state.href) {
            bodyEl.innerHTML = '<div class="dal-prev-empty">No file on this day.</div>';
        } else {
            var stage = document.createElement('div');
            stage.className = 'dal-prev-stage';
            if (isPdf || ext === '') {
                var fr = document.createElement('iframe');
                fr.src = isPdf ? pdfSrc(state.href) : state.href;
                fr.title = kindTitle;
                fr.addEventListener('load', function() { setTimeout(focusAmt, 80); });
                stage.appendChild(fr);
            } else {
                var img = document.createElement('img');
                img.src = state.href;
                img.alt = kindTitle;
                stage.appendChild(img);
            }
            bodyEl.appendChild(stage);
        }
        restoreWidth();
        panel.classList.add('open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('dal-preview-open');
        restoreZoom();
        if (state.kind !== 'production') {
            setTimeout(focusAmt, 80);
            setTimeout(focusAmt, 400);
        }
    }

    function fromLink(a) {
        if (!a) return;
        var $row = $(a).closest('tr.data-row');
        var kind = a.getAttribute('data-kind') || '';
        if (!kind && a.href) {
            var m = a.href.match(/[?&]kind=([^&]+)/);
            kind = m ? decodeURIComponent(m[1]) : 'pos';
        }
        var amt = '';
        if (kind === 'paidout') amt = $row.attr('data-paid') || '';
        else amt = $row.find('input.dal-manual-amt[data-field="pos_sales"]').val() || $row.attr('data-sales') || '';
        openPreview({
            href: a.getAttribute('href') || '',
            kind: kind || 'pos',
            ext: a.getAttribute('data-ext') || '',
            date: a.getAttribute('data-date') || $row.attr('data-date') || '',
            rid: a.getAttribute('data-rid') || $row.attr('data-rid') || '0',
            name: $row.find('.site-edit').attr('data-name') || $row.find('.col-site').text().trim(),
            reportId: $row.attr('data-id') || '0',
            amount: amt
        });
    }

    function fromRow($row, preferKind) {
        if (!$row || !$row.length) return;
        var $links = $row.find('a.file-view');
        var $a = $();
        $links.each(function() {
            var k = this.getAttribute('data-kind') || '';
            if (!k && this.href) {
                var m = this.href.match(/[?&]kind=([^&]+)/);
                k = m ? decodeURIComponent(m[1]) : '';
            }
            if ((preferKind && k === preferKind) || (!preferKind && k === 'pos')) {
                $a = $(this);
                return false;
            }
        });
        if (!$a.length) $a = $links.first();
        if ($a.length) fromLink($a[0]);
        else openPreview({
            href: '',
            kind: preferKind || 'pos',
            date: $row.attr('data-date') || '',
            rid: $row.attr('data-rid') || '0',
            name: $row.find('.site-edit').attr('data-name') || '',
            reportId: $row.attr('data-id') || '0',
            amount: $row.find('input.dal-manual-amt[data-field="pos_sales"]').val() || $row.attr('data-sales') || ''
        });
    }

    document.addEventListener('click', function(e) {
        var t = e.target;
        if (t && t.nodeType === 3) t = t.parentElement;
        var a = t && t.closest ? t.closest('a.file-view') : null;
        if (!a) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        e.stopPropagation();
        fromLink(a);
    }, true);
    $(document).on('dblclick', 'tr.data-row td, tr.data-row input.dal-manual-amt', function(e) {
        if ($(e.target).closest('a,button,.icon-trash-row,.btn-row-edit').length) return;
        e.preventDefault();
        var $td = $(this).closest('td');
        var prefer = 'pos';
        if ($td.find('a.file-view[data-kind="paidout"], a.file-view[href*="kind=paidout"]').length) prefer = 'paidout';
        else if ($td.find('a.file-view[data-kind="production"], a.file-view[href*="kind=production"]').length) prefer = 'production';
        fromRow($td.closest('tr.data-row'), prefer);
    });
    $('#dalPrevClose, #dalPrevClose2').on('click', closePreview);
    $('#dalPrevZoomOut').on('click', function() { applyZoom(state.zoom - 0.2); });
    $('#dalPrevZoomIn').on('click', function() { applyZoom(state.zoom + 0.2); });
    $('#dalPrevZoomFit').on('click', function() { applyZoom(1); });
    $('#dalPrevWider').on('click', function() { setPanelWidth((state.width || panel.offsetWidth) + 160); });
    $('#dalPrevFullW').on('click', function() { setPanelWidth(window.innerWidth - 8); });
    if (bodyEl) {
        bodyEl.addEventListener('wheel', function (e) {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            applyZoom(state.zoom + (e.deltaY < 0 ? 0.15 : -0.15));
        }, { passive: false });
    }
    if (resizeHandle && panel) {
        var drag = null;
        resizeHandle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            drag = { startX: e.clientX, startW: panel.offsetWidth };
            resizeHandle.classList.add('dragging');
        });
        document.addEventListener('mousemove', function (e) {
            if (!drag) return;
            setPanelWidth(drag.startW + (drag.startX - e.clientX));
        });
        document.addEventListener('mouseup', function () {
            if (!drag) return;
            drag = null;
            resizeHandle.classList.remove('dragging');
        });
    }
    window.dalOpenFilePreview = function (a) { fromLink(a); };
    $('#dalPrevFoot').on('mousedown', function(e) {
        if (e.target && e.target.id === 'dalPrevAmt') return;
        setTimeout(focusAmt, 0);
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') closePreview();
    });
    $('#dalPrevSave').on('click', function() {
        var id = state.reportId;
        var val = (amtEl.value || '').trim().replace(/[,$\s]/g, '');
        if (!id && !state.rid) { alert('Save this day first, then type the figure.'); return; }
        if (val === '' || isNaN(Number(val)) || Number(val) < 0) { alert('Type the amount as a number, for example 12410.21.'); return; }
        $.post(window.location.pathname, { action: 'update', report_id: id, field: state.field, value: val, restaurant_id: state.rid, report_date: state.date }, function(res) {
            if (res && res.success) {
                if (res.report_id) {
                    state.reportId = parseInt(res.report_id, 10) || state.reportId;
                    id = state.reportId;
                }
                var $row = state.date ? $('tr.data-row[data-rid="' + state.rid + '"][data-date="' + state.date + '"]') : $('tr.data-row[data-id="' + id + '"]');
                if (id) $row.attr('data-id', String(id));
                $row.find('input.dal-manual-amt').attr('data-id', String(id || 0));
                if (state.field === 'paid_out') {
                    $row.attr('data-paid', val);
                    $row.find('.paid-cell .amt-ro').first().text('$' + (parseFloat(val || '0').toFixed(2)));
                } else {
                    $row.attr('data-sales', val);
                    $row.find('input.dal-manual-amt[data-field="pos_sales"]').val(val);
                }
                try { sessionStorage.setItem('dalFlash', JSON.stringify({ text: res.message || 'Saved', color: '#16a34a' })); } catch (e) {}
                location.reload();
                return;
            }
            alert((res && res.error) || 'Could not save.');
        }, 'json').fail(function() { alert('Could not save.'); });
    });
    if (amtEl) amtEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveBtn.click();
        }
    });
})();
</script>
</body>
</html>
