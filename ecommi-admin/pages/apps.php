<?php
declare(strict_types=1);

$db = admin_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    $url = trim((string) ($_POST['url'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($name !== '' && $url !== '' && $status !== '') {
        $stmt = $db->prepare('INSERT INTO apps (name, url, status, notes) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $url, $status, $notes]);
    }
    header('Location: index.php?page=apps');
    exit;
}

$rows = $db->query('SELECT * FROM apps ORDER BY id')->fetchAll();
render_header('Apps', $user, 'apps');
?>
<h1>Apps</h1>
<p class="lede">Registry of internal and public systems. Status is for operations, not a live health check.</p>
<section class="panel">
  <table>
    <thead><tr><th>App</th><th>Location</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['name']); ?></td>
          <td><?php echo htmlspecialchars($row['url']); ?></td>
          <td><?php echo htmlspecialchars($row['status']); ?></td>
          <td><?php echo htmlspecialchars($row['notes']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="panel">
  <h2>Add an app</h2>
  <form method="post" class="grid-form">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>Name <input name="name" required></label>
    <label>URL or host <input name="url" required></label>
    <label>Status <input name="status" required placeholder="Live, Legacy DB, Planned"></label>
    <label>Notes <input name="notes"></label>
    <button type="submit">Add app</button>
  </form>
</section>
<?php
render_footer();
