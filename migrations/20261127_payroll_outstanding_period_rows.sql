-- Outstanding pay, per-employee period rows.
-- Supersedes the org-level payroll_outstanding_periods columns.
-- Those tables were never deployed. This file drops them if a trial run
-- created them, then creates the employee period rows.
-- Does not change payroll_finalized, payroll_payments, users, or attendance.
-- Reconciliation payments are not payroll payments.

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

DROP TABLE IF EXISTS payroll_outstanding_period_amounts;
DROP TABLE IF EXISTS payroll_outstanding_periods;

CREATE TABLE IF NOT EXISTS payroll_outstanding_period_rows (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    summary_id INT NOT NULL,
    period_no TINYINT NOT NULL,
    period_month DATE NOT NULL,
    amount DECIMAL(12,2) NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'manual',
    edited_by INT NOT NULL DEFAULT 0,
    edited_at DATETIME NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    row_version INT NOT NULL DEFAULT 1,
    UNIQUE KEY summary_half (summary_id, period_no, period_month),
    KEY summary_live (summary_id, deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
