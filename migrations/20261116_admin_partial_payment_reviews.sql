-- Review notes for partial payments. Safe to run more than once.
-- This file only creates admin_partial_payment_reviews.
-- It does not change payroll_finalized, payroll_payments, payroll_masters,
-- or payroll_balance, and it does not mark anyone paid.

CREATE TABLE IF NOT EXISTS admin_partial_payment_reviews (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    wage_id INT NOT NULL,
    employee_name VARCHAR(160) NOT NULL DEFAULT '',
    period VARCHAR(160) NOT NULL DEFAULT '',
    payment_date DATE NULL,
    site_paid_from VARCHAR(160) NOT NULL DEFAULT '',
    amount DECIMAL(12,2) NULL,
    method VARCHAR(20) NOT NULL DEFAULT 'Cash',
    verified TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    shift_notes JSON NULL,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY org_wage (org_id, wage_id),
    KEY org_id (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
