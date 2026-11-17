-- Immediate demand list for the outstanding report.
-- This file only creates admin_immediate_demand.
-- It does not change admin_outstanding_wages, pay runs, or payment tables.
-- wage_id is an optional pointer. It is not a foreign key.
-- Run on the payroll database when you are ready. Do not apply it from this repo's tests.

CREATE TABLE IF NOT EXISTS admin_immediate_demand (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    wage_id INT NULL,
    store_name VARCHAR(160) NOT NULL DEFAULT '',
    employee_name VARCHAR(160) NOT NULL DEFAULT '',
    pay_period VARCHAR(160) NOT NULL DEFAULT '',
    gross DECIMAL(12,2) NOT NULL DEFAULT 0,
    vacation_pay DECIMAL(12,2) NULL,
    employer_contribution DECIMAL(12,2) NULL,
    net_cash DECIMAL(12,2) NULL,
    cash_flag TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    updated_by INT NULL,
    KEY org_id (org_id),
    KEY org_wage (org_id, wage_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
