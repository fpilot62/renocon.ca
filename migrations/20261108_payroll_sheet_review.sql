-- Draft sheet review, proposals, and explicit approval.
-- Run on database admin_payroll. This does not change existing wage rows.
-- Uploaded workbooks are not stored in this file. Keep them outside the web root.

CREATE TABLE IF NOT EXISTS payroll_sheet_imports (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'workbook',
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    stored_name VARCHAR(64) NOT NULL DEFAULT '',
    byte_size INT NOT NULL DEFAULT 0,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    loaded_at DATETIME NULL,
    row_count INT NOT NULL DEFAULT 0,
    event_count INT NOT NULL DEFAULT 0,
    KEY org_created (org_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_rows (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    import_id INT NOT NULL DEFAULT 0,
    source_sheet VARCHAR(160) NOT NULL DEFAULT '',
    source_row INT NOT NULL DEFAULT 0,
    row_kind VARCHAR(40) NOT NULL DEFAULT '',
    source_json MEDIUMTEXT NULL,
    employee_name VARCHAR(160) NOT NULL DEFAULT '',
    store_name VARCHAR(160) NOT NULL DEFAULT '',
    user_id INT NULL,
    site_id INT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    period_month INT NULL,
    period_half INT NULL,
    period_year INT NULL,
    year_confidence VARCHAR(40) NOT NULL DEFAULT '',
    period_confidence VARCHAR(80) NOT NULL DEFAULT '',
    year_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    gross DECIMAL(12,2) NULL,
    net DECIMAL(12,2) NULL,
    reported_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    status_evidence_json TEXT NULL,
    raw_notes TEXT NULL,
    draft_notes TEXT NULL,
    flags_json TEXT NULL,
    party_class VARCHAR(20) NOT NULL DEFAULT 'unanswered',
    pay_route VARCHAR(20) NOT NULL DEFAULT 'unset',
    amount_basis VARCHAR(20) NOT NULL DEFAULT 'unset',
    proposed_amount DECIMAL(12,2) NULL,
    ee_deduction_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    ee_deduction_amount DECIMAL(12,2) NULL,
    er_cpp_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    er_cpp_amount DECIMAL(12,2) NULL,
    er_ei_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    er_ei_amount DECIMAL(12,2) NULL,
    er_other_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    er_other_amount DECIMAL(12,2) NULL,
    gst_verified TINYINT(1) NOT NULL DEFAULT 0,
    gst_invoice_principal DECIMAL(12,2) NULL,
    actual_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    actual_amount DECIMAL(12,2) NULL,
    actual_date DATE NULL,
    actual_method VARCHAR(32) NULL,
    actual_reference VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY org_import (org_id, import_id),
    KEY org_user (org_id, user_id),
    KEY org_source (org_id, source_sheet, source_row)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_events (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    import_id INT NOT NULL DEFAULT 0,
    event_key VARCHAR(80) NOT NULL DEFAULT '',
    source_row INT NULL,
    source_cell VARCHAR(40) NOT NULL DEFAULT '',
    employee_context VARCHAR(160) NOT NULL DEFAULT '',
    store_context VARCHAR(160) NOT NULL DEFAULT '',
    signed_amount DECIMAL(12,2) NULL,
    allocation_status VARCHAR(40) NOT NULL DEFAULT 'unallocated',
    payment_date DATE NULL,
    raw_notes TEXT NULL,
    flags_json TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY org_import (org_id, import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_revisions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    target_type VARCHAR(32) NOT NULL,
    target_id INT NOT NULL,
    action VARCHAR(40) NOT NULL,
    actor_id INT NOT NULL DEFAULT 0,
    before_json MEDIUMTEXT NULL,
    after_json MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    KEY org_target (org_id, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_plans (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    plan_date DATE NOT NULL,
    title VARCHAR(160) NOT NULL DEFAULT '',
    available_amount DECIMAL(12,2) NULL,
    note TEXT NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY org_date (org_id, plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_plan_lines (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    plan_id INT NOT NULL,
    row_id INT NULL,
    employee_name VARCHAR(160) NOT NULL DEFAULT '',
    user_id INT NULL,
    site_id INT NULL,
    proposed_amount DECIMAL(12,2) NULL,
    amount_basis VARCHAR(20) NOT NULL DEFAULT 'unset',
    pay_route VARCHAR(20) NOT NULL DEFAULT 'unset',
    party_class VARCHAR(20) NOT NULL DEFAULT 'unanswered',
    ee_deduction_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    ee_deduction_amount DECIMAL(12,2) NULL,
    er_cpp_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    er_cpp_amount DECIMAL(12,2) NULL,
    er_ei_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    er_ei_amount DECIMAL(12,2) NULL,
    er_other_state VARCHAR(20) NOT NULL DEFAULT 'unknown',
    er_other_amount DECIMAL(12,2) NULL,
    gst_verified TINYINT(1) NOT NULL DEFAULT 0,
    gst_invoice_principal DECIMAL(12,2) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    KEY plan_id (plan_id),
    KEY org_plan (org_id, plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_approvals (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    undone_at DATETIME NULL,
    undone_by INT NULL,
    preview_json MEDIUMTEXT NULL,
    result_json MEDIUMTEXT NULL,
    UNIQUE KEY org_key (org_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_approval_effects (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    approval_id INT NOT NULL,
    org_id INT NOT NULL,
    row_id INT NOT NULL,
    effect_kind VARCHAR(32) NOT NULL,
    finalized_id INT NULL,
    payment_id INT NULL,
    detail_json TEXT NULL,
    before_json TEXT NULL,
    after_json TEXT NULL,
    active_key VARCHAR(80) NULL,
    undone_at DATETIME NULL,
    UNIQUE KEY active_key (active_key),
    KEY approval_id (approval_id),
    KEY org_row (org_id, row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_sheet_identity_links (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id INT NOT NULL,
    user_id INT NOT NULL,
    other_org_id INT NOT NULL,
    other_user_id INT NOT NULL,
    created_by INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY pair_link (org_id, user_id, other_org_id, other_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
