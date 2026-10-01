<?php
declare(strict_types=1);

function render_header(string $title, array $user, string $page): void
{
    $nav = [
        'dashboard' => 'Dashboard',
        'apps' => 'Apps',
        'records' => 'Records & tracks',
        'prices' => 'Marketing prices',
    ];
    if ($user['role'] === 'superadmin') {
        $nav['passwords'] = 'Password vault';
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($title); ?> | Ecommi Admin</title>
  <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
  <aside class="sidebar">
    <p class="brand">Ecommi</p>
    <p class="brand-sub">Administration</p>
    <nav>
      <?php foreach ($nav as $key => $label): ?>
        <a href="index.php?page=<?php echo $key; ?>"<?php echo $page === $key ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($label); ?></a>
      <?php endforeach; ?>
    </nav>
    <p class="who"><?php echo htmlspecialchars($user['username']); ?> · <?php echo htmlspecialchars($user['role']); ?></p>
    <a class="logout" href="logout.php">Sign out</a>
  </aside>
  <main class="content">
    <?php
}

function render_footer(): void
{
    ?>
  </main>
  <script src="assets/admin.js"></script>
</body>
</html>
    <?php
}
