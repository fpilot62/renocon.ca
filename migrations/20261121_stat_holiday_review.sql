-- Ontario holiday eligibility review. Safe to run more than once.
-- A pending row is not a pay line.

SET @db := DATABASE();

CREATE TABLE IF NOT EXISTS stat_holiday_review (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    user_id INT NOT NULL,
    site_id INT NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(120) NULL,
    province VARCHAR(8) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    flags VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    decision VARCHAR(20) NULL,
    decided_by INT NULL,
    decided_at DATETIME NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stat_review (org_id, user_id, site_id, holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
