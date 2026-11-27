-- Outstanding pay (Employee Pay status sheet).
-- New tables only. Does not change payroll_finalized, payroll_payments,
-- users, attendance, or any payroll_sheet_* table. No payroll rows are inserted.
-- Paid rows here are manual reconciliation records. They are not payroll payments.
-- Run on database admin_payroll.

CREATE TABLE IF NOT EXISTS payroll_outstanding_summary (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    store_label VARCHAR(160) NOT NULL,
    sheet_employee VARCHAR(160) NOT NULL,
    user_id INT NULL,
    match_confidence VARCHAR(40) NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    notes TEXT NULL,
    source_file VARCHAR(255) NULL,
    loaded_at DATETIME NOT NULL,
    loaded_by INT NOT NULL DEFAULT 0,
    row_version INT NOT NULL DEFAULT 1,
    UNIQUE KEY org_store_employee (org_id, store_label, sheet_employee),
    KEY org_user (org_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_outstanding_periods (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    added_by INT NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL,
    UNIQUE KEY org_period (org_id, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_outstanding_period_amounts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    summary_id INT NOT NULL,
    period_id INT NOT NULL,
    amount DECIMAL(12,2) NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'manual',
    edited_by INT NOT NULL DEFAULT 0,
    edited_at DATETIME NOT NULL,
    row_version INT NOT NULL DEFAULT 1,
    UNIQUE KEY summary_period (summary_id, period_id),
    KEY period_id (period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_outstanding_payments (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    summary_id INT NOT NULL,
    paid_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method VARCHAR(40) NULL,
    reference_no VARCHAR(80) NULL,
    note TEXT NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    edited_by INT NULL,
    edited_at DATETIME NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    row_version INT NOT NULL DEFAULT 1,
    KEY summary_live (summary_id, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_outstanding_summary_audit (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    summary_id INT NOT NULL DEFAULT 0,
    target_type VARCHAR(20) NOT NULL DEFAULT 'summary',
    target_id INT NOT NULL DEFAULT 0,
    batch_key VARCHAR(40) NOT NULL DEFAULT '',
    action VARCHAR(40) NOT NULL DEFAULT 'edit',
    field_name VARCHAR(40) NOT NULL DEFAULT '',
    old_value TEXT NULL,
    new_value TEXT NULL,
    actor_id INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY org_summary (org_id, summary_id),
    KEY org_created (org_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
