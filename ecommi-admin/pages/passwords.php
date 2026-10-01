<?php
declare(strict_types=1);

require_superadmin();

$db = admin_db();
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_FILES['import']) && is_uploaded_file($_FILES['import']['tmp_name'])) {
        $handle = fopen($_FILES['import']['tmp_name'], 'r');
        if ($handle) {
            $header = fgetcsv($handle);
            $map = [];
            if (is_array($header)) {
                foreach ($header as $i => $col) {
                    $key = strtolower(trim((string) $col));
                    $map[$key] = $i;
                }
            }
            $pick = static function (array $row, array $map, array $names): string {
                foreach ($names as $name) {
                    if (isset($map[$name], $row[$map[$name]])) {
                        return trim((string) $row[$map[$name]]);
                    }
                }
                return '';
            };
            $stmt = $db->prepare('INSERT INTO vault (label, username, secret, url, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $added = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $label = $pick($row, $map, ['label', 'name', 'title', 'site', 'account']);
                $username = $pick($row, $map, ['username', 'user', 'login', 'email']);
                $secret = $pick($row, $map, ['password', 'secret', 'pass', 'pwd']);
                $url = $pick($row, $map, ['url', 'website', 'host']);
                $notes = $pick($row, $map, ['notes', 'note', 'comment']);
                if ($label === '' && $username === '' && $secret === '') {
                    continue;
                }
                if ($label === '') {
                    $label = $username !== '' ? $username : 'Imported entry';
                }
                $stmt->execute([$label, $username, vault_encrypt($secret), $url, $notes, gmdate('c')]);
                $added++;
            }
            fclose($handle);
            $notice = $added . ' vault entries imported.';
        }
    } elseif (isset($_POST['add'])) {
        $label = trim((string) ($_POST['label'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $secret = (string) ($_POST['secret'] ?? '');
        $url = trim((string) ($_POST['url'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($label !== '') {
            $stmt = $db->prepare('INSERT INTO vault (label, username, secret, url, notes, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$label, $username, vault_encrypt($secret), $url, $notes, gmdate('c')]);
        }
        header('Location: index.php?page=passwords');
        exit;
    }
}

$rows = $db->query('SELECT id, label, username, secret, url, notes FROM vault ORDER BY label')->fetchAll();
render_header('Password vault', $user, 'passwords');
?>
<h1>Password vault</h1>
<p class="lede">Superadmin only. Import a CSV export of <code>Password0</code> (columns such as name, username, password, url, notes). Secrets are encrypted on this server and are not stored in git.</p>
<?php if ($notice !== ''): ?><p class="notice"><?php echo htmlspecialchars($notice); ?></p><?php endif; ?>
<section class="panel">
  <h2>Import CSV</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="file" name="import" accept=".csv,text/csv" required>
    <button type="submit">Import Password0 CSV</button>
  </form>
</section>
<section class="panel">
  <h2>Add one entry</h2>
  <form method="post" class="grid-form">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="add" value="1">
    <label>Label <input name="label" required></label>
    <label>Username <input name="username"></label>
    <label>Password <input type="password" name="secret"></label>
    <label>URL <input name="url"></label>
    <label class="wide">Notes <input name="notes"></label>
    <button type="submit">Save</button>
  </form>
</section>
<section class="panel">
  <table>
    <thead><tr><th>Label</th><th>Username</th><th>Password</th><th>URL</th><th>Notes</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['label']); ?></td>
          <td><?php echo htmlspecialchars($row['username']); ?></td>
          <td><code class="secret" data-secret="<?php echo htmlspecialchars(vault_decrypt($row['secret'])); ?>">••••••••</code> <button type="button" class="linkish reveal">Show</button></td>
          <td><?php echo htmlspecialchars($row['url']); ?></td>
          <td><?php echo htmlspecialchars($row['notes']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php
render_footer();
