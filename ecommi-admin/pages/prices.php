<?php
declare(strict_types=1);

$db = admin_db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['delete_id'])) {
        $stmt = $db->prepare('DELETE FROM prices WHERE id = ?');
        $stmt->execute([(int) $_POST['delete_id']]);
    } else {
        $service = trim((string) ($_POST['service'] ?? ''));
        $channel = trim((string) ($_POST['channel'] ?? ''));
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $unit = trim((string) ($_POST['unit'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($service !== '' && $channel !== '' && $amount !== '' && $unit !== '') {
            $stmt = $db->prepare('INSERT INTO prices (service, channel, amount, unit, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$service, $channel, $amount, $unit, $notes]);
        }
    }
    header('Location: index.php?page=prices');
    exit;
}

$rows = $db->query('SELECT * FROM prices ORDER BY id')->fetchAll();
render_header('Marketing prices', $user, 'prices');
?>
<h1>Marketing portal prices</h1>
<p class="lede">Rate card for campaigns and packages. Replace the placeholders with the live portal numbers.</p>
<section class="panel">
  <table>
    <thead><tr><th>Service</th><th>Channel</th><th>Amount</th><th>Unit</th><th>Notes</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['service']); ?></td>
          <td><?php echo htmlspecialchars($row['channel']); ?></td>
          <td><?php echo htmlspecialchars($row['amount']); ?></td>
          <td><?php echo htmlspecialchars($row['unit']); ?></td>
          <td><?php echo htmlspecialchars($row['notes']); ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Remove this price?');">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="delete_id" value="<?php echo (int) $row['id']; ?>">
              <button class="linkish" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="panel">
  <h2>Add a price</h2>
  <form method="post" class="grid-form">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>Service <input name="service" required></label>
    <label>Channel <input name="channel" required placeholder="Portal, Direct, Partner"></label>
    <label>Amount <input name="amount" required></label>
    <label>Unit <input name="unit" required placeholder="month, project, lead"></label>
    <label class="wide">Notes <input name="notes"></label>
    <button type="submit">Add price</button>
  </form>
</section>
<?php
render_footer();
