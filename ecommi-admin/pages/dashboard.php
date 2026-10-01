<?php
declare(strict_types=1);

$db = admin_db();
$apps = (int) $db->query('SELECT COUNT(*) FROM apps')->fetchColumn();
$records = (int) $db->query('SELECT COUNT(*) FROM records')->fetchColumn();
$prices = (int) $db->query('SELECT COUNT(*) FROM prices')->fetchColumn();
$vault = is_superadmin() ? (int) $db->query('SELECT COUNT(*) FROM vault')->fetchColumn() : null;
$recent = $db->query('SELECT app_name, title, track, recorded_on FROM records ORDER BY id DESC LIMIT 6')->fetchAll();

render_header('Dashboard', $user, 'dashboard');
?>
<h1>Operations hub</h1>
<p class="lede">One place for Ecommi and Renocon administration. Payroll stays on payroll.renocon.ca. This panel is for records, tracks, marketing prices, and superadmin tools.</p>
<section class="stats">
  <a href="index.php?page=apps"><strong><?php echo $apps; ?></strong><span>Apps</span></a>
  <a href="index.php?page=records"><strong><?php echo $records; ?></strong><span>Records &amp; tracks</span></a>
  <a href="index.php?page=prices"><strong><?php echo $prices; ?></strong><span>Marketing prices</span></a>
  <?php if ($vault !== null): ?>
    <a href="index.php?page=passwords"><strong><?php echo $vault; ?></strong><span>Vault entries</span></a>
  <?php endif; ?>
</section>
<section class="panel">
  <h2>Recent tracks</h2>
  <?php if (!$recent): ?>
    <p>No records yet. Add the first track from Records.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>App</th><th>Title</th><th>Track</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['app_name']); ?></td>
            <td><?php echo htmlspecialchars($row['title']); ?></td>
            <td><?php echo htmlspecialchars($row['track']); ?></td>
            <td><?php echo htmlspecialchars($row['recorded_on']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php
render_footer();
