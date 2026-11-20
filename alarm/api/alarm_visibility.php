<?php
/**
 * Who a signed-in user is allowed to see, assign, or copy.
 *
 * Inside an org, a person sees their own row and people below them.
 * They do not see managers, peers, or other orgs. Role 10 (super admin)
 * is the top of the tree and can see every active account. That matches
 * the existing admin screen. Nobody else can see a super admin.
 *
 * reports_to is the manager's user_id. An empty reports_to means the
 * person is not under a named manager. A higher role in the same org can
 * see those unassigned people (a team admin sees staff who have no manager).
 * Once reports_to is set, only ancestors in that chain can see them.
 *
 * The directory stays on the server. Endpoints return user_id, name, and
 * email for visible people only. A hidden email gets the same error as an
 * unknown one, so a guess does not reveal that the account exists.
 */

function alarm_vis_role(array $row): int {
  $role = (int)($row['role'] ?? 1);
  return in_array($role, [1, 10, 20], true) ? $role : 1;
}

function alarm_vis_public(array $row): array {
  return [
    'user_id' => (int)$row['user_id'],
    'name' => (string)($row['name'] ?? ''),
    'email' => strtolower(trim((string)($row['email'] ?? ''))),
  ];
}

function alarm_vis_can_see(array $actor, array $target, array $byId, bool $includeInactive = false): bool {
  $actorId = (int)($actor['user_id'] ?? 0);
  $targetId = (int)($target['user_id'] ?? 0);
  if ($actorId < 1 || $targetId < 1) return false;
  if ($actorId === $targetId) return true;
  if ((int)($target['active'] ?? 0) !== 1 && !$includeInactive) return false;
  if (alarm_vis_role($actor) === 10) return true;
  if (alarm_vis_role($target) === 10) return false;
  if ((int)($actor['org_id'] ?? 0) !== (int)($target['org_id'] ?? 0)) return false;

  $seen = [];
  $cursor = $target;
  while ($cursor) {
    $parentId = (int)($cursor['reports_to'] ?? 0);
    if ($parentId < 1) break;
    if ($parentId === $actorId) return true;
    if (isset($seen[$parentId]) || !isset($byId[$parentId])) break;
    $seen[$parentId] = true;
    $cursor = $byId[$parentId];
  }

  $manager = (int)($target['reports_to'] ?? 0);
  if ($manager < 1 && alarm_vis_role($target) < alarm_vis_role($actor)) return true;
  return false;
}

function alarm_vis_index(array $directory): array {
  $byId = [];
  foreach ($directory as $row) {
    if (!is_array($row)) continue;
    $id = (int)($row['user_id'] ?? 0);
    if ($id > 0) $byId[$id] = $row;
  }
  return $byId;
}

function alarm_vis_visible(array $actor, array $directory, bool $includeInactive = false): array {
  $byId = alarm_vis_index($directory);
  $out = [];
  foreach ($byId as $row) {
    if (!alarm_vis_can_see($actor, $row, $byId, $includeInactive)) continue;
    $out[] = $row;
  }
  usort($out, function ($a, $b) {
    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });
  return $out;
}

function alarm_vis_find_email(array $actor, array $directory, string $email): ?array {
  $email = strtolower(trim($email));
  if ($email === '') return null;
  foreach (alarm_vis_visible($actor, $directory) as $row) {
    if (strtolower(trim((string)($row['email'] ?? ''))) === $email) return $row;
  }
  return null;
}

/**
 * Assignee and copy emails must be people this user can see.
 * Empty means "nobody extra". An email that is not on the visible list
 * is rejected without saying whether an account exists.
 *
 * @return array{ok:bool,assignee:?array,copies:array,error?:array}
 */
function alarm_vis_resolve_people(array $actor, array $directory, string $assigneeEmail, array $copyEmails): array {
  $assigneeEmail = strtolower(trim($assigneeEmail));
  $assignee = null;
  if ($assigneeEmail !== '') {
    if (!filter_var($assigneeEmail, FILTER_VALIDATE_EMAIL)) {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_email', 'error' => 'Enter a valid email for the person you want to assign.']];
    }
    $assignee = alarm_vis_find_email($actor, $directory, $assigneeEmail);
    if (!$assignee) {
      return ['ok' => false, 'error' => ['status' => 403, 'error_code' => 'not_visible', 'error' => 'You can only assign people on your list.']];
    }
  }
  $copies = [];
  $seen = [];
  foreach ($copyEmails as $raw) {
    $email = strtolower(trim((string)$raw));
    if ($email === '') continue;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_email', 'error' => 'Enter a valid email for each person you copy.']];
    }
    if (isset($seen[$email])) continue;
    $seen[$email] = true;
    if ($assignee && strtolower(trim((string)$assignee['email'])) === $email) continue;
    $person = alarm_vis_find_email($actor, $directory, $email);
    if (!$person) {
      return ['ok' => false, 'error' => ['status' => 403, 'error_code' => 'not_visible', 'error' => 'You can only copy people on your list.']];
    }
    $copies[] = $person;
  }
  if (count($copies) > 10) {
    return ['ok' => false, 'error' => ['status' => 400, 'error_code' => 'invalid_email', 'error' => 'Copy at most 10 people.']];
  }
  return ['ok' => true, 'assignee' => $assignee, 'copies' => $copies];
}

