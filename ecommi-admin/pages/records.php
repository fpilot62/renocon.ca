<?php
declare(strict_types=1);

$db = admin_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $app = trim((string) ($_POST['app_name'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $track = trim((string) ($_POST['track'] ?? ''));
    $detail = trim((string) ($_POST['detail'] ?? ''));
    $date = trim((string) ($_POST['recorded_on'] ?? ''));
    if ($app !== '' && $title !== '' && $track !== '' && $date !== '') {
        $stmt = $db->prepare('INSERT INTO records (app_name, title, track, detail, recorded_on) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$app, $title, $track, $detail, $date]);
    }
    header('Location: index.php?page=records');
    exit;
}

$rows = $db->query('SELECT * FROM records ORDER BY recorded_on DESC, id DESC')->fetchAll();
render_header('Records & tracks', $user, 'records');
?>
<h1>Records &amp; tracks</h1>
<p class="lede">Operational notes across payroll, construction, Penticton, and marketing. This is not a replacement for the payroll kiosk.</p>
<section class="panel">
  <h2>Add a record</h2>
  <form method="post" class="grid-form">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>App <input name="app_name" required placeholder="Payroll, Renocon, Penticton"></label>
    <label>Title <input name="title" required></label>
    <label>Track <input name="track" required placeholder="Finance, Site, Marketing"></label>
    <label>Date <input type="date" name="recorded_on" required value="<?php echo htmlspecialchars(gmdate('Y-m-d')); ?>"></label>
    <label class="wide">Detail <input name="detail"></label>
    <button type="submit">Save record</button>
  </form>
</section>
<section class="panel">
  <table>
    <thead><tr><th>Date</th><th>App</th><th>Title</th><th>Track</th><th>Detail</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['recorded_on']); ?></td>
          <td><?php echo htmlspecialchars($row['app_name']); ?></td>
          <td><?php echo htmlspecialchars($row['title']); ?></td>
          <td><?php echo htmlspecialchars($row['track']); ?></td>
          <td><?php echo htmlspecialchars($row['detail']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
render_footer();
