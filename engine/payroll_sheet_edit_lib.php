<?php
/**
 * Path: /engine/payroll_sheet_edit_lib.php
 * Sheet Review: inline editing of draft rows, an audit log for those edits,
 * and the source-cell window shown beside the editor.
 *
 * Writes only payroll_sheet_rows (employee link, store, net, note),
 * payroll_sheet_edit_audit, and payroll_sheet_cells. Never writes
 * payroll_finalized, payroll_payments, users, sites, or attendance.
 * Never approves a row and never marks anyone paid.
 */

require_once __DIR__ . '/payroll_sheet_review_lib.php';

function pse_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready || defined('PSE_SKIP_MIGRATION')) {
        return;
    }
    $file = dirname(__DIR__) . '/migrations/20261126_payroll_sheet_edit_source.sql';
    $sql = is_file($file) ? file_get_contents($file) : false;
    if ($sql === false) {
        throw new RuntimeException('The sheet edit migration file is missing.');
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (preg_split('/;/', (string)$sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    $ready = true;
}

function pse_now(): string
{
    return date('Y-m-d H:i:s');
}

function pse_is_mysql(PDO $pdo): bool
{
    return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
}

/** A row with an active approval has posted payroll. Editing it here is refused. */
function pse_row_is_locked(PDO $pdo, int $orgId, int $rowId): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM payroll_sheet_approval_effects WHERE org_id = ? AND row_id = ? AND undone_at IS NULL');
    $st->execute([$orgId, $rowId]);
    return (int)$st->fetchColumn() > 0;
}

/** @return array<int,string> user id => name, for this company only */
function pse_org_users(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT id, Name FROM users WHERE org_id = ? ORDER BY Name ASC');
    $st->execute([$orgId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $out[(int)$u['id']] = (string)$u['Name'];
    }
    return $out;
}

/** @return array<int,string> site id => store name, for this company only */
function pse_org_sites(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT SId, nick_name FROM sites WHERE org_id = ? ORDER BY nick_name ASC');
    $st->execute([$orgId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $out[(int)$s['SId']] = (string)$s['nick_name'];
    }
    return $out;
}

function pse_fmt_value($field, $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if ($field === 'net') {
        return number_format((float)$value, 2, '.', '');
    }
    if ($field === 'user_id' || $field === 'site_id') {
        return (string)(int)$value;
    }
    return (string)$value;
}

/**
 * Work out what a posted edit changes. Only fields present in the post are
 * considered. A blank net or the unknown box stores unknown (NULL), never 0.
 *
 * @param array<string,mixed> $current row from payroll_sheet_rows
 * @param array<string,mixed> $post
 * @param array<int,string> $users this company's users
 * @param array<int,string> $sites this company's sites
 * @return array{changes: array<string,array{0:?string,1:?string}>, errors: string[]}
 */
function pse_plan_edit(array $current, array $post, array $users, array $sites): array
{
    $changes = [];
    $errors = [];
    $new = [];
    if (array_key_exists('user_id', $post)) {
        $raw = trim((string)$post['user_id']);
        if ($raw === '' || $raw === '0') {
            $new['user_id'] = null;
        } elseif (!ctype_digit($raw) || !isset($users[(int)$raw])) {
            $errors[] = 'That employee is not in this company.';
        } else {
            $new['user_id'] = (int)$raw;
        }
    }
    if (array_key_exists('site_id', $post)) {
        $raw = trim((string)$post['site_id']);
        if ($raw === '' || $raw === '0') {
            $new['site_id'] = null;
        } elseif (!ctype_digit($raw) || !isset($sites[(int)$raw])) {
            $errors[] = 'That store is not in this company.';
        } else {
            $new['site_id'] = (int)$raw;
        }
    }
    if (array_key_exists('net', $post) || !empty($post['net_unknown'])) {
        if (!empty($post['net_unknown'])) {
            $new['net'] = null;
        } else {
            $parsed = psr_parse_amount($post['net'] ?? null);
            if (!$parsed['ok']) {
                $errors[] = 'Net is not a number. Leave it blank or tick unknown to keep it unknown. Type 0 for zero.';
            } elseif ($parsed['value'] !== null && abs((float)$parsed['value']) >= 10000000) {
                $errors[] = 'Net is out of range.';
            } else {
                $new['net'] = $parsed['value'];
            }
        }
    }
    if (array_key_exists('draft_notes', $post)) {
        $note = trim((string)$post['draft_notes']);
        if (function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') > 2000 : strlen($note) > 2000) {
            $errors[] = 'The note is longer than 2000 characters.';
        } else {
            $new['draft_notes'] = $note === '' ? '' : $note;
        }
    }
    foreach ($new as $field => $value) {
        $old = pse_fmt_value($field, $current[$field] ?? null);
        $next = pse_fmt_value($field, $value);
        if ($field === 'draft_notes') {
            $old = (string)($current['draft_notes'] ?? '');
            $next = (string)$value;
        }
        if ($old !== $next) {
            $changes[$field] = [$old, $next];
        }
    }
    return ['changes' => $changes, 'errors' => $errors];
}

function pse_audit(PDO $pdo, int $orgId, int $rowId, string $batch, string $action, string $field, ?string $old, ?string $new, int $actor): void
{
    $pdo->prepare(
        'INSERT INTO payroll_sheet_edit_audit (org_id, row_id, batch_key, action, field_name, old_value, new_value, actor_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$orgId, $rowId, $batch, $action, $field, $old, $new, $actor, pse_now()]);
}

/**
 * Apply one row's edit inside the caller's transaction.
 *
 * @return int number of fields changed
 */
function pse_apply_edit_in_tx(PDO $pdo, int $orgId, int $rowId, int $actor, array $post, array $users, array $sites, string $batch, string $action, bool $checkVersion): int
{
    $sql = 'SELECT * FROM payroll_sheet_rows WHERE id = ? AND org_id = ? AND deleted_at IS NULL';
    if (pse_is_mysql($pdo)) {
        $sql .= ' FOR UPDATE';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$rowId, $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('That row is not in this company.');
    }
    if (($row['row_kind'] ?? '') !== 'employee_record') {
        throw new RuntimeException('Only employee rows can be edited here.');
    }
    if ($checkVersion && (int)($post['row_version'] ?? -1) !== (int)($row['row_version'] ?? 1)) {
        throw new RuntimeException('Someone saved this row after you opened it. Reload and try again. Nothing was saved.');
    }
    if (pse_row_is_locked($pdo, $orgId, $rowId)) {
        throw new RuntimeException('This row has an active approval. Undo the approval first. Nothing was changed.');
    }
    $plan = pse_plan_edit($row, $post, $users, $sites);
    if ($plan['errors']) {
        throw new RuntimeException(implode(' ', $plan['errors']));
    }
    if (!$plan['changes']) {
        return 0;
    }
    $sets = [];
    $args = [];
    foreach ($plan['changes'] as $field => $pair) {
        $sets[] = $field . ' = ?';
        $value = $pair[1];
        if ($field === 'draft_notes') {
            $value = (string)$value;
        } elseif ($value !== null && $field === 'net') {
            $value = (float)$value;
        } elseif ($value !== null) {
            $value = (int)$value;
        }
        $args[] = $value;
    }
    $sets[] = 'row_version = row_version + 1';
    $sets[] = 'updated_at = ?';
    $args[] = pse_now();
    $args[] = $rowId;
    $args[] = $orgId;
    $pdo->prepare('UPDATE payroll_sheet_rows SET ' . implode(', ', $sets) . ' WHERE id = ? AND org_id = ?')->execute($args);
    foreach ($plan['changes'] as $field => $pair) {
        pse_audit($pdo, $orgId, $rowId, $batch, $action, $field, $pair[0], $pair[1], $actor);
    }
    return count($plan['changes']);
}

/**
 * Save one row's edit. All or nothing. Returns the number of fields changed.
 */
function pse_apply_edit(PDO $pdo, int $orgId, int $rowId, int $actor, array $post): int
{
    pse_ensure($pdo);
    $users = pse_org_users($pdo, $orgId);
    $sites = pse_org_sites($pdo, $orgId);
    $batch = bin2hex(random_bytes(8));
    $pdo->beginTransaction();
    try {
        $n = pse_apply_edit_in_tx($pdo, $orgId, $rowId, $actor, $post, $users, $sites, $batch, 'edit_row', true);
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return int[] ids of editable (employee, not deleted) rows carrying this sheet name */
function pse_rows_for_name(PDO $pdo, int $orgId, string $name): array
{
    $key = psr_norm_name($name);
    $st = $pdo->prepare('SELECT id, employee_name FROM payroll_sheet_rows WHERE org_id = ? AND deleted_at IS NULL AND row_kind = ?');
    $st->execute([$orgId, 'employee_record']);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (psr_norm_name($r['employee_name']) === $key) {
            $ids[] = (int)$r['id'];
        }
    }
    return $ids;
}

/**
 * Link every draft row that carries one sheet name to one employee.
 * Rows with an active approval are skipped and counted. All or nothing.
 *
 * @return array{linked:int, skipped_locked:int, unchanged:int}
 */
function pse_apply_person_link(PDO $pdo, int $orgId, string $name, int $userId, ?int $siteId, int $actor): array
{
    pse_ensure($pdo);
    if (trim($name) === '') {
        throw new RuntimeException('There is no sheet name to link.');
    }
    $users = pse_org_users($pdo, $orgId);
    $sites = pse_org_sites($pdo, $orgId);
    if ($userId <= 0 || !isset($users[$userId])) {
        throw new RuntimeException('That employee is not in this company.');
    }
    $ids = pse_rows_for_name($pdo, $orgId, $name);
    if (!$ids) {
        throw new RuntimeException('No draft rows carry that sheet name in this company.');
    }
    $batch = bin2hex(random_bytes(8));
    $out = ['linked' => 0, 'skipped_locked' => 0, 'unchanged' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            if (pse_row_is_locked($pdo, $orgId, $id)) {
                $out['skipped_locked']++;
                continue;
            }
            $post = ['user_id' => (string)$userId];
            if ($siteId !== null && $siteId > 0) {
                $post['site_id'] = (string)$siteId;
            }
            $n = pse_apply_edit_in_tx($pdo, $orgId, $id, $actor, $post, $users, $sites, $batch, 'link_person', false);
            $out[$n > 0 ? 'linked' : 'unchanged']++;
        }
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array<int,array<string,mixed>> newest first */
function pse_audit_fetch(PDO $pdo, int $orgId, ?int $rowId, int $limit = 100): array
{
    pse_ensure($pdo);
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT a.id, a.row_id, a.batch_key, a.action, a.field_name, a.old_value, a.new_value, a.actor_id, a.created_at, u.Name AS actor_name
            FROM payroll_sheet_edit_audit a LEFT JOIN users u ON u.id = a.actor_id
            WHERE a.org_id = ?';
    $args = [$orgId];
    if ($rowId !== null) {
        $sql .= ' AND a.row_id = ?';
        $args[] = $rowId;
    }
    $sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- source cells and the side panel ---------- */

function pse_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        if ($ch < 'A' || $ch > 'Z') {
            return 0;
        }
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n;
}

function pse_col_letter(int $index): string
{
    $s = '';
    while ($index > 0) {
        $m = ($index - 1) % 26;
        $s = chr(65 + $m) . $s;
        $index = intdiv($index - 1, 26);
    }
    return $s;
}

/**
 * Column meanings for sheets the loader knows. These are the columns the By Abdul
 * loader reads: A employee, B period, C gross, D net, E store. Other sheets get
 * letters only, so no meaning is guessed.
 *
 * @return array<string,string>
 */
function pse_known_headers(string $sheet): array
{
    if (strcasecmp(trim($sheet), 'By Abdul') === 0) {
        return ['A' => 'employee', 'B' => 'period', 'C' => 'gross', 'D' => 'net', 'E' => 'store'];
    }
    return [];
}

/** The raw spreadsheet cells a draft row was read from, as stored at load time. */
function pse_row_raw_cells(array $row): array
{
    $json = json_decode((string)($row['source_json'] ?? ''), true);
    if (!is_array($json)) {
        return [];
    }
    $raw = $json['raw_values'] ?? [];
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    return is_array($raw) ? $raw : [];
}

function pse_cell_text($value): string
{
    if ($value === null) {
        return '';
    }
    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
    return (string)$value;
}

/**
 * Cells around one row of the source sheet, for the side panel. Uses the stored
 * sheet cells when they are loaded. Otherwise it falls back to the raw values
 * kept on each draft row, which show only the rows that became drafts.
 *
 * @return array{mode:string, sheet:string, selected_row:int, columns:string[], header:array<string,string>, rows:array<int,array<string,mixed>>, note:string}
 */
function pse_source_window(PDO $pdo, int $orgId, array $row, int $radius = 6): array
{
    pse_ensure($pdo);
    $sheet = (string)($row['source_sheet'] ?? '');
    $selected = (int)($row['source_row'] ?? 0);
    $importId = (int)($row['import_id'] ?? 0);
    $from = max(1, $selected - $radius);
    $to = $selected + $radius;
    $rows = [];
    $mode = 'rows';
    $st = $pdo->prepare(
        'SELECT row_num, col_index, col_letter, cell_value, cell_formula, is_number FROM payroll_sheet_cells
         WHERE org_id = ? AND import_id = ? AND sheet_name = ? AND row_num BETWEEN ? AND ?
         ORDER BY row_num, col_index'
    );
    $st->execute([$orgId, $importId, $sheet, $from, $to]);
    $cells = $st->fetchAll(PDO::FETCH_ASSOC);
    $hasSelected = false;
    foreach ($cells as $c) {
        if ((int)$c['row_num'] === $selected) {
            $hasSelected = true;
        }
    }
    if ($cells && $hasSelected) {
        $mode = 'cells';
        foreach ($cells as $c) {
            $n = (int)$c['row_num'];
            if ($n < $from || $n > $to) {
                continue;
            }
            $rows[$n][(string)$c['col_letter']] = [
                'v' => (string)$c['cell_value'],
                'f' => (string)($c['cell_formula'] ?? ''),
                'num' => (int)$c['is_number'] === 1,
            ];
        }
    } else {
        $st = $pdo->prepare(
            'SELECT source_row, source_json FROM payroll_sheet_rows
             WHERE org_id = ? AND import_id = ? AND source_sheet = ? AND source_row BETWEEN ? AND ? AND deleted_at IS NULL
             ORDER BY source_row, id'
        );
        $st->execute([$orgId, $importId, $sheet, $from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (pse_row_raw_cells($r) as $col => $value) {
                $rows[(int)$r['source_row']][(string)$col] = [
                    'v' => pse_cell_text($value),
                    'f' => '',
                    'num' => is_int($value) || is_float($value),
                ];
            }
        }
    }
    $own = pse_row_raw_cells($row);
    $eventCols = [];
    try {
        $ev = $pdo->prepare('SELECT source_cell FROM payroll_sheet_events WHERE org_id = ? AND source_row = ?');
        $ev->execute([$orgId, $selected]);
        foreach ($ev->fetchAll(PDO::FETCH_COLUMN) as $cellList) {
            foreach (preg_split('/[,\s]+/', (string)$cellList) as $ref) {
                if (preg_match('/^([A-Za-z]{1,3})' . $selected . '$/', $ref, $m)) {
                    $eventCols[strtoupper($m[1])] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $eventCols = [];
    }
    $columns = [];
    foreach ($rows as $cellsInRow) {
        foreach (array_keys($cellsInRow) as $col) {
            $columns[$col] = true;
        }
    }
    $columns = array_keys($columns);
    usort($columns, static function ($a, $b) {
        return pse_col_index($a) <=> pse_col_index($b);
    });
    ksort($rows);
    $out = [];
    foreach ($rows as $n => $cellsInRow) {
        $isSel = $n === $selected;
        $out[] = [
            'n' => $n,
            'cells' => $cellsInRow,
            'selected' => $isSel,
            'hl' => $isSel ? array_values(array_unique(array_merge(array_keys($own), $mode === 'cells' ? array_keys($cellsInRow) : []))) : [],
            'ev' => $isSel ? array_keys($eventCols) : [],
        ];
    }
    return [
        'mode' => $mode,
        'sheet' => $sheet,
        'selected_row' => $selected,
        'columns' => $columns,
        'header' => pse_known_headers($sheet),
        'rows' => $out,
        'note' => $mode === 'cells'
            ? 'Cells as stored from the workbook. The selected row is highlighted.'
            : 'The full sheet cells are not loaded yet. Showing the raw values kept on each draft row, so rows that did not become drafts are not shown.',
    ];
}

/* ---------- loading the source cells (data load, run only with approval) ---------- */

/**
 * @return array<int,array{sheet:string,row:int,col:string,value:string,formula:string,is_number:int}>
 */
function pse_cells_parse_csv(string $text): array
{
    $out = [];
    foreach (psr_csv_records($text) as $rec) {
        $sheet = trim((string)($rec['sheet'] ?? ''));
        $row = (int)($rec['row'] ?? 0);
        $col = strtoupper(trim((string)($rec['col'] ?? '')));
        if ($sheet === '' || $row <= 0 || pse_col_index($col) <= 0) {
            throw new RuntimeException('Bad cell line: sheet, row and col are required.');
        }
        $out[] = [
            'sheet' => substr($sheet, 0, 160),
            'row' => $row,
            'col' => $col,
            'value' => (string)($rec['value'] ?? ''),
            'formula' => (string)($rec['formula'] ?? ''),
            'is_number' => !empty($rec['is_number']) && (string)$rec['is_number'] !== '0' ? 1 : 0,
        ];
    }
    return $out;
}

/**
 * Compare stored cells with the raw values already on the draft rows.
 * A cell with a formula is not compared, because the row keeps the formula text.
 *
 * @return array{checked:int, mismatches:string[], missing_rows:int[]}
 */
function pse_cells_verify(array $cells, array $draftRows): array
{
    $byCell = [];
    $rowsWithCells = [];
    foreach ($cells as $c) {
        $byCell[$c['sheet'] . '|' . $c['row'] . '|' . $c['col']] = $c;
        $rowsWithCells[$c['sheet'] . '|' . $c['row']] = true;
    }
    $checked = 0;
    $bad = [];
    $missing = [];
    foreach ($draftRows as $r) {
        $sheet = (string)$r['source_sheet'];
        $n = (int)$r['source_row'];
        if (empty($rowsWithCells[$sheet . '|' . $n])) {
            $missing[] = $n;
            continue;
        }
        foreach (pse_row_raw_cells($r) as $col => $value) {
            $cell = $byCell[$sheet . '|' . $n . '|' . $col] ?? null;
            $checked++;
            if ($cell === null) {
                $bad[] = "$sheet row $n col $col: no stored cell";
                continue;
            }
            if ($cell['formula'] !== '') {
                continue;
            }
            $a = pse_cell_text($value);
            $b = $cell['value'];
            if ($a !== $b && !(is_numeric($a) && is_numeric($b) && abs((float)$a - (float)$b) < 0.005)) {
                $bad[] = "$sheet row $n col $col: draft has '$a', cells have '$b'";
            }
        }
    }
    return ['checked' => $checked, 'mismatches' => $bad, 'missing_rows' => $missing];
}

/**
 * Replace the stored cells of the given sheets for one import. Own transaction.
 */
function pse_cells_store(PDO $pdo, int $orgId, int $importId, array $cells): int
{
    pse_ensure($pdo);
    $sheets = [];
    foreach ($cells as $c) {
        $sheets[$c['sheet']] = true;
    }
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM payroll_sheet_cells WHERE org_id = ? AND import_id = ? AND sheet_name = ?');
        foreach (array_keys($sheets) as $sheet) {
            $del->execute([$orgId, $importId, $sheet]);
        }
        $ins = $pdo->prepare(
            'INSERT INTO payroll_sheet_cells (org_id, import_id, sheet_name, row_num, col_letter, col_index, cell_value, cell_formula, is_number, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $now = pse_now();
        foreach ($cells as $c) {
            $ins->execute([
                $orgId, $importId, $c['sheet'], $c['row'], $c['col'], pse_col_index($c['col']), $c['value'],
                $c['formula'] === '' ? null : $c['formula'], $c['is_number'], $now,
            ]);
        }
        $pdo->commit();
        return count($cells);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
