-- One employee link for a sheet name, reused on every period and on later imports.
-- store_label is NULL when the link covers the name in the whole company.
-- store_key is '' in that case so the unique key accepts a single org-wide row.
-- Does not change payroll_finalized, payroll_payments, users, or attendance.

CREATE TABLE IF NOT EXISTS payroll_sheet_name_links (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    sheet_name VARCHAR(160) NOT NULL,
    store_label VARCHAR(160) NULL,
    store_key VARCHAR(160) NOT NULL DEFAULT '',
    user_id INT NOT NULL,
    linked_by INT NOT NULL DEFAULT 0,
    linked_at DATETIME NOT NULL,
    UNIQUE KEY org_name_store (org_id, sheet_name, store_key),
    KEY org_user (org_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_name_link_audit (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    sheet_name VARCHAR(160) NOT NULL,
    store_label VARCHAR(160) NULL,
    action VARCHAR(40) NOT NULL DEFAULT 'confirm',
    old_user_id INT NULL,
    new_user_id INT NULL,
    sheet_rows INT NOT NULL DEFAULT 0,
    skipped_locked INT NOT NULL DEFAULT 0,
    outstanding_rows INT NOT NULL DEFAULT 0,
    actor_id INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY org_created (org_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
