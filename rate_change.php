<?php
/**
 * Path: /rate_change.php
 * Admin template to record employee pay-rate changes with:
 *   - effective_date  = first work day the new rate applies
 *   - recorded_at     = timestamp when the admin saved (automatic)
 * Finalize uses the history row whose effective_date is on or before the shift date.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/engine/menu_generator.php';
require_once __DIR__ . '/payroll_engine/payroll_engine.php';

$user_level = (int)($_SESSION['user_level'] ?? 99);
$session_org = (int)($_SESSION['org_id'] ?? 0);
if ($user_level > 25) {
    header('Location: kiosk.php?err=Unauthorized');
    exit;
}

pe_ensure_user_rate_history($pdo);
pe_seed_user_rate_history($pdo);

$is_super = ($user_level <= 10);
$selected_org = 0;
$orgs = [];
$sites = [];
if (function_exists('sip_org_site_fence') || is_file(__DIR__ . '/engine/site_status_lib.php')) {
    if (!function_exists('sip_org_site_fence')) {
        require_once __DIR__ . '/engine/site_status_lib.php';
    }
    $fence = sip_org_site_fence($pdo, 'sip_org_id');
    $is_super = !empty($fence['is_super']);
    $selected_org = (int)($fence['selected_org'] ?? 0);
    $orgs = $fence['orgs'] ?? [];
    $sites = $fence['sites'] ?? [];
}
if (!$is_super) {
    $selected_org = $session_org;
    $st = $pdo->prepare('SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
    $st->execute([$session_org]);
    $sites = $st->fetchAll(PDO::FETCH_ASSOC);
}

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'bulk') ? 'bulk' : 'one';
$filter_site = (int)($_GET['site_id'] ?? $_POST['site_id'] ?? 0);
$filter_rate = trim((string)($_GET['current_rate'] ?? $_POST['current_rate'] ?? ''));
$filter_q = trim((string)($_GET['q'] ?? ''));
$pick_id = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$prefill_rate = trim((string)($_GET['new_rate'] ?? ''));
if (!is_numeric($prefill_rate) || (float)$prefill_rate <= 0) {
    $prefill_rate = '';
} else {
    $prefill_rate = number_format((float)$prefill_rate, 2, '.', '');
}
$prefill_effective = trim((string)($_GET['effective_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_effective)) {
    $prefill_effective = '';
}
$flash = '';
$flash_err = '';

function rc_save_employee_type(PDO $pdo, int $uid, string $raw, bool $is_super, int $session_org): bool {
    $type = function_exists('pe_normalize_employee_type') ? pe_normalize_employee_type($raw) : 'hourly';
    $chk = $pdo->prepare('SELECT id, org_id FROM users WHERE id = ? LIMIT 1');
    $chk->execute([$uid]);
    $u = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return false;
    }
    if (!$is_super && (int)$u['org_id'] !== $session_org) {
        return false;
    }
    $pdo->prepare('UPDATE users SET employee_type = ? WHERE id = ?')->execute([$type, $uid]);
    return true;
}


function rc_users_sql(PDO $pdo, int $org_id, int $site_id, string $rate_eq, string $q, bool $is_super): array {
    $where = ['u.active = 1'];
    $params = [];
    if ($org_id > 0) {
        $where[] = 'u.org_id = ?';
        $params[] = $org_id;
    } elseif (!$is_super) {
        $where[] = '1 = 0';
    }
    if ($site_id > 0) {
        $where[] = '(u.primary_restaurant_id = ? OR FIND_IN_SET(?, u.assigned_site_ids))';
        $params[] = $site_id;
        $params[] = (string)$site_id;
    }
    if ($rate_eq !== '' && is_numeric($rate_eq)) {
        $where[] = 'ROUND(u.hourly_rate, 2) = ?';
        $params[] = round((float)$rate_eq, 2);
    }
    if ($q !== '') {
        $where[] = '(u.Name LIKE ? OR u.user LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $sql = 'SELECT u.id, u.Name, u.user, u.hourly_rate, u.official_rate, u.transit_rate, u.org_id,
                   u.StartDate, u.province, u.employee_type, o.org_name, s.nick_name, s.province AS site_province
            FROM users u
            LEFT JOIN organizations o ON o.id = u.org_id
            LEFT JOIN sites s ON s.SId = u.primary_restaurant_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY u.Name ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function rc_attach_effective(PDO $pdo, array $people): array {
    $ids = [];
    foreach ($people as $person) {
        $id = (int)($person['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $map = [];
    if ($ids) {
        $in = implode(',', $ids);
        try {
            $rows = $pdo->query("SELECT user_id, effective_date FROM (
                SELECT h.user_id, h.effective_date,
                       ROW_NUMBER() OVER (
                           PARTITION BY h.user_id
                           ORDER BY h.recorded_at DESC, h.id DESC
                       ) AS rn
                FROM user_rate_history h
                JOIN users u ON u.id = h.user_id
                WHERE h.user_id IN ($in)
                  AND ABS(h.hourly_rate - u.hourly_rate) < 0.001
            ) rated WHERE rn = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $map[(int)$row['user_id']] = (string)$row['effective_date'];
            }
        } catch (Throwable $e) {
            $map = [];
        }
    }
    foreach ($people as &$person) {
        $person['rate_effective_date'] = $map[(int)($person['id'] ?? 0)] ?? '';
    }
    unset($person);
    return $people;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rc_save_type'])) {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
        $flash_err = 'Select an employee first.';
    } elseif (rc_save_employee_type($pdo, $uid, (string)($_POST['employee_type'] ?? 'hourly'), $is_super, $session_org)) {
        $saved = function_exists('pe_normalize_employee_type')
            ? pe_normalize_employee_type($_POST['employee_type'] ?? 'hourly')
            : 'hourly';
        $flash = $saved === 'contract'
            ? 'Saved employee type: Contract. Next finalize skips CPP and EI (employee + employer).'
            : ('Saved employee type: ' . (function_exists('pe_employee_type_label') ? pe_employee_type_label($saved) : ucfirst($saved)) . '. Normal CPP / EI.');
        $tab = 'one';
        $pick_id = $uid;
    } else {
        $flash_err = 'Could not save employee type (check org access).';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rc_save']) && empty($_POST['rc_save_type'])) {
    $mode = (string)($_POST['mode'] ?? 'one');
    $hourly = (float)($_POST['hourly_rate'] ?? 0);
    $official_raw = trim((string)($_POST['official_rate'] ?? ''));
    $transit_raw = trim((string)($_POST['transit_rate'] ?? ''));
    $effective = trim((string)($_POST['effective_date'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $prev_hourly_raw = trim((string)($_POST['previous_hourly'] ?? ''));
    $prev_effective = trim((string)($_POST['previous_effective'] ?? ''));
    $row_rates = (array)($_POST['row_rate'] ?? []);
    $row_dates = (array)($_POST['row_date'] ?? []);
    $ids = [];
    if ($mode === 'bulk') {
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['user_ids'] ?? []))));
    } else {
        $ids = [(int)($_POST['user_id'] ?? 0)];
    }
    $ids = array_values(array_filter($ids, static function ($id) { return $id > 0; }));

    if ($mode !== 'bulk' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) {
        $flash_err = 'Effective date is required (YYYY-MM-DD). That is the first work day the new rate applies.';
    } elseif (!$ids) {
        $flash_err = 'Select at least one employee.';
    } elseif ($mode !== 'bulk' && $prev_hourly_raw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prev_effective)) {
        $flash_err = 'If you fill Previous hourly, also enter Previous effective date.';
    } else {
        $ok = 0;
        $date_ok = 0;
        $rate_ok = 0;
        $same = 0;
        $who = (int)($_SESSION['user_id'] ?? 0);
        foreach ($ids as $uid) {
            $chk = $pdo->prepare('SELECT id, org_id, hourly_rate, official_rate, transit_rate FROM users WHERE id = ? LIMIT 1');
            $chk->execute([$uid]);
            $u = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$u) {
                continue;
            }
            if (!$is_super && (int)$u['org_id'] !== $session_org) {
                continue;
            }
            $official = ($official_raw === '') ? (($u['official_rate'] === null || $u['official_rate'] === '') ? null : (float)$u['official_rate']) : (float)$official_raw;
            $transit = ($transit_raw === '') ? (($u['transit_rate'] === null || $u['transit_rate'] === '') ? null : (float)$u['transit_rate']) : (float)$transit_raw;
            $raw_rate = '';
            if ($mode === 'bulk') {
                $raw_rate = trim((string)($row_rates[$uid] ?? ''));
                $effective_one = trim((string)($row_dates[$uid] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective_one)) {
                    continue;
                }
                $hourly_one = $raw_rate === '' ? (float)$u['hourly_rate'] : (float)$raw_rate;
                if ($raw_rate !== '' && $hourly_one <= 0) {
                    continue;
                }
            } else {
                $hourly_one = $hourly;
                $effective_one = $effective;
                $raw_rate = $hourly > 0 ? (string)$hourly : '';
            }
            $date_only = $official_raw === '' && $transit_raw === '' && $prev_hourly_raw === ''
                && ($raw_rate === '' || abs($hourly_one - (float)$u['hourly_rate']) < 0.001);
            if ($date_only) {
                if (pe_move_rate_effective_date($pdo, $uid, $effective_one, $who, $note, 'rate_change')) {
                    $ok++;
                    $date_ok++;
                } else {
                    $same++;
                }
                continue;
            }
            if ($hourly_one <= 0) {
                continue;
            }
            if ($prev_hourly_raw !== '' && (float)$prev_hourly_raw > 0) {
                pe_record_user_rate(
                    $pdo, $uid, (float)$prev_hourly_raw, $official, $transit,
                    $prev_effective, $who,
                    $note !== '' ? ('Previous rate. ' . $note) : 'Previous rate (backfill)',
                    'rate_change_prev'
                );
            }
            pe_record_user_rate(
                $pdo, $uid, $hourly_one, $official, $transit,
                $effective_one, $who, $note, 'rate_change'
            );
            if ($mode !== 'bulk' && isset($_POST['employee_type'])) {
                rc_save_employee_type($pdo, $uid, (string)$_POST['employee_type'], $is_super, $session_org);
            }
            $ok++;
            $rate_ok++;
        }
        if ($ok < 1 && $same > 0) {
            $flash = 'No change. Those effective dates were already set, and the rates were left as they are.';
            $tab = $mode === 'bulk' ? 'bulk' : 'one';
        } elseif ($ok < 1) {
            $flash_err = 'No employees were updated (check org access).';
        } else {
            if ($date_ok > 0 && $rate_ok > 0) {
                $flash = 'Updated ' . $date_ok . ' effective date' . ($date_ok === 1 ? '' : 's') . ' without changing those rates, and saved ' . $rate_ok . ' new rate' . ($rate_ok === 1 ? '' : 's') . '.';
            } elseif ($date_ok > 0) {
                $flash = $date_ok === 1
                    ? 'Updated 1 effective date. The rate was not changed.'
                    : ('Updated ' . $date_ok . ' effective dates. Rates were not changed.');
            } else {
                $when = $mode === 'bulk' ? 'the date on each row' : $effective;
                $flash = $rate_ok === 1
                    ? 'Saved 1 rate change. Recorded time is now. Shifts on/after ' . $when . ' use the new rate.'
                    : ('Saved ' . $rate_ok . ' rate changes. Recorded time is now. Shifts on/after ' . $when . ' use the new rate.');
            }
            $tab = $mode === 'bulk' ? 'bulk' : 'one';
        }
    }
}

$people = rc_users_sql($pdo, $selected_org, $filter_site, $filter_rate, $filter_q, $is_super);
$picked = null;
foreach ($people as $p) {
    if ((int)$p['id'] === $pick_id) {
        $picked = $p;
        break;
    }
}
if (!$picked && $pick_id > 0) {
    $st = $pdo->prepare('SELECT u.id, u.Name, u.user, u.hourly_rate, u.official_rate, u.transit_rate, u.org_id,
                                u.StartDate, u.province, u.employee_type, o.org_name, s.nick_name, s.province AS site_province
                         FROM users u
                         LEFT JOIN organizations o ON o.id = u.org_id
                         LEFT JOIN sites s ON s.SId = u.primary_restaurant_id
                         WHERE u.id = ? LIMIT 1');
    $st->execute([$pick_id]);
    $picked = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($picked && !$is_super && (int)$picked['org_id'] !== $session_org) {
        $picked = null;
    }
    if ($picked) {
        $havePick = false;
        foreach ($people as $p) {
            if ((int)$p['id'] === (int)$picked['id']) { $havePick = true; break; }
        }
        if (!$havePick) {
            array_unshift($people, $picked);
        }
    }
}

$people = rc_attach_effective($pdo, $people);

$hist_params = [];
$hist_where = '1=1';
if ($selected_org > 0) {
    $hist_where .= ' AND u.org_id = ?';
    $hist_params[] = $selected_org;
} elseif (!$is_super) {
    $hist_where .= ' AND 1=0';
}
$keep_all = (($_GET['list'] ?? '') === 'all');
if ($pick_id > 0 && $tab === 'one' && !$keep_all) {
    $hist_where .= ' AND h.user_id = ?';
    $hist_params[] = $pick_id;
}
$hist = $pdo->prepare(
    "SELECT h.*, u.Name, u.user, u.org_id, rec.Name AS recorder
     FROM user_rate_history h
     JOIN users u ON u.id = h.user_id
     LEFT JOIN users rec ON rec.id = h.recorded_by
     WHERE $hist_where
     ORDER BY h.recorded_at DESC, h.id DESC
     LIMIT 80"
);
$hist->execute($hist_params);
$history = $hist->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Rate Change</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: Inter, system-ui, sans-serif; background: #f1f5f9; margin: 0; color: #1e293b; padding-top: 130px; }
        .container { max-width: 1180px; margin: 24px auto 50px; padding: 0 18px; }
        .card { background: #fff; border-radius: 12px; padding: 22px 24px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px rgba(0,0,0,.05); margin-bottom: 18px; }
        h1 { margin: 0 0 6px; font-size: 22px; }
        .sub { color: #64748b; font-size: 13px; line-height: 1.45; margin: 0 0 16px; }
        label { display: block; font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; letter-spacing: .04em; }
        input, select, textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        .grid { display: grid; gap: 14px; }
        .g2 { grid-template-columns: 1fr 1fr; }
        .g3 { grid-template-columns: 1fr 1fr 1fr; }
        .g4 { grid-template-columns: 1fr 1fr 1fr 1fr; }
        .tabs { display: flex; gap: 8px; margin-bottom: 16px; }
        .tab { padding: 8px 14px; border-radius: 8px; text-decoration: none; font-size: 13px; font-weight: 700; background: #e2e8f0; color: #334155; }
        .tab.on { background: #1e293b; color: #fff; }
        .btn { background: #2563eb; color: #fff; border: 0; padding: 10px 16px; border-radius: 8px; font-weight: 800; cursor: pointer; font-size: 13px; }
        .btn:hover { background: #1d4ed8; }
        .btn-ghost { background: #fff; color: #334155; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 12px; }
        .ok { background: #dcfce7; color: #166534; padding: 10px 12px; border-radius: 8px; font-weight: 700; margin-bottom: 14px; }
        .err { background: #fee2e2; color: #991b1b; padding: 10px 12px; border-radius: 8px; font-weight: 700; margin-bottom: 14px; }
        .hint { font-size: 12px; color: #64748b; margin-top: 4px; }
        .ex { background: #fff7ed; border: 1px solid #fdba74; border-radius: 10px; padding: 12px 14px; font-size: 13px; color: #9a3412; margin: 0 0 16px; }
        .rc-chrome-toggle { width: 100%; display: flex; align-items: center; gap: 8px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; margin: 0 0 12px; cursor: pointer; font-size: 12px; font-weight: 800; color: #0f172a; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .rc-chrome-toggle:hover { background: #f8fafc; }
        #rcIntro[hidden] { display: none !important; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; padding: 10px; text-align: left; font-size: 11px; text-transform: uppercase; }
        td { padding: 10px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .stamp { font-size: 11px; color: #64748b; }
        .cur { font-weight: 800; color: #0f172a; }
        .tiny { font-size: 11px; color: #64748b; }
        .rowpick { max-height: 560px; overflow: auto; border: 1px solid #e2e8f0; border-radius: 8px; }
        .bulk-table input[type="checkbox"] { width: auto; }
        .bulk-table input.rc-row-rate { width: 110px; }
        .bulk-table input.rc-row-date { width: 158px; }
        .bulk-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin: 8px 0 12px; }
        .bulk-tools > div { min-width: 180px; }
        .rowpick label.emp { display: flex; gap: 10px; align-items: center; padding: 7px 10px; font-size: 13px; font-weight: 600; color: #0f172a; text-transform: none; letter-spacing: 0; margin: 0; border-bottom: 1px solid #f1f5f9; }
        .rowpick input { width: auto; }
        @media (max-width: 800px) { .g2, .g3, .g4 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php echo generateMenu($pdo); ?>
<?php require_once __DIR__ . '/payroll_help.php'; echo '<div style="margin:10px 16px 0;">' . payroll_help_panel('rate_change.php') . '</div>'; ?>

<div class="container">
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
            <h1 style="margin:0;">Rate Change</h1>
            <a href="employee_hub.php" style="font-size:13px;color:#2563eb;text-decoration:none;font-weight:700;"><i class="fa fa-users"></i> Employee Hub</a>
        </div>
        <button type="button" class="rc-chrome-toggle" id="rcIntroBtn" aria-expanded="false">Show help &amp; example</button>
        <div id="rcIntro" hidden>
            <div id="rcHelpSlot"></div>
            <p class="sub">Admin template. Each save stores the new rate, the <strong>effective date</strong> (first work day it applies), and a <strong>recorded timestamp</strong> (when you clicked Save). Finalize looks up the rate that was in effect on the shift date.</p>
            <div class="ex">
                <strong>SK minimum wage example (1 Oct 2026):</strong>
                if the profile already shows $15.70, record the previous rate <strong>$15.35</strong> with previous effective <strong>2025-10-01</strong>,
                and the new rate <strong>$15.70</strong> with effective <strong>2026-10-01</strong>.
                September shifts stay at $15.35; October 1 onward uses $15.70.
            </div>
        </div>

        <?php if ($flash): ?><div class="ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
        <?php if ($flash_err): ?><div class="err"><?= htmlspecialchars($flash_err) ?></div><?php endif; ?>

        <div class="tabs">
            <a class="tab <?= $tab === 'one' ? 'on' : '' ?>" href="rate_change.php?tab=one<?= $pick_id ? '&user_id='.(int)$pick_id : '' ?>">One employee</a>
            <a class="tab <?= $tab === 'bulk' ? 'on' : '' ?>" href="rate_change.php?tab=bulk">Many employees</a>
        </div>

        <form method="GET" class="grid g4" style="margin-bottom:16px;">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <?php if ($is_super && $orgs): ?>
            <div>
                <label>Organization</label>
                <select name="org_id" onchange="this.form.submit()">
                    <option value="all">All organizations</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int)$o['id'] ?>" <?= $selected_org === (int)$o['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$o['org_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label>Site</label>
                <select name="site_id" onchange="this.form.submit()">
                    <option value="0">All sites</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= (int)$s['SId'] ?>" <?= $filter_site === (int)$s['SId'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($s['nick_name'] ?? $s['SId'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Current hourly equals</label>
                <input type="number" step="0.01" name="current_rate" value="<?= htmlspecialchars($filter_rate) ?>" placeholder="15.35">
            </div>
            <div>
                <label>Name search</label>
                <input type="text" name="q" value="<?= htmlspecialchars($filter_q) ?>" placeholder="Type a name">
            </div>
            <div style="align-self:end;">
                <button class="btn-ghost" type="submit">Filter list</button>
            </div>
        </form>

        <?php if ($tab === 'one'): ?>
        <form method="POST" id="rcOne">
            <input type="hidden" name="rc_save" value="1">
            <input type="hidden" name="mode" value="one">
            <input type="hidden" name="site_id" value="<?= (int)$filter_site ?>">
            <input type="hidden" name="current_rate" value="<?= htmlspecialchars($filter_rate) ?>">
            <div class="grid g2">
                <div>
                    <label>Employee</label>
                    <select name="user_id" id="rcUser" required onchange="rcFill()">
                        <option value="">— Select employee —</option>
                        <?php foreach ($people as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"
                                data-hourly="<?= htmlspecialchars((string)$p['hourly_rate']) ?>"
                                data-official="<?= htmlspecialchars((string)$p['official_rate']) ?>"
                                data-transit="<?= htmlspecialchars((string)$p['transit_rate']) ?>"
                                data-etype="<?= htmlspecialchars(function_exists('pe_normalize_employee_type') ? pe_normalize_employee_type($p['employee_type'] ?? 'hourly') : 'hourly') ?>"
                                data-start="<?= htmlspecialchars((string)($p['StartDate'] ?? '')) ?>"
                                <?= $pick_id === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['Name']) ?> — $<?= number_format((float)$p['hourly_rate'], 2) ?>
                                <?= !empty($p['nick_name']) ? ' · '.htmlspecialchars($p['nick_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hint" id="rcCur">Current profile rate shows after you pick someone.</div>
                </div>
                <div>
                    <label>Employee type</label>
                    <select name="employee_type" id="rcType">
                        <option value="hourly">Hourly — normal CPP / EI</option>
                        <option value="monthly">Monthly / salaried — normal CPP / EI</option>
                        <option value="contract">Contract — no CPP, no EI</option>
                    </select>
                    <div class="hint">Standing designation. Contract skips CPP and EI on the next finalize. Does not change the period YBE flag.</div>
                    <button class="btn-ghost" type="submit" name="rc_save_type" value="1" formnovalidate style="margin-top:8px;">Save type only</button>
                </div>
                <div>
                    <label>New hourly rate</label>
                    <input type="number" step="0.01" min="0.01" name="hourly_rate" id="rcHourly" placeholder="Leave blank to keep the current rate" value="<?= htmlspecialchars($prefill_rate) ?>">
                    <div class="hint">Leave this blank, or enter the same rate, to change only the effective date.</div>
                </div>
                <div>
                    <label>Official rate (optional)</label>
                    <input type="number" step="0.01" name="official_rate" id="rcOfficial" placeholder="Leave blank to keep">
                </div>
                <div>
                    <label>Transit rate (optional)</label>
                    <input type="number" step="0.01" name="transit_rate" id="rcTransit" placeholder="Leave blank to keep">
                </div>
                <div>
                    <label>Effective date * <span style="color:#ea580c;">first work day new rate applies</span></label>
                    <input type="date" name="effective_date" value="<?= htmlspecialchars($prefill_effective !== '' ? $prefill_effective : '2026-10-01') ?>" required>
                    <div class="hint">Example: SK min wage → 2026-10-01. September shifts stay $15.35; Oct 1 onward $15.70.</div>
                </div>
                <div>
                    <label>Saved on <span style="color:#64748b;">clock time only — not the effective date</span></label>
                    <input type="text" value="<?= htmlspecialchars(date('Y-m-d H:i:s')) ?> (filled in when you press Save)" disabled>
                </div>
                <div>
                    <label>Previous hourly (optional backfill)</label>
                    <input type="number" step="0.01" name="previous_hourly" placeholder="15.35">
                    <div class="hint">Use when the profile already shows the new rate and you still need the old rate on file.</div>
                </div>
                <div>
                    <label>Previous effective date</label>
                    <input type="date" name="previous_effective" value="2025-10-01">
                </div>
                <div style="grid-column:1 / -1;">
                    <label>Reason / note</label>
                    <input type="text" name="note" maxlength="255" placeholder="SK minimum wage 1 Oct 2026">
                </div>
            </div>
            <div style="margin-top:16px;">
                <button class="btn" type="submit"><i class="fa fa-floppy-disk"></i> Save rate change</button>
            </div>
        </form>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="rc_save" value="1">
            <input type="hidden" name="mode" value="bulk">
            <input type="hidden" name="site_id" value="<?= (int)$filter_site ?>">
            <input type="hidden" name="current_rate" value="<?= htmlspecialchars($filter_rate) ?>">
            <p class="sub" style="margin-bottom:8px;">Tick several people. Leave <strong>New rate</strong> blank to change only the date — the hourly rate stays as it is. Type a rate, or use “Put this rate on every row”, only when the amount should change.</p>
            <div class="bulk-tools">
                <div>
                    <label>Date for the buttons below</label>
                    <input type="date" id="rcBulkDate" value="2026-10-01">
                </div>
                <button class="btn-ghost" type="button" onclick="rcFillDates()">Put this date on every row</button>
                <button class="btn-ghost" type="button" onclick="rcSelectOtherDates()">Tick people not already on this date</button>
                <div>
                    <label>Rate for the button below</label>
                    <input type="number" step="0.01" min="0.01" id="rcBulkRate" placeholder="Optional">
                </div>
                <button class="btn-ghost" type="button" onclick="rcFillRates()">Put this rate on every row</button>
            </div>
            <div class="grid g3">
                <div>
                    <label>Official rate (optional)</label>
                    <input type="number" step="0.01" name="official_rate" placeholder="Leave blank to keep each person">
                </div>
                <div>
                    <label>Transit rate (optional)</label>
                    <input type="number" step="0.01" name="transit_rate" placeholder="Leave blank to keep each person">
                </div>
                <div>
                    <label>Reason / note</label>
                    <input type="text" name="note" maxlength="255" placeholder="Rate effective 1 Oct 2026">
                </div>
            </div>
            <div style="margin:14px 0 8px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                <div class="tiny"><?= count($people) ?> people in this filter. Only ticked rows are saved.</div>
                <div style="display:flex; gap:8px;">
                    <button class="btn-ghost" type="button" onclick="rcToggle(true)">Select all</button>
                    <button class="btn-ghost" type="button" onclick="rcToggle(false)">Clear</button>
                </div>
            </div>
            <div class="rowpick">
                <?php if (!$people): ?>
                    <div style="padding:12px;color:#64748b;">No employees match. Change the filter and press Filter list.</div>
                <?php else: ?>
                <table class="bulk-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Employee</th>
                            <th>Current rate</th>
                            <th>Saved effective date</th>
                            <th>New rate (blank keeps current)</th>
                            <th>New effective date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($people as $p):
                        $pid = (int)$p['id'];
                        $saved_eff = (string)($p['rate_effective_date'] ?? '');
                        $row_date = $saved_eff !== '' ? $saved_eff : '2026-10-01';
                    ?>
                        <tr class="rc-bulk-row" data-saved="<?= htmlspecialchars($saved_eff) ?>">
                            <td><input type="checkbox" name="user_ids[]" value="<?= $pid ?>"></td>
                            <td>
                                <?= htmlspecialchars($p['Name']) ?>
                                <div class="tiny"><?= htmlspecialchars((string)($p['nick_name'] ?: $p['org_name'] ?: '')) ?></div>
                            </td>
                            <td class="cur">$<?= number_format((float)$p['hourly_rate'], 2) ?></td>
                            <td><?= $saved_eff !== '' ? htmlspecialchars($saved_eff) : '—' ?></td>
                            <td><input class="rc-row-rate" type="number" step="0.01" min="0.01" name="row_rate[<?= $pid ?>]" placeholder="Keep $<?= number_format((float)$p['hourly_rate'], 2) ?>"></td>
                            <td><input class="rc-row-date" type="date" name="row_date[<?= $pid ?>]" value="<?= htmlspecialchars($row_date) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <div style="margin-top:16px;">
                <button class="btn" type="submit"><i class="fa fa-floppy-disk"></i> Save ticked rows</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="margin:0 0 12px;font-size:16px;">Rate history (newest recorded first)</h2>
        <p class="sub">Recorded = when an admin saved. Effective = first work day the rate applies. A later recorded row with an earlier effective date still wins for that work day if it is the newest save for that date.</p>
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Hourly</th>
                    <th>Official</th>
                    <th>Transit</th>
                    <th>Effective date</th>
                    <th>Saved on</th>
                    <th>By</th>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$history): ?>
                <tr><td colspan="8" class="tiny">No dated rate changes yet. Use the form above.</td></tr>
            <?php endif; ?>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><a href="rate_change.php?tab=one&amp;user_id=<?= (int)$h['user_id'] ?>&amp;org_id=<?= (int)($h['org_id'] ?? 0) ?>&amp;list=all#rcOne" style="color:#1d4ed8;font-weight:800;"><?= htmlspecialchars((string)$h['Name']) ?></a></td>
                    <td class="cur">$<?= number_format((float)$h['hourly_rate'], 2) ?></td>
                    <td><?= $h['official_rate'] === null ? '—' : '$'.number_format((float)$h['official_rate'], 2) ?></td>
                    <td><?= $h['transit_rate'] === null ? '—' : '$'.number_format((float)$h['transit_rate'], 2) ?></td>
                    <td style="font-weight:800;"><?= htmlspecialchars((string)$h['effective_date']) ?></td>
                    <td class="stamp">Saved <?= htmlspecialchars((string)$h['recorded_at']) ?></td>
                    <td class="tiny"><?= htmlspecialchars((string)($h['recorder'] ?: '—')) ?></td>
                    <td class="tiny"><?= htmlspecialchars((string)($h['note'] ?: $h['source'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
  var btn = document.getElementById("rcIntroBtn");
  var box = document.getElementById("rcIntro");
  if (!btn || !box) return;
  var slot = document.getElementById("rcHelpSlot");
  var panel = document.querySelector(".pg-help-from-nav");
  if (panel && slot) {
    slot.appendChild(panel);
    panel.style.maxWidth = "none";
    panel.style.margin = "0 0 12px";
    panel.style.padding = "0";
  }
  var key = "rcIntroOpen";
  function setOpen(open) {
    if (open) box.removeAttribute("hidden");
    else box.setAttribute("hidden", "");
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    btn.textContent = open ? "Hide help & example" : "Show help & example";
    try { localStorage.setItem(key, open ? "1" : "0"); } catch (e) {}
  }
  var stored = null;
  try { stored = localStorage.getItem(key); } catch (e) {}
  setOpen(stored === "1");
  btn.addEventListener("click", function () {
    setOpen(btn.getAttribute("aria-expanded") !== "true");
  });
})();
</script>
<script>
function rcFill() {
    var sel = document.getElementById('rcUser');
    if (!sel) return;
    var opt = sel.options[sel.selectedIndex];
    var cur = document.getElementById('rcCur');
    if (!opt || !opt.value) {
        if (cur) cur.textContent = 'Current profile rate shows after you pick someone.';
        return;
    }
    var et = document.getElementById('rcType');
    if (et) et.value = opt.getAttribute('data-etype') || 'hourly';
    var h = opt.getAttribute('data-hourly') || '0';
    var o = opt.getAttribute('data-official') || '0';
    var t = opt.getAttribute('data-transit') || '0';
    var s = opt.getAttribute('data-start') || '';
    if (cur) {
        cur.innerHTML = 'Current profile: <strong>$' + Number(h).toFixed(2) + '</strong> hourly'
            + ' · official $' + Number(o).toFixed(2)
            + ' · transit $' + Number(t).toFixed(2)
            + (s ? ' · start ' + s : '')
            + '. New rate does not replace older dates unless you also enter Previous hourly.';
    }
}
function rcToggle(on) {
    document.querySelectorAll('input[name="user_ids[]"]').forEach(function (el) { el.checked = !!on; });
}
function rcFillDates() {
    var d = document.getElementById('rcBulkDate');
    if (!d || !d.value) return;
    document.querySelectorAll('.rc-row-date').forEach(function (el) { el.value = d.value; });
}
function rcFillRates() {
    var r = document.getElementById('rcBulkRate');
    if (!r || !r.value) return;
    document.querySelectorAll('.rc-row-rate').forEach(function (el) { el.value = Number(r.value).toFixed(2); });
}
function rcSelectOtherDates() {
    var d = document.getElementById('rcBulkDate');
    var want = d ? d.value : '';
    document.querySelectorAll('tr.rc-bulk-row').forEach(function (tr) {
        var box = tr.querySelector('input[type="checkbox"]');
        if (!box) return;
        box.checked = !!(want && (tr.getAttribute('data-saved') || '') !== want);
    });
}
rcFill();
</script>

<?php if ($picked): ?>
<script>
(function(){
    var et = document.getElementById('rcType');
    if (et) et.value = <?= json_encode(function_exists('pe_normalize_employee_type') ? pe_normalize_employee_type($picked['employee_type'] ?? 'hourly') : 'hourly') ?>;
})();
</script>
<?php endif; ?>
</body>
</html>
