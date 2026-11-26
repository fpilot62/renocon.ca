<?php
/**
 * Hide admin and test logins from Sheet Review. Nothing here writes the database.
 */

/** @var list<int> admin vernon (org 2) stays in the database and is hidden here. */
const SHEET_HIDDEN_USER_IDS = [10006];

/** @return list<int> */
function sheet_hidden_user_ids(): array
{
    return SHEET_HIDDEN_USER_IDS;
}

function sheet_name_is_hidden_admin(string $name): bool
{
    $name = ltrim($name);
    return $name !== '' && strncasecmp($name, 'admin', 5) === 0;
}

function sheet_person_is_hidden(int $userId, string $name = ''): bool
{
    if ($userId > 0 && in_array($userId, sheet_hidden_user_ids(), true)) {
        return true;
    }
    return sheet_name_is_hidden_admin($name);
}

function sheet_show_hidden_requested(): bool
{
    $raw = (string)($_POST['show_hidden'] ?? $_GET['show_hidden'] ?? '');
    return $raw === '1' || $raw === 'on';
}

function sheet_row_is_hidden(array $row): bool
{
    $uid = (int)($row['user_id'] ?? $row['id'] ?? 0);
    if (sheet_person_is_hidden($uid, '')) {
        return true;
    }
    foreach (['Name', 'name', 'employee_name', 'linked_name'] as $key) {
        if (sheet_name_is_hidden_admin((string)($row[$key] ?? ''))) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function sheet_filter_hidden_rows(array $rows, bool $show): array
{
    if ($show) {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $row): bool {
        return !sheet_row_is_hidden($row);
    }));
}
