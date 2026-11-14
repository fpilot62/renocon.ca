-- Outstanding wages tracking ledger. Safe to run more than once.
-- This file only creates the two ledger tables below.
-- It does not create pay runs and it does not change wage, payment, or balance tables.
-- Status values on this table are labels for this ledger only.
-- Run on the payroll database when you are ready. Do not apply it from this repo's tests.

CREATE TABLE IF NOT EXISTS admin_outstanding_wages (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    site_id INT NULL,
    store_name VARCHAR(160) NOT NULL DEFAULT '',
    employee_name VARCHAR(160) NOT NULL DEFAULT '',
    user_id INT NULL,
    pay_period VARCHAR(160) NOT NULL DEFAULT '',
    period_start DATE NULL,
    period_end DATE NULL,
    gross DECIMAL(12,2) NOT NULL DEFAULT 0,
    net DECIMAL(12,2) NULL,
    employer_deductions DECIMAL(12,2) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    portal_record VARCHAR(255) NOT NULL DEFAULT '',
    checked_vs_son TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    dup_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    updated_by INT NULL,
    UNIQUE KEY org_dup (org_id, dup_key),
    KEY org_store (org_id, store_name),
    KEY org_user (org_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_outstanding_wage_edits (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    wage_id INT NOT NULL,
    org_id INT NOT NULL,
    actor_id INT NOT NULL DEFAULT 0,
    field_name VARCHAR(40) NOT NULL,
    before_value TEXT NULL,
    after_value TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY wage_id (wage_id),
    KEY org_wage (org_id, wage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
