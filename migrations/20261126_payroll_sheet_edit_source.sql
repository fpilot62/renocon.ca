-- Sheet Review: source cells for the side panel, and an audit log for inline edits.
-- New tables only. Does not change payroll_finalized, payroll_payments, users,
-- attendance, or any existing payroll_sheet_* table.
-- Run on database admin_payroll. The application also creates these on open.

CREATE TABLE IF NOT EXISTS payroll_sheet_cells (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    import_id INT NOT NULL DEFAULT 0,
    sheet_name VARCHAR(160) NOT NULL DEFAULT '',
    row_num INT NOT NULL DEFAULT 0,
    col_letter VARCHAR(4) NOT NULL DEFAULT '',
    col_index INT NOT NULL DEFAULT 0,
    cell_value TEXT NULL,
    cell_formula TEXT NULL,
    is_number TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY org_cell (org_id, import_id, sheet_name, row_num, col_index),
    KEY org_sheet_row (org_id, sheet_name, row_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_edit_audit (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    row_id INT NOT NULL,
    batch_key VARCHAR(40) NOT NULL DEFAULT '',
    action VARCHAR(40) NOT NULL DEFAULT 'edit',
    field_name VARCHAR(40) NOT NULL DEFAULT '',
    old_value TEXT NULL,
    new_value TEXT NULL,
    actor_id INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY org_row (org_id, row_id),
    KEY org_created (org_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
