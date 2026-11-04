-- Cheque register for Payroll Center.
-- engine/payroll_cheque_lib.php applies this on first use (adds missing
-- columns only). Running this file twice is safe for the CREATE statements.
-- It does not update payroll_finalized amounts.

CREATE TABLE IF NOT EXISTS payroll_cheques (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    user_id INT NOT NULL,
    site_id INT NOT NULL DEFAULT 0,
    cheque_number INT NOT NULL,
    cheque_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    period_start DATE NULL,
    period_end DATE NULL,
    employee_name VARCHAR(160) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'issued',
    issue_nonce VARCHAR(64) NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    voided_by INT NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(255) NULL,
    paid_at DATETIME NULL,
    UNIQUE KEY org_cheque (org_id, cheque_number),
    UNIQUE KEY org_nonce_user_site (org_id, issue_nonce, user_id, site_id),
    KEY org_period (org_id, period_start, period_end),
    KEY org_user (org_id, user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_cheque_lines (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cheque_id INT NOT NULL,
    finalized_id INT NOT NULL,
    line_remaining DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_id INT NULL,
    KEY cheque_id (cheque_id),
    KEY finalized_id (finalized_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Added by payroll_cheque_lib.php when missing:
--   users.eft_no_cheque TINYINT(1) NOT NULL DEFAULT 0
--   payroll_payments.reversed_at DATETIME NULL
--   payroll_payments.reversed_by INT NULL
--   payroll_payments.cheque_id INT NULL