function alarm_vis_table_columns(PDO $pdo, string $table): array {
  if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) return [];
  try {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
  } catch (PDOException $e) {
    return [];
  }
  $cols = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[strtolower((string)$row['Field'])] = $row;
  }
  return $cols;
}

function alarm_vis_ensure_schema(PDO $pdo): void {
  $cols = alarm_vis_table_columns($pdo, 'alarm_users');
  if ($cols && !isset($cols['reports_to'])) {
    $pdo->exec('ALTER TABLE alarm_users ADD COLUMN reports_to INT NULL DEFAULT NULL');
    try {
      $pdo->exec('ALTER TABLE alarm_users ADD KEY idx_alarm_users_reports_to (reports_to)');
    } catch (PDOException $e) {
      // The index is only a lookup aid. A second deploy can hit a duplicate key name.
    }
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS alarm_task_recipients (
    recipient_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    kind VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (recipient_id),
    UNIQUE KEY uq_alarm_task_recipient (task_id, user_id, kind),
    KEY idx_alarm_task_recipients_user (user_id, task_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function alarm_vis_load_directory(PDO $pdo): array {
  $cols = alarm_vis_table_columns($pdo, 'alarm_users');
  if (!isset($cols['user_id'])) return [];
  $select = ['user_id'];
  foreach (['name', 'email', 'active', 'role', 'org_id', 'reports_to'] as $name) {
    if (isset($cols[$name])) $select[] = $name;
  }
  $stmt = $pdo->query('SELECT `' . implode('`,`', $select) . '` FROM alarm_users');
  $rows = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $rows[] = [
      'user_id' => (int)$row['user_id'],
      'name' => (string)($row['name'] ?? ''),
      'email' => strtolower(trim((string)($row['email'] ?? ''))),
      'active' => isset($row['active']) ? (int)$row['active'] : 1,
      'role' => isset($row['role']) ? (int)$row['role'] : 1,
      'org_id' => isset($row['org_id']) ? (int)$row['org_id'] : 0,
      'reports_to' => isset($row['reports_to']) ? (int)$row['reports_to'] : 0,
    ];
  }
  return $rows;
}

function alarm_vis_actor(array $directory, int $userId): ?array {
  foreach ($directory as $row) {
    if ((int)$row['user_id'] === $userId) return $row;
  }
  return null;
}

function alarm_vis_replace_recipients(PDO $pdo, int $taskId, int $ownerId, ?array $assignee, array $copies): void {
  if ($taskId < 1) return;
  $pdo->prepare('DELETE FROM alarm_task_recipients WHERE task_id = ?')->execute([$taskId]);
  $ins = $pdo->prepare('INSERT INTO alarm_task_recipients (task_id, user_id, kind, created_at) VALUES (?, ?, ?, NOW())');
  $written = [];
  if ($assignee) {
    $id = (int)$assignee['user_id'];
    if ($id > 0 && $id !== $ownerId) {
      $ins->execute([$taskId, $id, 'assignee']);
      $written[$id] = true;
    }
  }
  foreach ($copies as $person) {
    $id = (int)($person['user_id'] ?? 0);
    if ($id < 1 || $id === $ownerId || isset($written[$id])) continue;
    $ins->execute([$taskId, $id, 'copy']);
    $written[$id] = true;
  }
}

function alarm_vis_selftest(): void {
  $dir = [
    ['user_id' => 1, 'name' => 'Super', 'email' => 'super@renocon.ca', 'active' => 1, 'role' => 10, 'org_id' => 1, 'reports_to' => 0],
    ['user_id' => 2, 'name' => 'Ada Admin', 'email' => 'ada@renocon.ca', 'active' => 1, 'role' => 20, 'org_id' => 1, 'reports_to' => 1],
    ['user_id' => 3, 'name' => 'Bea Admin', 'email' => 'bea@renocon.ca', 'active' => 1, 'role' => 20, 'org_id' => 1, 'reports_to' => 1],
    ['user_id' => 4, 'name' => 'Sara Lee', 'email' => 'sara@renocon.ca', 'active' => 1, 'role' => 1, 'org_id' => 1, 'reports_to' => 2],
    ['user_id' => 5, 'name' => 'Ali Khan', 'email' => 'ali@renocon.ca', 'active' => 1, 'role' => 1, 'org_id' => 1, 'reports_to' => 4],
    ['user_id' => 6, 'name' => 'Unassigned', 'email' => 'una@renocon.ca', 'active' => 1, 'role' => 1, 'org_id' => 1, 'reports_to' => 0],
    ['user_id' => 7, 'name' => 'Other Org', 'email' => 'other@renocon.ca', 'active' => 1, 'role' => 1, 'org_id' => 2, 'reports_to' => 0],
    ['user_id' => 8, 'name' => 'Inactive', 'email' => 'old@renocon.ca', 'active' => 0, 'role' => 1, 'org_id' => 1, 'reports_to' => 2],
    ['user_id' => 9, 'name' => 'Cycle A', 'email' => 'a@renocon.ca', 'active' => 1, 'role' => 1, 'org_id' => 1, 'reports_to' => 10],
    ['user_id' => 10, 'name' => 'Cycle B', 'email' => 'b@renocon.ca', 'active' => 1, 'role' => 1, 'org_id' => 1, 'reports_to' => 9],
  ];
  $by = [];
  foreach ($dir as $row) $by[$row['user_id']] = $row;
  $ids = function (array $actor) use ($dir) {
    return array_map(function ($row) { return (int)$row['user_id']; }, alarm_vis_visible($actor, $dir));
  };

  $fail = function ($message) {
    fwrite(STDERR, "alarm_visibility selftest failed: {$message}\n");
    exit(1);
  };

  $sara = $ids($by[4]);
  if ($sara !== [5, 4] && $sara !== [4, 5]) $fail('sara should see herself and Ali');
  if (in_array(2, $sara, true) || in_array(1, $sara, true)) $fail('sara must not see people above her');
  $ada = $ids($by[2]);
  foreach ([2, 4, 5, 6] as $id) if (!in_array($id, $ada, true)) $fail('ada should see herself, her reports, and unassigned staff');
  if (in_array(3, $ada, true) || in_array(1, $ada, true) || in_array(7, $ada, true) || in_array(8, $ada, true)) $fail('ada must not see peers, her manager, another org, or inactive staff');
  $adaWithInactive = array_map(function ($row) { return (int)$row['user_id']; }, alarm_vis_visible($by[2], $dir, true));
  if (!in_array(8, $adaWithInactive, true) || in_array(1, $adaWithInactive, true)) $fail('admin tools can see inactive reports and still not a manager');
  $super = $ids($by[1]);
  if (!in_array(7, $super, true) || in_array(8, $super, true)) $fail('super sees active accounts in every org and skips inactive ones');
  if (alarm_vis_can_see($by[9], $by[10], alarm_vis_index($dir)) !== true) $fail('cycle still sees a direct report');
  if (alarm_vis_can_see($by[9], $by[2], alarm_vis_index($dir)) !== false) $fail('cycle must stop');

  $hidden = alarm_vis_resolve_people($by[4], $dir, 'ada@renocon.ca', []);
  if (!empty($hidden['ok']) || ($hidden['error']['error_code'] ?? '') !== 'not_visible') $fail('cannot assign a manager');
  if (stripos((string)$hidden['error']['error'], 'ada') !== false) $fail('error must not echo the hidden address');
  $ok = alarm_vis_resolve_people($by[4], $dir, 'ali@renocon.ca', ['ali@renocon.ca']);
  if (empty($ok['ok']) || (int)$ok['assignee']['user_id'] !== 5 || $ok['copies'] !== []) $fail('assignee is not also copied');
  $missing = alarm_vis_resolve_people($by[4], $dir, 'nope@renocon.ca', []);
  if (($missing['error']['error_code'] ?? '') !== 'not_visible') $fail('unknown email uses the same refusal');

  echo "ok\n";
  exit(0);
}

if (PHP_SAPI === 'cli' && isset($argv) && in_array('--selftest', $argv, true) && isset($argv[0]) && realpath((string)$argv[0]) === __FILE__) {
  alarm_vis_selftest();
}
