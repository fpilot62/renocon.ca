<?php
/** Path: /app_activity.php — last 30 days of signups/logins; older rows stay in Archive. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_activity_lib.php';
if (file_exists(__DIR__ . '/engine/menu_generator.php')) {
    require_once __DIR__ . '/engine/menu_generator.php';
}

$user_level = (int)($_SESSION['user_level'] ?? 99);
if ($user_level > 30) {
    header('Location: login.php');
    exit;
}

app_activity_backfill($pdo);
app_activity_backfill_signups($pdo);

$view = (isset($_GET['view']) && $_GET['view'] === 'archive') ? 'archive' : 'recent';
$appFilter = trim((string)($_GET['app'] ?? 'all'));
$allowedApps = ['all', 'crewpulse', 'alarmpulse', 'alarmpulse_web', 'azan', 'payroll'];
if (!in_array($appFilter, $allowedApps, true)) {
    $appFilter = 'all';
}
$sortKeys = ['when' => 'occurred_at', 'app' => 'app', 'event' => 'event', 'name' => 'name', 'email' => 'email', 'source' => 'source'];
$sort = trim((string)($_GET['sort'] ?? 'when'));
if (!isset($sortKeys[$sort])) {
    $sort = 'when';
}
$dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$groupByDate = !isset($_GET['group']) || $_GET['group'] !== '0';
$eventFilter = trim((string)($_GET['event'] ?? 'all'));
if (!in_array($eventFilter, ['all', 'signup', 'login'], true)) {
    $eventFilter = 'all';
}

$sql = "SELECT app, event, user_key, name, email, source, occurred_at
        FROM app_activity
        WHERE app <> '_meta' AND occurred_at <= NOW()";
$params = [];
if ($view === 'archive') {
    $sql .= " AND occurred_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
} else {
    $sql .= " AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}
if ($appFilter !== 'all') {
    $sql .= " AND app = ?";
    $params[] = $appFilter;
}
if ($eventFilter !== 'all') {
    $sql .= " AND event = ?";
    $params[] = $eventFilter;
}
$sql .= " ORDER BY occurred_at DESC LIMIT 2000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

app_activity_merge_sqlite(
    $rows,
    '/home/renocon/apps/alarmpulse/data/alarm.db',
    'alarmpulse_web',
    $view,
    $appFilter,
    $eventFilter,
    'website'
);
foreach ([
    '/home/renocon/apps/azan/data/azan.db',
    '/home/renocon/apps/azan-native/data/azan.db',
] as $azanDb) {
    app_activity_merge_sqlite($rows, $azanDb, 'azan', $view, $appFilter, $eventFilter, 'azan');
    if (is_readable($azanDb)) {
        break;
    }
}

function aa_label_app(string $app): string {
    return [
        'crewpulse' => 'CrewPulse',
        'alarmpulse' => 'AlarmPulse app',
        'alarmpulse_web' => 'AlarmPulse website',
        'azan' => 'Azan',
        'payroll' => 'Payroll website',
    ][$app] ?? $app;
}

function aa_row_date(array $r): string {
    $when = (string)($r['occurred_at'] ?? '');
    return substr($when, 0, 10);
}

function aa_sort_value(array $r, string $sort): string {
    if ($sort === 'when') {
        return (string)($r['occurred_at'] ?? '');
    }
    if ($sort === 'app') {
        return strtolower(aa_label_app((string)($r['app'] ?? '')));
    }
    return strtolower((string)($r[$sort] ?? ''));
}

$mul = ($dir === 'asc') ? 1 : -1;
usort($rows, static function ($a, $b) use ($sort, $mul, $groupByDate) {
    if ($groupByDate) {
        $da = aa_row_date($a);
        $db = aa_row_date($b);
        if ($da !== $db) {
            $dateMul = ($sort === 'when') ? $mul : -1;
            return $dateMul * strcmp($da, $db);
        }
    }
    $cmp = strcmp(aa_sort_value($a, $sort), aa_sort_value($b, $sort));
    if ($cmp === 0 && $sort !== 'when') {
        $cmp = strcmp((string)($a['occurred_at'] ?? ''), (string)($b['occurred_at'] ?? ''));
    }
    return $mul * $cmp;
});

$counts = ['signup' => 0, 'login' => 0];
$grouped = [];
foreach ($rows as $r) {
    $ev = $r['event'] ?? '';
    if (isset($counts[$ev])) {
        $counts[$ev]++;
    }
    $day = aa_row_date($r) ?: 'Unknown date';
    $grouped[$day][] = $r;
}

$qs = static function (array $extra) use ($view, $appFilter, $sort, $dir, $groupByDate, $eventFilter): string {
    $q = array_merge([
        'view' => $view,
        'app' => $appFilter,
        'event' => $eventFilter,
        'sort' => $sort,
        'dir' => $dir,
        'group' => $groupByDate ? '1' : '0',
    ], $extra);
    return 'app_activity.php?' . http_build_query($q);
};

$sortLink = static function (string $key, string $label, string $class = '') use ($qs, $sort, $dir): string {
    $nextDir = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
    $on = ($sort === $key) ? ' on' : '';
    $arrow = ($sort === $key) ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $cls = trim('th-sort' . $on . ($class ? ' ' . $class : ''));
    return '<a class="' . htmlspecialchars($cls) . '" href="' . htmlspecialchars($qs(['sort' => $key, 'dir' => $nextDir])) . '">'
        . htmlspecialchars($label) . $arrow . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Signups &amp; logins | crewPulse</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body { margin:0; background:#f1f5f9; color:#0f172a; font:16px/1.45 Inter, system-ui, sans-serif; padding-top:72px; }
    main { max-width:1100px; margin:0 auto; padding:20px 20px 48px; }
    h1 { margin:4px 0 8px; font-size:1.5rem; font-weight:800; }
    .kicker { color:#c2410c; font-weight:700; letter-spacing:.08em; text-transform:uppercase; font-size:.78rem; }
    .muted { color:#64748b; }
    a { color:#1d4ed8; }
    .tabs, .filters { display:flex; flex-wrap:wrap; gap:8px; margin:16px 0; }
    .tab, .chip {
      display:inline-block; padding:8px 12px; border-radius:999px; text-decoration:none;
      border:1px solid #cbd5e1; color:#0f172a; background:#fff;
    }
    .tab.on, .chip.on { background:#ea580c; color:#fff; border-color:#ea580c; font-weight:700; }
    .stats { display:flex; gap:16px; margin:8px 0 16px; color:#334155; font-weight:700; }
    table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; border:1px solid #e2e8f0; }
    th, td { text-align:left; padding:10px 12px; border-bottom:1px solid #e2e8f0; font-size:.92rem; }
    th { color:#64748b; font-weight:700; background:#f8fafc; }
    a.th-sort { color:#64748b; text-decoration:none; display:block; }
    a.th-sort:hover, a.th-sort.on { color:#1d4ed8; }
    .day-row td { background:#e2e8f0; color:#0f172a; font-weight:800; border-bottom:1px solid #cbd5e1; }
    .day-count { font-weight:600; color:#64748b; margin-left:8px; }
    .ev-signup { color:#166534; font-weight:700; }
    .ev-login { color:#1d4ed8; font-weight:700; }
    .empty { padding:28px; color:#64748b; }
    @media (max-width:700px) { .col-source, .col-email { display:none; } }
  </style>
</head>
<body>
<?php if (function_exists('generateMenu')) echo generateMenu($pdo); ?>
<main>
  <p class="kicker">Payroll</p>
  <h1><?= $view === 'archive' ? 'Archive — older than 30 days' : 'Last 30 days — signups &amp; logins' ?></h1>
  <p class="muted">Times are America/Edmonton (today <?= htmlspecialchars(date('D, M j, Y')) ?>). Future punches are hidden. Older than 30 days stays in Archive. Azan now requires name, email, and password before the app opens.</p>
  <p><a href="employee_hub.php">← Employee Hub</a></p>

  <div class="tabs">
    <a class="tab <?= $view === 'recent' ? 'on' : '' ?>" href="<?= htmlspecialchars($qs(['view' => 'recent'])) ?>">Last 30 days</a>
    <a class="tab <?= $view === 'archive' ? 'on' : '' ?>" href="<?= htmlspecialchars($qs(['view' => 'archive'])) ?>">Archive</a>
  </div>
  <div class="filters">
    <?php foreach (['all' => 'All apps', 'crewpulse' => 'CrewPulse', 'alarmpulse' => 'AlarmPulse app', 'alarmpulse_web' => 'AlarmPulse website', 'azan' => 'Azan', 'payroll' => 'Payroll'] as $id => $label): ?>
      <a class="chip <?= $appFilter === $id ? 'on' : '' ?>" href="<?= htmlspecialchars($qs(['app' => $id])) ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
    <?php foreach (['all' => 'All events', 'signup' => 'Signups', 'login' => 'Logins'] as $id => $label): ?>
      <a class="chip <?= $eventFilter === $id ? 'on' : '' ?>" href="<?= htmlspecialchars($qs(['event' => $id])) ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
    <a class="chip <?= $groupByDate ? 'on' : '' ?>" href="<?= htmlspecialchars($qs(['group' => $groupByDate ? '0' : '1'])) ?>"><?= $groupByDate ? 'Grouped by date' : 'Ungrouped' ?></a>
  </div>
  <p class="stats"><span><?= (int)$counts['signup'] ?> signups</span><span><?= (int)$counts['login'] ?> logins</span></p>

  <?php if (!$rows): ?>
    <p class="empty"><?php
      if ($appFilter === 'azan') {
          echo 'No Azan ' . ($eventFilter === 'login' ? 'logins' : ($eventFilter === 'signup' ? 'signups' : 'signups or logins')) . ' in this window yet. New users must create an account with email on azan.ecommi.com or the next Azan app build.';
      } else {
          echo 'No ' . ($view === 'archive' ? 'archived' : 'recent') . ' rows for this filter.';
      }
    ?></p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th><?= $sortLink('when', 'When') ?></th>
        <th><?= $sortLink('app', 'App') ?></th>
        <th><?= $sortLink('event', 'Event') ?></th>
        <th><?= $sortLink('name', 'Name') ?></th>
        <th class="col-email"><?= $sortLink('email', 'Email / username', 'col-email') ?></th>
        <th class="col-source"><?= $sortLink('source', 'Source', 'col-source') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php
      $renderRow = static function (array $r): void { ?>
      <tr>
        <td><?= htmlspecialchars((string)$r['occurred_at']) ?></td>
        <td><?= htmlspecialchars(aa_label_app((string)$r['app'])) ?></td>
        <td class="ev-<?= htmlspecialchars((string)$r['event']) ?>"><?= htmlspecialchars((string)$r['event']) ?></td>
        <td><?= htmlspecialchars((string)($r['name'] ?: '—')) ?></td>
        <td class="col-email"><?= htmlspecialchars((string)($r['email'] ?: '—')) ?></td>
        <td class="col-source"><?= htmlspecialchars((string)($r['source'] ?: '—')) ?></td>
      </tr>
      <?php };
      if ($groupByDate) {
          foreach ($grouped as $day => $dayRows) {
              $label = $day;
              $ts = strtotime($day);
              if ($ts) {
                  $label = date('D, M j, Y', $ts);
              }
              echo '<tr class="day-row"><td colspan="6">' . htmlspecialchars($label)
                  . '<span class="day-count">' . count($dayRows) . '</span></td></tr>';
              foreach ($dayRows as $r) {
                  $renderRow($r);
              }
          }
      } else {
          foreach ($rows as $r) {
              $renderRow($r);
          }
      }
      ?>
    </tbody>
  </table>
  <?php endif; ?>
</main>
</body>
</html>
